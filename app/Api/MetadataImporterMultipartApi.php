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
use Psr\Log\LoggerInterface;

use App\Models\MailLog;

use Illuminate\Database\Capsule\Manager as Capsule;

use Illuminate\Database\QueryException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use PhpMimeMailParser\Parser;

use Exception;
use Throwable;

class MetadataImporterMultipartApi extends RqwatchApi
{
	protected string $logPrefix = 'MetadataImporterMultipartApi';
	protected Capsule $capsule;

	// this constructor overrides RqwatchApi contructor
	public function __construct(
		Request $request,
		LoggerInterface $fileLogger,
		LoggerInterface $syslogLogger,
		float $startTime,
		float $startMemory,
		Capsule $capsule
	) {
			parent::__construct(
				$request,
				$fileLogger,
				$syslogLogger,
				$startTime,
				$startMemory
			);

			$this->capsule = $capsule;
	}

	protected function getAllowedIps(): array {
		return array_map('trim', explode(',', $_ENV['RSPAMD_API_ACL']));
	}

	protected function getAuthCredentials(): array {
		return [$_ENV['RSPAMD_API_USER'], $_ENV['RSPAMD_API_PASS']];
	}

	public function handle(): void {

		try {
			$contentType = (string) $this->request->headers->get('Content-Type', '');

			$meta = [];
			$rawEmail = '';

			if (stripos($contentType, 'multipart/form-data') === false) {
				throw new \RuntimeException('Missing multipart/form-data content type');
			}

			// 1) metadata JSON (string field)
			$metadataJson = (string) $this->request->request->get('metadata', '');
			if ($metadataJson === '') {
				throw new \RuntimeException('Missing multipart field: metadata');
			}

			$meta = json_decode($metadataJson, true, 512, JSON_THROW_ON_ERROR);
			if (!is_array($meta)) {
				throw new \RuntimeException('Invalid metadata JSON');
			}

			// 2) message (file upload)
			$msgFile = $this->request->files->get('message');
			if (!$msgFile) {
				throw new \RuntimeException('Missing multipart file: message');
			}
			if ($msgFile->getError() !== UPLOAD_ERR_OK) {
				throw new \RuntimeException('Upload error for message: ' . $msgFile->getError());
			}

			$rawEmail = (string) file_get_contents($msgFile->getPathname());
			if ($rawEmail === false || $rawEmail === '') {
				throw new \RuntimeException('Empty or unreadable message content');
			}
		} catch (Throwable $e) {
			$this->fileLogger->error("[{$this->logPrefix}] Request parse error: " . $e->getMessage(), [
				'trace' => $e->getTraceAsString(),
			]);
			$this->dropLogResponse(
				Response::HTTP_BAD_REQUEST,
				"Invalid request (parse error)",
				$e->getMessage(),
				'critical'
			);
		}

		$parser = new Parser();
		if (!empty($rawEmail)) {
			$parser->setText($rawEmail);
		}
		
		mb_internal_encoding('UTF-8');
		
		$qid       = $meta['qid'] ?? null;
		$score     = $meta['score'] ?? null;
		$action    = $meta['action'] ?? null;

		if (empty($qid) && empty($score) && empty($action)) {
			$this->fileLogger->error("qid, score and action missing");
			$msg = "qid, score and action missing";
			$this->dropLogResponse(
				Response::HTTP_BAD_REQUEST, $msg,
				$msg, 'critical');
		}
		
		$server = $this->request->query->get('server', '');
		$fuzzy  = $meta['fuzzy'] ?? null;

		if (is_array($fuzzy)) {
			$fuzzy = json_encode($fuzzy, JSON_UNESCAPED_UNICODE);
		} elseif ($fuzzy === 'unknown' || $fuzzy === null || $fuzzy === '') {
			$fuzzy = '[]';
		}

		$ip        = $meta['ip'] ?? null;
		$mail_from = $meta['from'] ?? null;
		$subject   = $meta['subject'] ?? null;
		$size      = isset($meta['size']) ? (int)$meta['size'] : null;

		$rcptArr = [];
		if (isset($meta['rcpt'])) {
			if (is_array($meta['rcpt'])) {
				$rcptArr = $meta['rcpt'];
			} elseif (is_string($meta['rcpt']) && $meta['rcpt'] !== '' && $meta['rcpt'] !== 'unknown') {
				$rcptArr = [$meta['rcpt']];
			}
		}

		$rcptArr = array_values(array_filter(array_map(
			fn($v) => strtolower(trim((string)$v)),
			$rcptArr
		)));

		$symbols   = isset($meta['symbols']) ? json_encode($meta['symbols']) : '[]';
		
		$server = Helper::sanitize_string($server);
		
		// moved to HttpFoundation
		// $parser = new Parser();
		// $parser->setStream(fopen("php://input", "r"));
		
		// return all headers as a string, no charset conversion
		$stringHeaders = trim($parser->getHeadersRaw());
		
		$mime_from = $parser->getHeader('from');
		$mime_to = $parser->getHeader('to');
		$mime_subject = $parser->getHeader('subject');
		$message_id = $parser->getHeader('message-id');
		
		// return all headers as an array, with charset conversion
		$arrayHeaders = $parser->getHeaders();
		if (empty($qid) || $qid === "unknown") {
			if (isset($arrayHeaders['x-rspamd-queue-id'])) {
				$qid = $arrayHeaders['x-rspamd-queue-id'];
				if (!preg_match('/^[a-zA-Z0-9]+$/', $qid)) {
					$qid = "unknown";
				}
			}
		}

		// check for antivirus symbol
		$symbolsArr = json_decode($symbols, true) ?: [];
		if (Helper::check_virus_from_all($symbolsArr)) {
			$has_virus = 1;
		} else {
			$has_virus = 0;
		}

		$mail_stored = 0;
		$mail_location = null;
		$store_settings = Config::get('store_settings');
		
		if ((!empty($action) && !empty($store_settings[$action])) || $has_virus) {
			if ($mail_location = Helper::store_raw_mail($_ENV['QUARANTINE_DIR'], $qid, $rawEmail)) {
				$this->syslogLogger->info("$qid stored in quarantine: $mail_location");
				$mail_stored = 1;
			} else {
				$this->fileLogger->error("Error storing $qid in quarantine. Check PHP logs");
				$this->syslogLogger->error("Error storing $qid in quarantine. Check PHP logs");
			}
		}
		
		if (empty($mail_from)) {
			$this->fileLogger->warning("Unknown envelope address, using empty-mail-from@localhost", [
				'qid' => $qid,
			]);
			$mail_from = 'empty-mail-from@localhost';
		}
		
		if ($fuzzy === 'unknown') {
			$fuzzy = '[]';
		}
		
		$data = array(
			'qid' => $qid,
			'server' => $server,
			// prefer subject from mime. then by rspamd
			'subject' => !empty($mime_subject) ? $mime_subject : $subject,
			'score' => $score,
			'action' => $action,
			'symbols' => $symbols,
			'has_virus' => $has_virus,
			'fuzzy_hashes' => $fuzzy,
			'ip' => $ip,
			'mail_from' => strtolower((string)$mail_from),
			'mime_from' => $mime_from,
			'rcpt_to' => empty($rcptArr) ? 'unknown' : implode(', ', $rcptArr),
			'mime_to' => $mime_to ? mb_strimwidth($mime_to, 0, 250, "!!") : '',
			'mail_stored' => $mail_stored,
			'mail_location' => $mail_location,
			'size' => $size,
			//'headers' => $stringHeaders,
			'message_id' => $message_id,
		);

		[$data, $debug] = Helper::trimDataToDbLimits($data, MailLog::FIELD_LIMITS);

		if (!empty($debug)) {
			foreach ($debug as $debug_msg) {
				$this->fileLogger->warning("[{$this->logPrefix}] {$qid}: {$debug_msg}");
			}
		}

		// Try to detect the most likely encoding
		$enc = mb_detect_encoding(
			$stringHeaders,
				[
					'UTF-8',
					'ISO-8859-1',
					'ISO-8859-7',
					'Windows-1251',
					'Windows-1252',
					'KOI8-R',
					'ASCII'
				],
				true
		);

		if (!$enc) {
			// unknown or mixed encodings: clean non-UTF-8 bytes
			$data['headers'] = iconv('UTF-8', 'UTF-8//IGNORE', $stringHeaders);
			$detected = 'unknown';
		} elseif ($enc !== 'UTF-8') {
			// Convert to UTF-8
			$data['headers'] = mb_convert_encoding($stringHeaders, 'UTF-8', $enc);
			$detected = $enc;
		} else {
			// Already UTF-8
			$data['headers'] = $stringHeaders;
			$detected = 'UTF-8';
		}
		
		$this->fileLogger->debug("{$qid}: Header encoding detected: {$detected}");

		/* get headers from array with char convertion
		$headersText = '';

		foreach ($arrayHeaders as $name => $value) {
			if (is_array($value)) {
				foreach ($value as $v) {
					$headersText .= $name . ': ' . $v . "\r\n";
				}
			} else {
				$headersText .= $name . ': ' . $value . "\r\n";
			}
		}

		// Clean invalid UTF-8 bytes - drop or replace them safely
		$headersText = mb_convert_encoding($headersText, 'UTF-8', 'UTF-8'); 
		$headersText = iconv('UTF-8', 'UTF-8//IGNORE', $headersText);

		$data['headers'] = $headersText;
		*/

		$db_id = null;
		try {
			$this->capsule::connection()->transaction(function () use ($data, $rcptArr, &$db_id) {

				$db_id = $this->capsule::table($_ENV['MAILLOGS_TABLE'])
					->insertGetId($data);

				if ($db_id && !empty($rcptArr)) {
					$recipients = array_unique($rcptArr);

					$rows = [];

					foreach ($recipients as $email) {
						if ($email !== '') {
							$rows[] = [
								'mail_log_id'     => $db_id,
								'recipient_email' => $email,
							];
						}
					}

					if (!empty($rows)) {
						$this->capsule::table($_ENV['MAIL_RECIPIENTS_TABLE'])
							->insert($rows);
					}
				}
			});
		} catch (QueryException $e) {
				// $bindings = $e->getBindings(); // array
				// $sql = $e->getSql(); // array
				// $e->getMessage() // very verbose

				// XXX We could cache failed inserts in Redis and retry later via cron

				$pdoMessage = $e->getPrevious()?->getMessage() ?? 'Unknown database error';
				$err_msg = "{$qid} DB error: {$pdoMessage}";
				$response_msg = "Database error. Please try again later";
				$this->dropLogResponse(
					Response::HTTP_INTERNAL_SERVER_ERROR, $response_msg,
					$err_msg, 'critical');
		} catch (Exception $e) {
				$err_msg = "DB insert error: " . $e->getMessage();
				$response_msg = "Unexpected error";
				$this->dropLogResponse(
					Response::HTTP_INTERNAL_SERVER_ERROR, $response_msg,
					$err_msg, 'critical');
		}
		
		if (Config::get('log_to_files') && ($dir = Config::get('log_to_files_dir'))) {
			$web_headers = getallheaders();
			Helper::log_to_files($dir, $symbols, $web_headers, $stringHeaders, $arrayHeaders);
		}
		
		$runtime = $this->getRuntime();
		if ($db_id) {
			$score = number_format((float)$score, 2);
			$this->syslogLogger->info("$qid score: {$score} '$action' saved in DB [id: $db_id] by {$this->logPrefix} | $runtime");
		} else {
			$err_msg = "Error storing $qid in DB by {$this->logPrefix}. Check PHP/rspamd logs | $runtime";
			$response_msg = "Error storing message in DB";
			$this->dropLogResponse(
				Response::HTTP_INTERNAL_SERVER_ERROR, $response_msg,
				$err_msg, 'critical');
		}
		
		$response = new Response();
		$response->setContent('Message saved');
		$response->setCharset('UTF-8');
		$response->headers->set('Content-Type', 'text/plain');
		$response->setStatusCode(Response::HTTP_OK);
		$response->send();

		exit;
	}
}
