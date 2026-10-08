<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Api;

use App\Core\Exception\FuzzyError;
use App\Core\Exception\FuzzyException;

use App\Models\MailLogFuzzy;

use App\Services\FuzzyService;
use App\Services\MailLogService;

use Symfony\Component\HttpFoundation\Response;

use InvalidArgumentException;
use Throwable;

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
		$node = (string) ($_ENV['MY_API_SERVER_ALIAS'] ?? '');

		$remote_user = (string) ($post['remote_user'] ?? '');
		$action = (string) ($post['action'] ?? '');
		$id = intval($post['id'] ?? 0);
		$flag = intval($post['flag'] ?? 0);
		// optional: the list's weight from config when not given
		$weight_param = (string) ($post['weight'] ?? '');
		$weight = $weight_param === '' ? null : (ctype_digit($weight_param) ? (int) $weight_param : 0);

		if ($remote_user === '' || !in_array($action, ['learn', 'unlearn', 'boost'], true) || $id < 1
			|| ($action === 'learn' && $flag < 1)
			|| ($action === 'boost' && $weight === null)
			|| ($weight !== null && ($weight < 1 || $weight > 65535))) {
			$err_msg = "{$this->clientIp} requested FuzzyMailApi on '{$node}' with missing or invalid user, action, id, flag or weight";
			$this->dropLogResponse(
				Response::HTTP_BAD_REQUEST, "Missing Required info",
				$err_msg, 'critical');
		}

		if (!FuzzyService::isEnabled()) {
			$err_msg = "{$remote_user} via {$this->clientIp} requested fuzzy {$action} of id {$id} on '{$node}' but fuzzy learning is disabled";
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
				$this->logRequest($remote_user, $action, (string) $maillog->qid, $node);
				$fuzzy->learnLocal($maillog, $flag, $remote_user, $weight);
			} else {
				$row = MailLogFuzzy::find($id);
				if ($row === null) {
					throw new InvalidArgumentException("Fuzzy record {$id} not found");
				}
				$this->logRequest($remote_user, $action, (string) $row->qid, $node);
				if ($action === 'boost') {
					$fuzzy->addWeightLocal($row, $weight, $remote_user);
				} else {
					$fuzzy->unlearnLocal($row, $remote_user);
				}
			}
		} catch (InvalidArgumentException $e) {
			$err_msg = "{$remote_user} via {$this->clientIp} requested fuzzy {$action} of id {$id} on '{$node}': " . $e->getMessage();
			$this->dropLogResponse(
				Response::HTTP_UNPROCESSABLE_ENTITY, "Message not found",
				$err_msg, 'warning');
		} catch (FuzzyException $e) {
			$err_msg = "{$remote_user} via {$this->clientIp} fuzzy {$action} of id {$id} on '{$node}' failed: " . $e->getMessage();
			$status = match ($e->error) {
				FuzzyError::Conflict => Response::HTTP_CONFLICT,
				FuzzyError::Unavailable => Response::HTTP_NOT_IMPLEMENTED,
				FuzzyError::Upstream => Response::HTTP_BAD_GATEWAY,
				FuzzyError::Internal => Response::HTTP_INTERNAL_SERVER_ERROR,
			};
			$this->dropLogResponse($status, $e->getMessage(), $err_msg, 'error');
		} catch (Throwable $e) {
			// the real error stays in the log: it may carry SQL
			$err_msg = "{$remote_user} via {$this->clientIp} fuzzy {$action} of id {$id} on '{$node}' failed: " . $e->getMessage();
			$this->dropLogResponse(
				Response::HTTP_INTERNAL_SERVER_ERROR, "Internal error",
				$err_msg, 'error');
		}

		$response = new Response();
		$response->setContent("Message {$action}ed");
		$response->setCharset('UTF-8');
		$response->setStatusCode(Response::HTTP_OK);
		$response->prepare($this->request);
		$response->send();
		exit;
	}

	// logged before the work, so the service's result line follows it
	private function logRequest(string $remote_user, string $action, string $qid, string $node): void {
		$msg = "[{$this->logPrefix}] '{$remote_user}' via '{$this->clientIp}' requested fuzzy {$action} of mail {$qid} on '{$node}'";
		$this->fileLogger->info($msg);
		$this->syslogLogger->info($msg);
	}

}
