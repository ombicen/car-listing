<?php
// Helper functions for BP_Get_Cars plugin
if (!function_exists('bp_get_cars_helpers_loaded')) {
    function bp_get_cars_clean_number($number) {
        $number = html_entity_decode($number);
        $number = str_replace(['\u00a0', ' '], '', $number);
        return $number;
    }
    function bp_get_cars_clean_text($text) {
        $text = html_entity_decode($text);
        $text = preg_replace('/\s+/', ' ', str_replace('\u00a0', ' ', $text));
        return trim($text);
    }
    define('bp_get_cars_helpers_loaded', true);
}
