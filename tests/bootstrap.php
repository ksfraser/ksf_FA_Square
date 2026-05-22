<?php
declare(strict_types=1);

// Composer autoloader (famock defines FA function stubs)
$autoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoload)) {
    fprintf(STDERR, "Composer autoloader not found. Run 'composer install' first.\n");
    exit(1);
}

require_once $autoload;
