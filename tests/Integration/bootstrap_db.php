<?php
// Minimal bootstrap for integration tests - match FA's db_* functions
$path_to_root = "/var/www/html";
$tab_pref = "0_";

// Load DB config
require_once($path_to_root . "/config_db.php");

// Connect to DB  
$db = @new mysqli($db_connections[0]["host"], $db_connections[0]["dbuser"], 
    $db_connections[0]["dbpassword"], $db_connections[0]["dbname"]);
if (mysqli_connect_errno()) {
    die("DB connection failed: " . mysqli_connect_error());
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
