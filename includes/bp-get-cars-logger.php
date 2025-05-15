<?php
// Logger logic for bp Get Cars plugin
class BP_Get_Cars_Logger
{
    public $error_log_file;
    public function __construct($error_log_file)
    {
        $this->error_log_file = $error_log_file;
    }
    public function log_error($message)
    {
        //if bp_get_cars_debug_mode isnt true, return
        if (!get_option('bp_get_cars_debug_mode', false)) {
            return;
        }
        $date = date('Y-m-d H:i:s');
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        $caller = isset($backtrace[1]['function']) ? $backtrace[1]['function'] : 'unknown';
        $file = isset($backtrace[0]['file']) ? basename($backtrace[0]['file']) : '';
        $line = isset($backtrace[0]['line']) ? $backtrace[0]['line'] : '';
        $entry = "[$date] [$file:$line] [$caller] $message\n";
        file_put_contents($this->error_log_file, $entry, FILE_APPEND | LOCK_EX);
    }

    public function clear_log()
    {
        file_put_contents($this->error_log_file, '');
    }
}
