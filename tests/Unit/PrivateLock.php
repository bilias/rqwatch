<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 A console command's "one run at a time" lock, kept in a directory of the
 test's own. LockableTrait's default is the machine's: a semaphore named
 after the command, which a real cron or another test run on the same
 machine may hold at that moment, refusing the test's run.
*/

namespace Tests\Unit;

use ReflectionProperty;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

final class PrivateLock
{
	/**
	 * @template T of Command
	 * @param T $command one that uses LockableTrait
	 * @return T
	 */
	public static function give(Command $command, string $dir): Command {
		(new ReflectionProperty($command, 'lockFactory'))->setValue($command, new LockFactory(new FlockStore($dir)));
		return $command;
	}
}
