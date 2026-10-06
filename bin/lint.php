<?php
declare(strict_types=1);

/**
 * Syntax-check every non-vendor PHP file in the module.
 *
 * PHPUnit only loads src/ and tests/, so pages/ and hooks.php are never parsed
 * by the suite. Two committed pages once carried stray HTML after end_page(),
 * which shipped to the live module and returned HTTP 500 while every unit test
 * still passed. This is the gate that catches that class of bug.
 *
 * Usage:
 *   php bin/lint.php
 *   composer lint
 *
 * Exit codes: 0 = all files parse, 1 = at least one parse error.
 */

$root = dirname(__DIR__);
$skipDirs = ['/vendor/', '/node_modules/', '/.git/', '/bin/'];

$iterator = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        static function (SplFileInfo $file) use ($skipDirs): bool {
            $path = $file->getPathname();
            foreach ($skipDirs as $skip) {
                if (strpos($path, $skip) !== false) {
                    return false;
                }
            }
            return true;
        }
    )
);

$checked = 0;
$failed = [];

foreach ($iterator as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
        continue;
    }

    $checked++;
    $output = [];
    $exitCode = 0;
    exec(
        sprintf('%s -l %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($file->getPathname())),
        $output,
        $exitCode
    );

    if ($exitCode !== 0) {
        $failed[$file->getPathname()] = $output;
    }
}

foreach ($failed as $path => $output) {
    fwrite(STDERR, sprintf("FAIL %s\n", $path));
    foreach ($output as $line) {
        fwrite(STDERR, sprintf("     %s\n", $line));
    }
}

// ---------------------------------------------------------------------------
// PSR-4 resolvability check.
//
// Parsing is not enough: a symbol can be valid PHP in a file whose name does
// not match the class it declares, and the autoloader will then never find it.
// Two such bugs shipped here -- BulkRefreshInterface declared inside
// PushContracts.php, and RefundServiceInterface lost entirely by the 54fbf55
// namespace migration -- both of which left whole classes unloadable at
// runtime while every test passed.
//
// Require the autoloader so the real autoloading path is exercised.
// ---------------------------------------------------------------------------
$autoload = $root . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;

    $srcRoot = $root . '/src';
    $namespace = 'ksfraser\\FrontAccounting\\Square\\';
    $unresolvable = [];

    $srcIterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcRoot, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($srcIterator as $file) {
        if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
            continue;
        }
        $relative = substr($file->getPathname(), strlen($srcRoot) + 1, -4);
        $symbol = $namespace . str_replace('/', '\\', $relative);

        // Staging adapters implement ImportStaging contracts owned by the
        // sibling ksf_FA_ImportStagingProcessing module. Their own classes are
        // resolvable; only the foreign interfaces are absent unless that module
        // is present. Skip the adapters rather than reporting a false failure.
        if (strpos($relative, 'Staging/') === 0) {
            continue;
        }

        if (!class_exists($symbol) && !interface_exists($symbol) && !trait_exists($symbol)) {
            $unresolvable[] = [$symbol, $file->getPathname()];
        }
    }

    foreach ($unresolvable as [$symbol, $path]) {
        fwrite(STDERR, sprintf("FAIL PSR-4: %s not autoloadable (declared in %s)\n", $symbol, $path));
    }

    if ($unresolvable) {
        fwrite(STDERR, sprintf("\n%d symbol(s) are not autoloadable.\n", count($unresolvable)));
        exit(1);
    }

    fwrite(STDOUT, sprintf("OK: src/ symbols resolve under PSR-4.\n"));
}

if ($failed) {
    fwrite(STDERR, sprintf("\n%d of %d file(s) failed to parse.\n", count($failed), $checked));
    exit(1);
}

fwrite(STDOUT, sprintf("OK: %d PHP file(s) parse cleanly.\n", $checked));
exit(0);