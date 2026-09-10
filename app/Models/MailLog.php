<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

use App\Core\App;
//use App\Configuration\AppConfig;

use App\Utils\Helper;

use Throwable;

class MailLog extends Model
{

	/*
	protected $table = AppConfig::MAIL_LOGS_TABLE;
	const CREATED_AT = 'created_at';
	const UPDATED_AT = 'updated_at';
	*/
	public $timestamps = true;
	protected $primaryKey = 'id';

	protected $casts = [
		'id' => 'integer',
		'qid' => 'string',
		'server' => 'string',
		'subject' => 'string',
		'score' => 'float',
		'action' => 'string',
		'has_virus' => 'boolean',
		'ip' => 'string',
		'mail_from' => 'string',
		'mime_from' => 'string',
		'rcpt_to' => 'string',
		'mime_to' => 'string',
		'size' => 'integer',
		'mail_stored' => 'boolean',
		'mail_location' => 'string',
		'notified' => 'boolean',
		'notify_date' => 'datetime',
		'notification_pending' => 'boolean',
		'released' => 'boolean',
		'release_date' => 'datetime',
		'message_id' => 'string',
		'created_at' => 'datetime',
		'updated_at' => 'datetime',
		'created_day' => 'date',
	];

	protected $fillable = [
		'qid',
		'server',
		'subject',
		'score',
		'action',
		'has_virus',
		'ip',
		'mail_from',
		'mime_from',
		'rcpt_to',
		'mime_to',
		'size',
		'mail_stored',
		'mail_location',
		'notified',
		'notify_date',
		'released',
		'release_date',
		'message_id',
	];

	protected $appends = [
		'mime_from_decoded',
		'virus_from_symbol',
	];

	private ?string $mimeFromDecoded = null;

	public const array SELECT_FIELDS = [
		'id',
		'qid',
		'action',
		'has_virus',
		'mail_from',
		'rcpt_to',
		'mime_from',
		'mime_to',
		'subject',
		'size',
		'score',
		'server',
		'mail_stored',
		'mail_location',
		'message_id',
		'ip',
		'notified',
		'notify_date',
		'released',
		'release_date',
		'notification_pending',
		'created_at',
		'updated_at',
		'created_day',
	];

	public const array REPORT_FIELDS = [
		'ip',
		'action',
		'has_virus',
		'mail_from',
		'rcpt_to',
		'mime_from',
		'mime_to',
		'server',
		'mail_from_domain',
		'rcpt_to_domain',
		'mime_from_domain',
		'mime_to_domain',
		'date',
	];

	public const array REPORT_DYN_FIELDS = [
		'ip',
		'mail_from',
		'rcpt_to',
		'date',
		'server',
	];

	// Static DB field length limits
	public const array FIELD_LIMITS = [
		'qid'           => 30,
		'server'        => 10,
		'subject'       => 1024,
		'action'        => 20,
		'ip'            => 50,
		'mail_from'     => 255,
		'mime_from'     => 255,
		'rcpt_to'       => 1024,
		'mime_to'       => 1024,
		'mail_location' => 255,
		'message_id'    => 1024,
	];

	/* not needed now
	public function getTable() {
		return AppConfig::MAIL_LOGS_TABLE ?? 'mail_logs';
	}
	*/

	public function getVirusFromSymbolAttribute(): ?string {
		if (empty($this->getAttribute('has_virus'))) {
			return null;
		}

		$virus = Helper::check_virus_from_all($this->getAttribute('symbols'));

		return $virus === false ? null : $virus;
	}

	public function getMimeFromDecodedAttribute(): string {
		// Twig probes with isset() and accessor gets the attr.  cache it
		if ($this->mimeFromDecoded !== null) {
			return $this->mimeFromDecoded;
		}

		// If mime_from is missing or empty, just return mail_from
		if (empty($this->mime_from)) {
			return $this->mimeFromDecoded = $this->getMailFrom();
		}

		/*
		 mailparse warns from its tokenizer and then goto state_ground - it
		 keeps parsing, so the warning is implied by neither an empty result
		 nor a Throwable. Trap it for the duration of the call; returning
		 true keeps PHP's own handler from writing it to the error log
		 unattributed.
		*/
		$warning = null;

		set_error_handler(
			function (int $errno, string $errstr) use (&$warning): bool {
				$warning = $errstr;

				return true;
			},
			E_WARNING
		);

		try {
			$parsed = mailparse_rfc822_parse_addresses((string) $this->mime_from);
		} catch (Throwable $e) {
			$parsed = null;
			$warning ??= $e->getMessage();
		} finally {
			restore_error_handler();
		}

		if ($warning !== null) {
			$this->logUnparsableMimeFrom($warning);
		}

		if (is_array($parsed) && !empty($parsed[0]['address'])) {
			return $this->mimeFromDecoded = (string) $parsed[0]['address'];
		}

		// Fallback if no address found
		return $this->mimeFromDecoded = $this->getMailFrom();
	}

	private function getMailFrom() {
		return empty($this->mail_from) ? '' : (string) $this->mail_from;
	}

	private function logUnparsableMimeFrom(string $reason): void {
		try {
			// mime_from is unvalidated header content on its way into a
			// line-based log, so drop control characters and bound it.
			$raw = (string) preg_replace(
				'/[\x00-\x1F\x7F]+/',
				' ',
				(string) $this->mime_from
			);

			App::fileLogger()->warning(
				"MailLog {$this->getKey()} (qid {$this->qid}): mime_from is not "
				. "RFC822 compliant ({$reason}), value: '"
				. mb_substr($raw, 0, 120) . "'"
			);
		} catch (Throwable) {
			// App container not up in this context. A diagnostic must never
			// break the read it is diagnosing.
		}
	}

	public function recipients() {
		return $this->hasMany(
			MailLogRecipient::class,
			'mail_log_id',
			'id'
		);
	}

	public function getRcptToAttribute($value): string {
		if ($this->relationLoaded('recipients')) {
			$emails = $this->recipients
				->pluck('recipient_email')
				->map(fn ($e) => strtolower(trim($e)))
				->unique()
				->values()
				->all();

			return implode(', ', $emails);
		}
		return (string) $value;
	}

	public function mailLogData() {
		return $this->hasOne(
			MailLogData::class,
			'mail_log_id',
			'id'
		);
	}

	/*
	 headers/symbols/fuzzy_hashes live in mail_log_data. The mail_logs
	 columns are dropped, so the relation is the only source and there is no
	 fallback to $value -- a fallback would silently return null once the
	 columns are gone, hiding the missing eager load instead of reporting it.

	 Callers are expected to eager-load the relation: getMailLogRelations()
	 for the detail paths that need headers, getMailLogSymbolsRelations()
	 everywhere else. A caller that forgot still gets the right value, but
	 via a lazy load -- one query per row, which on a 50-row list page is a
	 regression worth seeing rather than absorbing.
	*/
	private function mailLogDataValue(string $column): mixed {
		if (!$this->relationLoaded('mailLogData')) {
			$this->logMissingRelation('mailLogData', $column);
		}

		$data = $this->mailLogData;

		if ($data === null) {
			return null;
		}

		/*
		 The relation is loaded but this column was excluded from it -- a
		 constrained eager load such as 'mailLogData:mail_log_id,symbols'
		 asked for the wrong tier. Eloquent returns null for a cast column
		 that was never selected, so without this the caller gets a silent
		 wrong answer and relationLoaded() reports success.
		*/
		if (!array_key_exists($column, $data->getAttributes())) {
			$this->logMissingRelation('mailLogData', $column);

			return null;
		}

		return $data->{$column};
	}

	private function logMissingRelation(string $relation, string $column): void {
		try {
			App::fileLogger()->error(
				"MailLog {$this->getKey()}: '{$relation}' was not "
				. "eager-loaded, lazy-loading it to read '{$column}'"
			);
		} catch (Throwable) {
			// App container not up in this context. A diagnostic must never
			// break the read it is diagnosing.
		}
	}

	public function getHeadersAttribute($value): ?string {
		return $this->mailLogDataValue('headers');
	}

	public function getSymbolsAttribute($value): ?array {
		return $this->mailLogDataValue('symbols');
	}

	public function getFuzzyHashesAttribute($value): ?array {
		return $this->mailLogDataValue('fuzzy_hashes');
	}

}
