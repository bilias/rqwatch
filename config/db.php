<?php
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/
use Illuminate\Database\Capsule\Manager as Capsule;

$db_config = Array (
	'host'     => $_ENV['DB_HOST'],
	'username' => $_ENV['DB_USER'],
	'password' => $_ENV['DB_PASS'],
	'database' => $_ENV['DB_NAME'],
	'port'     => $_ENV['DB_PORT'],
	'charset'  => 'utf8mb4',
	'collation'=> 'utf8mb4_general_ci',
	'driver'   => 'mysql',
	'fetch'    => 'FETCH_ASSOC',
//	'prefix'   => 'my_',
);

$capsule = new Capsule;

$capsule->addConnection($db_config);

$capsule->bootEloquent();

// Make this Capsule instance available globally via static methods... (optional)
$capsule->setAsGlobal();

/*
// have DB:: and query builder
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;

// Set up a container manually
$container = new Container();

// Bind the container to facades
Facade::setFacadeApplication($container);

// Bind 'db' to the capsule instance, so DB:: works
$container->instance('db', $capsule->getDatabaseManager());
*/
