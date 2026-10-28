<?php
// Larastan defines this only when it can boot a Laravel app; rqwatch has none
if (!defined('LARAVEL_VERSION')) {
	define('LARAVEL_VERSION', ltrim((string) \Composer\InstalledVersions::getPrettyVersion('illuminate/database'), 'v'));
}
