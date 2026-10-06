<?php
declare(strict_types=1);

// Composer autoloader (famock defines FA function stubs)
$autoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoload)) {
    fprintf(STDERR, "Composer autoloader not found. Run 'composer install' first.\n");
    exit(1);
}

require_once $autoload;

// FA-faithful db_escape() stub. Real FrontAccounting db_escape() returns the
// escaped value already wrapped in single quotes (e.g. db_escape('hi') -> 'hi').
// The famock stub only does addslashes(), so define ours first; famock's
// function_exists guard will then skip its own definition.
if (!function_exists('db_escape')) {
    function db_escape($value = "") {
        return "'" . addslashes((string)$value) . "'";
    }
}

// Load FA function stubs from famock package
$famockDir = __DIR__ . '/../vendor/ksfraser/famock/php';
if (is_dir($famockDir)) {
    require_once $famockDir . '/FaDbStubs.php';
    require_once $famockDir . '/FaBusinessStubs.php';
    require_once $famockDir . '/FaConstantStubs.php';
    require_once $famockDir . '/FaDateStubs.php';
    require_once $famockDir . '/FaSessionStubs.php';
    require_once $famockDir . '/FaSecurityStubs.php';
    // FaUIStubs provides _() (gettext). Without it, unqualified _() calls
    // inside a namespaced service resolve to
    // ksfraser\FrontAccounting\Square\Services\_() and fatal.
    require_once $famockDir . '/FaUIStubs.php';
}

// ImportStaging contract interfaces.
//
// The ksfraser\FrontAccounting\ImportStaging\* namespace is owned by the
// ksf_FA_ImportStagingProcessing module (PSR-4 -> its src/). It is NOT a
// Packagist package, so it cannot be a Composer require here -- commit 493291f
// removed the phantom "ksfraser/import-staging" require precisely because it
// made the 7.4 vendor unresolvable. At FA runtime these classes come from the
// sibling module's own autoloader, so tests must supply them explicitly.
//
// Register a PSR-4 autoloader for that namespace only when the sibling module
// is present. Override the location with ISU_MODULE_PATH for a non-default
// checkout. If it is absent the interfaces stay undefined and the adapter
// tests fail loudly rather than silently passing against a stub.
$isuPath = getenv('ISU_MODULE_PATH');
if ($isuPath === false || $isuPath === '') {
    $isuPath = __DIR__ . '/../../ksf_FA_ImportStagingProcessing';
}
$isuPath = rtrim($isuPath, '/');
if (is_dir($isuPath . '/src')) {
    spl_autoload_register(static function ($class) use ($isuPath) {
        $prefix = 'ksfraser\\FrontAccounting\\ImportStaging\\';
        if (strpos($class, $prefix) !== 0) {
            return;
        }
        $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
        $file = $isuPath . '/src/' . $relative . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    });
} else {
    fwrite(
        STDERR,
        sprintf(
            "ImportStaging module not found at '%s'.\n"
            . "The ksfraser\\FrontAccounting\\ImportStaging\\* contract interfaces are\n"
            . "required by the repository-adapter tests. Set ISU_MODULE_PATH to the\n"
            . "ksf_FA_ImportStagingProcessing checkout and re-run.\n",
            $isuPath
        )
    );
}

// FA hook dispatchers, as neutral recording doubles.
//
// Services call hook_invoke_all() to reach other modules (e.g. Square emits
// stage_customer_data for Import Staging to consume). Defined here rather than
// inside a single test file so availability does not depend on test ordering --
// previously the stub lived in ImportServiceBroadcastTest, meaning any test
// class that ran first would fatal on an undefined function.
if (!function_exists('hook_invoke_all')) {
    function hook_invoke_all($method, &$data, $opts = null)
    {
        $GLOBALS['ksf_test_broadcasts'][] = [$method, $data, $opts];
    }
}

if (!function_exists('hook_invoke')) {
    function hook_invoke($ext, $method, &$data, $opts = null)
    {
        return null;
    }
}

// Reset the broadcast recorder between tests.
$GLOBALS['ksf_test_broadcasts'] = [];
