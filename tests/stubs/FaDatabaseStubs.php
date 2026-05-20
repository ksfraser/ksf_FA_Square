<?php
declare(strict_types=1);

// Minimal FA database function stubs for unit testing

if (!function_exists('db_query')) {
    function db_query($sql) {
        global $fa_test_db;
        if (!isset($fa_test_db)) {
            $fa_test_db = new \ArrayObject();
        }
        return $fa_test_db;
    }
}

if (!function_exists('db_fetch')) {
    function db_fetch($result) {
        if ($result instanceof \ArrayObject) {
            return $result->current();
        }
        return false;
    }
}

if (!function_exists('db_fetch_assoc')) {
    function db_fetch_assoc($result) {
        return db_fetch($result);
    }
}

if (!function_exists('db_escape')) {
    function db_escape($str) {
        return addslashes((string)$str);
    }
}

if (!function_exists('db_insert_id')) {
    function db_insert_id() {
        return 1;
    }
}

if (!function_exists('db_num_rows')) {
    function db_num_rows($result) {
        return $result instanceof \ArrayObject ? $result->count() : 0;
    }

}

if (!function_exists('db_affected_rows')) {
    function db_affected_rows($result) {
        return 1;
    }
}
