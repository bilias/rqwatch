<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Api;

use App\Services\MailLogService;
use App\Utils\Helper;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

use InvalidArgumentException;

final class GetMailApi extends RqwatchApi
{
	protected string $logPrefix = 'GetMailApi';

	#[\Override]
	protected function getAllowedIps(): array {
		return array_values(array_filter(
			array_map('trim', explode(',', Helper::env_string('MAIL_API_ACL') ?? ''))
		));
	}

	#[\Override]
	protected function getAuthCredentials(): array {
		return [Helper::env_string('MAIL_API_USER'), Helper::env_string('MAIL_API_PASS')];
	}

	#[\Override]
	public function handle(): void {
		$fields = $this->requireSingleValues($this->request->request->all(), ['remote_user', 'id']);
		$remote_user = $fields['remote_user'] ?? '';
		// digits only: getInt() throws on "abc" (a 500, not a 400), intval() reads "5abc" as 5
		$id_param = $fields['id'] ?? '';
		$id = ctype_digit($id_param) ? (int) $id_param : 0;

		// '' and '0' are no user too: fail closed
		if ($remote_user === '' || $remote_user === '0') {
			$err_msg = "{$this->clientIp} requested mail without a calling user email";
			$response_msg = "Missing Required info";
			$this->dropLogResponse(
				Response::HTTP_BAD_REQUEST, $response_msg,
				$err_msg, 'critical');
		}

		if ($id <= 0) {
			$err_msg = "{$remote_user} via {$this->clientIp} requested mail with invalid mail id '{$id_param}'";
			$response_msg = "Missing Required info";
			$this->dropLogResponse(
				Response::HTTP_BAD_REQUEST, $response_msg,
				$err_msg, 'critical');
		}
		
		$service = new MailLogService();

		try {
			// findMailLog throws InvalidArgumentException when no mail found
			$log = $service->findMailLog($id);
		} catch (InvalidArgumentException $e) {
			$err_msg = "{$remote_user} via {$this->clientIp} requested mail with id {$id} which does not exist";
			$response_msg = "Message not found";
			$this->dropLogResponse(
				Response::HTTP_BAD_REQUEST, $response_msg,
				$err_msg, 'warning');
		}

		// only a regular file under QUARANTINE_DIR, whatever the row says
		$location = Helper::quarantinedFile((string) $log->mail_location);
		if ($location === null) {
			$err_msg = "{$remote_user} via {$this->clientIp} requested mail {$log->qid} where local file '{$log->mail_location}' is not a file under QUARANTINE_DIR";
			$response_msg = "File not found";
			$this->dropLogResponse(
				Response::HTTP_BAD_REQUEST, $response_msg,
				$err_msg, 'critical');
		}

		// serve static file 
		$response = new BinaryFileResponse($location);
		$response->setStatusCode(Response::HTTP_OK);

		$this->fileLogger->info("[{$this->logPrefix}] '{$remote_user}' via '{$this->clientIp}' requested mail {$log->qid}");
		$this->syslogLogger->info("[{$this->logPrefix}] '{$remote_user}' via '{$this->clientIp}' requested mail {$log->qid}");
		$this->respond($response);
	}
}
