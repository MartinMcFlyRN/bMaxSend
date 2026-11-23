#!/usr/bin/env php
<?php
/**
 * Housekeeping (daily cron): old login attempts, old tracking events and audit log.
 *   php bin/cleanup.php [--events-days=365] [--audit-days=730]
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\DB;

$o = getopt('', ['events-days::', 'audit-days::']);
$ev = max(30, (int) ($o['events-days'] ?? 365));
$au = max(30, (int) ($o['audit-days'] ?? 730));
$a = DB::query('DELETE FROM login_attempts WHERE created_at < UTC_TIMESTAMP() - INTERVAL 1 DAY')->rowCount();
$b = DB::query('DELETE FROM tracking_events WHERE created_at < UTC_TIMESTAMP() - INTERVAL ? DAY', [$ev])->rowCount();
$c = DB::query('DELETE FROM audit_log WHERE created_at < UTC_TIMESTAMP() - INTERVAL ? DAY', [$au])->rowCount();
echo "login_attempts: $a, tracking_events: $b, audit_log: $c deleted\n";
