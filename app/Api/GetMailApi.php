<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Api;

use App\Models\MailLog;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class GetMailApi extends RqwatchApi
{
	protected string $logPrefix = 'GetMailApi';

	protected function getAllowedIps(): array {
		return array_map('trim', explode(',', $_ENV['MAIL_API_ACL']));
	}

	protected function getAuthCredentials(): array {
		return [$_ENV['MAIL_API_USER'], $_ENV['MAIL_API_PASS']];
	}

	public function handle(): void {
		$id = $this->request->request->get('id');
		$remote_user = $this->request->request->get('remote_user');

		if (empty($remote_user)) {
			$err_msg = "{$this->clientIp} requested mail without a calling user email";
			$response_msg = "Missing Required info";
			$this->dropLogResponse(
				Response::HTTP_BAD_REQUEST, $response_msg,
				$err_msg, 'critical');
		}

		if (empty($id)) {
			$err_msg = "{$remote_user} via {$this->clientIp} requested mail without a mail id";
			$response_msg = "Missing Required info";
			$this->dropLogResponse(
				Response::HTTP_BAD_REQUEST, $response_msg,
				$err_msg, 'critical');
		}
		
		$log = MailLog::find($id);

		if (empty($log)) {
			$err_msg = "{$remote_user} via {$this->clientIp} requested mail with id {$id} which does no exist";
			$response_msg = "Message not found";
			$this->dropLogResponse(
				Response::HTTP_BAD_REQUEST, $response_msg,
				$err_msg, 'warning');
		}

		if (!file_exists($log->mail_location)) {
			$err_msg = "{$remote_user} via {$this->clientIp} requested mail {$log->qid} where local file '{$log->mail_location}' not found";
			$response_msg = "File '{$log->mail_location}' not found";
			$this->dropLogResponse(
				Response::HTTP_BAD_REQUEST, $response_msg,
				$err_msg, 'critical');
		}

		// serve static file 
		$response = new BinaryFileResponse($log->mail_location);
		$response->setStatusCode(Response::HTTP_OK);

		$this->fileLogger->info("[{$this->logPrefix}] '{$remote_user}' via '{$this->clientIp}' requested mail {$log->qid}");
		$this->syslogLogger->info("[{$this->logPrefix}] '{$remote_user}' via '{$this->clientIp}' requested mail {$log->qid}");
		$response->send();
		exit;
	}
}
