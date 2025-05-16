<?php

declare(strict_types=1);

namespace Ekenbil\CarListing\Scraper;

use Ekenbil\CarListing\Logger\Logger;
use Ekenbil\CarListing\Parser\CarParser;
use Ekenbil\CarListing\Persistence\CarRepositoryInterface;
use simple_html_dom;

/**
 * Fetches car links from BytBil and imports them in configurable batches.
 * – Retries network calls with exponential back‑off
 * – Caches scraped link lists in WP‑transients to avoid re‑scraping during the same session
 * – Persists processed post‑IDs between batches; returns them *endast i sista batchen*.
 */
final class Scraper implements ScraperInterface
{
    private const LINKS_CACHE_TTL   = 1800; // 30 min
    private const IDS_CACHE_TTL     = 1800; // sync with links
    private const DEFAULT_RETRY_COUNT = 2;
    private const DEFAULT_RETRY_DELAY = 1; // seconds

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
        require_once dirname(__DIR__) . '/simple_html_dom.php'; // lazy load once
        $this->logger     = $logger;
        $this->parser     = $parser;
        $this->repository = $repository;
    }

    /**
     * Process a batch of listings and persist processed IDs between calls.
     * Returns `all_ids` *endast* när detta är sista batchen.
     *
     * @return array{results:array<array<string,mixed>>,has_more:bool,next_offset:int,total:int,session_id:string,all_ids?:array<int>}
     */
    public function updateCarListingBatch(int $offset, int $limit, array $options = [], ?string $sessionId = null): array
    {
        $this->logger->logError("[Scraper] updateCarListingBatch called: offset=$offset, limit=$limit, sessionId=" . ($sessionId ?? 'null'));
        // ---------- Configuration ----------
        $baseUrl            = rtrim((string) get_option('bp_get_cars_baseurl', 'https://www.bytbil.com'), '/');
        $storePath          = (string) get_option('bp_get_cars_store', '/handlare/ekenbil-ab-9951');
        $selCarLinks        = (string) get_option('bp_get_cars_selector_car_links', 'ul.result-list li .uk-width-1-1 .car-list-header a');
        $selPagination      = (string) get_option('bp_get_cars_selector_pagination', 'div.pagination-container a.pagination-page');
        $retryCount         = (int) get_option('bp_get_cars_retry_count', self::DEFAULT_RETRY_COUNT);
        $retryDelay         = (int) get_option('bp_get_cars_retry_delay', self::DEFAULT_RETRY_DELAY);
        $skipExisting       = $options['skip_existing'] ?? false;
        $this->logger->logError("[Scraper] Config: baseUrl=$baseUrl, storePath=$storePath, retryCount=$retryCount, retryDelay=$retryDelay, skipExisting=" . ($skipExisting ? 'true' : 'false'));

        // ---------- Session & caches ----------
        $sessionId ??= uniqid('bp_cars_', true);
        $linksKey = 'bp_get_cars_links_' . $sessionId;
        $idsKey   = 'bp_get_cars_ids_'   . $sessionId;

        $this->logger->logError("[Scraper] Using linksKey=$linksKey, idsKey=$idsKey");
        $linkList = get_transient($linksKey);
        if ($linkList === false) {
            $this->logger->logError("[Scraper] Cache miss – scraping dealer pages for session $sessionId");
            $linkList = $this->scrapeAllCarLinks("{$baseUrl}{$storePath}", $selPagination, $selCarLinks, $retryCount, $retryDelay);
            if (isset($linkList['error'])) {
                $this->logger->logError("[Scraper] scrapeAllCarLinks error: " . $linkList['error']);
                return $linkList; // early failure
            }
            set_transient($linksKey, $linkList, self::LINKS_CACHE_TTL);
            $this->logger->logError("[Scraper] Set new linksKey transient with " . count($linkList) . " links");
        }

        $processedIds = get_transient($idsKey);
        if ($processedIds === false) {
            $processedIds = [];
            $this->logger->logError("[Scraper] No processedIds found, initializing empty array");
        } else {
            $this->logger->logError("[Scraper] Loaded processedIds: " . json_encode($processedIds));
        }

        // ---------- Current batch ----------
        $batchLinks = array_slice($linkList, $offset, $limit);
        $this->logger->logError("[Scraper] Processing batch: offset=$offset, limit=$limit, batchLinks=" . json_encode($batchLinks));
        $results    = [];

        foreach ($batchLinks as $relUrl) {
            $guid = $relUrl;
            $this->logger->logError("[Scraper] Handling car: $guid");
            if ($skipExisting && ($dupId = $this->repository->isDuplicate($guid))) {
                $this->logger->logError("[Scraper] Skipped existing car: $guid (postId=$dupId)");
                $results[]    = ['guid' => $guid, 'status' => 'skipped_existing', 'id' => $dupId];
                $processedIds[] = $dupId;
                continue;
            }

            $html = $this->fetchWithRetry($baseUrl . $relUrl, $retryCount, $retryDelay);
            if ($html === null) {
                $this->logger->logError("[Scraper] Failed to fetch car page: $baseUrl$relUrl");
                $results[] = ['guid' => $guid, 'status' => 'error', 'reason' => 'fetch_failed'];
                continue;
            }

            $car    = $this->parser->parseCarPage($html, $guid, $this->fieldMap);
            $this->logger->logError("[Scraper] Parsed car: " . json_encode($car));
            $postId = $this->repository->createListing($car);

            if ($postId) {
                $this->logger->logError("[Scraper] Created/updated car: $guid (postId=$postId)");
                $results[]    = ['guid' => $guid, 'status' => 'success', 'id' => $postId];
                $processedIds[] = $postId;
            } else {
                $this->logger->logError("[Scraper] Failed to create/update car: $guid");
                $results[] = ['guid' => $guid, 'status' => 'error'];
            }
        }

        set_transient($idsKey, array_unique($processedIds), self::IDS_CACHE_TTL);
        $this->logger->logError("[Scraper] Updated processedIds: " . json_encode($processedIds));

        $nextOffset = $offset + $limit;
        $hasMore    = $nextOffset < count($linkList);

        $response = [
            'results'     => $results,
            'has_more'    => $hasMore,
            'next_offset' => $nextOffset,
            'total'       => count($linkList),
            'session_id'  => $sessionId,
        ];

        if (! $hasMore) {
            $this->logger->logError("[Scraper] Final batch complete, cleaning up transients and returning all_ids");
            delete_transient($linksKey);
            delete_transient($idsKey);
            $response['all_ids'] = $processedIds;
        }

        $this->logger->logError("[Scraper] Batch response: " . json_encode($response));
        return $response;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function fetchWithRetry(string $url, int $maxAttempts, int $delay): ?simple_html_dom
    {
        for ($i = 1; $i <= $maxAttempts; $i++) {
            $this->logger->logError("[Scraper] fetchWithRetry: Attempt $i for $url");
            $html = @file_get_html($url);
            if ($html) {
                $this->logger->logError("[Scraper] fetchWithRetry: Success on attempt $i for $url");
                return $html;
            }
            $this->logger->logError("[Scraper] fetchWithRetry: Attempt $i failed for $url");
            sleep($delay);
        }
        $this->logger->logError("[Scraper] fetchWithRetry: Permanent failure for $url after $maxAttempts attempts");
        return null;
    }

    /** @return array<string>|array{error:string} */
    private function scrapeAllCarLinks(string $dealerUrl, string $paginationSel, string $linkSel, int $retries, int $delay): array
    {
        $this->logger->logError("[Scraper] scrapeAllCarLinks: dealerUrl=$dealerUrl, paginationSel=$paginationSel, linkSel=$linkSel");
        $page1 = $this->fetchWithRetry($dealerUrl, $retries, $delay);
        if ($page1 === null) {
            $this->logger->logError("[Scraper] scrapeAllCarLinks: Could not fetch dealer page");
            return ['error' => 'Could not fetch dealer page'];
        }

        $pages = max(1, count($page1->find($paginationSel)));
        $this->logger->logError("[Scraper] scrapeAllCarLinks: Found $pages pages");
        $links = [];
        for ($p = 1; $p <= $pages; $p++) {
            $url  = $p === 1 ? $dealerUrl : $dealerUrl . '?Page=' . $p;
            $this->logger->logError("[Scraper] scrapeAllCarLinks: Fetching page $p ($url)");
            $html = $p === 1 ? $page1 : $this->fetchWithRetry($url, $retries, $delay);
            if (! $html) {
                $this->logger->logError("[Scraper] scrapeAllCarLinks: Skipping unreachable page $url");
                continue;
            }
            foreach ($html->find($linkSel) as $a) {
                $links[] = $a->href;
            }
            $this->logger->logError("[Scraper] scrapeAllCarLinks: Page $p collected " . count($links) . " links so far");
        }
        $this->logger->logError('[Scraper] scrapeAllCarLinks: Collected ' . count($links) . ' car links');
        return $links;
    }
}
