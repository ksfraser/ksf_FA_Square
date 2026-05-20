<?php
declare(strict_types=1);

// Load FA stubs for testing
require_once __DIR__ . '/stubs/FaDatabaseStubs.php';
require_once __DIR__ . '/stubs/FaConfigStubs.php';
require_once __DIR__ . '/stubs/FaUiStubs.php';

// Load Composer autoloader
$autoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoload)) {
    fprintf(STDERR, "Composer autoloader not found. Run 'composer install' first.\n");
    exit(1);
}

require_once $autoload;
