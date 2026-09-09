<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Api;

use App\Configuration\Config;

use App\Core\App;

use App\Utils\Helper;
use Psr\Log\LoggerInterface;

use App\Models\MailLog;

use App\Services\Import\MailLogWriter;
use App\Services\Import\MailLogSpool;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use PhpMimeMailParser\Parser;

use Illuminate\Database\QueryException;
use PDOException;

use Exception;
use Throwable;

class MetadataImporterMultipartApi extends RqwatchApi
{
	protected string $logPrefix = 'MetadataImporterMultipartApi';

	/*
	// this constructor overrides RqwatchApi constructor
	public function __construct(Request $request) {
			parent::__construct($request);
	}
	*/

	#[\Override]
	protected function getAllowedIps(): array {
		return array_values(array_filter(
			array_map('trim', explode(',', (string)($_ENV['RSPAMD_API_ACL'] ?? '')))
		));
	}

	#[\Override]
	protected function getAuthCredentials(): array {
		return [$_ENV['RSPAMD_API_USER'], $_ENV['RSPAMD_API_PASS']];
	}

	#[\Override]
	public function handle(): void {

		try {
			$contentType = (string) $this->request->headers->get('Content-Type', '');

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
			if ($rawEmail === '') {
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

		// $meta comes from an external JSON body. A non-scalar where we expect
		// a scalar would corrupt the row or throw on a typed parameter
		// (store_raw_mail). Treat it as absent rather than dropping the mail.
		foreach (['qid', 'score', 'action', 'ip', 'from', 'subject', 'size'] as $k) {
			if (isset($meta[$k]) && !is_scalar($meta[$k])) {
				$this->fileLogger->warning(
					"[{$this->logPrefix}] non-scalar value for metadata field '{$k}'; ignoring it"
				);
				$meta[$k] = null;
			}
		}

		mb_internal_encoding('UTF-8');
		
		$qid       = $meta['qid'] ?? null;
		$score     = $meta['score'] ?? null;
		$action    = $meta['action'] ?? null;
		$ip        = $meta['ip'] ?? null;
		$mail_from = $meta['from'] ?? null;
		$subject   = $meta['subject'] ?? null;
		$size      = (isset($meta['size']) && is_numeric($meta['size']))
			? (int)$meta['size']
			: null;

		$scoreMissing = !isset($score) || !is_numeric($score);

		if (empty($qid) && $scoreMissing && empty($action)) {
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

		// Parse the raw message only after metadata validation has passed, so
		// a rejected request does not pay for the MIME parse. Body comes from
		// the uploaded 'message' part - php://input is not readable for
		// multipart/form-data requests.
		$parser = new Parser();
		$parser->setText($rawEmail);
		
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

				// duplicated headers arrive as an array; rspamd appends its
				// own last, so prefer that one
				if (is_array($qid)) {
					$qid = end($qid);
				}

				if (!is_string($qid) || !preg_match('/^[a-zA-Z0-9]+$/', $qid)) {
					$qid = "unknown";
				}
			} else {
				// no metadata qid and no header
				$qid = "unknown";
			}
		}

		// the is_scalar() guard above nulls only non-scalars
		// an int, float or bool qid would hit store_raw_mail()'s `string $qid`
		// as an uncaught TypeError, outside the insert try/catch
		$qid = (string) $qid;

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
			if ($mail_location = Helper::store_raw_mail((string) ($_ENV['QUARANTINE_DIR'] ?? ''), $qid, $rawEmail)) {
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
		$spooled = false;
		$db_failed = false;
		$ok_msg = 'Message saved';
		$fail_msg = 'Error storing message in DB';

		/*
		 A degraded boot has no database at all, so there is nothing to
		 try: MailLogWriter's constructor calls App::capsule(), which
		 throws, and that would land in the Throwable branch below - the
		 one that deliberately does not spool. Skip straight to the spool
		 and let the tail answer 200.
		*/
		if (!App::dbAvailable()) {
			$this->fileLogger->critical("[{$this->logPrefix}] {$qid} no database connection, spooling");
			$this->syslogLogger->critical("{$qid} no database connection, spooling");

			$fail_msg = 'Database unavailable and spool failed';
			$db_failed = true;
		} else {
			try {
				$mailLogWriter = new MailLogWriter();
				// does both insertMailLog and insertMailRecipients
				// to both tables if migration is completed
				$db_id = $mailLogWriter->insert($data, $rcptArr);
			} catch (QueryException | PDOException $e) {
				// $bindings = $e->getBindings(); // array
				// $sql = $e->getSql(); // array
				// $e->getMessage() // very verbose

				/*
				 Logged here rather than left to dropLogResponse below,
				 because on the spool path we never get there and the
				 SQLSTATE is the only record of why the mail was spooled.
				*/
				$pdoMessage = $e->getPrevious()?->getMessage() ?? $e->getMessage();
				$this->fileLogger->critical("[{$this->logPrefix}] {$qid} DB error: {$pdoMessage}");
				$this->syslogLogger->critical("{$qid} DB error: {$pdoMessage}");

				$fail_msg = 'Database error. Please try again later';
				$db_failed = true;
			} catch (Throwable $e) {
				$this->fileLogger->critical("[{$this->logPrefix}] {$qid} DB insert error: " . $e->getMessage());
				$this->syslogLogger->critical("{$qid} DB insert error: " . $e->getMessage());
				$fail_msg = 'Unexpected error';
			}
		}

		/*
		 Only a database failure is spooled. Anything else reaching the
		 Throwable branch is a bug or a schema mismatch, and drain() stops
		 the whole pass on its first failure -- so an entry that throws
		 every time would block the spool for every mail behind it.
		 Database unavailability always arrives as QueryException or
		 PDOException: Connection::run() wraps statement and connect
		 errors, and a commit aborted by Galera throws PDOException raw.
		*/
		if ($db_failed) {
			$spooled = (new MailLogSpool())->push($data, $rcptArr);
		}
		
		if (Config::get('log_to_files') && ($dir = Config::get('log_to_files_dir'))) {
			$web_headers = getallheaders();
			Helper::log_to_files($dir, $symbols, $web_headers, $stringHeaders, $arrayHeaders);
		}
		
		$runtime = $this->getRuntime();
		if ($db_id) {
			$score = number_format((float)$score, 2);
			$this->syslogLogger->info("$qid score: {$score} '$action' saved in DB [id: $db_id] by {$this->logPrefix} | $runtime");
		} elseif ($spooled) {
			/*
			 MailLogSpool has already logged the qid and the key to both
			 logs. Answer 200 because we have taken responsibility for the
			 mail: rspamd's metadata_exporter fires once per scan and does
			 not resend, so a 5xx here would simply lose the metadata. The
			 raw file must survive for cron:import_spool, so no
			 discard_raw_mail() here.
			*/
			$ok_msg = 'Message spooled';
		} else {
			/*
			 Close the trail: syslog already says "stored in quarantine"
			 for a file that is about to go, so without this the last
			 word on the mail is misleading. False means there was
			 nothing stored, so nothing to report.
			*/
			if (Helper::discard_raw_mail($mail_location)) {
				$this->fileLogger->info("[{$this->logPrefix}] {$qid} removed from quarantine: {$mail_location}");
				$this->syslogLogger->info("{$qid} removed from quarantine: {$mail_location}");
			}

			$err_msg = "Error storing $qid in DB by {$this->logPrefix}. Check PHP/rspamd logs | $runtime";
			$this->dropLogResponse(
				Response::HTTP_INTERNAL_SERVER_ERROR, $fail_msg,
				$err_msg, 'critical');
		}
		
		$response = new Response();
		$response->setContent($ok_msg);
		$response->setCharset('UTF-8');
		$response->headers->set('Content-Type', 'text/plain');
		$response->setStatusCode(Response::HTTP_OK);
		$response->send();

		exit;
	}
}
