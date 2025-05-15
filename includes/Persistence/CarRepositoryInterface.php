<?php

declare(strict_types=1);

namespace Ekenbil\CarListing\Persistence;

interface CarRepositoryInterface
{
    /**
     * Check if a car with the given GUID exists.
     * @param string $guid
     * @return int|false Post ID if found, false otherwise
     */
    public function isDuplicate(string $guid);

    /**
     * Create a new car listing.
     * @param object $car
     * @return int|false Post ID if created, false otherwise
     */
    public function createListing(object $car);

    /**
     * Update details for a car post.
     * @param int $postId
     * @param array $details
     * @return void
     */
    public function updateDetails(int $postId, array $details): void;

    /**
     * Remove outdated posts not in the provided UUID list.
     * @param array $uuids
     * @return string
     */
    public function cleanOutdated(array $uuids): string;
}
