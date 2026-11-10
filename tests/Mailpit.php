<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis
 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Mail captured by the harness's Mailpit, read back through its HTTP API.
 Used by the integration and end-to-end bases; each says where its Mailpit is.
*/

namespace Tests;

use RuntimeException;

trait Mailpit
{
	/** Base URL of the harness's Mailpit HTTP API. */
	abstract protected static function mailpitUrl(): string;

	/** An address no other test uses, so the mail it receives is this test's. */
	protected static function address(string $prefix): string {
		return $prefix . '-' . bin2hex(random_bytes(4)) . '@rqwatch.test';
	}

	/**
	 * GET from Mailpit's API: a JSON object, or the test fails.
	 * @return array<array-key, mixed>
	 */
	protected static function mailpit(string $path): array {
		/** @var mixed $decoded */
		$decoded = json_decode(self::mailpitGet($path), true, 512, JSON_THROW_ON_ERROR);
		return is_array($decoded) ? $decoded : throw new RuntimeException("Mailpit {$path}: not a JSON object");
	}

	/** Messages Mailpit has received in this run. */
	protected static function mailCount(): int {
		/** @var mixed $total */
		$total = self::mailpit('/api/v1/messages?limit=1')['total'] ?? null;
		return is_int($total) ? $total : throw new RuntimeException('Mailpit: no message total');
	}

	/**
	 * Messages delivered to $address (To, Cc or envelope-only Bcc), optionally
	 * only those matching a Mailpit search for $text.
	 * @return list<array{ID: string, Subject: string, ...}> Mailpit message summaries
	 */
	protected static function mailsFor(string $address, ?string $text = null): array {
		$found = [];
		for ($start = 0; ; $start += 500) {
			$page = $text === null
				? self::mailpit("/api/v1/messages?limit=500&start={$start}")
				: self::mailpit('/api/v1/search?limit=500&start=' . $start . '&query=' . rawurlencode($text));
			/** @var mixed $messages */
			$messages = $page['messages'] ?? null;
			/** @var mixed $count */
			$count = $page['messages_count'] ?? null;
			if (!is_array($messages) || !is_int($count)) {
				throw new RuntimeException('Mailpit: not a message list');
			}
			/** @var mixed $message */
			foreach ($messages as $message) {
				$message = self::message($message);
				if (in_array(strtolower($address), self::recipientsOf($message), true)) {
					$found[] = $message;
				}
			}
			if ($start + 500 >= $count) {
				return $found;
			}
		}
	}

	/**
	 * A Mailpit message summary, with the fields the tests read checked
	 * @return array{ID: string, Subject: string, ...}
	 */
	private static function message(mixed $message): array {
		if (!is_array($message) || !is_string($message['ID'] ?? null) || !is_string($message['Subject'] ?? null)) {
			throw new RuntimeException('Mailpit: a message without an ID and Subject');
		}
		/** @var array{ID: string, Subject: string, ...} $message checked above */
		return $message;
	}

	/**
	 * Every address a captured message was delivered to, sorted.
	 * @param array<array-key, mixed> $message Mailpit message summary
	 * @return list<string>
	 */
	protected static function recipientsOf(array $message): array {
		$addresses = [];
		foreach (['To', 'Cc', 'Bcc'] as $field) {
			/** @var mixed $entries */
			$entries = $message[$field] ?? [];
			if (!is_array($entries)) {
				throw new RuntimeException("Mailpit: {$field} is not a list");
			}
			/** @var mixed $entry */
			foreach ($entries as $entry) {
				/** @var mixed $address */
				$address = is_array($entry) ? ($entry['Address'] ?? null) : null;
				$addresses[] = is_string($address) ? strtolower($address) : throw new RuntimeException("Mailpit: a {$field} entry without an Address");
			}
		}
		$addresses = array_values(array_unique($addresses));
		sort($addresses);
		return $addresses;
	}

	/** The plain-text body of a captured message. */
	protected static function messageText(string $id): string {
		/** @var mixed $text */
		$text = self::mailpit('/api/v1/message/' . self::pathSegment($id))['Text'] ?? null;
		return is_string($text) ? $text : throw new RuntimeException("message {$id}: no Text");
	}

	/** The raw bytes of the one message/rfc822 attachment of a captured message. */
	protected static function attachedMail(string $id): string {
		$id = self::pathSegment($id);
		/** @var list<array<string, mixed>> $attachments */
		$attachments = self::mailpit("/api/v1/message/{$id}")['Attachments'] ?? [];
		$parts = array_values(array_filter(
			$attachments,
			fn($part) => $part['ContentType'] === 'message/rfc822'
		));
		if (count($parts) !== 1) {
			throw new RuntimeException("message {$id}: expected one message/rfc822 attachment, found " . count($parts));
		}
		/** @var mixed $partId */
		$partId = $parts[0]['PartID'] ?? null;
		if (!is_string($partId)) {
			throw new RuntimeException("message {$id}: an attachment without a PartID");
		}
		return self::mailpitGet("/api/v1/message/{$id}/part/" . self::pathSegment($partId));
	}

	/*
	 A Mailpit id for an API path: a message id (base62) or a MIME part id
	 ("2", "1.2"). Letters and digits, single dots between: safe in the
	 URL path, or for any other sink.
	*/
	/** @psalm-taint-escape input */
	private static function pathSegment(string $value): string {
		if (!preg_match('/^[A-Za-z0-9]+(\.[A-Za-z0-9]+)*$/D', $value)) {
			throw new RuntimeException("Unexpected Mailpit id '{$value}'");
		}
		return $value;
	}

	private static function mailpitGet(string $path): string {
		$ch = curl_init(rtrim(static::mailpitUrl(), '/') . $path) ?: throw new RuntimeException('curl_init() failed');
		curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
		$body = curl_exec($ch);
		$status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		if (!is_string($body) || $status !== 200) {
			throw new RuntimeException("Mailpit {$path} answered {$status}");
		}
		return $body;
	}
}
