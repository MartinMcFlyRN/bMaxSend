#!/usr/bin/env php
<?php
/**
 * Delivery worker. Run it from cron every minute:
 *   * * * * * www-data php /var/www/bmaxsend/bin/worker.php >> /var/www/bmaxsend/storage/logs/cron.log 2>&1
 *
 * Options: -v  verbose output
 *          --once  a single round (useful for tests)
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
require dirname(__DIR__) . '/app/bootstrap.php';

$lock = fopen(BASE_PATH . '/storage/worker.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0); // another worker is running
}

$opts = getopt('v', ['once']);
$verbose = isset($opts['v']);
$runtime = isset($opts['once']) ? 5 : (int) config('worker.max_runtime', 55);
$out = $verbose ? fn(string $m) => print('[' . gmdate('H:i:s') . "] $m\n") : null;

try {
    (new App\Services\Sender($runtime, $out))->run();
} catch (Throwable $e) {
    App\Core\App::log('worker', 'FATAL ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
} finally {
    flock($lock, LOCK_UN);
}
