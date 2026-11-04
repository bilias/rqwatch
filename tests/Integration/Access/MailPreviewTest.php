<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 A quarantined HTML mail on the mail page, for its recipient and for an
 admin, renders in a sandboxed srcdoc iframe, and nothing in it can load
 from another origin: the iframe inherits the page's Content-Security-Policy
 (its own csp attribute is Chromium-only, Firefox ignores it). The mail's
 remote image, stylesheet and background are the positive control that the
 HTML part is rendered at all.
*/

namespace Tests\Integration\Access;

use PHPUnit\Framework\Attributes\TestDox;
use Tests\Integration\IntegrationTestCase;

final class MailPreviewTest extends IntegrationTestCase
{
	private const ID = 9501;
	private const REMOTE = 'https://tracker.example.net/pixel.gif';

	#[TestDox('HTML mail preview: sandboxed srcdoc iframe, and the page CSP allows no remote loads, for the recipient and an admin')]
	public function testHtmlPreview(): void {
		$this->quarantined(self::ID, 'mx1');
		file_put_contents(self::quarantineFile(self::ID), self::htmlMail());

		foreach (['alice' => '/mail/' . self::ID, 'admin' => '/admin/mail/' . self::ID] as $user => $path) {
			$page = self::client($user)->get($path);
			$this->assertSame(200, $page['status'], $user);
			if (preg_match('/<iframe\b([^>]*)>/', $page['body'], $iframe) !== 1) {
				$this->fail("{$user}: no iframe");
			}
			$this->assertMatchesRegularExpression('/\ssandbox(\s|$)/', $iframe[1], "{$user}: bare sandbox attribute");
			if (preg_match('/\bsrcdoc="([^"]*)"/', $iframe[1], $srcdoc) !== 1) {
				$this->fail("{$user}: no srcdoc");
			}
			$this->assertStringContainsString(self::REMOTE, html_entity_decode($srcdoc[1], ENT_QUOTES | ENT_HTML5), "{$user}: HTML part rendered");
			$this->assertNoRemoteLoads($page, $user);
		}
	}

	private static function htmlMail(): string {
		$id = self::ID;
		return "From: sender@example.net\r\nTo: alice@example.org\r\n"
			. "Subject: SUBJ-Q-{$id}\r\nMessage-ID: <q-{$id}@example.net>\r\n"
			. "MIME-Version: 1.0\r\nContent-Type: text/html; charset=utf-8\r\n\r\n"
			. '<html><head><link rel="stylesheet" href="https://tracker.example.net/s.css"></head>'
			. '<body style="background: url(https://tracker.example.net/bg.png)">'
			. '<img src="' . self::REMOTE . "\" alt=\"\">Body of SUBJ-Q-{$id}</body></html>\r\n";
	}
}
