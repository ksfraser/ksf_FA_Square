<?php
declare(strict_types=1);

// Composer autoloader (famock defines FA function stubs)
$autoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoload)) {
    fprintf(STDERR, "Composer autoloader not found. Run 'composer install' first.\n");
    exit(1);
}

require_once $autoload;

// Load FA function stubs from famock package
$famockDir = __DIR__ . '/../vendor/ksfraser/famock/php';
if (is_dir($famockDir)) {
    require_once $famockDir . '/FaDbStubs.php';
    require_once $famockDir . '/FaBusinessStubs.php';
    require_once $famockDir . '/FaConstantStubs.php';
    require_once $famockDir . '/FaDateStubs.php';
    require_once $famockDir . '/FaSessionStubs.php';
    require_once $famockDir . '/FaSecurityStubs.php';
}
