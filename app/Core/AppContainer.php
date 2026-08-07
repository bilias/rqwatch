<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Core;

use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Log\LoggerInterface;

use App\Core\Database\MigrationStatus;
use App\Core\Cache\CacheInterface;

final class AppContainer
{
	/*
	 * Container for Application lifetime objects
	 * Stores application services
	 * XXX Not to be used for Request/User/Session lifetime objects
	 */
	public function __construct(
		public readonly float $startTime,
		public readonly int $startMemory,
		public readonly LoggerInterface $fileLogger,
		public readonly LoggerInterface $syslogLogger,
		public readonly Capsule $capsule,
		public readonly MigrationStatus $migrationStatus,
		public readonly ?CacheInterface $cache,
	) { }
}

