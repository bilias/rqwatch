<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Controllers;

use App\Core\App;
use App\Configuration\AppConfig;
use App\Configuration\Config;

use App\Utils\Helper;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

use Throwable;

class SystemController extends Controller
{
	public function redisConfigReload(): Response {
		if (!$this->is_admin) {
			$this->fileLogger->warning("'{$this->username}' tried to reload config in redis without admin authorization");
			$this->flashbag->add('error', "Permission denied");
			return new RedirectResponse($this->getSearchUrl());
		}

		if (!$this->csrfValid('config_reload')) {
			$this->fileLogger->warning(
				"CSRF check failed on redisConfigReload from " . $_SERVER['REMOTE_ADDR']
			);
			$this->flashbag->add('error', 'Invalid or expired request. Please try again.');
			return new RedirectResponse($this->getSearchUrl());
		}

		if (!Helper::env_bool('REDIS_ENABLE')) {
			$this->flashbag->add('warning', "Redis is not enabled");
			return new RedirectResponse($this->getSearchUrl());
		}

		try {

			// Force reload the config and cache it again
			Config::loadAndInitWithRedisCache(
				App::cache(),
				AppConfig::CONFIG_DEFAULT_PATH,
				AppConfig::CONFIG_LOCAL_PATH,
				[],
				$_ENV['REDIS_CONFIG_KEY'],
				(int) $_ENV['REDIS_CONFIG_CACHE_TTL'],
				true
			);
			$this->fileLogger->info("Config reloaded and cached in Redis");
			$this->flashbag->add('info', "Config reloaded and cached in Redis");
			return new RedirectResponse($this->getSearchUrl());
		} catch (Throwable $e) {
			$this->fileLogger->error("Failed redisConfigReload: " . $e->getMessage());
			$this->flashbag->add('error', "Failed redisConfigReload: " . $e->getMessage());
			return new RedirectResponse($this->getSearchUrl());
		}
	}

	public function dnsFlush(): Response {
		if (!$this->is_admin) {
			$this->fileLogger->warning("'{$this->username}' tried to flush DNS cache without admin authorization");
			$this->flashbag->add('error', "Permission denied");
			return new RedirectResponse($this->getSearchUrl());
		}

		if (!$this->csrfValid('dns_flush')) {
			$this->fileLogger->warning(
				"CSRF check failed on dnsFlush from " . $_SERVER['REMOTE_ADDR']
			);
			$this->flashbag->add('error', 'Invalid or expired request. Please try again.');
			return new RedirectResponse($this->getSearchUrl());
		}

		if (!Helper::env_bool('REDIS_ENABLE')) {
			$this->flashbag->add('warning', "Redis is not enabled");
			return new RedirectResponse($this->getSearchUrl());
		}

		try {
			$deleted = Helper::flush_dns_cache();
			$this->fileLogger->info("DNS cache flushed: {$deleted} entries removed");
			$this->flashbag->add('info', "DNS cache flushed: {$deleted} entries removed");

		} catch (Throwable $e) {
			$this->fileLogger->error("DNS Flush failed: " . $e->getMessage());
			$this->flashbag->add('error', "dnsFlush failed");
		}

		return new RedirectResponse($this->getSearchUrl());
	}

}
