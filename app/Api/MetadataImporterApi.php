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
use App\Core\Auth\BasicAuth;

use App\Models\MailLog;

use Illuminate\Database\Capsule\Manager as Capsule;

use Illuminate\Database\QueryException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use PhpMimeMailParser\Parser;

class MetadataImporterApi extends RqwatchApi
{
	protected string $logPrefix = 'MetadataImporterApi';
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
			$headers = $this->request->headers->all(); // array of lowercased header names
			$rawEmail = $this->request->getContent(); // instead of php://input
		} catch (\Throwable $e) {
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
		
		$web_headers = getallheaders();
		
		$qid      = @$web_headers['X-Rspamd-Qid'];
		$fuzzy    = @$web_headers['X-Rspamd-Fuzzy'];
		//$subject_old  = iconv_mime_decode($web_headers['X-Rspamd-Subject'], 2);
		$subject  = mb_decode_mimeheader($web_headers['X-Rspamd-Subject']);
		$score    = @$web_headers['X-Rspamd-Score'];
		$rcpt_to  = @$web_headers['X-Rspamd-Rcpt'];
		$user     = @$web_headers['X-Rspamd-User'];
		$ip       = @$web_headers['X-Rspamd-Ip'];
		$action   = @$web_headers['X-Rspamd-Action'];
		$mail_from= @$web_headers['X-Rspamd-From'];
		$symbols  = @$web_headers['X-Rspamd-Symbols'];
		$size     = (int)@$web_headers['X-Rspamd-Size'];
		
		//$request_size = (int)$_SERVER['CONTENT_LENGTH'];
		
		if (empty($qid) and empty($score) and empty($action)) {
			$this->fileLogger->error("qid, score and action missing");
			$msg = "qid, score and action missing";
			$this->dropLogResponse(
				Response::HTTP_BAD_REQUEST, $msg,
				$msg, 'critical');
		}
		
		$server = $this->request->query->get('server', '');
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
		if (empty($qid) or $qid == "unknown") {
			if (isset($arrayHeaders['x-rspamd-queue-id']))
				$qid = $arrayHeaders['x-rspamd-queue-id'];
				if (!preg_match('/^[a-zA-Z0-9]+$/', $qid)) {
					$qid = "unknown";
				}
		}
		
		$mail_stored = 0;
		$mail_location = NULL;
		$store_settings = Config::get('store_settings');
		
		if (!empty($action) and !empty($store_settings[$action])) {
			if ($mail_location = Helper::store_raw_mail($_ENV['QUARANTINE_DIR'], $qid)) {
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
		
		if ($fuzzy == 'unknown') {
			$fuzzy = '[]';
		}
		
		// check for antivirus symbol
		if (Helper::check_virus_from_all(json_decode($symbols, true))) {
			$has_virus = 1;
		} else {
			$has_virus = 0;
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
			'mail_from' => strtolower($mail_from),
			'mime_from' => $mime_from,
			'rcpt_to' => ($rcpt_to == "unknown") ? "unknown" : strtolower(implode(', ', json_decode($rcpt_to, true))),
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

		// Clean invalid UTF-8 bytes — drop or replace them safely
		$headersText = mb_convert_encoding($headersText, 'UTF-8', 'UTF-8'); 
		$headersText = iconv('UTF-8', 'UTF-8//IGNORE', $headersText);

		$data['headers'] = $headersText;
		*/

		try {
			$db_id = $this->capsule::table($_ENV['MAILLOGS_TABLE'])
				->insertGetId($data);
			// This also works but uses 10MB instead of 5MB
			//$db_id = MailLog::create($data)->id;
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
		} catch (\Exception $e) {
				$err_msg = "DB insert error: " . $e->getMessage();
				$response_msg = "Unexpected error";
				$this->dropLogResponse(
					Response::HTTP_INTERNAL_SERVER_ERROR, $response_msg,
					$err_msg, 'critical');
		}
		
		if (Config::get('log_to_files') && ($dir = Config::get('log_to_files_dir'))) {
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
