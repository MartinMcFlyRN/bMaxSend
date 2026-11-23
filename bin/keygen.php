#!/usr/bin/env php
<?php
// Prints a new app_key for config/config.php
require dirname(__DIR__) . '/vendor/autoload.php';
echo App\Core\Crypto::generateKey(), "\n";
