<?php

declare(strict_types=1);

namespace Ekenbil\CarListing\Scraper;

use Ekenbil\CarListing\Logger\Logger;
use Ekenbil\CarListing\Parser\CarParser;
use Ekenbil\CarListing\Persistence\CarRepository;

class Scraper implements ScraperInterface
{
    private Logger $logger;
    private CarParser $parser;
    private CarRepository $repository;

    public function __construct(Logger $logger, CarParser $parser, CarRepository $repository)
    {
        $this->logger = $logger;
        $this->parser = $parser;
        $this->repository = $repository;
    }

    public function updateCarListingBatch(int $offset, int $limit, array $options = [], ?string $sessionId = null): array
    {
        include_once(dirname(__DIR__, 1) . '/simple_html_dom.php');
        $baseUrl = filter_var(get_option('bp_get_cars_baseurl', 'https://www.bytbil.com'), FILTER_VALIDATE_URL) ?: 'https://www.bytbil.com';
        $storePath = get_option('bp_get_cars_store', '/handlare/ekenbil-ab-9951');
        $store = $baseUrl . $storePath;
        $mainBaseUrl = $baseUrl;
        $temporarily_failed_ids = [];
        $selector_car_links = sanitize_text_field(get_option('bp_get_cars_selector_car_links', 'ul.result-list li .uk-width-1-1 .car-list-header a'));
        $selector_pagination = sanitize_text_field(get_option('bp_get_cars_selector_pagination', 'div.pagination-container a.pagination-page'));

        if (!$sessionId) {
            $sessionId = uniqid('bp_cars_', true);
            $this->logger->logError('Generated new session_id: ' . $sessionId);
        }
        $transient_key = 'bp_get_cars_links_' . $sessionId;
        $all_links = get_transient($transient_key);
        if ($all_links === false) {
            $this->logger->logError('No cached links for session, scraping store page: ' . $store);
            $retry_count = (int) get_option('bp_get_cars_retry_count', 2);
            $retry_delay = (int) get_option('bp_get_cars_retry_delay', 1);
            $html = false;
            $attempts = 0;
            while ($attempts <= $retry_count && ! $html) {
                $html = @file_get_html($store);
                if (! $html) {
                    $this->logger->logError("Could not fetch HTML from $store (attempt " . ($attempts + 1) . ")");
                    $attempts++;
                    if ($attempts <= $retry_count && $retry_delay > 0) sleep($retry_delay);
                }
            }
            if (! $html) {
                $this->logger->logError("Could not fetch HTML from $store after $retry_count attempts");
                return ['error' => 'Could not fetch HTML from dealer.'];
            }
            $pages = $html->find($selector_pagination);
            $pagesnr = max(1, count($pages));
            $this->logger->logError('Found ' . $pagesnr . ' pages.');
            $all_links = [];
            for ($l = 1; $l <= $pagesnr; $l++) {
                $page_url = $l === 1 ? $store : ($store . '?Page=' . $l);
                $this->logger->logError('Fetching page ' . $l . ': ' . $page_url);
                $page_html = $l === 1 ? $html : @file_get_html($page_url);
                $links = $page_html->find($selector_car_links);
                $this->logger->logError('Found ' . count($links) . ' car links on page ' . $l);
                foreach ($links as $carLink) {
                    $all_links[] = $carLink->href;
                }
            }
            set_transient($transient_key, $all_links, 60 * 30);
            $this->logger->logError('Cached car links for session_id: ' . $sessionId);
        } else {
            $this->logger->logError('Loaded cached car links for session_id: ' . $sessionId . ' (count: ' . count($all_links) . ')');
        }
        $batch = array_slice($all_links, $offset, $limit);
        $this->logger->logError('Processing batch: offset=' . $offset . ', limit=' . $limit . ', batch count=' . count($batch));
        $results = [];
        $map = [
            'Märke' => ['vehica_6659', 'taxonomy'],
            'Modell' => ['vehica_6660', 'taxonomy'],
            'Årsmodell' => ['vehica_14696', 'number'],
            'Miltal' => ['vehica_6664', 'number'],
            'Drivmedel' => ['vehica_6663', 'taxonomy'],
            'Växellåda' => ['vehica_6662', 'taxonomy'],
            'Drivhjul' => ['vehica_6661', 'taxonomy'],
            'Regnr' => ['vehica_6671', 'text']
        ];
        $skip_existing = isset($options['skip_existing']) ? (bool)$options['skip_existing'] : false;
        $ids_transient_key = 'bp_get_cars_ids_' . $sessionId;
        $all_processed_ids = get_transient($ids_transient_key);
        if ($all_processed_ids === false) {
            $all_processed_ids = [];
        }
        foreach ($batch as $carHref) {
            $this->logger->logError('Processing carHref: ' . $carHref);
            if ($skip_existing && ($id = $this->repository->isDuplicate($carHref)) !== false) {
                $this->logger->logError('Skipped existing car: ' . $carHref . ' (ID: ' . $id . ')');
                $results[] = ['guid' => $carHref, 'status' => 'skipped_existing', 'id' => $id];
                if ($id) $all_processed_ids[] = $id;
                continue;
            }
            $this->logger->logError('Fetching car details for: ' . $mainBaseUrl . $carHref);
            $carPage = false;
            $attempts = 0;
            while ($attempts <= $retry_count && ! $carPage) {
                $carPage = @file_get_html($mainBaseUrl . $carHref);
                if (! $carPage) {
                    $this->logger->logError("Could not fetch details for car: $carHref (attempt " . ($attempts + 1) . ")");
                    $attempts++;
                    if ($attempts <= $retry_count && $retry_delay > 0) sleep($retry_delay);
                }
            }
            if (! $carPage) {
                $this->logger->logError("Permanent failure to fetch details for car: $carHref after $retry_count attempts");
                $id = $this->repository->isDuplicate($carHref);
                if ($id) $temporarily_failed_ids[] = $id;
                $results[] = ['guid' => $carHref, 'status' => 'error', 'retries' => $retry_count];
                continue;
            }
            $car = (object) [];
            $car->guid = $carHref;
            $titleNode = $carPage->find('.vehicle-detail-title', 0);
            $car->title = \Ekenbil\CarListing\Helpers\Helpers::cleanText($titleNode ? $titleNode->plaintext : '');
            $priceNode = $carPage->find('#vehicle-details .vehicle-detail-price .car-price-details', 0);
            $car->price = \Ekenbil\CarListing\Helpers\Helpers::cleanNumber($priceNode ? $priceNode->plaintext : '');
            $car->details = $this->extractDetails($carPage, $map);
            $carfaxNode = $carPage->find('#extended-carfax-details .extended-carfax-details-headline a', 0);
            $car->carfax = $carfaxNode ? $carfaxNode->href : '';
            $car->additional = $this->extractAdditional($carPage);
            $car->images = $this->extractImages($carPage);
            $car->features = $this->extractFeatures($carPage);
            $this->logger->logError('Creating/updating listing for car: ' . $car->title);
            if ($id = $this->repository->createOrUpdateListing($car)) {
                $this->logger->logError('Successfully created/updated car: ' . $car->title . ' (ID: ' . $id . ')');
                $results[] = ['guid' => $car->guid, 'status' => 'success', 'id' => $id];
                $all_processed_ids[] = $id;
            } else {
                $this->logger->logError('Failed to create/update car: ' . $car->title);
                $results[] = ['guid' => $car->guid, 'status' => 'error'];
            }
        }
        set_transient($ids_transient_key, $all_processed_ids, 60 * 30);
        $has_more = ($offset + $limit) < count($all_links);
        $this->logger->logError('Batch complete. has_more=' . ($has_more ? 'true' : 'false') . ', next_offset=' . ($offset + $limit));
        if (! $has_more) {
            delete_transient($transient_key);
            $this->logger->logError('Deleted transient for session_id: ' . $sessionId);
            $all_ids_final = array_unique(array_merge($all_processed_ids, $temporarily_failed_ids));
            delete_transient($ids_transient_key);
            return [
                'results' => $results,
                'has_more' => $has_more,
                'next_offset' => $offset + $limit,
                'total' => count($all_links),
                'session_id' => $sessionId,
                'all_ids' => $all_ids_final
            ];
        }
        return [
            'results' => $results,
            'has_more' => $has_more,
            'next_offset' => $offset + $limit,
            'total' => count($all_links),
            'session_id' => $sessionId
        ];
    }

    // PSR-12 bridge: allow both update_car_listing_batch and updateCarListingBatch
    public function update_car_listing_batch($offset, $limit, $args = [], $session_id = null)
    {
        if (method_exists($this, 'updateCarListingBatch')) {
            return $this->updateCarListingBatch($offset, $limit, $args, $session_id);
        }
        return null;
    }

    // Helper extraction methods (renamed for PSR-12)
    private function extractDetails($carPage, $map)
    {
        $details = (object) [];
        $detailsHtml = $carPage->find('div.vehicle-detail-headline .object-info-box dl div');
        foreach ($detailsHtml as $carFeature) {
            $dtNode = $carFeature->find('dt', 0);
            $ddNode = $carFeature->find('dd', 0);
            $keyFeature = $dtNode ? $dtNode->plaintext : '';
            $featureKey = $map[$keyFeature] ?? null;
            if ($featureKey && $ddNode) {
                $value = \Ekenbil\CarListing\Helpers\Helpers::cleanNumber($ddNode->plaintext);
                $details->{$featureKey[0]} = [$value, $featureKey[1]];
            }
        }
        return $details;
    }

    private function extractAdditional($carPage)
    {
        $additional = [];
        $key = $value = '';
        $u = 1;
        $carDetailsNodes = $carPage->find('.vehicle-detail-additional-detail > .additional-vehicle-data > ul > li > div');
        foreach ($carDetailsNodes as $carDetails) {
            if ($u % 2 == 0) {
                $value = \Ekenbil\CarListing\Helpers\Helpers::cleanText($carDetails->plaintext);
                $additional[$key] = $value;
            } else {
                $key = \Ekenbil\CarListing\Helpers\Helpers::cleanText($carDetails->plaintext);
            }
            $u++;
        }
        return $additional;
    }

    private function extractImages($carPage)
    {
        $images = [];
        $carImagesNodes = $carPage->find('div.main-slideshow-container > ul.uk-slideshow > li');
        foreach ($carImagesNodes as $carImages) {
            $src = $carImages->{'data-src'} ?? '';
            if (! empty($src)) {
                $images[] = (string) $src;
            }
        }
        return $images;
    }

    private function extractFeatures($carPage)
    {
        $features = [];
        $equipmentNodes = $carPage->find('div.vehicle-detail-equipment-detail .equipment-box ul li');
        foreach ($equipmentNodes as $equipment) {
            $features[] = \Ekenbil\CarListing\Helpers\Helpers::cleanText($equipment->plaintext);
        }
        return $features;
    }
}
