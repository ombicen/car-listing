<?php

declare(strict_types=1);

namespace Ekenbil\CarListing\Scraper;

interface ScraperInterface
{
    /**
     * Update car listing in batches.
     * @param int $offset
     * @param int $limit
     * @param array $options
     * @param string|null $sessionId
     * @return array
     */
    public function updateCarListingBatch(int $offset, int $limit, array $options = [], ?string $sessionId = null): array;
}
