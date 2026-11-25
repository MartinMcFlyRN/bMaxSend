#!/usr/bin/env php
<?php
/**
 * bMaxSend installer.
 *
 *   php bin/install.php                         interactive
 *   php bin/install.php --email=a@b.it --password='...' --name='Admin'
 *
 * - generates app_key in config/config.php if empty
 * - creates the database tables (database/schema.sql)
 * - creates the first administrator
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
define('BASE_PATH', dirname(__DIR__));
$cfgFile = BASE_PATH . '/config/config.php';
if (!is_file($cfgFile)) {
    copy(BASE_PATH . '/config/config.example.php', $cfgFile);
    echo "Created config/config.php from the example: edit the database settings and run the installer again.\n";
    exit(1);
}
require BASE_PATH . '/vendor/autoload.php';

// 1. app key
$cfgText = file_get_contents($cfgFile);
$cfg = require $cfgFile;
if (empty($cfg['app_key'])) {
    $key = App\Core\Crypto::generateKey();
    $cfgText = preg_replace("/'app_key'\s*=>\s*''/", "'app_key' => '" . $key . "'", $cfgText, 1, $n);
    if ($n !== 1) {
        exit("Could not write app_key to config/config.php: set it manually to $key\n");
    }
    file_put_contents($cfgFile, $cfgText);
    echo "Generated app_key.\n";
}

App\Core\App::boot();
use App\Core\DB;

// 2. schema
try {
    DB::pdo();
} catch (Throwable $e) {
    exit('Database connection failed: ' . $e->getMessage() . "\n");
}
$sql = file_get_contents(BASE_PATH . '/database/schema.sql');
$sql = preg_replace('/^--.*$/m', '', $sql);
foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
    DB::pdo()->exec($stmt);
}
App\Services\Migrator::run(fn(string $m) => print("$m\n"));
echo "Database schema OK.\n";

// 3. first admin
$opts = getopt('', ['email::', 'password::', 'name::']);
if ((int) DB::value("SELECT COUNT(*) FROM users WHERE role = 'admin'") === 0) {
    $email = $opts['email'] ?? readline('Admin email: ');
    $name = $opts['name'] ?? (readline('Admin name [Administrator]: ') ?: 'Administrator');
    $pass = $opts['password'] ?? null;
    if ($pass === null) {
        echo 'Admin password (min 10 chars): ';
        system('stty -echo');
        $pass = trim((string) fgets(STDIN));
        system('stty echo');
        echo "\n";
    }
    $email = mb_strtolower(trim((string) $email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen((string) $pass) < 10) {
        exit("Invalid email or password too short.\n");
    }
    DB::insert('users', [
        'role' => 'admin', 'name' => $name, 'email' => $email, 'password_hash' => password_hash((string) $pass, PASSWORD_DEFAULT),
        'status' => 'active', 'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
    ]);
    echo "Administrator $email created.\n";
} else {
    echo "An administrator already exists.\n";
}

@mkdir(BASE_PATH . '/storage/logs', 0775, true);
echo "\nDone. Add the worker to cron:\n  * * * * * www-data php " . BASE_PATH . "/bin/worker.php >> " . BASE_PATH . "/storage/logs/cron.log 2>&1\n";
