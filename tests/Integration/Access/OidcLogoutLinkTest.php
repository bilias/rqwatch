<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 After logging out of rqwatch with the OpenID Connect session still open
 (/login?openidc_session_active=1), the login page offers a link to log
 out at the identity provider: its end_session_endpoint, read from the
 provider's discovery document, with this client's id and the login page
 to come back to, added with '&' when the endpoint has a query already.
 The link is shown once. A provider without the endpoint gets no link and
 a warning in rqwatch.log. The provider here is a discovery document this
 instance serves as a static file, removed afterwards.
*/

namespace Tests\Integration\Access;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Integration\IntegrationTestCase;

final class OidcLogoutLinkTest extends IntegrationTestCase
{
	private const string PROVIDER = 'oidc-logout-link-test';
	private const string CLIENT_ID = 'rqwatch-integration';
	private const string STILL_ACTIVE = "You are logged out, but your 'SSO' session is still active.";

	private bool $served = false;

	/** @var array{string, string}|null the two pages logoutPages() loaded */
	private ?array $bodies = null;

	#[\Override]
	protected function tearDown(): void {
		if ($this->served) {
			$dir = self::root() . '/web/' . self::PROVIDER;
			@unlink("{$dir}/.well-known/openid-configuration");
			@rmdir("{$dir}/.well-known");
			@rmdir($dir);
		}
		parent::tearDown();
	}

	/** @param array<string, string> $discovery the provider's discovery document */
	private function serve(array $discovery): void {
		$dir = self::root() . '/web/' . self::PROVIDER . '/.well-known';
		if (!is_dir($dir)) {
			mkdir($dir, 0755, true);
		}
		$this->served = true;
		file_put_contents("{$dir}/openid-configuration", json_encode($discovery, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
	}

	/**
	 * The login page after /login?openidc_session_active=1, with OpenID
	 * Connect on and this test's provider, and the page loaded again
	 * @return array{string, string} the first and the second page
	 */
	private function logoutPages(): array {
		self::withEnv([
			'OPENIDC_AUTH_ENABLED' => 'true',
			'OPENIDC_LABEL' => 'SSO',
			'OPENIDC_URL' => rtrim((string) getenv('INTEGRATION_BASE_URL'), '/') . '/' . self::PROVIDER,
			'OPENIDC_CLIENT_ID' => self::CLIENT_ID,
			'OPENIDC_CLIENT_SECRET' => 'integration-oidc-client-secret',
		], function (): void {
			$client = self::anonymous();
			$response = $client->get('/login?openidc_session_active=1');
			$this->assertSame([302, '/login'], [$response['status'], self::target($response)]);
			$first = $client->get('/login');
			$second = $client->get('/login');
			$this->assertSame([200, 200], [$first['status'], $second['status']]);
			$this->bodies = [$first['body'], $second['body']];
		});
		return $this->loadedPages();
	}

	/** @return array{string, string} what the closure in logoutPages() stored */
	private function loadedPages(): array {
		return $this->bodies ?? throw new \LogicException('the pages were not loaded');
	}

	// the identity provider logout link on a page, decoded; null without one
	private static function logoutLink(string $body): ?string {
		return preg_match('/<a href="([^"]*)" rel="noopener">\s*Click here to log out from/', $body, $link) === 1
			? html_entity_decode($link[1], ENT_QUOTES | ENT_HTML5)
			: null;
	}

	/** @return array<string, array{string, string}> end_session_endpoint, separator before rqwatch's parameters */
	public static function endpoints(): array {
		return [
			'plain' => ['https://idp.example.org/realms/r/protocol/openid-connect/logout', '?'],
			'with a query' => ['https://idp.example.org/logout?ui=compact', '&'],
		];
	}

	#[TestDox('The provider\'s end_session_endpoint, with the client id and the way back to login, shown once')]
	#[DataProvider('endpoints')]
	public function testLinkFromDiscovery(string $endpoint, string $separator): void {
		$this->serve(['issuer' => 'https://idp.example.org', 'end_session_endpoint' => $endpoint]);
		[$first, $second] = $this->logoutPages();
		$this->assertStringContainsString(self::STILL_ACTIVE, html_entity_decode($first, ENT_QUOTES | ENT_HTML5));
		$this->assertSame($endpoint . $separator . http_build_query([
			'client_id' => self::CLIENT_ID,
			'post_logout_redirect_uri' => rtrim((string) getenv('INTEGRATION_BASE_URL'), '/') . '/login',
		]), self::logoutLink($first));
		$this->assertNull(self::logoutLink($second), 'shown once');
	}

	#[TestDox('A provider without end_session_endpoint: no link, a warning logged')]
	public function testNoEndpoint(): void {
		$log = self::root() . '/logs/rqwatch.log';
		clearstatcache();
		$before = is_file($log) ? (int) filesize($log) : 0;
		$this->serve(['issuer' => 'https://idp.example.org']);
		[$first] = $this->logoutPages();
		$this->assertStringContainsString(self::STILL_ACTIVE, html_entity_decode($first, ENT_QUOTES | ENT_HTML5));
		$this->assertNull(self::logoutLink($first));
		clearstatcache();
		$this->assertStringContainsString(
			'OPENIDC end_session_endpoint unavailable: The provider end_session_endpoint could not be fetched.',
			(string) file_get_contents($log, false, null, $before)
		);
	}
}
