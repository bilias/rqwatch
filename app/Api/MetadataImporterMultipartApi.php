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

use App\Models\MailLog;

use App\Services\Import\MailLogWriter;
use App\Services\Import\MailLogSpool;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\File\UploadedFile;

use PhpMimeMailParser\Parser;

use Illuminate\Database\QueryException;
use PDOException;
use RuntimeException;

use Throwable;

final class MetadataImporterMultipartApi extends RqwatchApi
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
			array_map('trim', explode(',', Helper::env_string('RSPAMD_API_ACL') ?? ''))
		));
	}

	#[\Override]
	protected function getAuthCredentials(): array {
		return [Helper::env_string('RSPAMD_API_USER'), Helper::env_string('RSPAMD_API_PASS')];
	}

	// config.php's $store_settings, for a configured one that is not an array
	private const array DEFAULT_STORE_SETTINGS = [
		'no action'       => false,
		'add header'      => true,
		'rewrite subject' => true,
		'greylist'        => false,
		'discard'         => true,
		'reject'          => true,
	];

	// mail_logs and mail_log_data columns holding the sender's free text: left
	// out when the database rejects a mail, to keep the rest of its record
	private const array FREE_TEXT_COLUMNS = [
		'subject', 'mime_from', 'mime_to', 'message_id', 'headers', 'symbols', 'fuzzy_hashes',
	];

	/**
	 * Stores the raw mail in the quarantine; null, logged, when there is
	 * none or it cannot be stored, and the metadata is imported without it
	 */
	private function storeRawMail(string $qid, string $rawEmail): ?string {
		if ($rawEmail === '') {
			$this->fileLogger->error("[{$this->logPrefix}] {$qid}: to be stored in quarantine, but there is no message");
			return null;
		}
		$location = Helper::store_raw_mail(Helper::env_string('QUARANTINE_DIR') ?? '', $qid, $rawEmail);
		if ($location === null) {
			$this->fileLogger->error("Error storing $qid in quarantine. Check PHP logs");
			$this->syslogLogger->error("Error storing $qid in quarantine. Check PHP logs");
			return null;
		}
		// the column would cut a longer path: a row pointing nowhere
		if (strlen($location) > MailLog::FIELD_LIMITS['mail_location']) {
			Helper::discard_raw_mail($location);
			$this->fileLogger->error("[{$this->logPrefix}] {$qid}: quarantine path longer than "
				. MailLog::FIELD_LIMITS['mail_location'] . " bytes, not stored: {$location}");
			return null;
		}
		$this->syslogLogger->info("$qid stored in quarantine: $location");
		return $location;
	}

	/**
	 * Saves the mail's record: inserted; spooled when the database cannot
	 * take any mail now (MailLogWriter::databaseUnavailable()), to be
	 * replayed by cron:import_spool; or, when the database rejected this
	 * mail's data, inserted again without its free text. Only database-wide
	 * failures are spooled: drain() stops at the first entry that fails, so
	 * a mail the database rejects every time would block the spool.
	 * Anything else is thrown.
	 * @param array<string, mixed> $data
	 * @param list<string> $recipients
	 * @return array{?int, bool, string} row id, spooled, the reply if neither
	 */
	private function save(array $data, array $recipients, string $qid): array {
		/*
		 A degraded boot has no database at all, so there is nothing to
		 try: MailLogWriter's constructor calls App::capsule(), which throws.
		*/
		if (!App::dbAvailable()) {
			$this->fileLogger->critical("[{$this->logPrefix}] {$qid} no database connection, spooling");
			$this->syslogLogger->critical("{$qid} no database connection, spooling");
			return [null, (new MailLogSpool())->push($data, $recipients), 'Database unavailable and spool failed'];
		}

		try {
			return [(new MailLogWriter())->insert($data, $recipients), false, ''];
		} catch (QueryException | PDOException $e) {
			// the only record of why the mail was spooled or cut
			$reason = MailLogWriter::describe($e);
			if (MailLogWriter::databaseUnavailable($e)) {
				$this->fileLogger->critical("[{$this->logPrefix}] {$qid} DB error: {$reason}");
				$this->syslogLogger->critical("{$qid} DB error: {$reason}");
				return [null, (new MailLogSpool())->push($data, $recipients), 'Database error. Please try again later'];
			}
			$cut = "{$qid} the database rejected the mail: {$reason}; saving it without "
				. implode(', ', self::FREE_TEXT_COLUMNS);
			$this->fileLogger->critical("[{$this->logPrefix}] {$cut}");
			$this->syslogLogger->critical($cut);
		}

		$id = (new MailLogWriter())->insert(array_merge($data, array_fill_keys(self::FREE_TEXT_COLUMNS, null)), $recipients);
		$this->fileLogger->warning("[{$this->logPrefix}] {$qid} saved without its free text [id: {$id}]");
		return [$id, false, ''];
	}

	/**
	 * A metadata field that must be a single value. $meta comes from an
	 * external JSON body: a non-scalar would corrupt the row or throw on a
	 * typed parameter (store_raw_mail), so it is logged and treated as
	 * absent rather than dropping the mail.
	 * @param array<array-key, mixed> $meta
	 */
	private function metaScalar(array $meta, string $key): int|float|string|bool|null {
		/** @var mixed $value */
		$value = $meta[$key] ?? null;
		if ($value === null || is_scalar($value)) {
			return $value;
		}
		$this->fileLogger->warning(
			"[{$this->logPrefix}] non-scalar value for metadata field '{$key}'; ignoring it"
		);
		return null;
	}

	/**
	 * A metadata field that is text (qid, action, ip, from, subject): a number
	 * is taken in its exact string form, as the text columns stored it;
	 * anything else is logged and treated as absent, as metaScalar() does.
	 * @param array<array-key, mixed> $meta
	 */
	private function metaString(array $meta, string $key): ?string {
		$value = $this->metaScalar($meta, $key);
		if ($value === null || is_string($value)) {
			return $value;
		}
		if (is_int($value) || is_float($value)) {
			return (string) $value;
		}
		$this->fileLogger->warning(
			"[{$this->logPrefix}] metadata field '{$key}' is " . get_debug_type($value) . ", not text; ignoring it"
		);
		return null;
	}

	/**
	 * Charsets the message's MIME parts declare, in part order: what
	 * unencoded 8-bit headers are most likely in. Only names
	 * Helper::mailCharset() accepts; the sender chooses them.
	 * @return list<string>
	 */
	private static function declaredCharsets(Parser $parser): array {
		$charsets = [];
		/** @var array<string, mixed> $part a mailparse part: its header fields by name */
		foreach ($parser->getParts() as $part) {
			// mailparse gives a string; anything else counts as no charset
			/** @var mixed $declared */
			$declared = $part['charset'] ?? null;
			$charset = is_string($declared) ? Helper::mailCharset($declared) : null;
			if ($charset !== null && !in_array($charset, $charsets, true)) {
				$charsets[] = $charset;
			}
		}
		return $charsets;
	}

	/**
	 * A header value as UTF-8; the parser's false (header absent) as is.
	 * @param list<string> $charsets
	 */
	private static function headerToUtf8(string|false $value, array $charsets, string $fallback): string|false {
		return $value === false ? false : Helper::toUtf8($value, $charsets, $fallback);
	}

	#[\Override]
	public function handle(): void {

		// 1) metadata JSON (string field): without it there is nothing to import
		$metadataJson = '';
		try {
			$contentType = (string) $this->request->headers->get('Content-Type', '');

			if (stripos($contentType, 'multipart/form-data') === false) {
				throw new \RuntimeException('Missing multipart/form-data content type');
			}

			$metadataJson = (string) $this->request->request->get('metadata', '');
			if ($metadataJson === '') {
				throw new \RuntimeException('Missing multipart field: metadata');
			}

			$meta = json_decode($metadataJson, true, 512, JSON_THROW_ON_ERROR);
			if (!is_array($meta)) {
				throw new \RuntimeException('Invalid metadata JSON');
			}
		} catch (Throwable $e) {
			// the reason only: the metadata, and a trace's arguments, hold personal data
			$this->fileLogger->error("[{$this->logPrefix}] Request parse error: " . $e->getMessage());
			$this->dropLogResponse(
				Response::HTTP_BAD_REQUEST,
				"Invalid request (parse error)",
				$e->getMessage(),
				'critical'
			);
		}

		// 2) message (file upload): without it the metadata is still imported
		$rawEmail = '';
		$messageError = null;
		try {
			$msgFile = $this->request->files->get('message');
			// one file: message[] gives an array
			if (!$msgFile instanceof UploadedFile) {
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
			$messageError = $e->getMessage();
		}

		// single-value fields: metaScalar() logs and drops anything else
		mb_internal_encoding('UTF-8');
		
		$qid       = $this->metaString($meta, 'qid');
		$score     = $this->metaScalar($meta, 'score');
		$action    = $this->metaString($meta, 'action');
		$ip        = $this->metaString($meta, 'ip');
		$mail_from = $this->metaString($meta, 'from');
		$subject   = $this->metaString($meta, 'subject');
		$size      = $this->metaScalar($meta, 'size');
		$size      = is_numeric($size) ? (int) $size : null;

		$scoreMissing = !isset($score) || !is_numeric($score);

		if (($qid ?? '') === '' && $scoreMissing && ($action ?? '') === '') {
			$this->fileLogger->error("qid, score and action missing");
			$msg = "qid, score and action missing";
			$this->dropLogResponse(
				Response::HTTP_BAD_REQUEST, $msg,
				$msg, 'critical');
		}
		
		$server = $this->request->query->get('server', '');
		/** @var mixed $fuzzyMeta */
		$fuzzyMeta = $meta['fuzzy'] ?? null;

		if (is_array($fuzzyMeta)) {
			$fuzzy = json_encode($fuzzyMeta, JSON_UNESCAPED_UNICODE);
		} else {
			// a list of hashes, or none: anything else is logged and left out
			if (!($fuzzyMeta === 'unknown' || $fuzzyMeta === null || $fuzzyMeta === '')) {
				$this->fileLogger->warning(
					"[{$this->logPrefix}] metadata field 'fuzzy' is not a list of hashes (" . get_debug_type($fuzzyMeta) . "); ignoring it"
				);
			}
			$fuzzy = '[]';
		}

		// rcpt: a list of addresses, or 'unknown' for none; a value that is
		// not text, or is '0', is logged and dropped, the rest kept
		/** @var mixed $rcptMeta */
		$rcptMeta = $meta['rcpt'] ?? null;
		if ($rcptMeta === null || $rcptMeta === 'unknown') {
			$rcptMeta = [];
		}
		$rcptArr = [];
		/** @var mixed $rcpt */
		foreach (is_array($rcptMeta) ? $rcptMeta : [$rcptMeta] as $rcpt) {
			if (is_int($rcpt) || is_float($rcpt)) {
				$rcpt = (string) $rcpt;
			}
			if (!is_string($rcpt)) {
				$this->fileLogger->warning(
					"[{$this->logPrefix}] metadata field 'rcpt' has a value of type " . get_debug_type($rcpt) . ", not text; ignoring it"
				);
				continue;
			}
			$rcpt = strtolower(trim($rcpt));
			if ($rcpt === '0') {
				$this->fileLogger->warning("[{$this->logPrefix}] metadata field 'rcpt' has the value '0'; ignoring it");
				continue;
			}
			if ($rcpt !== '') {
				$rcptArr[] = $rcpt;
			}
		}

		$server = Helper::sanitize_string($server);
		
		// moved to HttpFoundation
		// $parser = new Parser();
		// $parser->setStream(fopen("php://input", "r"));

		// Parse the raw message only after metadata validation has passed, so
		// a rejected request does not pay for the MIME parse. Body comes from
		// the uploaded 'message' part - php://input is not readable for
		// multipart/form-data requests.
		// without a message (logged below, with the qid) there are no headers;
		// the parser refuses an empty text
		$stringHeaders = '';
		$charsets = [];
		$mime_from = $mime_to = $mime_subject = $message_id = false;
		$arrayHeaders = [];

		/*
		 Settings a mistyped config.local.php would make throw mid-import: boot
		 reports them but lets the importer run, so each falls back here and is
		 logged below with the qid.
		*/
		$settingErrors = [];
		try {
			$fallbackCharset = Helper::mailFallbackCharset();
		} catch (RuntimeException $e) {
			$fallbackCharset = Helper::DEFAULT_MAIL_FALLBACK_CHARSET;
			$settingErrors[] = $e->getMessage() . '; decoding with ' . Helper::DEFAULT_MAIL_FALLBACK_CHARSET;
		}

		if ($rawEmail !== '') {
			$parser = new Parser();
			$parser->setText($rawEmail);

			// return all headers as a string, no charset conversion
			$stringHeaders = trim($parser->getHeadersRaw());

			// unencoded 8-bit headers arrive as raw bytes: decode them with the
			// charset a MIME part declares, else $mail_fallback_charset
			$charsets = self::declaredCharsets($parser);
			$mime_from = self::headerToUtf8($parser->getHeader('from'), $charsets, $fallbackCharset);
			$mime_to = self::headerToUtf8($parser->getHeader('to'), $charsets, $fallbackCharset);
			$mime_subject = self::headerToUtf8($parser->getHeader('subject'), $charsets, $fallbackCharset);
			$message_id = $parser->getHeader('message-id');

			// return all headers as an array, with charset conversion
			/** @var array<string, string|array<array-key, string>> $arrayHeaders header name => value, or values when repeated */
			$arrayHeaders = $parser->getHeaders();
		}
		if ($qid === null || $qid === '' || $qid === "unknown" || !preg_match('/^[a-zA-Z0-9]+$/D', $qid)) {
			if (isset($arrayHeaders['x-rspamd-queue-id'])) {
				$qid = $arrayHeaders['x-rspamd-queue-id'];

				// duplicated headers arrive as an array; rspamd appends its
				// own last, so prefer that one
				if (is_array($qid)) {
					$qid = end($qid);
				}

				if (!is_string($qid) || !preg_match('/^[a-zA-Z0-9]+$/D', $qid)) {
					$qid = "unknown";
				}
			} else {
				// no metadata qid and no header
				$qid = "unknown";
			}
		}

		// metaString() and the header fallback above leave text
		$qid = (string) $qid;

		if ($messageError !== null) {
			$this->fileLogger->error("[{$this->logPrefix}] {$qid}: no usable message part ({$messageError}); importing the metadata without the mail");
			$this->syslogLogger->error("{$qid}: no usable message part ({$messageError}); importing the metadata without the mail");
		}

		// check for antivirus symbol
		$symbolsArr = Helper::sanitizeSymbols($meta['symbols'] ?? [], $qid);
		$symbols    = json_encode($symbolsArr);
		if (Helper::check_virus_from_all($symbolsArr) !== false) {
			$has_virus = 1;
		} else {
			$has_virus = 0;
		}

		// unset stores only viruses; not an array is a config error: config.php's defaults
		try {
			$store_settings = Config::getArray('store_settings') ?? [];
		} catch (RuntimeException $e) {
			$store_settings = self::DEFAULT_STORE_SETTINGS;
			$settingErrors[] = $e->getMessage() . "; using config.php's defaults";
		}
		$store = ($action !== null && $action !== '' && !empty($store_settings[$action])) || $has_virus;

		foreach ($settingErrors as $settingError) {
			$this->fileLogger->error("[{$this->logPrefix}] {$qid}: {$settingError}");
			$this->syslogLogger->error("{$qid}: {$settingError}");
		}

		if ($mail_from === null || $mail_from === '') {
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
			'subject' => $mime_subject !== false && $mime_subject !== '' ? $mime_subject : $subject,
			'score' => $score,
			'action' => $action,
			'symbols' => $symbols,
			'has_virus' => $has_virus,
			'fuzzy_hashes' => $fuzzy,
			'ip' => $ip,
			'mail_from' => strtolower($mail_from),
			// an absent header is NULL; false would be bound as 0 and stored as '0'
			'mime_from' => $mime_from !== false ? $mime_from : null,
			'rcpt_to' => empty($rcptArr) ? 'unknown' : implode(', ', $rcptArr),
			'mime_to' => $mime_to !== false ? mb_strimwidth($mime_to, 0, 250, "!!") : '',
			// set once the raw file is stored, below
			'mail_stored' => 0,
			'mail_location' => null,
			'size' => $size,
			//'headers' => $stringHeaders,
			'message_id' => $message_id !== false ? $message_id : null,
		);

		[$data, $debug] = Helper::trimDataToDbLimits($data, MailLog::FIELD_LIMITS);

		if (!empty($debug)) {
			foreach ($debug as $debug_msg) {
				$this->fileLogger->warning("[{$this->logPrefix}] {$qid}: {$debug_msg}");
			}
		}

		$data['headers'] = Helper::toUtf8($stringHeaders, $charsets, $fallbackCharset);
		if ($data['headers'] !== $stringHeaders) {
			$this->fileLogger->debug("{$qid}: 8-bit headers decoded, declared charsets: "
				. ($charsets === [] ? 'none' : implode(', ', $charsets)));
		}

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
		$mail_location = null;
		$ok_msg = 'Message saved';
		$fail_msg = 'Error storing message in DB';

		/*
		 From here on a stored raw file must end up referenced by a row or by
		 a spool entry. Every other way out, an exception included, removes
		 it below: no file is left that nothing points to.
		*/
		try {
			if ($store) {
				$mail_location = $this->storeRawMail($qid, $rawEmail);
				if ($mail_location !== null) {
					$data['mail_stored'] = 1;
					$data['mail_location'] = $mail_location;
				}
			}

			// admin notification is decided at arrival: only mails imported
			// while admin_notification_rcpt is set get admin_notified = 0
			if ($mail_location !== null && in_array($action, ['discard', 'reject'], true)
				&& !empty(Helper::adminNotificationRcpt())
				&& App::migrationStatus()->adminNotifiedCompleted()) {
				$data['admin_notified'] = 0;
			}

			[$db_id, $spooled, $fail_msg] = $this->save($data, $rcptArr, $qid);
		} catch (Throwable $e) {
			$reason = MailLogWriter::describe($e);
			$this->fileLogger->critical("[{$this->logPrefix}] {$qid} import error: {$reason}");
			$this->syslogLogger->critical("{$qid} import error: {$reason}");
			$fail_msg = 'Unexpected error';
		}

		// a debug dump: its failure, a mistyped setting included, must not change the mail's outcome
		try {
			$dir = Config::getString('log_to_files_dir');
			if (Config::getBool('log_to_files') && $dir !== null && $dir !== '') {
				/** @var array<string, string> $web_headers name => value: always an array since PHP 7.3 */
				$web_headers = getallheaders();
				Helper::log_to_files($dir, $symbols, $web_headers, $stringHeaders, $arrayHeaders);
			}
		} catch (Throwable $e) {
			$this->fileLogger->error("[{$this->logPrefix}] {$qid}: log_to_files not written: " . $e->getMessage());
		}
		
		$runtime = $this->getRuntime();
		if ($db_id !== null) {
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
