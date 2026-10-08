<?php
/**
 * Minimal bootstrap for integration tests - match FA's db_* functions.
 *
 * $path_to_root used to be hardcoded to /var/www/html, so the whole Integration
 * suite only ran INSIDE the container and `phpunit --testsuite Integration` on the
 * host died with
 *
 *   Failed opening required '/var/www/html/modules/ksf_FA_Square/tests/Integration/bootstrap_db.php'
 *
 * Resolution order, so the same suite runs in both places:
 *   1. FA_PATH_TO_ROOT env var (explicit override)
 *   2. /var/www/html when it exists -- i.e. inside the container
 *   3. the FA tree alongside the dev checkout, e.g.
 *      ../../../ksf_Infrastructure/FA/2.4.3
 *   4. any ksf_Infrastructure/FA/* tree found by walking up from this file
 */
$candidates = array();

$env = getenv('FA_PATH_TO_ROOT');
if ($env !== false && $env !== '') {
    $candidates[] = $env;
}

$candidates[] = '/var/www/html';

// Walk up from this file looking for a dev-tree FA install.
$here = __DIR__;
for ($i = 0; $i < 6; $i++) {
    $base = dirname($here);
    $candidates[] = $base . '/ksf_Infrastructure/FA/2.4.3';
    $candidates[] = $base . '/ksf_Infrastructure/FA';
    $here = $base;
}

$path_to_root = null;
foreach ($candidates as $candidate) {
    if ($candidate !== '' && is_file($candidate . '/config_db.php')) {
        $path_to_root = $candidate;
        break;
    }
}

if ($path_to_root === null) {
    fwrite(STDERR, "Could not locate an FA root containing config_db.php.\n"
        . "Tried:\n  - FA_PATH_TO_ROOT (env)\n"
        . "  - /var/www/html\n"
        . "  - ../../ksf_Infrastructure/FA/2.4.3 and upward\n\n"
        . "Set FA_PATH_TO_ROOT, or run inside the container.\n");
    exit(1);
}

$tab_pref = "0_";

// FA defines TB_PREF as a constant; this minimal bootstrap must too, because
// production code under test refers to it. ImportServiceLiveSandboxTest does.
if (!defined('TB_PREF')) {
    define('TB_PREF', $tab_pref);
}

// Load DB config
require_once($path_to_root . "/config_db.php");

// Connect to DB.
//
// The host/port from config_db.php are correct INSIDE the container, where the
// DB is a service name (ksfii_app-mariadb) on the compose network. Running the
// suite from the host needs an override, because the published port is 3307 and
// the service name does not resolve. FA_DB_HOST / FA_DB_PORT let that happen
// WITHOUT editing any FA config_db.php -- which matters because the two running
// stacks have different credentials and copying config between them has broken
// this box before.
$dbHost = getenv('FA_DB_HOST') ?: $db_connections[0]["host"];
$dbPort = getenv('FA_DB_PORT') ?: (isset($db_connections[0]["port"]) ? $db_connections[0]["port"] : 3306);
$dbUser = getenv('FA_DB_USER') ?: $db_connections[0]["dbuser"];
$dbPass = getenv('FA_DB_PASS') !== false ? getenv('FA_DB_PASS') : $db_connections[0]["dbpassword"];

$db = @new mysqli($dbHost, $dbUser, $dbPass, $db_connections[0]["dbname"], (int)$dbPort);
if (mysqli_connect_errno()) {
    die("DB connection failed: " . mysqli_connect_error()
        . " (host={$dbHost} port={$dbPort})\n"
        . "Set FA_DB_HOST / FA_DB_PORT to reach the containerised DB from the host.\n"
        . "Set FA_DB_USER / FA_DB_PASS if the target stack's credentials differ from\n"
        . "this config_db.php -- the two running stacks DO differ, and one config\n"
        . "cannot serve both. See AGENTS_ARCH.md on the two-stack credential hazard.\n");
}

// Match FA's db_escape exactly
function db_escape($value = "", $nullify = false)
{
    global $db;
    $nullify = ($nullify === null) ? false : $nullify;
    if ((!isset($value)) || (is_null($value)) || ($value === "")) {
        $value = ($nullify) ? "NULL" : "''";
    } else {
        if (is_string($value)) {
            $value = "'" . mysqli_real_escape_string($db, $value) . "'";
        } else if (!is_numeric($value)) {
            echo "ERROR: incorrect data type sent to sql query\n";
            exit(1);
        }
    }
    return $value;
}

function db_query($sql, $err_msg = null)
{
    global $db;
    $result = mysqli_query($db, $sql);
    if ($result === false && $err_msg !== null) {
        echo "DB ERROR: {$err_msg} - " . mysqli_error($db) . "\n";
    }
    return $result;
}

function db_insert_id()
{
    global $db;
    return mysqli_insert_id($db);
}

function db_num_rows($result)
{
    return $result->num_rows;
}

function db_fetch_assoc($result)
{
    return $result->fetch_assoc();
}

function db_affected_rows()
{
    global $db;
    return mysqli_affected_rows($db);
}

function db_error_no()
{
    global $db;
    return mysqli_errno($db);
}

function db_error_msg($conn)
{
    global $db;
    return mysqli_error($db);
}
