<?php

declare(strict_types=1);

namespace Ekenbil\CarListing\Persistence;

use Ekenbil\CarListing\Logger\Logger;

class CarRepository implements CarRepositoryInterface
{
    private Logger $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    public function isDuplicate(string $guid)
    {
        $duplicate = new \WP_Query([
            'post_type'      => BP_GET_CARS_POST_TYPE,
            'meta_key'       => 'uid',
            'meta_value'     => sanitize_text_field($guid),
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => true
        ]);
        $post = end($duplicate->posts);
        return $duplicate->post_count > 0 ? $post : false;
    }

    public function createOrUpdateListing(object $car)
    {
        // This is a direct copy of the create_listing logic from Plugin
        $author_id = get_current_user_id();
        if (!$author_id) {
            $author_id = get_option('bp_get_cars_default_author', 1);
        }
        $postarg = [
            'post_type'   => BP_GET_CARS_POST_TYPE,
            'post_title'  => sanitize_text_field($car->title),
            'post_status' => 'publish',
            'post_author' => $author_id,
            'guid'        => $car->guid,
            'meta_input'  => [
                'uid'                      => sanitize_text_field($car->guid),
                'vehica_carfax'            => esc_url($car->carfax),
                'vehica_currency_6656_2316' => sanitize_text_field($car->price)
            ]
        ];
        $postid = wp_insert_post($postarg);
        if (is_wp_error($postid)) {
            $this->logger->logError('Error creating car listing: ' . $postid->get_error_message());
            return false;
        }
        // Defensive: ensure $car->details is always an array
        $details = is_array($car->details) ? $car->details : (array) $car->details;
        $this->updateDetails($postid, $details);
        $result = wp_set_object_terms($postid, $car->features, 'vehica_6670');
        if (is_wp_error($result)) {
            $this->logger->logError('Error assigning features: ' . $result->get_error_message());
        }
        $result = wp_set_object_terms($postid, 'Begagnad', 'vehica_6654');
        if (is_wp_error($result)) {
            $this->logger->logError('Error assigning status: ' . $result->get_error_message());
        }
        if (! empty($car->additional['Färg'])) {
            $result = wp_set_object_terms($postid, sanitize_text_field($car->additional['Färg']), 'vehica_6666');
            if (is_wp_error($result)) {
                $this->logger->logError('Error assigning color: ' . $result->get_error_message());
            }
        }
        $gids = $this->uploadImages($car->images, $postid, $car->title);
        if (! empty($gids) && count($gids) > 1) {
            update_post_meta($postid, 'vehica_6673', implode(',', $gids));
        }
        return $postid;
    }

    public function createListing(object $car)
    {
        // Alias to createOrUpdateListing for interface compatibility
        return $this->createOrUpdateListing($car);
    }

    public function updateDetails(int $postId, array $details): void
    {
        foreach ($details as $key => $value) {
            if ($value[1] === 'taxonomy') {
                wp_set_object_terms($postId, [$value[0]], $key);
            } else {
                update_post_meta($postId, sanitize_key($key), sanitize_text_field($value[0]));
            }
        }
    }

    public function cleanOutdated(array $uuids): string
    {
        // Implement logic to remove outdated posts not in $uuids
        // Placeholder: return empty string
        return '';
    }

    private function uploadImages($images, $postid, $title)
    {
        $gids = [];
        $i = 0;
        if (! function_exists('download_url')) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
        }
        if (! function_exists('media_handle_sideload')) {
            require_once(ABSPATH . 'wp-admin/includes/media.php');
        }
        if (! function_exists('wp_read_image_metadata')) {
            require_once(ABSPATH . 'wp-admin/includes/image.php');
        }
        foreach ($images as $img) {
            $tmp = download_url(esc_url($img));
            if (is_wp_error($tmp)) {
                $this->logger->logError("Error downloading image $img: " . $tmp->get_error_message());
                continue;
            }
            $filename = sanitize_title($title) . '-' . $i . '.jpg';
            $attachment = [
                'name'     => $filename,
                'tmp_name' => $tmp
            ];
            $attachid = media_handle_sideload($attachment, $postid, $title);
            if (file_exists($tmp) && ! unlink($tmp)) {
                $this->logger->logError('Could not remove temp image file: ' . $tmp);
            }
            if (is_wp_error($attachid)) {
                $this->logger->logError("Error uploading image $filename: " . $attachid->get_error_message());
                continue;
            }
            $gids[] = $attachid;
            if ($i === 0) {
                set_post_thumbnail($postid, $attachid);
            }
            $i++;
        }
        return $gids;
    }
}
