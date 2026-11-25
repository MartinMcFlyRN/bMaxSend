#!/usr/bin/env php
<?php
/** Applies pending database migrations. Run it after every update: php bin/migrate.php */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
require dirname(__DIR__) . '/app/bootstrap.php';

$applied = App\Services\Migrator::run(fn(string $m) => print("$m\n"));
echo $applied ? 'Done: ' . implode(', ', $applied) . "\n" : "Database already up to date.\n";
