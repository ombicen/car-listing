<?php

declare(strict_types=1);

namespace Ekenbil\CarListing\Helpers;

class Helpers
{
    // PSR-12 compatible wrapper for legacy helpers
    public static function cleanNumber(string $number): string
    {
        return bp_get_cars_clean_number($number);
    }

    public static function cleanText(string $text): string
    {
        return bp_get_cars_clean_text($text);
    }

    public static function cleanNumberLegacy(string $number): string
    {
        $number = html_entity_decode($number);
        $number = str_replace(['\u00a0', ' '], '', $number);
        return $number;
    }

    public static function cleanTextLegacy(string $text): string
    {
        $text = html_entity_decode($text);
        $text = preg_replace('/\s+/', ' ', str_replace('\u00a0', ' ', $text));
        return trim($text);
    }
}
