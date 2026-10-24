<?php

declare(strict_types=1);

// PHP's disposable built-in server must serve compiled public assets itself.
$public = dirname(__DIR__, 2).'/public';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$file = is_string($path) ? realpath($public.$path) : false;
if (false !== $file && str_starts_with($file, $public.'/') && is_file($file) && !str_ends_with($file, '.php')) {
    return false;
}
// A real directory named in the URL makes PHP's built-in server infer a
// different script/base path. Match the Apache/FPM front-controller contract
// for routed requests, including /template-assets/<private stored filename>.
$_SERVER['SCRIPT_FILENAME'] = $public.'/index.php';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
require $public.'/index.php';
