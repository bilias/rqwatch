<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Router script for PHP's built-in web server in the qa/ harnesses
 (php -S ... -t web qa/php-router.php), so it behaves like Apache in
 production: an existing file under web/ (css, js, images) is served as it
 is, a missing one where web/.htaccess doesn't rewrite is a 404, everything
 else goes to web/index.php. php -S hands every request to
 its router script, and for an existing file it sets SCRIPT_NAME to that
 file, so Symfony took the path to be '/' and rqwatch answered
 /css/style.css with the homepage.
*/

$web = realpath(__DIR__ . '/../web');
if ($web === false) {
	http_response_code(500);
	exit("qa/php-router.php: web/ not found\n");
}

$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($requestUri, PHP_URL_PATH);
$file = is_string($path) ? realpath($web . rawurldecode($path)) : false;
// inside web/ only (realpath has resolved any ..), and never a PHP file
if ($file !== false && str_starts_with($file, $web . '/') && is_file($file) && !str_ends_with($file, '.php')) {
	return false;
}
// as web/.htaccess: these paths are never rewritten to index.php, so a
// missing file there is the web server's 404, not rqwatch's
if ($file === false && is_string($path) && preg_match('#^/(api|maps/|favicon\.ico|css/|images/)#i', $path) === 1) {
	http_response_code(404);
	exit;
}

// as Apache's rewrite to index.php sets them; Symfony derives the path from these
$_SERVER['SCRIPT_FILENAME'] = $web . '/index.php';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
require $web . '/index.php';
