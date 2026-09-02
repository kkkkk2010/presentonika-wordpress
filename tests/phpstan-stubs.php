<?php

/**
 * Static-analysis-only declarations supplied by WP-CLI at runtime.
 */
class WP_CLI {
    public static function add_command(string $name, callable $callback): void {}
    public static function warning(string $message): void {}
    public static function log(string $message): void {}
    public static function error(string $message): void {}
    public static function success(string $message): void {}
}
