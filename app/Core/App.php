<?php

declare(strict_types=1);

namespace App\Core;

final class App
{
    public static array $config = [];

    public static function boot(): void
    {
        $file = BASE_PATH . '/config/config.php';
        if (!is_file($file)) {
            throw new \RuntimeException('Missing config/config.php: copy config/config.example.php and run bin/install.php');
        }
        self::$config = require $file;
        if (!isset(self::$config['base_path'])) {
            self::$config['base_path'] = rtrim((string) parse_url((string) (self::$config['app_url'] ?? ''), PHP_URL_PATH), '/');
        }
        date_default_timezone_set('UTC');
        mb_internal_encoding('UTF-8');

        if (!empty(self::$config['debug'])) {
            ini_set('display_errors', '1');
            error_reporting(E_ALL);
        } else {
            ini_set('display_errors', '0');
        }
        ini_set('log_errors', '1');
        ini_set('error_log', BASE_PATH . '/storage/logs/php-error.log');
    }

    /** Host name of the admin panel (from app_url). */
    public static function panelHost(): string
    {
        return strtolower((string) parse_url((string) config('app_url'), PHP_URL_HOST));
    }

    public static function log(string $channel, string $message): void
    {
        $line = sprintf("[%s] %s: %s\n", gmdate('Y-m-d H:i:s'), $channel, $message);
        @file_put_contents(BASE_PATH . '/storage/logs/' . preg_replace('/[^a-z0-9_-]/i', '', $channel) . '.log', $line, FILE_APPEND | LOCK_EX);
    }
}
