<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Core;

use Psr\Log\LoggerInterface;
use App\Core\Database\MigrationStatus;

use App\Services\MailLogService;

final class ServiceFactory
{
	public function __construct(
		private readonly LoggerInterface $fileLogger,
		private readonly MigrationStatus $migrationStatus,
	) {}

	public function mailLogService(?Session $session = null): MailLogService {
		return new MailLogService(
			$this->fileLogger,
			$this->migrationStatus,
			$session
		);
	}

}
