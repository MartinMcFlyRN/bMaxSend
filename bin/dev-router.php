<?php
// Router for the PHP built-in server (development only):
//   php -S 127.0.0.1:8080 -t public bin/dev-router.php
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$file = dirname(__DIR__) . '/public' . $path;
if ($path !== '/' && is_file($file) && !str_ends_with($file, '.php')) {
    return false;
}
require dirname(__DIR__) . '/public/index.php';
