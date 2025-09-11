<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Api;

use App\Core\Config;
use App\Utils\Helper;

use App\Core\Auth\BasicAuth;

use App\Models\MailLog;
use App\Services\MailLogService;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ReleaseMailApi extends RqwatchApi
{
	protected string $logPrefix = 'ReleaseMailApi';

	protected function getAllowedIps(): array {
		return array_map('trim', explode(',', $_ENV['WEB_API_ACL']));
	}

	protected function getAuthCredentials(): array {
		return [$_ENV['WEB_API_USER'], $_ENV['WEB_API_PASS']];
	}

	public function handle(): void {
		$post = $this->request->request->all();

		if (!array_key_exists('id', $post)) {
			$err_msg = "{$this->clientIp} requested mail release without a mail id";
			$response_msg = "Missing Required info";
			$this->dropLogResponse(
				Response::HTTP_BAD_REQUEST, $response_msg,
				$err_msg, 'critical');
		}
		$id = $post['id'];
		
		if (!array_key_exists('local_user', $post)) {
			$err_msg = "{$this->clientIp} requested mail release of mail with id {$id} without a calling user email";
			$response_msg = "Missing Required info";
			$this->dropLogResponse(
				Response::HTTP_BAD_REQUEST, $response_msg,
				$err_msg, 'critical');
		}
		$remote_user = $post['local_user'];

		if (!array_key_exists('email', $post)) {
			$err_msg = "{$this->clientIp} requested mail release of mail with id {$id} without a destination email";
			$response_msg = "Missing Required info";
			$this->dropLogResponse(
				Response::HTTP_BAD_REQUEST, $response_msg,
				$err_msg, 'critical');
		}
		$release_to = $post['email'];
		
		$maillog = MailLog::find($id);
		
		if (empty($maillog)) {
			$err_msg = "{$remote_user} via {$this->clientIp} requested release of mail with id {$id} which does no exist";
			$response_msg = "Message not found";
			$this->dropLogResponse(
				Response::HTTP_BAD_REQUEST, $response_msg,
				$err_msg, 'warning');
		}
		
		$service = new MailLogService($this->fileLogger);
		
		if ($service->releaseHtmlMail($release_to, $maillog)) {
			$runtime = $this->getRuntime();
			$msg = "Message {$maillog->qid} released to '" .
				implode(', ', $release_to) .
				"' via {$this->logPrefix} from '{$remote_user}' by '{$this->clientIp}' | {$runtime}";
		
			$this->fileLogger->info($msg);
			$this->syslogLogger->info($msg);
		
			$response = new Response();
			$response->setContent('Message Released');
			$response->setCharset('UTF-8');
			$response->setStatusCode(Response::HTTP_OK);
			$response->prepare($this->request);
			$response->send();
			exit;
		}
		
		// we're here only if send mail failed
		$runtime = $this->getRuntime();
		$err_msg = "Message {$maillog->qid} failed to be released via API by {$this->clientIp} | {$runtime}";
		$response_msg = "Message Release Failed";
			$this->dropLogResponse(
				Response::HTTP_INTERNAL_SERVER_ERROR, $response_msg,
				$err_msg, 'error');
		
		exit;
	}
}
