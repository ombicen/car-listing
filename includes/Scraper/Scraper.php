<?php

declare(strict_types=1);

namespace Ekenbil\CarListing\Scraper;

use Ekenbil\CarListing\Logger\Logger;
use Ekenbil\CarListing\Parser\CarParser;
use Ekenbil\CarListing\Persistence\CarRepositoryInterface;
use simple_html_dom; // make IDE aware (autoloaded via include below)

/**
 * Fetches car links from BytBil and imports them in configurable batches.
 * – Retries network calls with exponential back‑off
 * – Caches scraped link lists in WP‑transients to avoid re‑scraping during the same session
 * – Delegates all parsing & persistence to CarParser + CarRepository
 */
final class Scraper implements ScraperInterface
{
    private const LINKS_CACHE_TTL = 1800; // 30 min
    private const DEFAULT_RETRY_COUNT  = 2;
    private const DEFAULT_RETRY_DELAY  = 1; // seconds

    private Logger $logger;
    private CarParser $parser;
    private CarRepositoryInterface $repository;

    /** @var array<string, array{string,string}> */
    private array $fieldMap = [
        'Märke'     => ['vehica_6659',  'taxonomy'],
        'Modell'    => ['vehica_6660',  'taxonomy'],
        'Årsmodell' => ['vehica_14696', 'number'],
        'Miltal'    => ['vehica_6664',  'number'],
        'Drivmedel' => ['vehica_6663',  'taxonomy'],
        'Växellåda' => ['vehica_6662',  'taxonomy'],
        'Drivhjul'  => ['vehica_6661',  'taxonomy'],
        'Regnr'     => ['vehica_6671',  'text'],
    ];

    public function __construct(Logger $logger, CarParser $parser, CarRepositoryInterface $repository)
    {
        // Lazy‑load the HTML‑DOM helper once for all calls
        require_once dirname(__DIR__, 2) . '/simple_html_dom.php';

        $this->logger     = $logger;
        $this->parser     = $parser;
        $this->repository = $repository;
    }

    /**
     * Scrape & import one batch.
     *
     * @param int         $offset      Zero‑based cursor in link‑array
     * @param int         $limit       Max number of cars to process
     * @param array       $options     [ 'skip_existing' => bool ]
     * @param string|null $sessionId   Unique key shared across consecutive batches
     *
     * @return array{results:array<array<string,mixed>>,has_more:bool,next_offset:int,total:int,session_id:string,all_ids?:array<int>}
     */
    public function updateCarListingBatch(int $offset, int $limit, array $options = [], ?string $sessionId = null): array
    {
        // 1) ------- Resolve config -------
        $baseUrl           = rtrim((string) get_option('bp_get_cars_baseurl', 'https://www.bytbil.com'), '/');
        $storePath         = (string) get_option('bp_get_cars_store', '/handlare/ekenbil-ab-9951');
        $selectorCarLinks  = (string) get_option('bp_get_cars_selector_car_links', 'ul.result-list li .uk-width-1-1 .car-list-header a');
        $selectorPagination = (string) get_option('bp_get_cars_selector_pagination', 'div.pagination-container a.pagination-page');
        $retryCount        = (int) get_option('bp_get_cars_retry_count', self::DEFAULT_RETRY_COUNT);
        $retryDelay        = (int) get_option('bp_get_cars_retry_delay', self::DEFAULT_RETRY_DELAY);
        $skipExisting      = $options['skip_existing'] ?? false;

        // 2) ------- Session & caching -------
        $sessionId   ??= uniqid('bp_cars_', true);
        $transientKey       = 'bp_get_cars_links_' . $sessionId;
        $linkList           = get_transient($transientKey);

        if ($linkList === false) {
            $this->logger->logError("[Scraper] Cache miss – scraping dealer pages for session $sessionId");
            $linkList = $this->scrapeAllCarLinks("{$baseUrl}{$storePath}", $selectorPagination, $selectorCarLinks, $retryCount, $retryDelay);
            if (isset($linkList['error'])) {
                return $linkList; // early‑return on fatal scrape error
            }
            set_transient($transientKey, $linkList, self::LINKS_CACHE_TTL);
        }

        // 3) ------- Slice current batch -------
        $batchLinks = array_slice($linkList, $offset, $limit);
        $this->logger->logError("[Scraper] Batch offset={$offset} limit={$limit} (" . count($batchLinks) . ' links)');

        $results           = [];
        $processedPostIds  = [];

        // 4) ------- Process each car page -------
        foreach ($batchLinks as $relUrl) {
            $guid = $relUrl; // relative path is our GUID/uid
            $this->logger->logError("[Scraper] Handling $guid");

            if ($skipExisting && ($dupId = $this->repository->isDuplicate($guid))) {
                $results[] = ['guid' => $guid, 'status' => 'skipped_existing', 'id' => $dupId];
                $processedPostIds[] = $dupId;
                continue;
            }

            $html = $this->fetchWithRetry($baseUrl . $relUrl, $retryCount, $retryDelay);
            if ($html === null) {
                $results[] = ['guid' => $guid, 'status' => 'error', 'reason' => 'fetch_failed'];
                continue;
            }

            $car = $this->parser->parseCarPage($html, $guid, $this->fieldMap);
            $postId = $this->repository->createOrUpdateListing($car);

            if ($postId) {
                $results[] = ['guid' => $guid, 'status' => 'success', 'id' => $postId];
                $processedPostIds[] = $postId;
            } else {
                $results[] = ['guid' => $guid, 'status' => 'error'];
            }
        }

        // 5) ------- Assemble response -------
        $nextOffset = $offset + $limit;
        $hasMore    = $nextOffset < count($linkList);

        // Clean up when finished
        if (! $hasMore) {
            delete_transient($transientKey);
        }

        return [
            'results'     => $results,
            'has_more'    => $hasMore,
            'next_offset' => $nextOffset,
            'total'       => count($linkList),
            'session_id'  => $sessionId,
            'all_ids'     => $processedPostIds,
        ];
    }

    // ---------------------------------------------------------------------
    // Private helpers
    // ---------------------------------------------------------------------

    /**
     * Fetch URL with retry/back‑off. Returns null on failure.
     */
    private function fetchWithRetry(string $url, int $maxAttempts, int $delay): ?simple_html_dom
    {
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $html = @file_get_html($url);
            if ($html) {
                return $html;
            }
            $this->logger->logError("[Scraper] Attempt $attempt failed for $url");
            sleep($delay);
        }
        $this->logger->logError("[Scraper] Permanent failure for $url after $maxAttempts attempts");
        return null;
    }

    /**
     * Scrape *all* car links for a dealer, following pagination.
     * @return array<string>|array{error:string}
     */
    private function scrapeAllCarLinks(string $dealerUrl, string $paginationSelector, string $linkSelector, int $retries, int $delay): array
    {
        $firstPage = $this->fetchWithRetry($dealerUrl, $retries, $delay);
        if ($firstPage === null) {
            return ['error' => 'Could not fetch dealer page'];
        }

        $pagesTotal = max(1, count($firstPage->find($paginationSelector)));
        $links      = [];

        for ($page = 1; $page <= $pagesTotal; $page++) {
            $pageUrl = $page === 1 ? $dealerUrl : $dealerUrl . '?Page=' . $page;
            $html    = $page === 1 ? $firstPage : $this->fetchWithRetry($pageUrl, $retries, $delay);
            if ($html === null) {
                $this->logger->logError("[Scraper] Skipping unreachable page $pageUrl");
                continue;
            }
            foreach ($html->find($linkSelector) as $a) {
                $links[] = $a->href;
            }
        }
        $this->logger->logError('[Scraper] Collected ' . count($links) . ' car links');
        return $links;
    }
}
