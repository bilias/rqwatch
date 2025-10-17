<?php
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU Affero General Public License version 3
as published by the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU Affero General Public License for more details.

You should have received a copy of the GNU Affero General Public License
along with this program.  If not, see <https://www.gnu.org/licenses/>.
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
