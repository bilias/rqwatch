<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Utils;

use App\Configuration\AppConfig;
use App\Configuration\Config;

use App\Core\App;

use App\Core\Auth\AuthManager;

use App\Services\MapService;

use PhpIP\IP;
use MaxMind\Db\Reader as MaxMindDbReader;

use PhpMimeMailParser\Charset as MimeCharset;

use NetDNS2\Resolver;
use NetDNS2\RR\A as DnsA;
use NetDNS2\RR\PTR as DnsPtr;
use NetDNS2\Exception as DnsException;
use NetDNS2\ENUM\Error as DnsError;

use Symfony\Component\HttpFoundation\Response;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

use DateTime;
use DateTimeInterface;
use DateTimeZone;

use Exception;
use Throwable;
use RuntimeException;
use InvalidArgumentException;

final class Helper {

	// used when a *_symbol_patterns config value is missing or invalid
	private const array SYMBOL_PATTERNS = [
		'local_symbol_patterns' => ['RQWATCH_*', 'LOCAL_*'],
		'whitelist_symbol_patterns' => ['RQWATCH_*_WL', 'RQWATCH_*_WHITELIST'],
		'blacklist_symbol_patterns' => ['RQWATCH_*_BL', 'RQWATCH_*_BLACKLIST'],
	];

	// patterns read from config, per process
	/** @var array<string, list<string>> config name => trimmed patterns */
	private static array $symbolPatterns = [];

	// the GeoIP database, open for the process (a request or a cron run) once used
	private static ?MaxMindDbReader $geoipReader = null;
	private static ?string $geoipReaderDb = null;

	private static function logger(): LoggerInterface {
		return App::fileLogger();
	}

	// Rspamd queue id as a path component: alphanumeric, else a unique placeholder.
	// Either result is safe for any sink, so every input taint is escaped.
	/** @psalm-taint-escape input */
	private static function fileSafeQid(string $qid): string {
		if (preg_match('/^[a-zA-Z0-9]+$/D', $qid)) {
			return $qid;
		}
		self::logger()->warning("QID invalid format: {$qid}");
		return "invalid/" . uniqid();
	}

	// Stores raw mail into filesystem. Can be used for release to user
	public static function store_raw_mail(
		string $dir,
		string $qid,
		string $rawEmail
	): ?string {
		if (!is_dir($dir)) {
			self::logger()->error("$dir does not exist");
			return null;
		}

		if (!is_writable($dir)) {
			self::logger()->error("$dir is not writable");
			return null;
		}

		if ($rawEmail === '') {
			self::logger()->error("No input received for $qid");
			return null;
		}

		$raw_input = $rawEmail;

		// Write raw input to a file
		$date = date("Y-m-d");
		$dir_raw = $dir . "/" . $date . "/";

		if (!empty($qid) && $qid != "unknown") {
			$dir_raw .= self::fileSafeQid($qid);
		} else {
			$dir_raw .= "unknown/" . uniqid();
		}

		if (!file_exists($dir_raw) && !@mkdir($dir_raw, 0750, true) && !is_dir($dir_raw)) {
			$err = error_get_last()['message'] ?? 'unknown error';
			self::logger()->error("Error creating directory $dir_raw: $err");
			return null;
		}

		$file_raw = $dir_raw . "/mail.eml";

		$bytes = @file_put_contents($file_raw, $raw_input);

		if ($bytes === false) {
			$err = error_get_last()['message'] ?? 'unknown error';
			self::logger()->error("Failed to write raw email to file: $file_raw: $err");
			@unlink($file_raw);
			@rmdir($dir_raw);
			return null;
		}
		return $file_raw;
	}

	/*
	 Undo store_raw_mail() when the mail_logs row could not be written.
	 The file is written before the row exists, so a failed insert leaves
	 a quarantine file that nothing reaps: every deletion path keys off
	 mail_logs.mail_location, and
	 CronQuarantine::pruneEmptyQuarantineDateDirs() reaches only <date>
	 and the unknown/invalid group dirs, never <date>/<qid>, so an empty
	 qid dir left behind would also keep its date dir from ever being
	 pruned.

	 deleteDirectory() is deliberately the mechanism: it is the same call
	 cleanQuarantine() uses, and it carries the containment checks --
	 realpath on both sides, a prefix match with a trailing separator so
	 /var/q-backup cannot pass for /var/q, refusal on filesystem root or
	 a non-directory, symlinks unlinked rather than followed, and fail
	 closed on anything that is not a regular file or directory. Do not
	 replace it with a bare unlink()/rmdir().

	 Do not call this once failed inserts are spooled for replay -- a
	 spooled mail_location has to keep pointing at a file that exists.
	*/
	public static function discard_raw_mail(?string $file): bool {
		/** @psalm-suppress RiskyTruthyFalsyComparison '' and '0' are no location; '0' would make dirname() '.' */
		if (empty($file)) {
			return false;
		}

		return self::deleteDirectory(dirname($file));
	}

	public static function get_today(): string {
		return date("Y-m-d");
	}

	// our fuzzy_check rule's symbols (contrib fuzzy_check.conf), not a map
	public static function isFuzzySymbol(string $name): bool {
		return self::fuzzySymbolClass($name) !== null;
	}

	/*
	 * CSS class of our fuzzy symbol: "NAME" or a detail line "NAME:score:...".
	 * *_WHITE is a Not spam list (like rspamd's FUZZY_WHITE), any other is spam.
	 * Null when it is not one of ours.
	 */
	public static function fuzzySymbolClass(string $symbol): ?string {
		$name = strtoupper(explode(':', $symbol, 2)[0]);
		if (!str_starts_with($name, 'RQWATCH_FUZZY_')) {
			return null;
		}
		return str_ends_with($name, '_WHITE') ? 'rqwatch_fuzzy_white' : 'rqwatch_fuzzy';
	}

	/**
	 * Our fuzzy symbols in a mail's symbols, as [['label' => ..., 'class' => ...]].
	 * $labels maps symbol name to list label.
	 *
	 * @param array<array-key, array<string, mixed>> $symbols
	 * @param array<string, string> $labels
	 * @return list<array{label: string, class: string}>
	 */
	public static function getFuzzyHits(array $symbols, array $labels): array {
		$hits = [];
		foreach ($symbols as $symbol) {
			$name = (string) ($symbol['name'] ?? '');
			if ($name === '' || ($class = self::fuzzySymbolClass($name)) === null) {
				continue;
			}
			$hits[] = [
				'label' => $labels[strtoupper($name)] ?? $name,
				'class' => $class,
			];
		}
		return $hits;
	}

	/*
	 * Does a symbol name match one of the patterns of a *_symbol_patterns
	 * config value (fnmatch, case-insensitive).
	 */
	public static function symbolMatches(string $name, string $patterns): bool {
		if (!isset(self::$symbolPatterns[$patterns])) {
			/** @var mixed $list */
			$list = Config::get($patterns);
			$valid = is_array($list) && $list !== []
				&& array_filter($list, fn ($p) => !is_string($p) || trim($p) === '') === [];
			if (!$valid) {
				self::logger()->error("[Helper] invalid {$patterns} in config: list of symbol patterns, using defaults");
				$list = self::SYMBOL_PATTERNS[$patterns] ?? [];
			}
			/** @var array<array-key, string> $list checked above, or the defaults */
			self::$symbolPatterns[$patterns] = array_map('trim', array_values($list));
		}

		foreach (self::$symbolPatterns[$patterns] as $pattern) {
			if (fnmatch($pattern, $name, FNM_CASEFOLD)) {
				return true;
			}
		}
		return false;
	}

	/** @param array<array-key, array<string, mixed>> $symbols */
	public static function checkForMap(array $symbols): bool {
		foreach ($symbols as $symbol) {
			if (isset($symbol['name']) && !self::isFuzzySymbol((string) $symbol['name'])
				&& (self::symbolMatches((string) $symbol['name'], 'local_symbol_patterns')
					|| isset(MapService::mappedSymbolSet()[(string) $symbol['name']]))) {
				return true;
			}
		}
		return false;
	}

	/** @param array<array-key, array<string, mixed>> $symbols */
	public static function checkForWhitelist(array $symbols): bool {
		foreach ($symbols as $symbol) {
			if (isset($symbol['name'])
				&& self::symbolMatches((string) $symbol['name'], 'whitelist_symbol_patterns')) {
				return true;
			}
		}
		return false;
	}

	// comma-separated addresses, trimmed, lowercased and deduplicated; null if empty or any is invalid
	/** @return list<string>|null */
	public static function parseEmailList(string $list): ?array {
		$emails = array_filter(
			array_map(fn ($e) => strtolower(trim($e)), explode(',', $list)),
			fn ($e) => $e !== ''
		);
		foreach ($emails as $e) {
			if (filter_var($e, FILTER_VALIDATE_EMAIL) === false) {
				return null;
			}
		}
		return empty($emails) ? null : array_values(array_unique($emails));
	}

	// $admin_notification_rcpt as a list; invalid addresses are dropped
	/** @return list<string> */
	public static function adminNotificationRcpt(): array {
		$rcpt = array_map('trim', explode(',', (string) Config::get('admin_notification_rcpt')));
		$rcpt = array_filter($rcpt, fn ($a) => filter_var($a, FILTER_VALIDATE_EMAIL) !== false);

		return array_values(array_unique($rcpt));
	}

	/** @param array<array-key, array<string, mixed>> $symbols */
	public static function checkForBlacklist(array $symbols): bool {
		foreach ($symbols as $symbol) {
			if (isset($symbol['name'])
				&& self::symbolMatches((string) $symbol['name'], 'blacklist_symbol_patterns')) {
				return true;
			}
		}
		return false;
	}

	// change class depending on symbol
	public static function get_symbol_class(?string $symbol = null): string {
		if ($symbol !== null && $symbol !== '') {
			if (($class = self::fuzzySymbolClass($symbol)) !== null) {
				return $class;
			}
			// a detail line is "NAME:score:options"
			$check = [['name' => explode(':', $symbol, 2)[0]]];
			if (self::checkForWhitelist($check)) {
				return 'whitelist';
			}
			if (self::checkForBlacklist($check)) {
				return 'blacklist';
			}
			if (self::checkForMap($check)) {
				return 'rqwatch_map';
			}
		}
		return '';
	}

	/** @param array<array-key, array<string, mixed>>|null $symbols a mail's decoded symbols */
	public static function get_action_details(?array $symbols = null): string {
		if ($symbols !== null && $symbols !== []) {
			if (self::checkForWhitelist($symbols)) {
				return 'whitelist';
			}
			if (self::checkForBlacklist($symbols)) {
				return 'blacklist';
			}
			if (self::checkForMap($symbols)) {
				return 'rqwatch_map';
			}
		}
		return '';
	}

	// change row class depending on action
	/** @param array<array-key, array<string, mixed>>|null $symbols a mail's decoded symbols */
	public static function get_row_class(string $action, ?array $symbols = null): string {
		if ($symbols !== null && $symbols !== []) {
			// green only if the whitelist won; a forced reject/discard keeps its colour
			if (!in_array($action, ['reject', 'discard'], true) && self::checkForWhitelist($symbols)) {
				return 'whitelist';
			}
			if (self::checkForBlacklist($symbols)) {
				return 'blacklist';
			}
			/*
			if (self::checkForMap($symbols)) {
				return 'rqwatch_map';
			}
			*/
		}

		if (!empty($action)) {
			switch ($action) {
				case 'no action':
					return "clean";
				case 'add header':
					return "add_header";
				case 'rewrite subject':
					return "subject";
				case 'greylist':
					return "greylist";
				case 'reject':
					return "reject";
				case 'discard':
					return "discard";
				default:
					return "clean";
			}
		}
		return "clean";
	}

	public static function getDelivery(string $action): ?string {
		if (!empty($action)) {
			switch ($action) {
				case 'greylist':
					return "greylisted";
				case 'reject':
					return "rejected and notified sender";
				case 'discard':
					return "discarded without notifying sender";
				case 'no action':
				case 'add header':
				case 'rewrite subject':
				default:
					return "delivered";
			}
		}
		return null;
	}

	// Converts rspamd JSON symbols into an array, sorted by score
	/**
	 * @param array<array-key, array<string, mixed>> $symbols_ar
	 * @return array<array-key, array<string, mixed>>
	 */
	public static function get_scores(array $symbols_ar, float $score): array {
		//$symbols_ar = json_decode($symbols, true);

		$score_sort = ($score > 0) ? SORT_DESC : SORT_ASC;

		$scores = array_column($symbols_ar, 'score');
		array_multisort($scores, $score_sort, SORT_NUMERIC, $symbols_ar);

		return $symbols_ar;
	}

	public static function getRuntime(float $startTime, int $startMemory): string {
		$endTime = microtime(true);
		$endMemory = memory_get_usage();
		$peakMemory = memory_get_peak_usage();

		$executionTime = $endTime - $startTime;
		$memoryUsed = $endMemory - $startMemory;

		return sprintf(
			"Execution time: %.3f sec | Memory used: %.2f MB | Peak memory: %.2f MB",
			$executionTime,
			$memoryUsed / 1048576,
			$peakMemory / 1048576
		);
	}

	public static function convertSRSAddress(?string $srsAddress): string {
	    // Normalize case
	    $srsAddress = strtolower((string) $srsAddress);

	    // Match SRS0 format
	    // if (preg_match('/^srs0=[^=]+=[^=]+=([^=]+)=(.*@.*)$/i', $srsAddress, $matches))
	    if (preg_match('/^srs0=[^=]+=[^=]+=([^=]+)=(.+)$/i', $srsAddress, $matches)) {
	        $domain = $matches[1];
	        $localPart = $matches[2];

	        // If localPart contains another address (like PRVS), extract user part
	        if (strpos($localPart, '@') !== false) {
	            $localPart = explode('@', $localPart)[0];
	        }

	        return "$localPart@$domain";
	    }
		 // Match SRS1 format
	    if (preg_match('/^srs1=[^=]+=[^=]+==[^=]+=[^=]+=([^=]+)=([^@=]+)@[^=]+$/i', $srsAddress, $matches)) {
	        $domain = $matches[1];
	        $localPart = $matches[2];
	        return "$localPart@$domain";
	    }

	    return $srsAddress;
	}

	public static function convertPRVSAddress(string $email): string {
		// Match PRVS format
		if (preg_match('/^prvs=[^=]+=(.+)@(.+)$/i', $email, $matches)) {
			$local = $matches[1];
			$domain = $matches[2];
			return "$local@$domain";
		}

		return $email;
	}

	public static function decodeEmail(?string $email): string {
		// Step 1: Decode SRS if applicable
		$decoded = self::convertSRSAddress($email);

		// Step 2: Decode PRVS if present
		return self::convertPRVSAddress($decoded);
	}

	// Snippet from PHP Share: http://www.phpshare.org
	/** @param int|float|numeric-string|null $bytes strings are SUM() results */
	public static function formatSizeUnits(int|float|string|null $bytes): string {
	if ($bytes >= 1073741824) {
		$bytes = number_format((float) $bytes / 1073741824.0, 1, ',', '.') . 'G';
	} elseif ($bytes >= 1048576) {
		$bytes = number_format((float) $bytes / 1048576.0, 1, ',', '.') . 'M';
	} elseif ($bytes >= 1024) {
		$bytes = number_format((float) $bytes / 1024.0, 1, ',', '.') . 'K';
	} elseif ($bytes > 1) {
		$bytes = (string) $bytes . 'B';
	} elseif ($bytes == 1) {
		$bytes = $bytes . 'B';
	} else {
		$bytes = 'Null';
	}

	return $bytes;
	}

	// expects json decoded array with one symbol
	public static function check_virus(mixed $symbol): string|false {

		if (!is_array($symbol) || count($symbol) == 0) {
			return false;
		}
		if (isset($symbol['group']) && $symbol['group'] == 'antivirus') {
			if (isset($symbol['options']) && isset($symbol['options'][0])) {
				$virus = (string) $symbol['options'][0];
			} else {
				$virus = '';
			}
			$antivirus = (string) $symbol['name'];
			return "[$antivirus: $virus]";
		}
		return false;
	}

	// expects json decoded array with all symbols. Untyped on purpose:
	// MailLog's accessor passes null when mailLogData was not eager loaded
	public static function check_virus_from_all(mixed $symbols): string|false {
		if (!is_array($symbols) || count($symbols) == 0) {
			return false;
		}

		/** @var mixed $symbol */
		foreach ($symbols as $symbol) {
			if (($ret = self::check_virus($symbol)) !== false) {
				return $ret;
			} 
		}
		return false;
	}

	// first pattern (fnmatch, case-insensitive) matching an antivirus symbol name or one of its options
	/**
	 * @param array<array-key, mixed> $symbols decoded rspamd symbols, entries checked here
	 * @param array<array-key, string> $patterns
	 */
	public static function virusSkipMatch(array $symbols, array $patterns): ?string {
		foreach ($symbols as $symbol) {
			if (!is_array($symbol) || ($symbol['group'] ?? null) !== 'antivirus' || !isset($symbol['name'])) {
				continue;
			}
			$names = [(string) $symbol['name']];
			/** @var mixed $opt */
			foreach ((array) ($symbol['options'] ?? []) as $opt) {
				if (is_scalar($opt)) {
					$names[] = (string) $opt;
				}
			}
			foreach ($patterns as $pattern) {
				foreach ($names as $name) {
					if (fnmatch((string) $pattern, $name, FNM_CASEFOLD)) {
						return (string) $pattern;
					}
				}
			}
		}
		return null;
	}

	// repair only Rspamd symbol shapes that would crash the formatter; log each repair
	/** @return array<array-key, array<string, mixed>> */
	public static function sanitizeSymbols(mixed $symbols, string $qid = ''): array {
		if (!is_array($symbols)) {
			self::logger()->warning("{$qid}: symbols is not a list, stored as empty");
			return [];
		}

		$isList = array_is_list($symbols);
		$clean = [];
		/** @var mixed $s */
		foreach ($symbols as $k => $s) {
			if (!is_array($s)) {
				self::logger()->warning("{$qid}: dropped symbol entry that is not a record");
				continue;
			}
			/** @var array<string, mixed> $s a decoded JSON object: rspamd's field names */
			$name = is_scalar($s['name'] ?? null) ? (string) $s['name'] : '?';
			if (!array_key_exists('score', $s)) {
				self::logger()->warning("{$qid}: symbol {$name} has no score, set to 0");
				$s['score'] = 0;
			}
			if (isset($s['options']) && !is_array($s['options'])) {
				self::logger()->warning("{$qid}: symbol {$name} options not a list, wrapped");
				$s['options'] = [$s['options']];
			}
			$clean[$k] = $s;
		}

		return $isList ? array_values($clean) : $clean;
	}

	// checks date for correct format YYYY-MM-DD
	public static function check_date(?string $day): bool {
		if ($day === null) {
			return false;
		}
		
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
			return false;
		}

		$dt = DateTime::createFromFormat('Y-m-d', $day);

		if ($dt && $dt->format('Y-m-d') === $day) {
			return true;
		}
		return false;
	}

	public static function sanitize_mail(string $mail): ?string {
		return preg_replace('/[^a-zA-Z0-9 .\-<>@]/', '', $mail);
	}

	public static function sanitize_string(string $mail): ?string {
		return preg_replace('/[^a-zA-Z0-9.\-]/', '', $mail);
	}

	/**
	 * @param string|false $symbols json_encode() of the mail's symbols
	 * @param array<string, string> $web_headers getallheaders()
	 * @param array<string, string|array<array-key, string>> $arrayHeaders Parser::getHeaders()
	 */
	public static function log_to_files(string $dir, string|false $symbols, array $web_headers, string $stringHeaders, array $arrayHeaders): bool {
		if (!is_dir($dir)) {
			self::logger()->warning("$dir does not exist");
			if (!mkdir($dir, 0750, true)) {
				self::logger()->error("Error creating directory $dir");
				return false;
			} else {
				self::logger()->info("Created directory $dir");
			}
		}

		if (!is_writable($dir)) {
			self::logger()->error("$dir is not writable");
			return false;
		}

		// symbols_array is string: pretty JSON (Psalm treats print_r() as an HTML sink)
		$symbols_array = (string) json_encode(json_decode((string) $symbols, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

		// no credentials on disk: Basic Auth and the .env secrets Dotenv puts in $_SERVER
		$redact = fn(string $key, mixed $value): mixed => preg_match('/_PW$|PASS|SECRET|DSN|AUTHORIZATION/i', $key) ? '[redacted]' : $value;

		if (!$fp_web_headers = fopen("{$dir}/web_headers", "w")) {
			self::logger()->error("Cannot open file ({$dir}/web_headers)");
			return false;
		}

		foreach ($web_headers as $key => $value) {
			fwrite($fp_web_headers, "$key => " . $redact((string) $key, $value) . "\r\n");
		}
		fclose($fp_web_headers);

		if (!$fp_server = fopen("{$dir}/server", "w")) {
			self::logger()->error("Cannot open file ({$dir}/server)");
			return false;
		}

		foreach ($_SERVER as $key => $value) {
			fwrite($fp_server, "$key => " . $redact((string) $key, $value) . "\r\n");
		}
		fclose($fp_server);

		if (!$fp_symbols = fopen("{$dir}/symbols", "w")) {
			self::logger()->error("Cannot open file ({$dir}/symbols)");
			return false;
		}

		fwrite($fp_symbols, $symbols_array);
		fclose($fp_symbols);

		if (!$fp_headers = fopen("{$dir}/headers", "w")) {
			self::logger()->error("Cannot open file ({$dir}/headers)");
			return false;
		}

		fwrite($fp_headers, $stringHeaders);
		fclose($fp_headers);

		if (!$fp_headers_ar = fopen("{$dir}/headers_ar", "w")) {
			self::logger()->error("Cannot open file ({$dir}/headers_ar)");
			return false;
		}

		foreach ($arrayHeaders as $key => $value) {
			if(!is_array($value)) {
				fwrite($fp_headers_ar, "$key => $value\r\n");
			} else {
				foreach ($value as $key2 => $value2) {
					fwrite($fp_headers_ar, "$key [$key2] => $value2\r\n");
				}
			}
		}
		fclose($fp_headers_ar);

		self::logger()->info("Saved debug files in $dir");
		return true;
	}

	// $score is null when Rspamd sent none (the importer still stores the mail)
	/**
	 * @param array<array-key, array<string, mixed>> $symbols_ar
	 * @return array{symbols: list<string>, virus_found: string|null}
	 */
	public static function format_symbols(array $symbols_ar, ?float $score, bool $has_virus): array {
		$sorted_symbols = self::get_scores($symbols_ar, $score ?? 0.0);

		$symbols = array();
		$virus_found = null;

		foreach ($sorted_symbols as $symbol) {
			 $name = (string) $symbol['name'];
			 $score = (string) $symbol['score'];

			 if ($has_virus && ($virus = Helper::check_virus($symbol)) !== false) {
					$symbols[] = "$name:$score:$virus";
					$virus_found = $virus;
			 } elseif (isset($symbol['options'])) {
					$opts = implode(", ", array_map(strval(...), (array) $symbol['options']));
					$symbols[] = "$name:$score:$opts";
			 } else {
					$symbols[] = "$name:$score";
			 }
		}

		return array(
			'symbols' => $symbols,
			'virus_found' => $virus_found
		);
	}

	/** @return array{error: string|false, id: int|false, qid: string|false} */
	public static function check_id_qid(string $type, string|int $value): array {
		$error = false;
		$id = false;
		$qid = false;

		$strValue = (string) $value;

		if ($strValue === '') {
			$error = "Empty value";
		} elseif ($type === 'id') {
			if (!ctype_digit($strValue) || (int)$strValue <= 0) {
				$error = "ID wrong format";
			} else {
				$id = (int) $strValue;
			}
		} elseif ($type === 'qid') {
			if (!preg_match('/^[a-zA-Z0-9]+$/D', $strValue)) {
				$error = "QID wrong format";
			} else {
				$qid = $strValue;
			}
		} else {
			$error = "Unknown error";
		}

		return array(
			'error' => $error,
			'id'    => $id,
			'qid'   => $qid,
		);
	}

	// /**
	//  * @template T
	//  * @param array<array-key, T> $array
	//  * @return array<array-key, T> reindexed when $value was found
	//  */
	// public static function removeValFromArr(array $array, string $value): array {
	// 	$key = array_search($value, $array);
	// 	if ($key !== false) {
	// 		unset($array[$key]);
	// 		return array_values($array);
	// 	}
	// 	return $array;
	// }

	// /**
	//  * @template T
	//  * @param array<array-key, T> $array
	//  * @param array<array-key, mixed> $values
	//  * @return list<T>
	//  */
	// public static function removeArrFromArr(array $array, array $values): array {
	// 	$array = array_diff($array, $values);
	// 	return array_values($array);
	// }

	// /**
	//  * @template T
	//  * @param array<array-key, T> $array
	//  * @param array<array-key, T> $values
	//  * @return array<array-key, T>
	//  */
	// public static function addArrToArr(array $array, array $values): array {
	// 	foreach ($values as $val) {
	// 		if (!in_array($val, $array, true)) {
	// 			$array[] = $val;
	// 		}
	// 	}
	// 	return $array;
	// }

	public static function passwordHash(string $clear_pass): ?string {
		if (!empty($clear_pass)) {
			return password_hash($clear_pass, Config::get('password_hash'));
		}
		return null;
	}

	public static function passwordVerify(string $clear_pass, string $hash): bool {
		if (!empty($clear_pass) && !empty($hash) &&
		    password_verify($clear_pass, $hash)) {
				return true;
		}
		return false;
	}

	// hide $base from $path
	public static function stripBasePath(string $base, string $path): string {
		$base = rtrim($base, '/');

		// If $path starts with $base followed by a slash, strip it
		if (strpos($path, $base . '/') === 0) {
			return substr($path, strlen($base) + 1);
		}

		// If $path is exactly equal to $base, return an empty string
		if ($path === $base) {
			return '';
		}

		// base not found in path
		return $path;
	}

	// /** @return array<int, string> trimmed, lowercased, empty entries dropped (keys kept) */
	// public static function cleanEmailList(string $input): array {
	// 	$emails = explode(',', $input);
	// 	$emails = array_map(function($email) {
	// 		return strtolower(trim($email));
	// 	}, $emails);

	// 	$emails = array_filter($emails);

	// 	if (!empty($emails)) {
	// 		return $emails;
	// 	}
	// 	return [];
	// }

	/** @param array<string, mixed> $ar */
	public static function getReleaseText(array $ar): string {
		$ret = "The following mail has been released from quarantine storage
and attached to this mail.

Please be very carefull before opening any attachment the original mail might have!

Date: {$ar['created_at']}
From: {$ar['mime_from']}
To: {$ar['rcpt_to']}
Subject: {$ar['subject']}
Mail Queue ID: {$ar['qid']}
Spam Score: {$ar['score']}
Virus Detected:";
		if (!empty($ar['has_virus'])) {
			$ret .= rtrim(" Yes {$ar['virus_name']}");
		} else {
			$ret .= " No";
		}

		$ret .= "
Action: {$ar['action']}

{$ar['signature']}";
		return $ret;
	}

	/** @param array<string, mixed> $ar */
	public static function getNotifyText(array $ar): string {
		$ret = "The following mail has been saved in our quarantine storage
because our system has detected it as being high scored spam or that it contains a virus.

Date: {$ar['created_at']}
From: {$ar['mime_from']}
To: {$ar['rcpt_to']}
Subject: {$ar['subject']}
Mail Queue ID: {$ar['qid']}
Spam Score: {$ar['score']}
Virus Detected:";
		if (!empty($ar['has_virus'])) {
			$ret .= rtrim(" Yes {$ar['virus_name']}");
		} else {
			$ret .= " No";
		}

		$ret .= "
Action: {$ar['action']}

You can view mail details and optionally release it from quarantine by clicking here:
{$ar['detailurl']}";
		if (!empty($ar['link_one_time'])) {
			$ret .= "\nThis link can be used only once.";
		}

		$ret .= "\n\n{$ar['signature']}";
		return $ret;
	}

	/** @param array<string, mixed> $ar */
	public static function getAdminNotifyText(array $ar): string {
		$ret = "Admin notification: the following mail has been saved in quarantine.

Date: {$ar['created_at']}
Server: {$ar['server']}
MAIL From: {$ar['mail_from']}
From: {$ar['mime_from']}
To: {$ar['rcpt_to']}
Subject: {$ar['subject']}
Mail Queue ID: {$ar['qid']}
Spam Score: {$ar['score']}
Virus Detected:";
		if (!empty($ar['has_virus'])) {
			$ret .= rtrim(" Yes {$ar['virus_name']}");
		} else {
			$ret .= " No";
		}

		$ret .= "
Action: {$ar['action']}

Mail details:
{$ar['detailurl']}

{$ar['signature']}";
		return $ret;
	}

	// a .env value, null when unset (Dotenv sets only strings)
	public static function env_string(string $key): ?string {
		$value = $_ENV[$key] ?? null;
		return is_string($value) ? $value : null;
	}

	// a .env value the caller cannot work without
	public static function env_required(string $key): string {
		return self::env_string($key) ?? throw new RuntimeException("{$key} is not set in .env");
	}

	public static function env_bool(string $key, bool $default = false): bool {
		return filter_var($_ENV[$key] ?? $default, FILTER_VALIDATE_BOOLEAN);
	}

	public static function mailApiCredentialsSet(): bool {
		$user = self::env_string('MAIL_API_USER');
		$pass = self::env_string('MAIL_API_PASS');

		return is_string($user)
			&& $user !== ''
			&& is_string($pass)
			&& $pass !== ''
			&& !str_starts_with($pass, 'CHANGE_ME_');
	}

	/** @return list<array{ip: string, host: string|null, country: string|null}> */
	public static function extract_mail_relays(string $headers): array {
		// unfold continuation lines so each header is one line; on a PCRE failure
		// keep them folded (a wrapped Received: is then read from its first line)
		$lines = preg_split('/\r\n|\r|\n/', preg_replace('/(?:\r\n|\r|\n)[ \t]+/', ' ', $headers) ?? $headers);
		if ($lines === false) {
			return [];
		}
		$budget = AppConfig::DNS_MAX_RELAYS; // max DNS lookups per message
		$relays = [];

		foreach ($lines as $line) {
			if (stripos($line, 'Received:') !== 0) {
				continue;
			}

			$host = null;

			/*
			$ip = null;
			// Try to extract IP in brackets [1.2.3.4]
			if (preg_match('/\[(IPv6:)?([^\]]+)\]/i', $line, $ipMatch)) {
				$ip = preg_replace('/^IPv6:/i', '', $ipMatch[2]);
				if (!filter_var($ip, FILTER_VALIDATE_IP)) {
					$ip = null;
				}
			}
			*/

			// Try to extract all IPs in brackets [1.2.3.4]
			$ips = [];
			// [1.2.3.4], [IPv6:2001:db8::1] or (1.2.3.4); brackets holding only an address
			if ((int) preg_match_all('/[\[\(](?:IPv6:)?([0-9a-f:.]+)[\]\)]/i', $line, $ipMatches) > 0) {
				foreach ($ipMatches[1] as $rawIp) {
					if (filter_var($rawIp, FILTER_VALIDATE_IP)) {
						$ips[] = $rawIp;
					}
				}
			}

			// sender from "from"; "by" (the receiving host) only for hops without an IP
			if (preg_match('/\bfrom\s+([a-z0-9\.\-]+\.[a-z]{2,})/i', $line, $hostMatch)
				|| (empty($ips) && preg_match('/\bby\s+([a-z0-9\.\-]+\.[a-z]{2,})/i', $line, $hostMatch))) {
				$host = strtolower($hostMatch[1]);
			}

			//if ($ip) {
			if (!empty($ips)) {
				foreach (array_reverse($ips) as $ip) {
					// Resolve hostname from IP (optional; basic reverse lookup)
					$resolvedHost = $budget-- > 0 ? self::my_gethostbyaddr($ip) : $ip;
					$relays[] = [
						'ip' => $ip,
						'host' => ($resolvedHost !== $ip) ? $resolvedHost : ($host ?? null),
					];
				}
			} elseif ($host !== null) {
				// No IP found — try resolving IP from hostname
				$resolvedIp = $budget-- > 0 ? self::my_gethostbyname($host) : $host;
				if (filter_var($resolvedIp, FILTER_VALIDATE_IP)) {
					$relays[] = [
						'ip' => $resolvedIp,
						'host' => $host,
					];
				}
			}
		}

		// Deduplicate by IP
		$seen = [];
		$unique_relay = [];
		foreach ($relays as $relay) {
			if (!in_array($relay['ip'], $seen)) {
				$seen[] = $relay['ip'];
				$relay['country'] = self::getCountry($relay['ip']);
				$unique_relay[] = $relay;
			}
		}

		return $unique_relay;
	}

	public static function getCountry(?string $ip): ?string {
		if ($ip === null || $ip === '') {
			return null;
		}
		if (Config::get('geoip_enable') &&
		    ($geoip_db = Config::get('geoip_country_db')) &&
		    !self::isLocalOrReservedIp($ip)) {
			try {
				/** @var array{country?: array{names?: array{en?: string}}}|null $geo the MaxMind country record */
				$geo = self::geoipReader($geoip_db)->get($ip);

				$country = $geo['country']['names']['en'] ?? null;
				if (is_string($country) && $country !== '') {
					return $country;
				}
			} catch (Exception $e) {
				// drop it (its handle goes with it) and reopen on the next lookup
				self::$geoipReader = null;
				self::logger()->error("GeoIP problem: " . $e->getMessage());
				return null;
			}
		}
		return null;
	}

	// the open reader for $db, opened on first use or when the configured file changes;
	// a dropped reader's file handle is closed with it, so close() is not needed
	private static function geoipReader(string $db): MaxMindDbReader {
		if (self::$geoipReader === null || self::$geoipReaderDb !== $db) {
			self::$geoipReader = null;
			self::$geoipReader = new MaxMindDbReader($db);
			self::$geoipReaderDb = $db;
		}
		return self::$geoipReader;
	}

	public static function isLocalOrReservedIp(string $ip): bool {
		if ($ip == 'unknown') {
			return true;
		}

		try {
			$ipObj = IP::create($ip);
		} catch (InvalidArgumentException $e) {
			return true; // Invalid IP format
		}

		return $ipObj->isPrivate()
			|| $ipObj->isReserved()
			|| $ipObj->isLoopback()
			|| $ipObj->isLinkLocal();
	}

	public static function getAuthProvider(int $id): string {
		return AuthManager::getAuthProviderById($id);
	}

	/*
	 * A quarantined mail file: its real path when it is a regular file under
	 * QUARANTINE_DIR (realpath on both sides, so ../ and symlinks resolve
	 * before the check), null otherwise. Every reader of mail_location uses it.
	 */
	public static function quarantinedFile(string $path): ?string {
		$dir = rtrim(trim(self::env_string('QUARANTINE_DIR') ?? ''), DIRECTORY_SEPARATOR);
		$base = $dir === '' ? false : realpath($dir);
		$real = $path === '' ? false : realpath($path);
		if ($base !== false && $base !== DIRECTORY_SEPARATOR && $real !== false && is_file($real)
			&& str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
			return $real;
		}
		self::logger()->warning("Not a file under QUARANTINE_DIR: {$path}");
		return null;
	}

	public static function deleteDirectory(string $dir): bool {
		$quarantineEnv = trim(self::env_string('QUARANTINE_DIR') ?? '');
		if ($quarantineEnv === '' || $quarantineEnv === DIRECTORY_SEPARATOR) {
			self::logger()->warning("QUARANTINE_DIR is empty or points to root /");
			return false;
		}

		$quarantineDir = rtrim($quarantineEnv, DIRECTORY_SEPARATOR);

		// Resolve real paths for security
		$realQuarantine = realpath($quarantineDir);
		$realDir = realpath($dir);

		if ($realDir === false || $realQuarantine === false) {
			self::logger()->warning("Quarantine directory problem: {$dir}");
			return false;
		}

		if (!is_dir($realQuarantine)) {
			self::logger()->warning("QUARANTINE_DIR is not a directory");
			return false;
		}

		// Don't allow operations on filesystem root
		if ($realQuarantine === DIRECTORY_SEPARATOR || $realDir === DIRECTORY_SEPARATOR) {
			self::logger()->warning("Quarantine points to root /");
			return false;
		}

		// Safety check: ensure $dir is inside quarantine directory and not just
		// sharing a common prefix (e.g. /var/q and /var/q-backup)
		$quarantinePrefix = rtrim($realQuarantine, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
		$isInsideQuarantine = str_starts_with($realDir, $quarantinePrefix);

		if (!$isInsideQuarantine) {
			self::logger()->warning("Quarantine $realDir is not inside QUARANTINE_DIR");
			return false;
		}

		if (!is_dir($realDir)) {
			self::logger()->warning("Quarantine $realDir is not a directory");
			return false;
		}

		$files = scandir($realDir);
		if ($files === false) {
			self::logger()->warning("Quarantine $realDir scan problem");
			return false;
		}

		foreach ($files as $file) {
			if ($file === '.' || $file === '..') {
				continue;
			}

			$path = $realDir . DIRECTORY_SEPARATOR . $file;

			// Never follow symlinks; just unlink them
			if (is_link($path) || is_file($path)) {
				if (!unlink($path)) {
					self::logger()->warning("Cannot unlink file in quarantine: $path");
					return false;
				}
				continue;
			}

			if (is_dir($path)) {
				if (!self::deleteDirectory($path)) {
					self::logger()->warning("Cannot delete directory in quarantine: $path");
					return false;
				}
				continue;
			}

			// Unknown filesystem object (fifo/socket/etc) - fail closed
			self::logger()->warning("Unknown file type in quarantine: $path");
			return false;
		}

		if (!rmdir($realDir)) {
			self::logger()->warning("Cannot remove directory in quarantine: $realDir");
			return false;
		}

		return true;
	}

	/** @param non-empty-string|null $fromTz */
	public static function toUtcTimestamp(string $datetime, ?string $fromTz = null): int {
		$fromTz = $fromTz ?? date_default_timezone_get();
		$date = new DateTime($datetime, new DateTimeZone($fromTz));
		$date->setTimezone(new DateTimeZone('UTC'));
		return $date->getTimestamp();
	}

	public static function extractEmail(string $input): ?string {
		// Use regex to find email first
		if (preg_match('/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i', $input, $matches)) {
			return strtolower(trim($matches[0]));
		}
		return null;
	}

	/**
	 * @param array<string, mixed> $data
	 * @param array<string, int> $limits field => max characters
	 * @return array{array<string, mixed>, list<string>} the data and a message per truncated field
	 */
	public static function trimDataToDbLimits(array $data, array $limits): array {
		$debug = [];
		foreach ($limits as $field => $max) {
			if (isset($data[$field]) && is_string($data[$field])) {
				if (mb_strlen($data[$field]) > $max) {
					$debug[] = "Field {$field} exceeded max {$max}, truncated.";
				}
				/* prevent any hidden invalid byte issues
				$data[$field] = mb_convert_encoding($data[$field], 'UTF-8', 'UTF-8');
				$data[$field] = iconv('UTF-8', 'UTF-8//IGNORE', $data[$field]);
				*/
				// use mb_strimwidth to handle multibyte safely
				$data[$field] = mb_strimwidth($data[$field], 0, $max, '');
			}
		}
		return [$data, $debug];
	}

	public static function formatTtlHuman(int $ttl): string {
		$minutes = floor($ttl / 60);
		$seconds = $ttl % 60;
		if ($minutes > 0) {
			return sprintf('%dm %ds', $minutes, $seconds);
		}
		return sprintf('%ds', $seconds);
	}

	public function getMailFromMime(string $mime_from): string {
		$parse = mailparse_rfc822_parse_addresses($mime_from);
		/** @var mixed $address */
		$address = $parse[0]['address'] ?? '';

		return is_string($address) ? $address : '';
	}

	/**
	 * SQL with its bindings filled in, for debug logs only (not escaped)
	 *
	 * @param array<array-key, mixed> $bindings
	 */
	public static function sqlWithBindings(string $sql, array $bindings): string {
		$values = array_map(fn (mixed $b): string => match (true) {
			$b instanceof DateTimeInterface => $b->format('Y-m-d H:i:s'),
			is_scalar($b), $b === null => (string) $b,
			default => get_debug_type($b),
		}, array_values($bindings));

		return vsprintf(str_replace(['%', '?'], ['%%', '"%s"'], $sql), $values);
	}

	/**
	 * A charset name a mail declares, if it may decode 8-bit text: one the
	 * MIME parser knows (its alias table; anything else is us-ascii), and
	 * ASCII-compatible. Never 7-bit escape encodings (UTF-7, ISO-2022-*,
	 * HZ) or UTF-16, which turn plain ASCII into other characters, control
	 * characters included; never ASCII or UTF-8, which need no decoding.
	 * The name is the sender's, so this also keeps unlisted iconv decoders
	 * out of reach.
	 */
	public static function mailCharset(string $declared): ?string {
		$charset = (new MimeCharset())->getCharsetAlias(trim($declared));
		if (in_array($charset, ['us-ascii', 'utf-8'], true)
			|| preg_match('/^(utf-7|utf-16|x-imap4-modified-utf7|iso-2022-|hz-)/', $charset)) {
			return null;
		}
		return $charset;
	}

	/**
	 * $input as UTF-8: unchanged when valid, else decoded with the first of
	 * $charsets, then $mail_fallback_charset, that iconv accepts, else with
	 * invalid bytes replaced. Any byte string decodes as a single-byte
	 * charset, so the fallback is a guess the admin chooses.
	 * @param list<string> $charsets
	 */
	public static function toUtf8(string $input, array $charsets = []): string {
		if ($input === '' || mb_check_encoding($input, 'UTF-8')) {
			return $input;
		}

		$charsets[] = (string) (Config::get('mail_fallback_charset') ?: 'Windows-1252');
		foreach ($charsets as $enc) {
			$converted = @iconv($enc, 'UTF-8//IGNORE', $input);
			if ($converted !== false && mb_check_encoding($converted, 'UTF-8')) {
				return $converted;
			}
		}

		return mb_scrub($input, 'UTF-8');
	}

	public static function normalizeToUtf8(?string $input): string {
		if ($input === null || $input === '' || mb_check_encoding($input, 'UTF-8')) {
			return (string) $input;
		}

		// Not UTF-8: the MIME part declared no charset (the parser passes it
		// through unchanged) or a wrong one. Try the HTML's own <meta> charset
		// before the configured fallback.
		$charsets = [];
		if (preg_match('/<meta\b[^>]*\bcharset\s*=\s*["\']?\s*([\w.:-]+)/i', $input, $m)
			&& ($charset = self::mailCharset($m[1])) !== null) {
			$charsets[] = $charset;
		}

		return self::toUtf8($input, $charsets);
	}

	public static function debug_exception_err(?string $input): string {
		return self::debugException(LogLevel::ERROR, $input);
	}

	// a client's own bad request (an unknown or out-of-scope mail id): logged
	// with the caller's file and line, but not as an error
	public static function debug_exception_warn(?string $input): string {
		return self::debugException(LogLevel::WARNING, $input);
	}

	private static function debugException(string $level, ?string $input): string {
		if ($input === null) {
			$input = '';
		}

		// frame 0: this function, called from its wrapper in Helper.php;
		// frame 1: the wrapper, called from the caller's file and line
		$trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);

		// the first frame outside Helper.php is the caller
		foreach ($trace as $frame) {
			if (isset($frame['file']) && strpos($frame['file'], 'Helper.php') === false) {
				$file = $frame['file'];
				$line = $frame['line'] ?? '?';

				$err_msg = "{$file}:{$line} '{$input}'";
				self::logger()->log($level, $err_msg);
				return $err_msg;
			}
		}

		return $input;
	}

	/*
	 Only NXDOMAIN is a definite "no name" (an empty NOERROR answer does not
	 throw). A timeout (INT_FAILED_SOCKET), SERVFAIL or any other failure is
	 cached for dns_resolv_redis_fail_cache_ttl instead, so one slow cold
	 lookup does not hide the name for the whole negative TTL.
	*/
	private static function dnsTransientFailure(Exception $e): bool {
		return !($e instanceof DnsException
			&& $e->getCode() === DnsError::DNS_NXDOMAIN->value);
	}

	public static function my_gethostbyaddr(string $ip): string {
		if (!Config::get('dns_resolv')) {
			return $ip;
		}

		if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
			return $ip;
		}

		$cache = App::cache();
		if ($cache === null) {
			return self::lookupPtr($ip)[0];
		}

		$redisKey = Config::get('dns_resolv_redis_key') . ":ptr:{$ip}";
		try {
			$cached = $cache->get($redisKey);
			if ($cached !== false) {
				return $cached;
			}
		} catch (Throwable $e) {
			// fallback to live DNS if Redis fails
		}

		[$host, $transient] = self::lookupPtr($ip);

		$ttl      = (int) Config::get('dns_resolv_redis_cache_ttl') ?: 86400;
		$negTtl   = (int) Config::get('dns_resolv_redis_neg_cache_ttl') ?: 3600;
		$failTtl  = (int) Config::get('dns_resolv_redis_fail_cache_ttl') ?: 60;
		try {
			$cache->set($redisKey, $host, $host === $ip ? ($transient ? $failTtl : $negTtl) : $ttl);
		} catch (Throwable $e) {
			// ignore cache write failure
		}

		return $host;
	}

	/**
	 * PTR lookup of $ip: the host, or $ip when there is none or the query
	 * fails, and whether the failure was transient (worth a short cache TTL).
	 *
	 * @return array{string, bool}
	 */
	private static function lookupPtr(string $ip): array {
		try {
			$resolver = new Resolver([
				'timeout' => Config::get('dns_resolv_timeout'),
				'retry'   => 0,
			]);
			if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
				/** @var string $expanded validated as IPv6 above, so inet_pton() cannot fail */
				$expanded = inet_pton($ip);
				$hex = bin2hex($expanded);
				$ptr = implode('.', array_reverse(str_split($hex))) . '.ip6.arpa';
			} else {
				$ptr = implode('.', array_reverse(explode('.', $ip))) . '.in-addr.arpa';
			}
			$result = $resolver->query($ptr, 'PTR');
			// the answer may start with a CNAME (RFC 2317)
			foreach ($result->answer as $rr) {
				if ($rr instanceof DnsPtr) {
					return [rtrim((string)$rr->ptrdname, '.'), false];
				}
			}
			return [$ip, false];
		} catch (Exception $e) {
			return [$ip, self::dnsTransientFailure($e)];
		}
	}

	public static function my_gethostbyname(string $host): string {
		if (!Config::get('dns_resolv')) {
			return $host;
		}

		$cache = App::cache();
		if ($cache === null) {
			return self::lookupA($host)[0];
		}

		$redisKey = Config::get('dns_resolv_redis_key') . ':fwd:' . $host;
		try {
			$cached = $cache->get($redisKey);
			if ($cached !== false) {
				return $cached;
			}
		} catch (Throwable $e) {
			// fallback to live DNS if Redis fails
		}

		[$ip, $transient] = self::lookupA($host);

		$ttl      = (int) Config::get('dns_resolv_redis_cache_ttl') ?: 86400;
		$negTtl   = (int) Config::get('dns_resolv_redis_neg_cache_ttl') ?: 3600;
		$failTtl  = (int) Config::get('dns_resolv_redis_fail_cache_ttl') ?: 60;
		try {
			$cache->set($redisKey, $ip, $ip === $host ? ($transient ? $failTtl : $negTtl) : $ttl);
		} catch (Throwable $e) {
			// ignore cache write failure
		}

		return $ip;
	}

	/**
	 * A lookup of $host: its first address, or $host when there is none or
	 * the query fails, and whether the failure was transient.
	 *
	 * @return array{string, bool}
	 */
	private static function lookupA(string $host): array {
		try {
			$resolver = new Resolver([
				'timeout' => Config::get('dns_resolv_timeout'),
				'retry'   => 0,
			]);
			$result = $resolver->query($host, 'A');
			// the answer may start with a CNAME
			foreach ($result->answer as $rr) {
				if ($rr instanceof DnsA) {
					return [(string)$rr->address, false];
				}
			}
			return [$host, false];
		} catch (Exception $e) {
			return [$host, self::dnsTransientFailure($e)];
		}
	}

	public static function count_dns_cache(): ?int {
		if (!Helper::env_bool('REDIS_ENABLE')) {
			return null;
		}

		$cache = App::cache();
		if ($cache === null) {
			return null;
		}

		try {
			return $cache->countByPrefix(Config::get('dns_resolv_redis_key'));
		} catch (Throwable $e) {
			return null;
		}
	}

	public static function flush_dns_cache(): int {
		if (!Helper::env_bool('REDIS_ENABLE')) {
			return 0;
		}

		$cache = App::cache();
		if ($cache === null) {
			throw new RuntimeException("Redis cache is unavailable, cannot flush DNS cache");
		}

		return $cache->deleteByPrefix(Config::get('dns_resolv_redis_key'));
	}

	/*
	 An error response sent as text/plain with nosniff: a browser never
	 renders the body as HTML, so the body is escaped for html, the sink
	 qa/taint-stubs.phpstub puts on Response::__construct.
	*/
	public static function plainTextResponse(string $body, int $status): Response {
		$response = new Response(self::plainTextBody($body), $status);
		$response->headers->set('Content-Type', 'text/plain; charset=utf-8');
		$response->headers->set('X-Content-Type-Options', 'nosniff');
		return $response;
	}

	/** @psalm-taint-escape html */
	private static function plainTextBody(string $body): string {
		return $body;
	}

	/*
	 Abort a web request with a real status code. Services are called from
	 controllers, and Router::run() only send()s the controller's Response
	 after dispatch() returns, so nothing has been written yet and headers
	 are still settable. Mirrors Kernel::bootFailure(): the client message
	 stays generic because the remedy is in the log.
	*/
	public static function failRequest(
		string $message,
		int $status = Response::HTTP_INTERNAL_SERVER_ERROR
	): never {
		/** @psalm-suppress RedundantCondition CLI_MODE is defined per entry point */
		if (defined('CLI_MODE') && CLI_MODE) {
			fwrite(STDERR, $message . PHP_EOL);
			exit(1);
		}

		self::plainTextResponse($message, $status)->send();

		exit(1);
	}

}
