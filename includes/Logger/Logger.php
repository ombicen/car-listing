<?php

declare(strict_types=1);

namespace Ekenbil\CarListing\Logger;

class Logger
{
    private string $errorLogFile;

    public function __construct(string $errorLogFile)
    {
        $this->errorLogFile = $errorLogFile;
    }

    public function logError(string $message): void
    {
        if (!get_option('bp_get_cars_debug_mode', false)) {
            return;
        }
        $date = date('Y-m-d H:i:s');
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        $caller = isset($backtrace[1]['function']) ? $backtrace[1]['function'] : 'unknown';
        $file = isset($backtrace[0]['file']) ? basename($backtrace[0]['file']) : '';
        $line = isset($backtrace[0]['line']) ? $backtrace[0]['line'] : '';
        $entry = "[$date] [$file:$line] [$caller] $message\n";
        file_put_contents($this->errorLogFile, $entry, FILE_APPEND | LOCK_EX);
    }

    // PSR-12 bridge: allow both log_error and logError
    public function log_error($message)
    {
        if (method_exists($this, 'logError')) {
            $this->logError($message);
        }
    }

    public function clearLog(): void
    {
        file_put_contents($this->errorLogFile, '');
    }
}
