<?php

declare(strict_types=1);

namespace Ekenbil\CarListing;

use Ekenbil\CarListing\Logger\Logger;
use Ekenbil\CarListing\Scraper\Scraper;
use Ekenbil\CarListing\Parser\CarParser;
use Ekenbil\CarListing\Persistence\CarRepository;
use Ekenbil\CarListing\Persistence\CarRepositoryInterface;
use Ekenbil\CarListing\Scraper\ScraperInterface;

/**
 * Plugin Name:       Ekenbil Car Listing
 * Plugin URI:        https://ekenbil.se/
 * Description:       Professional car inventory management and listing plugin for WordPress. Easily import, update, and display car listings from external sources with advanced scraping, error logging, and admin configuration.
 * Author:            Ekenbil AB
 * Version:           1.0.1
 * Author URI:        https://ekenbil.se/
 * Text Domain:       bp-get-cars
 */

if (! defined('BP_GET_CARS_POST_TYPE')) {
    define('BP_GET_CARS_POST_TYPE', 'vehica_car');
}

require_once __DIR__ . '/autoload.php';

final class Plugin
{
    /** @var Logger */
    private Logger $logger;

    /** @var ScraperInterface */
    private ScraperInterface $scraper;

    /** @var CarRepositoryInterface */
    private CarRepositoryInterface $repository;

    /** @var self|null */
    private static ?self $instance = null;

    /** Plugin text domain */
    private const TEXT_DOMAIN = 'bp-get-cars';

    /**
     * Singleton instance getter.
     * @return self
     */
    public static function get_instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Plugin constructor.
     * Private to enforce singleton.
     */
    private function __construct()
    {
        $this->logger = new Logger(plugin_dir_path(__FILE__) . 'bp-get-cars-error.log');
        $this->repository = new CarRepository($this->logger);
        $this->scraper = new Scraper($this->logger, new CarParser(), $this->repository);

        // Hooks
        register_activation_hook(__FILE__, [$this, 'schedule_daily_update']);
        register_deactivation_hook(__FILE__, [$this, 'clear_daily_update']);
        add_action('bp_get_cars_update_event', [$this, 'run_cron_batch_update']);

        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_notices', [$this, 'update_notice']);
        add_action('admin_enqueue_scripts', [$this, 'admin_enqueue_scripts']);
        add_action('wp_ajax_bp_get_cars_update', [$this, 'ajax_update_car_listing']);
        add_action('wp_ajax_bp_get_cars_update_batch', [$this, 'ajax_update_car_listing']);
        add_filter('cron_schedules', [$this, 'add_cron_interval']);
    }

    /**
     * For PSR-4 compatibility prevent cloning or unserializing.
     */
    private function __clone() {}
    public function __wakeup() {}

    /*** ====== SCHEDULING ====== ***/
    public function schedule_daily_update(): void
    {
        if (! wp_next_scheduled('bp_get_cars_update_event')) {
            wp_schedule_event(time(), 'every_2_hours', 'bp_get_cars_update_event');
        }
    }

    public function add_cron_interval(array $schedules): array
    {
        $schedules['every_2_hours'] = [
            'interval' => 2 * HOUR_IN_SECONDS,
            'display'  => esc_html__('Every 2 Hours', self::TEXT_DOMAIN)
        ];
        return $schedules;
    }

    public function clear_daily_update(): void
    {
        wp_clear_scheduled_hook('bp_get_cars_update_event');
    }

    /*** ====== ADMIN UI ====== ***/
    public function admin_menu(): void
    {
        add_submenu_page(
            'edit.php?post_type=' . BP_GET_CARS_POST_TYPE,
            esc_html__('Bil Listan Api', self::TEXT_DOMAIN),
            esc_html__('Uppdatera lista', self::TEXT_DOMAIN),
            'edit_posts',
            'carlisting-api',
            [$this, 'update_car_listing_page']
        );
        add_submenu_page(
            'edit.php?post_type=' . BP_GET_CARS_POST_TYPE,
            esc_html__('Car Listing Settings', self::TEXT_DOMAIN),
            esc_html__('Settings', self::TEXT_DOMAIN),
            'manage_options',
            'carlisting-settings',
            [$this, 'settings_page']
        );
    }

    public function register_settings(): void
    {
        register_setting('bp_get_cars_settings_group', 'bp_get_cars_baseurl', [
            'type' => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default' => 'https://www.bytbil.com',
        ]);
        register_setting('bp_get_cars_settings_group', 'bp_get_cars_store', [
            'type' => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default' => '/handlare/ekenbil-ab-9951',
        ]);
        register_setting('bp_get_cars_settings_group', 'bp_get_cars_selector_car_links', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'ul.result-list li .uk-width-1-1 .car-list-header a',
        ]);
        register_setting('bp_get_cars_settings_group', 'bp_get_cars_selector_pagination', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'div.pagination-container a.pagination-page',
        ]);
        register_setting('bp_get_cars_settings_group', 'bp_get_cars_debug_mode', [
            'type' => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default' => false,
        ]);
        register_setting('bp_get_cars_settings_group', 'bp_get_cars_batch_size', [
            'type' => 'integer',
            'sanitize_callback' => 'absint',
            'default' => 5,
        ]);
        register_setting('bp_get_cars_settings_group', 'bp_get_cars_retry_count', [
            'type' => 'integer',
            'sanitize_callback' => 'absint',
            'default' => 2,
        ]);
        register_setting('bp_get_cars_settings_group', 'bp_get_cars_retry_delay', [
            'type' => 'integer',
            'sanitize_callback' => 'absint',
            'default' => 1,
        ]);
    }

    public function settings_page(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions.', self::TEXT_DOMAIN));
        }
        require_once(plugin_dir_path(__FILE__) . 'admin/bp-get-cars-settings-page.php');
        ekenbil_car_listing_settings_page($this);
    }

    public function update_notice(): void
    {
        global $pagenow, $post_type;
        if ($pagenow === 'edit.php' && $post_type === BP_GET_CARS_POST_TYPE && current_user_can('edit_posts')) {
            echo '<div id="bp-get-cars-notice-box" class="notice notice-info" style="padding-bottom:15px;">
                <div id="bp-get-cars-update-status" style="margin-top:10px;"></div>
            </div>';
        }
    }

    public function update_car_listing_page(): void
    {
        if (! current_user_can('edit_posts')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', self::TEXT_DOMAIN));
        }
        $batch_size = (int) get_option('bp_get_cars_batch_size', 5);
        echo '<div class="wrap"><h1>' . esc_html__('Update Car List', self::TEXT_DOMAIN) . '</h1>';
        echo '<button id="bp-get-cars-update-btn" class="button button-primary">' . esc_html__('Start Update', self::TEXT_DOMAIN) . '</button>';
        echo '<input type="hidden" id="bp_get_cars_batch_size" value="' . esc_attr((string)$batch_size) . '" />';
        echo '<div id="bp-get-cars-update-status"></div>';
    }

    /*** ====== CORE CRUD & BATCH LOGIC ====== ***/
    public function clean_outdated(array $uuids): string
    {
        if (empty($uuids)) {
            return esc_html__('No outdated posts to remove.', self::TEXT_DOMAIN);
        }
        $args = [
            'post_type'   => BP_GET_CARS_POST_TYPE,
            'numberposts' => -1,
            'fields'      => 'ids',
            'exclude'     => $uuids
        ];
        $posts_to_delete = get_posts($args);
        if (! empty($posts_to_delete)) {
            foreach ($posts_to_delete as $post_id) {
                $this->logger->log_error('Removing post with ID: ' . $post_id);
                wp_delete_post($post_id, true);
            }
            $this->logger->log_error('Removed ' . count($posts_to_delete) . ' outdated posts.');
            return count($posts_to_delete) . esc_html__(' outdated posts have been removed.', self::TEXT_DOMAIN);
        } else {
            $this->logger->log_error('No outdated posts to remove.');
            return esc_html__('No outdated posts to remove.', self::TEXT_DOMAIN);
        }
    }

    public function is_duplicate(string $guid)
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

    public function success_message(string $title): string
    {
        return '<div id="message" class="updated notice is-dismissible"><p>' . esc_html($title) . ' ' . esc_html__('has been added.', self::TEXT_DOMAIN) . '</p><button type="button" class="notice-dismiss"><span class="screen-reader-text">' . esc_html__('Dismiss this notice.', self::TEXT_DOMAIN) . '</span></button></div>';
    }

    public function error_message(string $title): string
    {
        return '<div id="message" class="updated notice notice-error is-dismissible"><p>' . esc_html($title) . ' ' . esc_html__('could not be added.', self::TEXT_DOMAIN) . '</p><button type="button" class="notice-dismiss"><span class="screen-reader-text">' . esc_html__('Dismiss this notice.', self::TEXT_DOMAIN) . '</span></button></div>';
    }

    /**
     * @param object $car
     * @return int|false
     */
    public function create_listing(object $car)
    {
        $author_id = get_current_user_id();
        if (!$author_id) {
            $author_id = (int) get_option('bp_get_cars_default_author', 1);
        }
        $postarg = [
            'post_type'   => BP_GET_CARS_POST_TYPE,
            'post_title'  => sanitize_text_field($car->title),
            'post_status' => 'publish',
            'post_author' => $author_id,
            'guid'        => $car->guid,
            'meta_input'  => [
                'uid'                       => sanitize_text_field($car->guid),
                'vehica_carfax'             => esc_url($car->carfax),
                'vehica_currency_6656_2316' => sanitize_text_field($car->price)
            ]
        ];
        $postid = wp_insert_post($postarg);
        if (is_wp_error($postid)) {
            $this->logger->log_error('Error creating car listing: ' . $postid->get_error_message());
            return false;
        }
        $this->update_details((int)$postid, $car->details ?? []);
        $result = wp_set_object_terms($postid, $car->features ?? [], 'vehica_6670');
        if (is_wp_error($result)) {
            $this->logger->log_error('Error assigning features: ' . $result->get_error_message());
        }
        $result = wp_set_object_terms($postid, 'Begagnad', 'vehica_6654');
        if (is_wp_error($result)) {
            $this->logger->log_error('Error assigning status: ' . $result->get_error_message());
        }
        if (! empty($car->additional['Färg'])) {
            $result = wp_set_object_terms($postid, sanitize_text_field($car->additional['Färg']), 'vehica_6666');
            if (is_wp_error($result)) {
                $this->logger->log_error('Error assigning color: ' . $result->get_error_message());
            }
        }
        $gids = $this->upload_images($car->images ?? [], $postid, $car->title);
        if (! empty($gids) && count($gids) > 1) {
            update_post_meta($postid, 'vehica_6673', implode(',', $gids));
        }
        return $postid;
    }

    /**
     * @param int $postid
     * @param array $details
     */
    public function update_details(int $postid, array $details): void
    {
        foreach ($details as $key => $value) {
            if ($value[1] === 'taxonomy') {
                wp_set_object_terms($postid, [$value[0]], $key);
            } else {
                update_post_meta($postid, sanitize_key($key), sanitize_text_field($value[0]));
            }
        }
    }

    /**
     * @param array $images
     * @param int $postid
     * @param string $title
     * @return array Attachment IDs
     */
    public function upload_images(array $images, int $postid, string $title): array
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
                $this->logger->log_error("Error downloading image $img: " . $tmp->get_error_message());
                continue;
            }
            $filename = sanitize_title($title) . '-' . $i . '.jpg';
            $attachment = [
                'name'     => $filename,
                'tmp_name' => $tmp
            ];
            $attachid = media_handle_sideload($attachment, $postid, $title);
            // Delete the temporary file after use
            if (file_exists($tmp) && ! unlink($tmp)) {
                $this->logger->log_error('Could not remove temp image file: ' . $tmp);
            }
            if (is_wp_error($attachid)) {
                $this->logger->log_error("Error uploading image $filename: " . $attachid->get_error_message());
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

    public function clean_number($number)
    {
        return \Ekenbil\CarListing\Helpers\Helpers::cleanNumber((string)$number);
    }

    public function clean_text($text)
    {
        return \Ekenbil\CarListing\Helpers\Helpers::cleanText((string)$text);
    }

    public function admin_enqueue_scripts(string $hook): void
    {
        $is_edit = $hook === 'edit.php' && isset($_GET['post_type']) && $_GET['post_type'] === BP_GET_CARS_POST_TYPE;
        $is_batch = $hook === 'vehica_car_page_carlisting-api';
        if ($is_edit || $is_batch) {
            wp_enqueue_script('bp-get-cars-admin', plugin_dir_url(__FILE__) . 'assets/js/bp-get-cars-admin.js', ['jquery'], null, true);
            wp_localize_script('bp-get-cars-admin', 'BPGetCarsAjax', [
                'ajax_url'   => admin_url('admin-ajax.php'),
                'nonce'      => wp_create_nonce('bp_get_cars_update_nonce'),
                'batch_size' => get_option('bp_get_cars_batch_size', 5)
            ]);
            wp_enqueue_style('bp-get-cars-style', plugin_dir_url(__FILE__) . 'assets/css/style.css', [], null);
        }
    }

    /**
     * Handle AJAX update for car listing.
     */
    public function ajax_update_car_listing(): void
    {
        $this->logger->log_error('Start updating car listing');
        // Custom error for nonce/permissions
        if (!isset($_POST['nonce']) || !check_ajax_referer('bp_get_cars_update_nonce', 'nonce', false)) {
            wp_send_json_error(['error' => esc_html__('Nonce verification failed.', self::TEXT_DOMAIN)]);
            wp_die();
        }
        $this->logger->log_error('Nonce verified');
        $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
        $limit = (int) get_option('bp_get_cars_batch_size', 5);
        $session_id = isset($_POST['session_id']) ? sanitize_text_field((string)$_POST['session_id']) : null;
        $this->logger->log_error('Processing batch update, session_id=' . ($session_id ?: 'none'));
        $result = $this->scraper->updateCarListingBatch($offset, $limit, [
            'skip_existing' => true
        ], $session_id);

        // If any item in the batch failed, treat as error
        if (isset($result['results']) && is_array($result['results'])) {
            foreach ($result['results'] as $item) {
                if (isset($item['status']) && $item['status'] === 'error') {
                    $this->logger->log_error('Error in batch item: ' . json_encode($item));
                    wp_send_json_error([
                        'error' => esc_html__('A car in the batch failed to import or update.', self::TEXT_DOMAIN),
                        'item'  => $item
                    ]);
                    wp_die();
                }
            }
        }
        if (isset($result['error'])) {
            $this->logger->log_error('Error during batch update: ' . $result['error']);
            wp_send_json_error(['error' => esc_html($result['error'])]);
            wp_die();
        } else {
            $this->logger->log_error('Batch update completed successfully');
        }

        // If this is the last batch and all_ids is present, clean outdated posts
        if (isset($result['all_ids']) && is_array($result['all_ids'])) {
            $this->logger->log_error('Cleaning outdated posts: ' . implode(', ', $result['all_ids']));
            $this->clean_outdated($result['all_ids']);
            $this->logger->log_error('Outdated posts cleaned');
        }
        wp_send_json_success($result);
    }

    public function run_cron_batch_update(): void
    {
        $this->logger->log_error('Start cron batch update');
        $offset = 0;
        $limit = (int) get_option('bp_get_cars_batch_size', 5);
        $session_id = uniqid('bp_cars_cron_', true);
        $all_ids = [];
        do {
            $result = $this->scraper->updateCarListingBatch($offset, $limit, ['skip_existing' => true], $session_id);
            if (isset($result['results'])) {
                foreach ($result['results'] as $item) {
                    if (!empty($item['id'])) {
                        $all_ids[] = $item['id'];
                    }
                }
            }
            $offset = isset($result['next_offset']) ? $result['next_offset'] : ($offset + $limit);
            // If this is the last batch and all_ids is present, use it for cleanup
            if (isset($result['all_ids']) && is_array($result['all_ids'])) {
                $all_ids = $result['all_ids'];
            }
        } while (isset($result['has_more']) && $result['has_more']);
        // Clean up outdated posts
        $this->clean_outdated($all_ids);
        $this->logger->log_error('Cron batch update finished');
    }

    /**
     * Return the error log file path for admin/settings UI.
     * @return string
     */
    public function get_error_log_file(): string
    {
        return $this->logger->getErrorLogFile();
    }

    /**
     * Public getter for logger for settings page and admin UI.
     * @return Logger
     */
    public function get_logger(): Logger
    {
        return $this->logger;
    }
}

// Initialize the plugin (after plugins_loaded for safe autoloading)
add_action('plugins_loaded', function () {
    Plugin::get_instance();
});
