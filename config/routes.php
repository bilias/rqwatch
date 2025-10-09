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

// Routes system
$routes = new RouteCollection();

$routes->add('login', new Route(
	'/login',
	[ '_controller' => 'App\\Controllers\\LoginController::login' ],
	[]
));

$routes->add('logout', new Route(
	'/logout',
	[ '_controller' => 'App\\Controllers\\LoginController::logout' ],
	[]
));

$routes->add('homepage', new Route(
	'/',
	[ '_controller' => 'App\\Controllers\\MailLogController::showAll',
//	  '_middleware' => [App\Core\Middleware\AuthMiddleware::class],
	],
	[]
));

$routes->add('admin_homepage', new Route(
	'/admin',
	[ '_controller' => 'App\\Controllers\\MailLogController::showAll',
//	  '_middleware' => [App\Core\Middleware\AuthMiddleware::class],
	],
	[]
));

$routes->add('search_results', new Route(
	'/results',
	[ '_controller' => 'App\\Controllers\\MailLogController::showResults' ],
	[]
));

$routes->add('admin_search_results', new Route(
	'/admin/results',
	[ '_controller' => 'App\\Controllers\\MailLogController::showResults' ],
	[]
));

$routes->add('reports', new Route(
	'/reports/{field}',
	[ '_controller' => 'App\\Controllers\\MailLogController::showReports' ],
	[ 'field' => '[a-zA-Z_0-9]{1,64}' ]
));

$routes->add('admin_reports', new Route(
	'/admin/reports/{field}',
	[ '_controller' => 'App\\Controllers\\MailLogController::showReports' ],
	[ 'field' => '[a-zA-Z_0-9]{1,64}' ]
));

$routes->add('day_logs', new Route(
	'/day/{date}', // path
	[ '_controller' => 'App\\Controllers\\MailLogController::showDay', 'date' => null ], // defaults
	[ 'date' => '\d{4}-\d{2}-\d{2}' ] // YYYY-MM-DD format
));

$routes->add('admin_day_logs', new Route(
	'/admin/day/{date}', // path
	[ '_controller' => 'App\\Controllers\\MailLogController::showDay', 'date' => null ], // defaults
	[ 'date' => '\d{4}-\d{2}-\d{2}' ] // YYYY-MM-DD format
));

$routes->add('quarantine', new Route(
	'/quarantine',
	[ '_controller' => 'App\\Controllers\\MailLogController::showQuarantine' ],
	[]
));

$routes->add('admin_quarantine', new Route(
	'/admin/quarantine',
	[ '_controller' => 'App\\Controllers\\MailLogController::showQuarantine' ],
	[]
));

$routes->add('quarantine_day', new Route(
	'/quarantine/{date}', // path
	[ '_controller' => 'App\\Controllers\\MailLogController::showQuarantineDay' ],
	[ 'date' => '\d{4}-\d{2}-\d{2}' ] // YYYY-MM-DD format
));

$routes->add('admin_quarantine_day', new Route(
	'/admin/quarantine/{date}', // path
	[ '_controller' => 'App\\Controllers\\MailLogController::showQuarantineDay' ],
	[ 'date' => '\d{4}-\d{2}-\d{2}' ] // YYYY-MM-DD format
));

$routes->add('detail', new Route(
	'/detail/{type}/{value}', // path
	[ '_controller' => 'App\\Controllers\\MailLogController::detail' ], // defaults
	[ 'type' => 'id|qid' ] // requirements: only 'id' or 'qid' allowed for {type}
));

$routes->add('admin_detail', new Route(
	'/admin/detail/{type}/{value}', // path
	[ '_controller' => 'App\\Controllers\\MailLogController::detail' ], // defaults
	[ 'type' => 'id|qid' ] // requirements: only 'id' or 'qid' allowed for {type}
));

$routes->add('search', new Route(
	'/search', // path
	[ '_controller' => 'App\\Controllers\\MailLogController::search' ]
));

$routes->add('admin_search', new Route(
	'/admin/search', // path
	[ '_controller' => 'App\\Controllers\\MailLogController::search' ]
));

$routes->add('search_filter_del', new Route(
	'/search/del/{filter_id}', // path
	[ '_controller' => 'App\\Controllers\\MailLogController::search_filter_del', 'filter_id' => null ],
	[ 'filter_id' => '\d{1,2}' ]
));

$routes->add('admin_search_filter_del', new Route(
	'/admin/search/del/{filter_id}', // path
	[ '_controller' => 'App\\Controllers\\MailLogController::search_filter_del', 'filter_id' => null ],
	[ 'filter_id' => '\d{1,2}' ]
));

$routes->add('admin_users', new Route(
	'/admin/users', // path
	[ '_controller' => 'App\\Controllers\\UserController::showAll', ], // defaults
	[]
));

$routes->add('admin_useradd', new Route(
	'/admin/user/add', // path
	[ '_controller' => 'App\\Controllers\\UserController::add', ], // defaults
	[]
));

$routes->add('admin_useredit', new Route(
	'/admin/user/edit/{id}', // path
	[ '_controller' => 'App\\Controllers\\UserController::edit', ], // defaults
	[ 'id' => '\d{1,8}']
));

$routes->add('admin_userdel', new Route(
	'/admin/user/del/{id}', // path
	[ '_controller' => 'App\\Controllers\\UserController::del', ], // defaults
	[ 'id' => '\d{1,8}']
));

$routes->add('admin_usersearch', new Route(
	'/admin/user/search', // path
	[ '_controller' => 'App\\Controllers\\UserController::searchUser', ], // defaults
	[], // requirements
	[], // options
	'', // host
	[], // schemes
	['POST'] // methods
));

$routes->add('admin_userloginas', new Route(
	'/admin/user/login/{id}', // path
	[ '_controller' => 'App\\Controllers\\UserController::loginAs', ], // defaults
	[ 'id' => '\d{1,8}']
));

$routes->add('admin_user', new Route(
	'/admin/user/{id}', // path
	[ '_controller' => 'App\\Controllers\\UserController::showOne', ], // defaults
	[ 'id' => '\d{1,8}' ] // 
));

$routes->add('admin_aliases', new Route(
	'/admin/aliases', // path
	[ '_controller' => 'App\\Controllers\\MailAliasController::showAll', ], // defaults
	[]
));

$routes->add('admin_aliases_add', new Route(
	'/admin/aliases/add', // path
	[ '_controller' => 'App\\Controllers\\MailAliasController::add', ], // defaults
	[]
));

$routes->add('admin_aliases_edit', new Route(
	'/admin/aliases/edit/{id}', // path
	[ '_controller' => 'App\\Controllers\\MailAliasController::edit', ], // defaults
	[ 'id' => '\d{1,8}']
));

$routes->add('admin_aliases_del', new Route(
	'/admin/aliases/del/{id}', // path
	[ '_controller' => 'App\\Controllers\\MailAliasController::del', ], // defaults
	[ 'id' => '\d{1,8}']
));

$routes->add('profile', new Route(
	'/profile', // path
	[ '_controller' => 'App\\Controllers\\UserController::profile', ], // defaults
	[ ],
));

$routes->add('showmail', new Route(
	'/mail/{id}', // path
	[ '_controller' => 'App\\Controllers\\MailLogController::showMail', ], // defaults
	[ 'id' => '\d{1,8}']
));

$routes->add('admin_showmail', new Route(
	'/admin/mail/{id}', // path
	[ '_controller' => 'App\\Controllers\\MailLogController::showMail', ], // defaults
	[ 'id' => '\d{1,8}']
));

$routes->add('attachsave', new Route(
	'/attach/save/{id}/{attach_id}', // path
	[ '_controller' => 'App\\Controllers\\MailLogController::saveAttachment', ], // defaults
	[ 'id' => '\d{1,8}', 'attach_id' => '\d{1,2}']
));

$routes->add('admin_attachsave', new Route(
	'/admin/attach/save/{id}/{attach_id}', // path
	[ '_controller' => 'App\\Controllers\\MailLogController::saveAttachment', ], // defaults
	[ 'id' => '\d{1,8}', 'attach_id' => '\d{1,2}']
));

$routes->add('attachopen', new Route(
	'/attach/open/{id}/{attach_id}', // path
	[ '_controller' => 'App\\Controllers\\MailLogController::openAttachment', ], // defaults
	[ 'id' => '\d{1,8}', 'attach_id' => '\d{1,2}']
));

$routes->add('admin_attachopen', new Route(
	'/admin/attach/open/{id}/{attach_id}', // path
	[ '_controller' => 'App\\Controllers\\MailLogController::openAttachment', ], // defaults
	[ 'id' => '\d{1,8}', 'attach_id' => '\d{1,2}']
));

$routes->add('releasemail', new Route(
	'/release/{id}', // path
	[ '_controller' => 'App\\Controllers\\MailLogController::releaseMail', ], // defaults
	[ 'id' => '\d{1,8}']
));

$routes->add('admin_releasemail', new Route(
	'/admin/release/{id}', // path
	[ '_controller' => 'App\\Controllers\\MailLogController::releaseMail', ], // defaults
	[ 'id' => '\d{1,8}']
));

$routes->add('admin_maps', new Route(
	'/admin/maps', // path
	[ '_controller' => 'App\\Controllers\\MapController::showSelectMap', ], // defaults
	[]
));

$routes->add('maps', new Route(
	'/user/maps', // path
	[ '_controller' => 'App\\Controllers\\MapController::showSelectMap', ], // defaults
	[]
));

$routes->add('admin_map_show_all', new Route(
	'/admin/map/all/{model}', // path
	[ '_controller' => 'App\\Controllers\\MapController::showAllMaps',  'model' => null ], // defaults
	[ 'model' => '[a-zA-Z_]{1,64}' ]
));

$routes->add('admin_maps_custom_show', new Route(
	'/admin/maps/custom', // path
	[ '_controller' => 'App\\Controllers\\MapController::showCustomMapsConfig', ], // defaults
	[ ]
));

$routes->add('admin_maps_custom_add', new Route(
	'/admin/maps/custom/add', // path
	[ '_controller' => 'App\\Controllers\\MapController::addCustomMap', ], // defaults
	[ ]
));

$routes->add('admin_maps_custom_del', new Route(
	'/admin/maps/custom/del/{id}', // path
	[ '_controller' => 'App\\Controllers\\MapController::delCustomMap', ], // defaults
	[ 'id' => '\d{1,8}']
));

$routes->add('admin_map_search_entry', new Route(
	'/admin/map/search', // path
	[ '_controller' => 'App\\Controllers\\MapController::searchMapEntry', ], // defaults
	[], // requirements
	[], // options
	'', // host
	[], // schemes
	['POST'] // methods
));

$routes->add('map_show_all', new Route(
	'/user/map/all', // path
	[ '_controller' => 'App\\Controllers\\MapController::showAllMaps', ], // defaults
	[]
));

$routes->add('admin_map_show', new Route(
	'/admin/map/{map}', // path
	[ '_controller' => 'App\\Controllers\\MapController::showMap', ], // defaults
	[ 'map' => '[a-zA-Z_0-9]{1,64}' ]
));

$routes->add('map_show', new Route(
	'/user/map/{map}', // path
	[ '_controller' => 'App\\Controllers\\MapController::showMap', ], // defaults
	[ 'map' => '[a-zA-Z_]{1,64}' ]
));

$routes->add('admin_map_add_entry', new Route(
	'/admin/map/{map}/add', // path
	[ '_controller' => 'App\\Controllers\\MapController::addMapEntry', ], // defaults
	[ 'map' => '[a-zA-Z_0-9]{1,64}' ]
));

$routes->add('map_add_entry', new Route(
	'/user/map/{map}/add', // path
	[ '_controller' => 'App\\Controllers\\MapController::addMapEntry', ], // defaults
	[ 'map' => '[a-zA-Z_]{1,64}' ]
));

$routes->add('admin_map_del_all_entries', new Route(
	'/admin/map/{map}/delall', // path
	[ '_controller' => 'App\\Controllers\\MapController::delMapAllEntries', ], // defaults
	[ 'map' => '[a-zA-Z_0-9]{1,64}' ]
));

$routes->add('map_del_all_entries', new Route(
	'/user/map/{map}/delall', // path
	[ '_controller' => 'App\\Controllers\\MapController::delMapAllEntries', ], // defaults
	[ 'map' => '[a-zA-Z_]{1,64}' ]
));

$routes->add('admin_map_del_entry', new Route(
	'/admin/map/{map}/del/{id}', // path
	[ '_controller' => 'App\\Controllers\\MapController::delMapEntry', ], // defaults
	[ 'map' => '[a-zA-Z_0-9]{1,64}', 'id' => '\d{1,8}' ]
));

$routes->add('map_del_entry', new Route(
	'/user/map/{map}/del/{id}', // path
	[ '_controller' => 'App\\Controllers\\MapController::delMapEntry', ], // defaults
	[ 'map' => '[a-zA-Z_]{1,64}', 'id' => '\d{1,8}' ]
));

$routes->add('admin_map_toggle_entry', new Route(
	'/admin/map/{map}/toggle/{id}', // path
	[ '_controller' => 'App\\Controllers\\MapController::toggleMapEntry', ], // defaults
	[ 'map' => '[a-zA-Z_0-9]{1,64}', 'id' => '\d{1,8}' ]
));

$routes->add('map_toggle_entry', new Route(
	'/user/map/{map}/toggle/{id}', // path
	[ '_controller' => 'App\\Controllers\\MapController::toggleMapEntry', ], // defaults
	[ 'map' => '[a-zA-Z_]{1,64}', 'id' => '\d{1,8}' ]
));

// default middleware classes incase route is missing from middlewareMap bellow
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

// attach middleware to routes
$middlewareMap = [
	'route_disable_authmiddleware' => ['NO_MIDDLEWARE'], // set on specific route to disable Middleware
	'login' => ['NO_MIDDLEWARE'], // set on specific route to disable Middleware
	'logout' => $userMiddlewareClasses,
	'homepage' => $userMiddlewareClasses,
	'admin_homepage' => $adminMiddlewareClasses,
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
	'admin_userloginas' => $adminMiddlewareClasses,
	'admin_user' => $adminMiddlewareClasses,
	'admin_aliases' => $adminMiddlewareClasses,
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
	'admin_map_search_entry' => $adminMiddlewareClasses,
	'admin_map_del_entry' => $adminMiddlewareClasses,
	'map_del_entry' => $userMiddlewareClasses,
	'admin_map_del_all_entries' => $adminMiddlewareClasses,
	'map_del_all_entries' => $userMiddlewareClasses,
	'admin_map_toggle_entry' => $adminMiddlewareClasses,
	'map_toggle_entry' => $userMiddlewareClasses,
	'admin_maps_custom_show' => $adminMiddlewareClasses,
	'admin_maps_custom_add' => $adminMiddlewareClasses,
	'admin_maps_custom_del' => $adminMiddlewareClasses,
];

// removed from bootstrap (require_once) and added in Router.php
return $routes;
