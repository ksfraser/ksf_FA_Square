<?php
declare(strict_types=1);

if (!function_exists('display_notification')) {
    function display_notification($msg) {}
}

if (!function_exists('display_error')) {
    function display_error($msg) {}
}

if (!function_exists('display_warning')) {
    function display_warning($msg) {}
}

if (!function_exists('_')) {
    function _($text) { return $text; }
}
