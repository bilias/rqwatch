<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Api;

use Psr\Log\LoggerInterface;

use App\Core\Auth\BasicAuth;
use App\Utils\Helper;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use RuntimeException;

// cannot be intantiated as new RqwatchApi(), only children
abstract class RqwatchApi
{
	protected float $startTime;
	protected float $startMemory;
	protected LoggerInterface $fileLogger;
	protected LoggerInterface $syslogLogger;
	protected string $logPrefix = 'RqwatchApi';
	protected Request $request;
	protected string $clientIp;
	protected BasicAuth $auth;

	public function __construct(
		Request $request,
		LoggerInterface $fileLogger,
		LoggerInterface $syslogLogger,
		float $startTime,
		float $startMemory)
	{
		$this->startTime = $startTime;
		$this->startMemory = $startMemory;
		$this->fileLogger = $fileLogger;
		$this->syslogLogger = $syslogLogger;
		$this->request = $request;
		$this->clientIp = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';

		// Check if API is disabled
		$this->checkApiEnabled();
		// Check IP access
		$this->checkIpAcl($this->getAllowedIps());
		// Authenticate (BasicAuth)
		$this->checkAuth();
	}

	// Force child classes to implement these
	abstract public function handle(): void;
	abstract protected function getAllowedIps(): array;
	abstract protected function getAuthCredentials(): array;

	protected function checkApiEnabled(): void {
		if (!Helper::env_bool('API_ENABLE')) {
			$logMsg = "{$this->clientIp} requested '" . $_SERVER['REQUEST_URI'] . "' but API is disabled.";

			$responseMsg = "API is disabled";
			$this->dropLogResponse(
				Response::HTTP_FORBIDDEN, $responseMsg,
				$logMsg, 'warning');
		}
	}

	protected function checkIpAcl(array $allowedIps): void {
		if (empty($allowedIps)) {
			$msg = "getAllowedIps() returned empty IP ACL in " . static::class;
			$this->fileLogger->error($msg);
			throw new RuntimeException("getAllowedIps() returned empty IP ACL in " .
				static::class);
			exit;
		}

		if (!in_array($this->clientIp, $allowedIps)) {
			$logMsg = "{$this->clientIp} is not allowed to access this API";
			$responseMsg = "Permission denied";
			$this->dropLogResponse(
				Response::HTTP_FORBIDDEN, $responseMsg,
				$logMsg, 'warning');
		}
	}

	protected function checkAuth(): void {
		[$username, $password] = $this->getAuthCredentials();

		if (empty($username) or empty($password)) {
			$msg = "getAuthCredentials() returned empty username or password in " . static::class;
			$this->fileLogger->error($msg);
			throw new RuntimeException("getAuthCredentials() returned empty username or password in " .
				static::class);
			exit;
		}

		try {
			$this->auth = new BasicAuth($username, $password, $this->fileLogger);
		} catch (HttpException $e) {
			$msg = $e->getMessage();
			$this->dropLogResponse($e->getStatusCode(), $msg, $msg, 'warning');
		}

		// Authenticate the remote system
		if (!$this->auth->authenticate() || !$this->auth->getAuthenticatedUser()) {
			$msg = "Authentication failed in " . static::class;
			$this->fileLogger->error($msg);
			// we should never be here
			throw new RuntimeException("Authentication failed in " . static::class);
			exit;
		}
	}

	protected function dropLogResponse(
		int $httpCode = 500,
		string $responseMsg,
		string $logMsg,
		string $logLevel = 'error',
	): void {

		$fullLogMsg = $this->logPrefix ? "[{$this->logPrefix}] $logMsg" : $logMsg;

		if ($this->fileLogger && method_exists($this->fileLogger, $logLevel)) {
			$this->fileLogger->$logLevel($fullLogMsg);
		}

		if ($this->syslogLogger && method_exists($this->syslogLogger, $logLevel)) {
			$this->syslogLogger->$logLevel($fullLogMsg);
		}

		$response = new Response($responseMsg, $httpCode);
		$response->send();
		exit;
	}

	protected function getRuntime(): string {
		return Helper::get_runtime($this->startTime, $this->startMemory);
	}
}
