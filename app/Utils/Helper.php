<?php
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Utils;

use App\Core\Config;
use Psr\Log\LoggerInterface;
use App\Core\Auth\AuthManager;

use PhpIP\IP;

use DateTime;
use DateTimeZone;

class Helper {

	private static ?LoggerInterface $logger = null;

	public static function setLogger(LoggerInterface $logger): void {
		self::$logger = $logger;
	}

	// Stores raw mail into filesystem. Can be used for release to user
	public static function store_raw_mail($dir, $qid) {
		if (!is_dir($dir)) {
			self::$logger->error("$dir does not exist");
			return false;
		}

		if (!is_writable($dir)) {
			self::$logger->error("$dir is not writable");
			return false;
		}

		$raw_input = file_get_contents('php://input');

		if (empty($raw_input)) {
			self::$logger->error("No input received.");
			http_response_code(400);
			return false;
		}

		// Write raw input to a file
		$date = date("Y-m-d");
		$dir_raw = $dir . "/" . $date . "/";

		if (!empty($qid) and $qid != "unknown") {
			$dir_raw .= $qid;
		} else {
			$dir_raw .= "unknown/" . uniqid();
		}

		if (!file_exists($dir_raw))
			if (!mkdir($dir_raw, 0750, true)) {
				self::$logger->error("Error creating directory $dir_raw");
			}

		$file_raw = $dir_raw . "/mail.eml";

		if (file_put_contents($file_raw, $raw_input) === false) {
			self::$logger->error("Failed to write raw email to file: $file_raw");
		}
		return $file_raw;
	}

	public static function get_today() {
		return date("Y-m-d");
	}

	public static function checkForMap(array $symbols): bool {
		foreach ($symbols as $symbol) {
			if (isset($symbol['name']) && (
				 (preg_match('/^RQWATCH_.*$/i', $symbol['name'])) ||
				 (preg_match('/^LOCAL_.*$/i', $symbol['name'])))) {
					return true;
			}
		}
		return false;
	}

	public static function checkForWhitelist(array $symbols): bool {
		foreach ($symbols as $symbol) {
			if (isset($symbol['name']) &&
			    preg_match('/^RQWATCH_.*_(WL|WHITELIST)/i', $symbol['name'])
			) {
					return true;
			  }
		}
		return false;
	}

	public static function checkForBlacklist(array $symbols): bool {
		foreach ($symbols as $symbol) {
			if (isset($symbol['name']) &&
			    preg_match('/^RQWATCH_.*_(BL|BLACKLIST)/i', $symbol['name'])
			) {
					return true;
			  }
		}
		return false;
	}

	// change class depending on symbol
	public static function get_symbol_class(string $symbol = null): string {
		if (!empty($symbol)) {
			$check[0]['name'] = $symbol;
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

	public static function get_action_details($symbols = null) {
		if (!empty($symbols)) {
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
	public static function get_row_class(string $action, $symbols = null) {
		if (!empty($symbols)) {
			if (self::checkForWhitelist($symbols)) {
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
	public static function get_scores(array $symbols_ar, float $score): array {
		//$symbols_ar = json_decode($symbols, true);

		$score_sort = ($score > 0) ? SORT_DESC : SORT_ASC;

		$scores = array_column($symbols_ar, 'score');
		array_multisort($scores, $score_sort, SORT_NUMERIC, $symbols_ar);

		return $symbols_ar;
	}

	public static function get_runtime($startTime, $startMemory) {
		$endTime = microtime(true);
		$endMemory = memory_get_usage();
		$peakMemory = memory_get_peak_usage();

		$executionTime = $endTime - $startTime;
		$memoryUsed = $endMemory - $startMemory;

		$runtime = sprintf(
			"Execution time: %.3f sec | Memory used: %.2f MB | Peak memory: %.2f MB",
			$executionTime,
			$memoryUsed / 1048576,
			$peakMemory / 1048576
		);

		return $runtime;
	}

	public static function convertSRSAddress($srsAddress) {
	    // Normalize case
	    $srsAddress = strtolower($srsAddress);

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

	public static function convertPRVSAddress($email) {
	// Match PRVS format
	if (preg_match('/^prvs=[^=]+=(.+)@(.+)$/i', $email, $matches)) {
	    $local = $matches[1];
	    $domain = $matches[2];
	    return "$local@$domain";
	}

	return $email;
	}

	public static function decodeEmail($email) {
	// Step 1: Decode SRS if applicable
	$decoded = self::convertSRSAddress($email);

	// Step 2: Decode PRVS if present
	$decoded = self::convertPRVSAddress($decoded);

	return $decoded;
	}

	// Snippet from PHP Share: http://www.phpshare.org
	public static function formatSizeUnits($bytes) {
	if ($bytes >= 1073741824) {
	    $bytes = number_format($bytes / 1073741824, 1, ',', '.') . 'G';
	} elseif ($bytes >= 1048576) {
	    $bytes = number_format($bytes / 1048576, 1, ',', '.') . 'M';
	} elseif ($bytes >= 1024) {
	    $bytes = number_format($bytes / 1024, 1, ',', '.') . 'K';
	} elseif ($bytes > 1) {
	    $bytes = $bytes . 'B';
	} elseif ($bytes == 1) {
	    $bytes = $bytes . 'B';
	} else {
	    $bytes = 'Null';
	}

	return $bytes;
	}

	// expects json decoded array with one symbol
	public static function check_virus($symbol) {

		if (!is_array($symbol) or count($symbol) == 0) {
			return false;
		}
		if (isset($symbol['group']) and $symbol['group'] == 'antivirus') {
			if (isset($symbol['options']) and isset($symbol['options'][0])) {
				$virus = $symbol['options'][0];
			} else {
				$virus = '';
			}
			$antivirus = $symbol['name'];
			return "[$antivirus: $virus]";
		}
		return false;
	}

	// expects json decoded array with all symbols
	public static function check_virus_from_all($symbols) {
		if (!is_array($symbols) or count($symbols) == 0) {
			return false;
		}

		foreach ($symbols as $symbol) {
			if ($ret = self::check_virus($symbol)) {
				return $ret;
			} 
		}
		return false;
	}

	// checks date for correct format YYYY-MM-DD
	public static function check_date($day) {
		if (!$day) {
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

	public static function sanitize_mail($mail) {
		return preg_replace('/[^a-zA-Z0-9 .\-<>@]/', '', $mail);
	}

	public static function sanitize_string($mail) {
		return preg_replace('/[^a-zA-Z0-9.\-]/', '', $mail);
	}

	public static function log_to_files(string $dir, $symbols, $web_headers, $stringHeaders, $arrayHeaders): bool {
		if (!is_dir($dir)) {
			self::$logger->warning("$dir does not exist");
			if (!mkdir($dir, 0750, true)) {
				self::$logger->error("Error creating directory $dir");
				return false;
			} else {
				self::$logger->info("Created directory $dir");
			}
		}

		if (!is_writable($dir)) {
			self::$logger->error("$dir is not writable");
			return false;
		}

		// symbols_array is string
		$symbols_array = print_r(json_decode($symbols, true), true);

		if (!$fp_web_headers = fopen("{$dir}/web_headers", "w")) {
			self::$logger->error("Cannot open file ({$dir}/web_headers)");
		}

		foreach ($web_headers as $key => $value) {
			fwrite($fp_web_headers, "$key => $value\r\n");
		}
		fclose($fp_web_headers);

		if (!$fp_server = fopen("{$dir}/server", "w")) {
			self::$logger->error("Cannot open file ({$dir}/server)");
		}

		foreach ($_SERVER as $key => $value) {
			fwrite($fp_server, "$key => $value\r\n");
		}
		fclose($fp_server);

		if (!$fp_symbols = fopen("{$dir}/symbols", "w")) {
			self::$logger->error("Cannot open file ({$dir}/symbols)");
		}

		fwrite($fp_symbols, $symbols_array);
		fclose($fp_symbols);

		if (!$fp_headers = fopen("{$dir}/headers", "w")) {
			self::$logger->error("Cannot open file ({$dir}/headers)");
		}

		fwrite($fp_headers, $stringHeaders);
		fclose($fp_headers);

		if (!$fp_headers_ar = fopen("{$dir}/headers_ar", "w")) {
			self::$logger->error("Cannot open file ({$dir}/headers_ar)");
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

		self::$logger->info("Saved debug files in $dir");
		return true;
	}

	public static function format_symbols(array $symbols_ar, float $score, bool $has_virus): array {
		$sorted_symbols = self::get_scores($symbols_ar, $score);

		$symbols = array();
		$virus_found = null;

		foreach ($sorted_symbols as $num => $symbol) {
			 $name = $symbol['name'];
			 $score = $symbol['score'];

			 if ($has_virus and ($virus = Helper::check_virus($symbol))) {
					$symbols[] = "$name:$score:$virus";
					$virus_found = $virus;
			 } elseif (isset($symbol['options'])) {
					$opts = implode(", ", $symbol['options']);
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

	public static function check_id_qid(string $type, string|int $value): array {
		$error = false;
		$id = false;
		$qid = false;

		if (empty($value)) {
			$error = "Empty value";
		}
      elseif ($type === 'id') {
         if (!ctype_digit($value)) {
            $error = "ID wrong format";
         } else {
            $id = (int) $value;
            $where = 'id';
            $what = $id;
         }
      } elseif ($type === 'qid') {
         if (!preg_match('/^[a-zA-Z0-9]+$/', $value)) {
            $error = "QID wrong format";
         } else {
            $qid = $value;
            $where = 'qid';
            $what = $qid;
         }
      } else {
         $error = "Unknown error";
      }

		$ret = array(
			'error' => $error,
			'id'    => $id,
			'qid'   => $qid,
		);

		return $ret;
	}

	public static function removeValFromArr(array $array, string $value): array {
		$key = array_search($value, $array);
		if ($key !== false) {
			unset($array[$key]);
			return array_values($array);
		}
		return $array;
	}

	public static function removeArrFromArr(array $array, array $values): array {
		$array = array_diff($array, $values);
		return array_values($array);
	}

	public static function addArrToArr(array $array, array $values): array {
		foreach ($values as $val) {
			if (!in_array($val, $array, true)) {
				$array[] = $val;
			}
		}
		return $array;
	}

	public static function passwordHash(string $clear_pass): ?string {
		if (!empty($clear_pass)) {
			return password_hash($clear_pass, Config::get('password_hash'));
		}
		return null;
	}

	public static function passwordVerify(string $clear_pass, string $hash): bool {
		if (!empty($clear_pass) and !empty($hash) and 
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

	public static function cleanEmailList(string $input): array {
		$emails = explode(',', $input);
		$emails = array_map(function($email) {
			return strtolower(trim($email));
		}, $emails);

		$emails = array_filter($emails);

		if (!empty($emails)) {
			return $emails;
		}
		return [];
	}

	public static function getReleaseText($ar): string {
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
		if (empty($ar['has_virus'])) {
			$ret .= " Yes {$ar['virus_name']}";
		} else {
			$ret .= " No";
		}

		$ret .= "
Action: {$ar['action']}

{$ar['signature']}";
		return $ret;
	}

	public static function getNotifyText($ar): string {
		$ret = "The following mail has been saved in our quarantine storage
because our system has detected it as being high scored spam or that it contains a virus.

Date: {$ar['created_at']}
From: {$ar['mime_from']}
To: {$ar['rcpt_to']}
Subject: {$ar['subject']}
Mail Queue ID: {$ar['qid']}
Spam Score: {$ar['score']}
Virus Detected:";
		if (empty($ar['has_virus'])) {
			$ret .= " Yes";
		} else {
			$ret .= " No";
		}

		$ret .= "
Action: {$ar['action']}

You can see mail details and release it from quarantine by clicking here:
{$ar['detailurl']}

{$ar['signature']}";
		return $ret;
	}

	public static function env_bool(string $key, bool $default = false): bool {
		return filter_var($_ENV[$key] ?? $default, FILTER_VALIDATE_BOOLEAN);
	}

	public static function extract_mail_relays(string $headers): array {
		$lines = preg_split('/\r\n|\r|\n/', $headers);
		$relays = [];

		foreach ($lines as $line) {
			if (stripos($line, 'Received:') === false) {
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
			if (preg_match_all('/\[(IPv6:)?([^\]]+)\]/i', $line, $ipMatches)) {
				foreach ($ipMatches[2] as $rawIp) {
					$cleanIp = preg_replace('/^IPv6:/i', '', $rawIp);
					if (filter_var($cleanIp, FILTER_VALIDATE_IP)) {
						$ips[] = $cleanIp;
					}
				}
			}

			// Try to extract hostname from "from" or "by"
			if (preg_match('/(?:from|by)\s+([a-z0-9\.\-]+\.[a-z]{2,})/i', $line, $hostMatch)) {
				$host = strtolower($hostMatch[1]);
			}

			//if ($ip) {
			if (!empty($ips)) {
				foreach (array_reverse($ips) as $ip) {
					// Resolve hostname from IP (optional; basic reverse lookup)
					$resolvedHost = gethostbyaddr($ip);
					$relays[] = [
						'ip' => $ip,
						'host' => ($resolvedHost !== $ip) ? $resolvedHost : ($host ?? null),
					];
				}
			} elseif ($host) {
				// No IP found — try resolving IP from hostname
				$resolvedIp = gethostbyname($host);
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
				$unique_relay[] = $relay;
			}
		}

		foreach ($unique_relay as $key => $relay) {
			$unique_relay[$key]['country'] = self::getCountry($relay['ip']);
		}

		return $unique_relay;
	}

	public static function getCountry(string $ip): ?string {
		if (Config::get('geoip_enable') && ($geoip_db = Config::get('geoip_country_db')) &&
				!self::isLocalOrReservedIp($ip)) {
			$geoip_reader = new \MaxMind\Db\Reader($geoip_db);
			$geo = $geoip_reader->get($ip);
			$geoip_reader->close();

			if (!empty($geo['country']['names']['en'])) {
				return $geo['country']['names']['en'];
			}
		}
		return null;
	}

	public static function isLocalOrReservedIp(string $ip): bool {
		try {
			$ipObj = IP::create($ip);
		} catch (\InvalidArgumentException $e) {
			return false; // Invalid IP format
		}

		return $ipObj->isPrivate()
			|| $ipObj->isReserved()
			|| $ipObj->isLoopback()
			|| $ipObj->isLinkLocal();
	}

	public static function getAuthProvider(int $id): ?string {
		if ($id !== null) {
			return AuthManager::getAuthProviderById($id);
		}
		return null;
	}

	public static function deleteDirectory(string $dir): bool {
		$quarantineDir = rtrim($_ENV['QUARANTINE_DIR'], DIRECTORY_SEPARATOR);

		// Resolve real paths for security
		$realDir = realpath($dir);
		$realQuarantine = realpath($quarantineDir);

		// Safety check: ensure $dir is inside quarantine directory
		if (!$realDir || !$realQuarantine || strpos($realDir, $realQuarantine) !== 0) {
			// Not inside QUARANTINE_DIR, do NOT delete
			return false;
		}

		if (!is_dir($realDir)) {
			return false;
		}

		$files = array_diff(scandir($realDir), ['.', '..']);
		foreach ($files as $file) {
			$path = $realDir . DIRECTORY_SEPARATOR . $file;
			if (is_dir($path)) {
				self::deleteDirectory($path);
			} else {
				unlink($path);
			}
		}

		rmdir($realDir);
		return true;
	}

	public static function toUtcTimestamp(string $datetime, string $fromTz = null): int {
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

		return $parse[0]['address'] ? $parse[0]['address'] : '';
	}

}
