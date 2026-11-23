<?php

declare(strict_types=1);

use App\Core\App;
use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Lang;
use App\Core\Router;
use App\Core\Session;
use App\Core\View;
use App\Services\DomainService;

require dirname(__DIR__) . '/app/bootstrap.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

$host = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$base = rtrim((string) config('base_path', ''), '/');
if ($base !== '' && str_starts_with($path, $base)) {
    $path = substr($path, strlen($base)) ?: '/';
}
$path = $path !== '/' ? rtrim($path, '/') : '/';

// Requests arriving on an alias domain can only reach the public email endpoints.
$onAlias = $host !== '' && $host !== App::panelHost();
if ($onAlias && !DomainService::isActiveAlias($host) && !config('allow_any_host', false)) {
    http_response_code(404);
    exit('Unknown host');
}

$router = new Router();
require BASE_PATH . '/app/routes.php';

try {
    if (!$onAlias) {
        header('X-Frame-Options: SAMEORIGIN');
        Session::start();
        if ($u = Auth::user()) {
            Lang::setLocale($u['language'] ?: (string) config('default_language', 'en'));
        }
    }
    $router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $path, $onAlias);
} catch (HttpException $e) {
    http_response_code($e->status);
    $messages = [
        403 => __('You are not allowed to access this page.'),
        404 => __('Page not found.'),
        405 => __('Method not allowed.'),
        419 => __('Session expired, please reload the page and try again.'),
        429 => __('Too many requests.'),
    ];
    $msg = $e->getMessage() !== (string) $e->status ? $e->getMessage() : ($messages[$e->status] ?? __('Error'));
    echo View::render('public/error', ['status' => $e->status, 'message' => $msg, 'title' => (string) $e->status], 'layouts/public');
} catch (Throwable $e) {
    App::log('error', $e::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString());
    http_response_code(500);
    $msg = config('debug') ? $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() : __('An unexpected error occurred.');
    echo View::render('public/error', ['status' => 500, 'message' => $msg, 'title' => '500'], 'layouts/public');
}
