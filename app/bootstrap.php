<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

if (!is_file(BASE_PATH . '/vendor/autoload.php')) {
    http_response_code(500);
    exit("Dependencies missing: run `composer install --no-dev` in " . BASE_PATH . "\n");
}
require BASE_PATH . '/vendor/autoload.php';

App\Core\App::boot();
App\Core\Lang::setLocale((string) config('default_language', 'en'));
