<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Api;

use App\Models\MailLogFuzzy;

use App\Services\FuzzyService;
use App\Services\MailLogService;

use Symfony\Component\HttpFoundation\Response;

use InvalidArgumentException;
use RuntimeException;

class FuzzyMailApi extends RqwatchApi
{
	protected string $logPrefix = 'FuzzyMailApi';

	#[\Override]
	protected function getAllowedIps(): array {
		return array_values(array_filter(
			array_map('trim', explode(',', (string)($_ENV['MAIL_API_ACL'] ?? '')))
		));
	}

	#[\Override]
	protected function getAuthCredentials(): array {
		return [$_ENV['MAIL_API_USER'], $_ENV['MAIL_API_PASS']];
	}

	#[\Override]
	public function handle(): void {
		$post = $this->request->request->all();

		$remote_user = (string) ($post['remote_user'] ?? '');
		$action = (string) ($post['action'] ?? '');
		$id = intval($post['id'] ?? 0);

		if ($remote_user === '' || !in_array($action, ['learn', 'unlearn'], true) || $id < 1) {
			$err_msg = "{$this->clientIp} requested FuzzyMailApi with missing or invalid user, action or id";
			$this->dropLogResponse(
				Response::HTTP_BAD_REQUEST, "Missing Required info",
				$err_msg, 'critical');
		}

		if (!FuzzyService::isEnabled()) {
			$err_msg = "{$remote_user} via {$this->clientIp} requested fuzzy {$action} of id {$id} but fuzzy learning is disabled";
			// not 403: the caller reads 403 as an auth or ACL problem
			$this->dropLogResponse(
				Response::HTTP_NOT_IMPLEMENTED, "Fuzzy learning is disabled on " . ($_ENV['MY_API_SERVER_ALIAS'] ?? ''),
				$err_msg, 'warning');
		}

		$fuzzy = new FuzzyService();

		try {
			if ($action === 'learn') {
				// findMailLog throws InvalidArgumentException when no mail found
				$maillog = (new MailLogService())->findMailLog($id);
				$fuzzy->learnLocal($maillog, $remote_user);
				$qid = $maillog->qid;
			} else {
				$row = MailLogFuzzy::find($id);
				if ($row === null) {
					throw new InvalidArgumentException("Fuzzy record {$id} not found");
				}
				$fuzzy->unlearnLocal($row, $remote_user);
				$qid = $row->qid;
			}
		} catch (InvalidArgumentException $e) {
			$err_msg = "{$remote_user} via {$this->clientIp} requested fuzzy {$action} of id {$id}: " . $e->getMessage();
			$this->dropLogResponse(
				Response::HTTP_UNPROCESSABLE_ENTITY, "Message not found",
				$err_msg, 'warning');
		} catch (RuntimeException $e) {
			$err_msg = "{$remote_user} via {$this->clientIp} fuzzy {$action} of id {$id} failed: " . $e->getMessage();
			// FuzzyService sets the status as the exception code
			$code = $e->getCode();
			$this->dropLogResponse(
				($code >= 400 && $code <= 599) ? $code : Response::HTTP_INTERNAL_SERVER_ERROR, $e->getMessage(),
				$err_msg, 'error');
		}

		$this->fileLogger->info("[{$this->logPrefix}] {$qid} fuzzy {$action} from '{$remote_user}' by '{$this->clientIp}' | " . $this->getRuntime());

		$response = new Response();
		$response->setContent("Message {$action}ed");
		$response->setCharset('UTF-8');
		$response->setStatusCode(Response::HTTP_OK);
		$response->prepare($this->request);
		$response->send();
		exit;
	}
}
