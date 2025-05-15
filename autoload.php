<?php

declare(strict_types=1);

namespace Ekenbil\CarListing;

// Simple PSR-4 autoloader for plugin classes
spl_autoload_register(function ($class) {
    $prefix = 'Ekenbil\\CarListing\\';
    $base_dir = __DIR__ . '/includes/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
    if (file_exists($file)) {
        require $file;
    }
});
