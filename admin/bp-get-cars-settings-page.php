<?php
// Settings page for Ekenbil Car Listing plugin
if (!defined('ABSPATH')) exit;

function ekenbil_car_listing_settings_page($main)
{
    // Use public getter for logger
    $logger = method_exists($main, 'get_logger') ? $main->get_logger() : null;
    if (isset($_POST['bp_get_cars_clear_log']) && current_user_can('manage_options') && $logger) {
        $logger->clearLog();
        // Use JS redirect for compatibility with WordPress admin
        echo '<script>window.location = window.location.href.replace(/([&?])bp_get_cars_clear_log=1(&|$)/, "$1");</script>';
        exit;
    }
    $log_content = '';
    $error_log_file = method_exists($main, 'get_error_log_file') ? $main->get_error_log_file() : null;
    if ($error_log_file && file_exists($error_log_file)) {
        $log_content = file_get_contents($error_log_file);
    }
    $selector_car_links = get_option('bp_get_cars_selector_car_links', 'ul.result-list li .uk-width-1-1 .car-list-header a');
    $selector_pagination = get_option('bp_get_cars_selector_pagination', 'div.pagination-container a.pagination-page');
    $debug_mode = get_option('bp_get_cars_debug_mode', false);
    $batch_size = get_option('bp_get_cars_batch_size', 5);
?>

    <div class="wrap">
        <h1><?php esc_html_e('Car Listing Settings', 'bp-get-cars'); ?></h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('bp_get_cars_settings_group');
            do_settings_sections('bp_get_cars_settings_group');
            ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="bp_get_cars_baseurl"><?php esc_html_e('Base URL', 'bp-get-cars'); ?></label>
                    </th>
                    <td><input name="bp_get_cars_baseurl" type="url" id="bp_get_cars_baseurl"
                            value="<?php echo esc_attr(get_option('bp_get_cars_baseurl', 'https://www.bytbil.com')); ?>"
                            class="regular-text" required></td>
                </tr>
                <tr>
                    <th scope="row"><label for="bp_get_cars_store"><?php esc_html_e('Store Path', 'bp-get-cars'); ?></label>
                    </th>
                    <td><input name="bp_get_cars_store" type="text" id="bp_get_cars_store"
                            value="<?php echo esc_attr(get_option('bp_get_cars_store', '/handlare/ekenbil-ab-9951')); ?>"
                            class="regular-text" required></td>
                </tr>
                <tr>
                    <th scope="row"><label
                            for="bp_get_cars_selector_car_links"><?php esc_html_e('Car Links Selector', 'bp-get-cars'); ?></label>
                    </th>
                    <td><input name="bp_get_cars_selector_car_links" type="text" id="bp_get_cars_selector_car_links"
                            value="<?php echo esc_attr($selector_car_links); ?>" class="regular-text" required></td>
                </tr>
                <tr>
                    <th scope="row"><label
                            for="bp_get_cars_selector_pagination"><?php esc_html_e('Pagination Selector', 'bp-get-cars'); ?></label>
                    </th>
                    <td><input name="bp_get_cars_selector_pagination" type="text" id="bp_get_cars_selector_pagination"
                            value="<?php echo esc_attr($selector_pagination); ?>" class="regular-text" required></td>
                </tr>
                <tr>
                    <th scope="row"><label
                            for="bp_get_cars_debug_mode"><?php esc_html_e('Enable Debug Mode', 'bp-get-cars'); ?></label>
                    </th>
                    <td><input name="bp_get_cars_debug_mode" type="checkbox" id="bp_get_cars_debug_mode" value="1"
                            <?php checked($debug_mode, true); ?> />
                        <span
                            class="description"><?php esc_html_e('Log server response headers and selector elements for troubleshooting.', 'bp-get-cars'); ?></span>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label
                            for="bp_get_cars_batch_size"><?php esc_html_e('Batch Size', 'bp-get-cars'); ?></label></th>
                    <td><input name="bp_get_cars_batch_size" type="number" id="bp_get_cars_batch_size" min="1" max="100"
                            value="<?php echo esc_attr($batch_size); ?>" class="small-text" required />
                        <span
                            class="description"><?php esc_html_e('Number of cars to process per batch (AJAX update).', 'bp-get-cars'); ?></span>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label
                            for="bp_get_cars_retry_count"><?php esc_html_e('Retry Count for Failed Car Pages', 'bp-get-cars'); ?></label>
                    </th>
                    <td><input name="bp_get_cars_retry_count" type="number" id="bp_get_cars_retry_count" min="0" max="10"
                            value="<?php echo esc_attr(get_option('bp_get_cars_retry_count', 2)); ?>" class="small-text"
                            required />
                        <span
                            class="description"><?php esc_html_e('Number of times to retry fetching a car page before skipping (0 = no retry).', 'bp-get-cars'); ?></span>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label
                            for="bp_get_cars_retry_delay"><?php esc_html_e('Delay Between Retries (seconds)', 'bp-get-cars'); ?></label>
                    </th>
                    <td><input name="bp_get_cars_retry_delay" type="number" id="bp_get_cars_retry_delay" min="0" max="30"
                            value="<?php echo esc_attr(get_option('bp_get_cars_retry_delay', 1)); ?>" class="small-text"
                            required />
                        <span
                            class="description"><?php esc_html_e('Seconds to wait between retry attempts for failed car pages.', 'bp-get-cars'); ?></span>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
        <h2><?php esc_html_e('Plugin Debug Log', 'bp-get-cars'); ?></h2>
        <div
            style="max-height:300px;overflow:auto;background:#fff;border:1px solid #ccc;padding:10px;font-family:monospace;white-space:pre-wrap;">
            <?php echo esc_html($log_content ? $log_content : __('No errors logged.', 'bp-get-cars')); ?></div>
        <form method="post" style="margin-top:10px;">
            <input type="hidden" name="bp_get_cars_clear_log" value="1" />
            <?php submit_button(__('Clear Log', 'bp-get-cars'), 'secondary', 'submit', false); ?>
        </form>
    </div>
<?php
}
