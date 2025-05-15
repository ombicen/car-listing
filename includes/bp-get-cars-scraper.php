<?php
// Scraper logic for bp Get Cars plugin
class BP_Get_Cars_Scraper
{
    public $error_log_file;
    private $main;

    // Centralized logging method
    private function log($message)
    {
        $this->main->logger->log_error('[BP_Get_Cars_Scraper] ' . $message);
    }

    public function __construct($main_instance)
    {
        $this->main = $main_instance;
    }

    // Removed update_car_listing (full sync) method. All imports should use batch logic.

    // Batch import with session ID to avoid collisions between parallel imports
    public function update_car_listing_batch($offset, $limit, $args = [], $session_id = null)
    {
        $this->log('DEBUG: Initiated update_car_listing_batch with offset=' . $offset . ', limit=' . $limit . ', session_id=' . ($session_id ?: 'none'));
        include_once(dirname(__FILE__) . '/simple_html_dom.php');
        $baseUrl = filter_var(get_option('bp_get_cars_baseurl', 'https://www.bytbil.com'), FILTER_VALIDATE_URL) ?: 'https://www.bytbil.com';
        $storePath = get_option('bp_get_cars_store', '/handlare/ekenbil-ab-9951');
        $store = $baseUrl . $storePath;
        $mainBaseUrl = $baseUrl;
        $selector_car_links = sanitize_text_field(get_option('bp_get_cars_selector_car_links', 'ul.result-list li .uk-width-1-1 .car-list-header a'));
        $selector_pagination = sanitize_text_field(get_option('bp_get_cars_selector_pagination', 'div.pagination-container a.pagination-page'));

        // Generate or use session ID
        if (!$session_id) {
            $session_id = uniqid('bp_cars_', true);
            $this->log('DEBUG: Generated new session_id: ' . $session_id);
        }
        $transient_key = 'bp_get_cars_links_' . $session_id;
        $all_links = get_transient($transient_key);
        if ($all_links === false) {
            $this->log('DEBUG: No cached links for session, scraping store page: ' . $store);
            $html = file_get_html($store);
            if (! $html) {
                $this->log("Kunde inte hämta HTML från $store");
                return ['error' => 'Kunde inte hämta HTML från bilhandlaren.'];
            }
            $pages = $html->find($selector_pagination);
            $pagesnr = max(1, count($pages));
            $this->log('DEBUG: Found ' . $pagesnr . ' pages.');
            $all_links = [];
            for ($l = 1; $l <= $pagesnr; $l++) {
                $page_url = $l === 1 ? $store : ($store . '?Page=' . $l);
                $this->log('DEBUG: Fetching page ' . $l . ': ' . $page_url);
                $page_html = $l === 1 ? $html : @file_get_html($page_url);
                $links = $page_html->find($selector_car_links);
                $this->log('DEBUG: Found ' . count($links) . ' car links on page ' . $l);
                foreach ($links as $carLink) {
                    $all_links[] = $carLink->href;
                }
            }
            set_transient($transient_key, $all_links, 60 * 30); // Cache for 30 minutes
            $this->log('DEBUG: Cached car links for session_id: ' . $session_id);
        } else {
            $this->log('DEBUG: Loaded cached car links for session_id: ' . $session_id . ' (count: ' . count($all_links) . ')');
        }
        $batch = array_slice($all_links, $offset, $limit);
        $this->log('DEBUG: Processing batch: offset=' . $offset . ', limit=' . $limit . ', batch count=' . count($batch));
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
        $skip_existing = isset($args['skip_existing']) ? (bool)$args['skip_existing'] : false;

        // Track all processed IDs in the session
        $ids_transient_key = 'bp_get_cars_ids_' . $session_id;
        $all_processed_ids = get_transient($ids_transient_key);
        if ($all_processed_ids === false) {
            $all_processed_ids = [];
        }

        $retry_count = (int) get_option('bp_get_cars_retry_count', 2);
        $retry_delay = (int) get_option('bp_get_cars_retry_delay', 1);
        $temporarily_failed_ids = [];

        foreach ($batch as $carHref) {
            $this->log('DEBUG: Processing carHref: ' . $carHref);
            if ($skip_existing && ($id = $this->main->is_duplicate($carHref)) !== false) {
                $this->log('DEBUG: Skipped existing car: ' . $carHref . ' (ID: ' . $id . ')');
                $results[] = ['guid' => $carHref, 'status' => 'skipped_existing', 'id' => $id];
                if ($id) $all_processed_ids[] = $id;
                continue;
            }
            $this->log('DEBUG: Fetching car details for: ' . $mainBaseUrl . $carHref);
            $carPage = false;
            $attempts = 0;
            while ($attempts <= $retry_count && ! $carPage) {
                $carPage = @file_get_html($mainBaseUrl . $carHref);
                if (! $carPage) {
                    $this->log("Kunde inte hämta detaljer för bil: $carHref (försök " . ($attempts + 1) . ")");
                    $attempts++;
                    if ($attempts <= $retry_count && $retry_delay > 0) sleep($retry_delay); // delay between retries
                }
            }
            if (! $carPage) {
                $this->log("Permanent misslyckande att hämta detaljer för bil: $carHref efter $retry_count försök");
                $id = $this->main->is_duplicate($carHref);
                if ($id) $temporarily_failed_ids[] = $id;
                $results[] = ['guid' => $carHref, 'status' => 'error', 'retries' => $retry_count];
                continue;
            }
            $car = (object) [];
            $car->guid = $carHref;
            $titleNode = $carPage->find('.vehicle-detail-title', 0);
            $car->title = $this->main->clean_text($titleNode ? $titleNode->plaintext : '');
            $priceNode = $carPage->find('#vehicle-details .vehicle-detail-price .car-price-details', 0);
            $car->price = $this->main->clean_number($priceNode ? $priceNode->plaintext : '');
            $car->details = $this->extract_details($carPage, $map);
            $carfaxNode = $carPage->find('#extended-carfax-details .extended-carfax-details-headline a', 0);
            $car->carfax = $carfaxNode ? $carfaxNode->href : '';
            $car->additional = $this->extract_additional($carPage);
            $car->images = $this->extract_images($carPage);
            $car->features = $this->extract_features($carPage);
            $this->log('DEBUG: Creating/updating listing for car: ' . $car->title);
            if ($id = $this->main->create_listing($car)) {
                $this->log('DEBUG: Successfully created/updated car: ' . $car->title . ' (ID: ' . $id . ')');
                $results[] = ['guid' => $car->guid, 'status' => 'success', 'id' => $id];
                $all_processed_ids[] = $id;
            } else {
                $this->log('DEBUG: Failed to create/update car: ' . $car->title);
                $results[] = ['guid' => $car->guid, 'status' => 'error'];
            }
        }
        // Save processed IDs for this session
        set_transient($ids_transient_key, $all_processed_ids, 60 * 30);
        $has_more = ($offset + $limit) < count($all_links);
        $this->log('DEBUG: Batch complete. has_more=' . ($has_more ? 'true' : 'false') . ', next_offset=' . ($offset + $limit));
        // Clean up transients if this was the last batch
        if (! $has_more) {
            delete_transient($transient_key);
            $this->log('DEBUG: Deleted transient for session_id: ' . $session_id);
            // Return all processed IDs for cleanup, including temporarily failed
            $all_ids_final = array_unique(array_merge($all_processed_ids, $temporarily_failed_ids));
            delete_transient($ids_transient_key);
            return [
                'results' => $results,
                'has_more' => $has_more,
                'next_offset' => $offset + $limit,
                'total' => count($all_links),
                'session_id' => $session_id,
                'all_ids' => $all_ids_final
            ];
        }
        return [
            'results' => $results,
            'has_more' => $has_more,
            'next_offset' => $offset + $limit,
            'total' => count($all_links),
            'session_id' => $session_id
        ];
    }

    public function extract_details($carPage, $map)
    {
        $details = (object) [];
        $detailsHtml = $carPage->find('div.vehicle-detail-headline .object-info-box dl div');
        foreach ($detailsHtml as $carFeature) {
            $dtNode = $carFeature->find('dt', 0);
            $ddNode = $carFeature->find('dd', 0);
            $keyFeature = $dtNode ? $dtNode->plaintext : '';
            $featureKey = $map[$keyFeature] ?? null;
            if ($featureKey && $ddNode) {
                $value = $this->main->clean_number($ddNode->plaintext);
                $details->{$featureKey[0]} = [$value, $featureKey[1]];
            }
        }
        return $details;
    }

    public function extract_additional($carPage)
    {
        $additional = [];
        $key = $value = '';
        $u = 1;
        $carDetailsNodes = $carPage->find('.vehicle-detail-additional-detail > .additional-vehicle-data > ul > li > div');
        foreach ($carDetailsNodes as $carDetails) {
            if ($u % 2 == 0) {
                $value = $this->main->clean_text($carDetails->plaintext);
                $additional[$key] = $value;
            } else {
                $key = $this->main->clean_text($carDetails->plaintext);
            }
            $u++;
        }
        return $additional;
    }

    public function extract_images($carPage)
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

    public function extract_features($carPage)
    {
        $features = [];
        $equipmentNodes = $carPage->find('div.vehicle-detail-equipment-detail .equipment-box ul li');
        foreach ($equipmentNodes as $equipment) {
            $features[] = $this->main->clean_text($equipment->plaintext);
        }
        return $features;
    }
}
