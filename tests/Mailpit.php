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
	 * GET from Mailpit's API.
	 * @return array<string, mixed>
	 */
	protected static function mailpit(string $path): array {
		return json_decode(self::mailpitGet($path), true, 512, JSON_THROW_ON_ERROR);
	}

	/** Messages Mailpit has received in this run. */
	protected static function mailCount(): int {
		return (int) self::mailpit('/api/v1/messages?limit=1')['total'];
	}

	/**
	 * Messages delivered to $address (To, Cc or envelope-only Bcc), optionally
	 * only those matching a Mailpit search for $text.
	 * @return list<array<string, mixed>> Mailpit message summaries
	 */
	protected static function mailsFor(string $address, ?string $text = null): array {
		$found = [];
		for ($start = 0; ; $start += 500) {
			$page = $text === null
				? self::mailpit("/api/v1/messages?limit=500&start={$start}")
				: self::mailpit('/api/v1/search?limit=500&start=' . $start . '&query=' . rawurlencode($text));
			foreach ($page['messages'] as $message) {
				if (in_array(strtolower($address), self::recipientsOf($message), true)) {
					$found[] = $message;
				}
			}
			if ($start + 500 >= (int) $page['messages_count']) {
				return $found;
			}
		}
	}

	/**
	 * Every address a captured message was delivered to, sorted.
	 * @param array<string, mixed> $message Mailpit message summary
	 * @return list<string>
	 */
	protected static function recipientsOf(array $message): array {
		$addresses = [];
		foreach (['To', 'Cc', 'Bcc'] as $field) {
			foreach ($message[$field] ?? [] as $entry) {
				$addresses[] = strtolower((string) $entry['Address']);
			}
		}
		$addresses = array_values(array_unique($addresses));
		sort($addresses);
		return $addresses;
	}

	/** The raw bytes of the one message/rfc822 attachment of a captured message. */
	protected static function attachedMail(string $id): string {
		$parts = array_values(array_filter(
			self::mailpit("/api/v1/message/{$id}")['Attachments'] ?? [],
			fn($part) => $part['ContentType'] === 'message/rfc822'
		));
		if (count($parts) !== 1) {
			throw new RuntimeException("message {$id}: expected one message/rfc822 attachment, found " . count($parts));
		}
		return self::mailpitGet("/api/v1/message/{$id}/part/{$parts[0]['PartID']}");
	}

	private static function mailpitGet(string $path): string {
		$ch = curl_init(rtrim(static::mailpitUrl(), '/') . $path);
		curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
		$body = curl_exec($ch);
		$status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		if (!is_string($body) || $status !== 200) {
			throw new RuntimeException("Mailpit {$path} answered {$status}");
		}
		return $body;
	}
}
