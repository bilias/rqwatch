<?php
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

use App\Core\RouteName;

// Routes system
$routes = new RouteCollection();

// default middleware classes incase route does not have _middleware
$defaultMiddlewareClasses = [
	App\Core\Middleware\AuthMiddleware::class,
	App\Core\Middleware\Authorization::class		// require admin
];

$adminMiddlewareClasses = [
	App\Core\Middleware\AuthMiddleware::class,
	App\Core\Middleware\Authorization::class		// require admin
];

$userMiddlewareClasses = [
	App\Core\Middleware\AuthMiddleware::class,
];

$noMiddlewareClasses = ['NO_MIDDLEWARE'];

$routes->add(RouteName::CONFIG_RELOAD->value, new Route(
	'/admin/config/reload', // path
	[ '_controller' => 'App\\Controllers\\Controller::redisConfigReload',
	  '_middleware' => $adminMiddlewareClasses,
	],
));

$routes->add(RouteName::LOGIN->value, new Route(
	'/login',
	[ '_controller' => 'App\\Controllers\\LoginController::login',
	  '_middleware' => $noMiddlewareClasses,
	],
));

$routes->add(RouteName::LOGOUT->value, new Route(
	'/logout',
	[ '_controller' => 'App\\Controllers\\LoginController::logout',
	  '_middleware' => $userMiddlewareClasses,
	],
));

$routes->add(RouteName::HOMEPAGE->value, new Route(
	'/',
	[ '_controller' => 'App\\Controllers\\MailLogController::showAll',
	  '_middleware' => $userMiddlewareClasses,
	],
));

$routes->add(RouteName::ADMIN_HOMEPAGE->value, new Route(
	'/admin',
	[ '_controller' => 'App\\Controllers\\MailLogController::showAll',
	  '_middleware' => $adminMiddlewareClasses,
	],
));

$routes->add(RouteName::SEARCH_RESULTS->value, new Route(
	'/results',
	[ '_controller' => 'App\\Controllers\\MailLogController::showResults',
	  '_middleware' => $userMiddlewareClasses,
	],
));

$routes->add(RouteName::ADMIN_SEARCH_RESULTS->value, new Route(
	'/admin/results',
	[ '_controller' => 'App\\Controllers\\MailLogController::showResults',
	  '_middleware' => $adminMiddlewareClasses,
	],
));

$routes->add(RouteName::REPORTS->value, new Route(
	'/reports/{field}/{mode}',
	[ '_controller' => 'App\\Controllers\\MailLogController::showReports',
	  '_middleware' => $userMiddlewareClasses,
	  'mode' => 'count' // defaults
	],
	// requirements
	[ 'field' => '[a-zA-Z_0-9]{1,64}',
	  'mode' => 'count|volume|day',
	],
));

$routes->add(RouteName::ADMIN_REPORTS->value, new Route(
	'/admin/reports/{field}/{mode}',
	[ '_controller' => 'App\\Controllers\\MailLogController::showReports',
	  '_middleware' => $adminMiddlewareClasses,
	  'mode' => 'count' // defaults
	],
	// requirements
	[ 'field' => '[a-zA-Z_0-9]{1,64}',
	  'mode' => 'count|volume|day',
	],
));

$routes->add(RouteName::DAY_LOGS->value, new Route(
	'/day/{date}', // path
	[ '_controller' => 'App\\Controllers\\MailLogController::showDay',
	  '_middleware' => $userMiddlewareClasses,
	  'date' => null // defaults
	],
	[ 'date' => '\d{4}-\d{2}-\d{2}' ] // requirements, YYYY-MM-DD format
));

$routes->add(RouteName::ADMIN_DAY_LOGS->value, new Route(
	'/admin/day/{date}', // path
	[ '_controller' => 'App\\Controllers\\MailLogController::showDay',
	  '_middleware' => $adminMiddlewareClasses,
	  'date' => null // defaults
	],
	[ 'date' => '\d{4}-\d{2}-\d{2}' ] // requirements, YYYY-MM-DD format
));

$routes->add(RouteName::QUARANTINE->value, new Route(
	'/quarantine',
	[ '_controller' => 'App\\Controllers\\MailLogController::showQuarantine',
	  '_middleware' => $userMiddlewareClasses,
	],
));

$routes->add(RouteName::ADMIN_QUARANTINE->value, new Route(
	'/admin/quarantine',
	[ '_controller' => 'App\\Controllers\\MailLogController::showQuarantine',
	  '_middleware' => $adminMiddlewareClasses,
	],
));

$routes->add(RouteName::QUARANTINE_DAY->value, new Route(
	'/quarantine/{date}', // path
	[ '_controller' => 'App\\Controllers\\MailLogController::showQuarantineDay',
	  '_middleware' => $userMiddlewareClasses,
	],
	[ 'date' => '\d{4}-\d{2}-\d{2}' ] // requirements, YYYY-MM-DD format
));

$routes->add(RouteName::ADMIN_QUARANTINE_DAY->value, new Route(
	'/admin/quarantine/{date}', // path
	[ '_controller' => 'App\\Controllers\\MailLogController::showQuarantineDay',
	  '_middleware' => $adminMiddlewareClasses,
	],
	[ 'date' => '\d{4}-\d{2}-\d{2}' ] // requirements, YYYY-MM-DD format
));

$routes->add(RouteName::DETAIL->value, new Route(
	'/detail/{type}/{value}', // path
	[ '_controller' => 'App\\Controllers\\MailLogController::detail',
	  '_middleware' => $userMiddlewareClasses,
	],
	[ 'type' => 'id|qid' ] // requirements: only 'id' or 'qid' allowed for {type}
));

$routes->add(RouteName::ADMIN_DETAIL->value, new Route(
	'/admin/detail/{type}/{value}', // path
	[ '_controller' => 'App\\Controllers\\MailLogController::detail',
	  '_middleware' => $adminMiddlewareClasses,
	],
	[ 'type' => 'id|qid' ] // requirements: only 'id' or 'qid' allowed for {type}
));

$routes->add(RouteName::SEARCH->value, new Route(
	'/search', // path
	[ '_controller' => 'App\\Controllers\\MailLogController::search',
	  '_middleware' => $userMiddlewareClasses,
	]
));

$routes->add(RouteName::ADMIN_SEARCH->value, new Route(
	'/admin/search', // path
	[ '_controller' => 'App\\Controllers\\MailLogController::search',
	  '_middleware' => $adminMiddlewareClasses,
	]
));

$routes->add(RouteName::SEARCH_FILTER_DEL->value, new Route(
	'/search/del/{filter_id}', // path
	[ '_controller' => 'App\\Controllers\\MailLogController::search_filter_del',
	  '_middleware' => $userMiddlewareClasses,
	  'filter_id' => null // defaults
	],
	[ 'filter_id' => '\d{1,2}' ] // requirements
));

$routes->add(RouteName::ADMIN_SEARCH_FILTER_DEL->value, new Route(
	'/admin/search/del/{filter_id}', // path
	[ '_controller' => 'App\\Controllers\\MailLogController::search_filter_del',
	  '_middleware' => $adminMiddlewareClasses,
	  'filter_id' => null // defaults
	],
	[ 'filter_id' => '\d{1,2}' ] // requirements
));

$routes->add(RouteName::ADMIN_USERS->value, new Route(
	'/admin/users', // path
	[ '_controller' => 'App\\Controllers\\UserController::showAll',
	  '_middleware' => $adminMiddlewareClasses,
	],
));

$routes->add(RouteName::ADMIN_USERADD->value, new Route(
	'/admin/user/add', // path
	[ '_controller' => 'App\\Controllers\\UserController::add',
	  '_middleware' => $adminMiddlewareClasses,
	],
));

$routes->add(RouteName::ADMIN_USEREDIT->value, new Route(
	'/admin/user/edit/{id}', // path
	[ '_controller' => 'App\\Controllers\\UserController::edit',
	  '_middleware' => $adminMiddlewareClasses,
	],
	[ 'id' => '\d{1,8}' ] // requirements
));

$routes->add(RouteName::ADMIN_USERDEL->value, new Route(
	'/admin/user/del/{id}', // path
	[ '_controller' => 'App\\Controllers\\UserController::del',
	  '_middleware' => $adminMiddlewareClasses,
	],
	[ 'id' => '\d{1,8}' ] // requirements
));

$routes->add(RouteName::ADMIN_USERSEARCH->value, new Route(
	'/admin/user/search', // path
	[ '_controller' => 'App\\Controllers\\UserController::searchUser',
	  '_middleware' => $adminMiddlewareClasses,
	],
	[], // requirements
	[], // options
	'', // host
	[], // schemes
	['POST'] // methods
));

$routes->add(RouteName::ADMIN_USERLOGINAS->value, new Route(
	'/admin/user/login/{id}', // path
	[ '_controller' => 'App\\Controllers\\UserController::loginAs',
	  '_middleware' => $adminMiddlewareClasses,
	],
	[ 'id' => '\d{1,8}' ] // requirements
));

$routes->add(RouteName::ADMIN_USER->value, new Route(
	'/admin/user/{id}', // path
	[ '_controller' => 'App\\Controllers\\UserController::showOne',
	  '_middleware' => $adminMiddlewareClasses,
	],
	[ 'id' => '\d{1,8}' ] // requirements
));

$routes->add(RouteName::ADMIN_ALIASES->value, new Route(
	'/admin/aliases', // path
	[ '_controller' => 'App\\Controllers\\MailAliasController::showAll',
	  '_middleware' => $adminMiddlewareClasses,
	],
));

$routes->add(RouteName::ADMIN_ALIASES_SEARCH->value, new Route(
	'/admin/aliases/search', // path
	[ '_controller' => 'App\\Controllers\\MailAliasController::searchAlias',
	  '_middleware' => $adminMiddlewareClasses,
	],
	[], // requirements
	[], // options
	'', // host
	[], // schemes
	['POST'] // methods
));

$routes->add(RouteName::ADMIN_ALIASES_ADD->value, new Route(
	'/admin/aliases/add', // path
	[ '_controller' => 'App\\Controllers\\MailAliasController::add',
	  '_middleware' => $adminMiddlewareClasses,
	],
));

$routes->add(RouteName::ADMIN_ALIASES_EDIT->value, new Route(
	'/admin/aliases/edit/{id}', // path
	[ '_controller' => 'App\\Controllers\\MailAliasController::edit',
	  '_middleware' => $adminMiddlewareClasses,
	],
	[ 'id' => '\d{1,8}' ] // requirements
));

$routes->add(RouteName::ADMIN_ALIASES_DEL->value, new Route(
	'/admin/aliases/del/{id}', // path
	[ '_controller' => 'App\\Controllers\\MailAliasController::del',
	  '_middleware' => $adminMiddlewareClasses,
	],
	[ 'id' => '\d{1,8}' ] // requirements
));

$routes->add(RouteName::PROFILE->value, new Route(
	'/profile', // path
	[ '_controller' => 'App\\Controllers\\UserController::profile',
	  '_middleware' => $userMiddlewareClasses,
	],
));

$routes->add(RouteName::SHOWMAIL->value, new Route(
	'/mail/{id}', // path
	[ '_controller' => 'App\\Controllers\\MailLogController::showMail',
	  '_middleware' => $userMiddlewareClasses,
	],
	[ 'id' => '\d{1,8}' ] // requirements
));

$routes->add(RouteName::ADMIN_SHOWMAIL->value, new Route(
	'/admin/mail/{id}', // path
	[ '_controller' => 'App\\Controllers\\MailLogController::showMail',
	  '_middleware' => $adminMiddlewareClasses,
	],
	[ 'id' => '\d{1,8}' ] // requirements
));

$routes->add(RouteName::ATTACHSAVE->value, new Route(
	'/attach/save/{id}/{attach_id}', // path
	[ '_controller' => 'App\\Controllers\\MailLogController::saveAttachment',
	  '_middleware' => $userMiddlewareClasses,
	],
	// requirements
	[ 'id' => '\d{1,8}',
	  'attach_id' => '\d{1,2}',
	]
));

$routes->add(RouteName::ADMIN_ATTACHSAVE->value, new Route(
	'/admin/attach/save/{id}/{attach_id}', // path
	[ '_controller' => 'App\\Controllers\\MailLogController::saveAttachment',
	  '_middleware' => $adminMiddlewareClasses,
	],
	// requirements
	[ 'id' => '\d{1,8}',
	  'attach_id' => '\d{1,2}',
	]
));

$routes->add(RouteName::ATTACHOPEN->value, new Route(
	'/attach/open/{id}/{attach_id}', // path
	[ '_controller' => 'App\\Controllers\\MailLogController::openAttachment',
	  '_middleware' => $userMiddlewareClasses,
	],
	// requirements
	[ 'id' => '\d{1,8}',
	  'attach_id' => '\d{1,2}',
	]
));

$routes->add(RouteName::ADMIN_ATTACHOPEN->value, new Route(
	'/admin/attach/open/{id}/{attach_id}', // path
	[ '_controller' => 'App\\Controllers\\MailLogController::openAttachment',
	  '_middleware' => $adminMiddlewareClasses,
	],
	// requirements
	[ 'id' => '\d{1,8}',
	  'attach_id' => '\d{1,2}',
	]
));

$routes->add(RouteName::RELEASEMAIL->value, new Route(
	'/release/{id}', // path
	[ '_controller' => 'App\\Controllers\\MailLogController::releaseMail',
	  '_middleware' => $userMiddlewareClasses,
	],
	[ 'id' => '\d{1,8}' ] // requirements
));

$routes->add(RouteName::ADMIN_RELEASEMAIL->value, new Route(
	'/admin/release/{id}', // path
	[ '_controller' => 'App\\Controllers\\MailLogController::releaseMail',
	  '_middleware' => $adminMiddlewareClasses,
	],
	[ 'id' => '\d{1,8}' ] // requirements
));

$routes->add(RouteName::ADMIN_MAPS->value, new Route(
	'/admin/maps', // path
	[ '_controller' => 'App\\Controllers\\MapController::showSelectMap',
	  '_middleware' => $adminMiddlewareClasses,
	],
));

$routes->add(RouteName::MAPS->value, new Route(
	'/user/maps', // path
	[ '_controller' => 'App\\Controllers\\MapController::showSelectMap',
	  '_middleware' => $userMiddlewareClasses,
	],
));

$routes->add(RouteName::ADMIN_MAP_SHOW_ALL->value, new Route(
	'/admin/map/all/{model}', // path
	[ '_controller' => 'App\\Controllers\\MapController::showAllMaps',
	  '_middleware' => $adminMiddlewareClasses,
	  'model' => null // defaults
	],
	[ 'model' => '[a-zA-Z_]{1,64}' ] // requirements
));

$routes->add(RouteName::ADMIN_MAPS_CUSTOM_SHOW->value, new Route(
	'/admin/maps/custom', // path
	[ '_controller' => 'App\\Controllers\\MapController::showCustomMapsConfig',
	  '_middleware' => $adminMiddlewareClasses,
	],
));

$routes->add(RouteName::ADMIN_MAPS_CUSTOM_ADD->value, new Route(
	'/admin/maps/custom/add', // path
	[ '_controller' => 'App\\Controllers\\MapController::addCustomMap',
	  '_middleware' => $adminMiddlewareClasses,
	],
));

$routes->add(RouteName::ADMIN_MAPS_CUSTOM_EDIT->value, new Route(
	'/admin/maps/custom/edit/{id}', // path
	[ '_controller' => 'App\\Controllers\\MapController::editCustomMap',
	  '_middleware' => $adminMiddlewareClasses,
	],
	[ 'id' => '\d{1,8}' ] // requirements
));

$routes->add(RouteName::ADMIN_MAPS_CUSTOM_DEL->value, new Route(
	'/admin/maps/custom/del/{id}', // path
	[ '_controller' => 'App\\Controllers\\MapController::delCustomMap',
	  '_middleware' => $adminMiddlewareClasses,
	],
	[ 'id' => '\d{1,8}' ] // requirements
));

$routes->add(RouteName::ADMIN_MAP_SEARCH_ENTRY->value, new Route(
	'/admin/map/search', // path
	[ '_controller' => 'App\\Controllers\\MapController::searchMapEntry',
	  '_middleware' => $adminMiddlewareClasses,
	],
	[], // requirements
	[], // options
	'', // host
	[], // schemes
	['POST'] // methods
));

$routes->add(RouteName::MAP_SHOW_ALL->value, new Route(
	'/user/map/all', // path
	[ '_controller' => 'App\\Controllers\\MapController::showAllMaps',
	  '_middleware' => $userMiddlewareClasses,
	],
));

$routes->add(RouteName::ADMIN_MAP_SHOW->value, new Route(
	'/admin/map/{map}', // path
	[ '_controller' => 'App\\Controllers\\MapController::showMap',
	  '_middleware' => $adminMiddlewareClasses,
	],
	[ 'map' => '[a-zA-Z_0-9]{1,64}' ] // requirements
));

$routes->add(RouteName::MAP_SHOW->value, new Route(
	'/user/map/{map}', // path
	[ '_controller' => 'App\\Controllers\\MapController::showMap',
	  '_middleware' => $userMiddlewareClasses,
	],
	[ 'map' => '[a-zA-Z_]{1,64}' ] // requirements
));

$routes->add(RouteName::ADMIN_MAP_ADD_ENTRY->value, new Route(
	'/admin/map/{map}/add', // path
	[ '_controller' => 'App\\Controllers\\MapController::addMapEntry',
	  '_middleware' => $adminMiddlewareClasses,
	],
	[ 'map' => '[a-zA-Z_0-9]{1,64}' ] // requirements
));

$routes->add(RouteName::MAP_ADD_ENTRY->value, new Route(
	'/user/map/{map}/add', // path
	[ '_controller' => 'App\\Controllers\\MapController::addMapEntry',
	  '_middleware' => $userMiddlewareClasses,
	],
	[ 'map' => '[a-zA-Z_]{1,64}' ] // requirements
));

$routes->add(RouteName::ADMIN_MAP_EDIT_ENTRY->value, new Route(
	'/admin/map/{map}/edit/{id}', // path
	[ '_controller' => 'App\\Controllers\\MapController::editMapEntry',
	  '_middleware' => $adminMiddlewareClasses,
	],
	// requirements
	[ 'map' => '[a-zA-Z_0-9]{1,64}',
	  'id' => '\d{1,8}',
	]
));

$routes->add(RouteName::MAP_EDIT_ENTRY->value, new Route(
	'/user/map/{map}/edit/{id}', // path
	[ '_controller' => 'App\\Controllers\\MapController::editMapEntry',
	  '_middleware' => $userMiddlewareClasses,
	],
	// requirements
	[ 'map' => '[a-zA-Z_]{1,64}',
	  'id' => '\d{1,8}'
	]
));

$routes->add(RouteName::ADMIN_MAP_DEL_ALL_ENTRIES->value, new Route(
	'/admin/map/{map}/delall', // path
	[ '_controller' => 'App\\Controllers\\MapController::delMapAllEntries',
	  '_middleware' => $adminMiddlewareClasses,
	],
	[ 'map' => '[a-zA-Z_0-9]{1,64}' ] // requirements
));

$routes->add(RouteName::MAP_DEL_ALL_ENTRIES->value, new Route(
	'/user/map/{map}/delall', // path
	[ '_controller' => 'App\\Controllers\\MapController::delMapAllEntries',
	  '_middleware' => $userMiddlewareClasses,
	],
	[ 'map' => '[a-zA-Z_]{1,64}' ] // requirements
));

$routes->add(RouteName::ADMIN_MAP_DEL_ENTRY->value, new Route(
	'/admin/map/{map}/del/{id}', // path
	[ '_controller' => 'App\\Controllers\\MapController::delMapEntry',
	  '_middleware' => $adminMiddlewareClasses,
	],
	// requirements
	[ 'map' => '[a-zA-Z_0-9]{1,64}',
	  'id' => '\d{1,8}',
	]
));

$routes->add(RouteName::MAP_DEL_ENTRY->value, new Route(
	'/user/map/{map}/del/{id}', // path
	[ '_controller' => 'App\\Controllers\\MapController::delMapEntry',
	  '_middleware' => $userMiddlewareClasses,
	],
	// requirements
	[ 'map' => '[a-zA-Z_]{1,64}',
	  'id' => '\d{1,8}',
	]
));

$routes->add(RouteName::ADMIN_MAP_TOGGLE_ENTRY->value, new Route(
	'/admin/map/{map}/toggle/{id}', // path
	[ '_controller' => 'App\\Controllers\\MapController::toggleMapEntry',
	  '_middleware' => $adminMiddlewareClasses,
	],
	// requirements
	[ 'map' => '[a-zA-Z_0-9]{1,64}',
	  'id' => '\d{1,8}',
	]
));

$routes->add(RouteName::MAP_TOGGLE_ENTRY->value, new Route(
	'/user/map/{map}/toggle/{id}', // path
	[ '_controller' => 'App\\Controllers\\MapController::toggleMapEntry',
	  '_middleware' => $userMiddlewareClasses,
	],
	// requirements
	[ 'map' => '[a-zA-Z_]{1,64}',
	  'id' => '\d{1,8}',
	]
));

// Moved inside routes
// attach middleware to routes
/*
$middlewareMap = [
	'route_disable_authmiddleware' => ['NO_MIDDLEWARE'], // set on specific route to disable Middleware
	'login' => ['NO_MIDDLEWARE'], // set on specific route to disable Middleware
	'logout' => $userMiddlewareClasses,
	'homepage' => $userMiddlewareClasses,
	'admin_homepage' => $adminMiddlewareClasses,
	'config_reload' => $adminMiddlewareClasses,
	'profile' => $userMiddlewareClasses,
	'search_results' => $userMiddlewareClasses,
	'admin_search_results' => $adminMiddlewareClasses,
	'day_logs' => $userMiddlewareClasses,
	'admin_day_logs' => $adminMiddlewareClasses,
	'quarantine' => $userMiddlewareClasses,
	'admin_quarantine' => $adminMiddlewareClasses,
	'quarantine_day' => $userMiddlewareClasses,
	'admin_quarantine_day' => $adminMiddlewareClasses,
	'detail' => $userMiddlewareClasses,
	'admin_detail' => $adminMiddlewareClasses,
	'reports' => $userMiddlewareClasses,
	'admin_reports' => $adminMiddlewareClasses,
	'search' => $userMiddlewareClasses,
	'admin_search' => $adminMiddlewareClasses,
	'search_filter_del' => $userMiddlewareClasses,
	'admin_search_filter_del' => $adminMiddlewareClasses,
	'admin_users' => $adminMiddlewareClasses,
	'admin_useradd' => $adminMiddlewareClasses,
	'admin_useredit' => $adminMiddlewareClasses,
	'admin_userdel' => $adminMiddlewareClasses,
	'admin_usersearch' => $adminMiddlewareClasses,
	'admin_userloginas' => $adminMiddlewareClasses,
	'admin_user' => $adminMiddlewareClasses,
	'admin_aliases' => $adminMiddlewareClasses,
	'admin_aliases_search' => $adminMiddlewareClasses,
	'admin_aliases_add' => $adminMiddlewareClasses,
	'admin_aliases_edit' => $adminMiddlewareClasses,
	'admin_aliases_del' => $adminMiddlewareClasses,
	'showmail' => $userMiddlewareClasses,
	'admin_showmail' => $adminMiddlewareClasses,
	'attachsave' => $userMiddlewareClasses,
	'admin_attachsave' => $adminMiddlewareClasses,
	'attachopen' => $userMiddlewareClasses,
	'admin_attachopen' => $adminMiddlewareClasses,
	'releasemail' => $userMiddlewareClasses,
	'admin_releasemail' => $adminMiddlewareClasses,
	'admin_maps' => $adminMiddlewareClasses,
	'maps' => $userMiddlewareClasses,
	'admin_map_show_all' => $adminMiddlewareClasses,
	'map_show_all' => $userMiddlewareClasses,
	'admin_map_show' => $adminMiddlewareClasses,
	'map_show' => $userMiddlewareClasses,
	'admin_map_add_entry' => $adminMiddlewareClasses,
	'map_add_entry' => $userMiddlewareClasses,
	'admin_map_edit_entry' => $adminMiddlewareClasses,
	'map_edit_entry' => $userMiddlewareClasses,
	'admin_map_search_entry' => $adminMiddlewareClasses,
	'admin_map_del_entry' => $adminMiddlewareClasses,
	'map_del_entry' => $userMiddlewareClasses,
	'admin_map_del_all_entries' => $adminMiddlewareClasses,
	'map_del_all_entries' => $userMiddlewareClasses,
	'admin_map_toggle_entry' => $adminMiddlewareClasses,
	'map_toggle_entry' => $userMiddlewareClasses,
	'admin_maps_custom_show' => $adminMiddlewareClasses,
	'admin_maps_custom_add' => $adminMiddlewareClasses,
	'admin_maps_custom_edit' => $adminMiddlewareClasses,
	'admin_maps_custom_del' => $adminMiddlewareClasses,
];
*/

// removed from bootstrap (require_once) and added in Router.php
return $routes;
