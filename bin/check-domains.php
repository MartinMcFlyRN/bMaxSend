#!/usr/bin/env php
<?php
/**
 * Re-checks the DNS of the alias domains (pending ones and verified ones not
 * checked in the last 24 hours). Suggested cron: every hour.
 *   17 * * * * www-data php /var/www/bmaxsend/bin/check-domains.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\DB;
use App\Services\DomainService;

$rows = DB::all("SELECT * FROM domains WHERE status <> 'verified' OR last_check_at IS NULL OR last_check_at < UTC_TIMESTAMP() - INTERVAL 1 DAY");
foreach ($rows as $d) {
    if ($d['last_check_msg'] === 'Forced by administrator') {
        continue;
    }
    [$ok, $msg] = DomainService::runCheck($d);
    echo $d['hostname'] . ': ' . ($ok ? 'OK' : $msg) . "\n";
}
