<?php

declare(strict_types=1);

// PHP's disposable built-in server must serve compiled public assets itself.
$public = dirname(__DIR__, 2).'/public';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$file = is_string($path) ? realpath($public.$path) : false;
if (false !== $file && str_starts_with($file, $public.'/') && is_file($file) && !str_ends_with($file, '.php')) {
    return false;
}
require $public.'/index.php';
