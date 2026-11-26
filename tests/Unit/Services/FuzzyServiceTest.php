<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 What learn() and addWeight() refuse before any database or HTTP call: a
 flag not in fuzzy_learn_flags (logged), a weight outside 1-65535, and a
 server with no fuzzy_url in API_SERVERS (logged). learnLocal() refuses a
 mail that is not stored; a mail without a qid is named ''. Hits show
 once their migration has run, labelled by their symbol in upper case.
 The learned list sorts newest first, or by latest hit with the never hit
 last: by the SQL it builds, on a MySQL connection that is never opened.
*/

namespace Tests\Unit\Services;

use App\Configuration\Config;
use App\Core\App;
use App\Core\AppContainer;
use App\Core\Database\MigrationStatus;
use App\Core\Exception\FuzzyError;
use App\Core\Exception\FuzzyException;
use App\Inventory\Migrations;
use App\Models\MailLog;
use App\Models\MailLogFuzzy;
use App\Services\FuzzyService;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\Unit\UnitTestCase;

final class FuzzyServiceTest extends UnitTestCase
{
	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->setEnv('MY_API_SERVER_ALIAS', 'mx1');
		Config::set('fuzzy_learn_flags', [1 => ['label' => 'Spam']]);
		Config::set('API_SERVERS', ['mx1' => ['fuzzy_url' => 'http://127.0.0.1:11334'], 'mx2' => ['fuzzy_url' => ''], 'mx3' => [], 'mx4' => ['fuzzy_url' => 'http://192.0.2.4:11334']]);
	}

	#[\Override]
	protected function tearDown(): void {
		Model::unsetConnectionResolver();
		parent::tearDown();
	}

	private static function mail(?string $server, bool $stored = true, ?string $qid = 'Q1'): MailLog {
		$mail = new MailLog();
		$mail->setRawAttributes(['id' => 7, 'qid' => $qid, 'server' => $server, 'mail_stored' => $stored ? 1 : 0]);
		return $mail;
	}

	private static function row(string $server): MailLogFuzzy {
		$row = new MailLogFuzzy();
		$row->setRawAttributes(['id' => 3, 'qid' => 'Q1', 'api_server' => $server]);
		return $row;
	}

	/** @param \Closure(): mixed $call */
	private function assertRefused(\Closure $call, string $message, FuzzyError $error): void {
		try {
			$call();
		} catch (FuzzyException $e) {
			$this->assertSame($message, $e->getMessage());
			$this->assertSame($error, $e->error);
			return;
		}
		$this->fail('No FuzzyException');
	}

	/** @return array<string, array{?string, bool}> */
	public static function servers(): array {
		return [
			'fuzzy_url' => ['mx1', true],
			'empty fuzzy_url' => ['mx2', false],
			'no fuzzy_url' => ['mx3', false],
			'not in API_SERVERS' => ['mx5', false],
			'none' => [null, false],
		];
	}

	#[DataProvider('servers')]
	public function testServerEnabled(?string $server, bool $enabled): void {
		$this->assertSame($enabled, FuzzyService::serverEnabled($server));
	}

	// null is no server, even with an entry under ''
	public function testNoServerNotTheBlankEntry(): void {
		Config::set('API_SERVERS', ['' => ['fuzzy_url' => 'http://127.0.0.1:11334']]);
		$this->assertFalse(FuzzyService::serverEnabled(null));
	}

	public function testUnknownFlag(): void {
		$this->assertRefused(fn () => (new FuzzyService())->learn(self::mail('mx1'), 2, 'admin'),
			"Fuzzy flag 2 is not configured on 'mx1'", FuzzyError::Unavailable);
		$this->assertSame(["error [FuzzyService] Q1 fuzzy flag 2 is not in fuzzy_learn_flags on 'mx1'. Check config.local.php"], $this->log->lines);
	}

	public function testUnknownFlagNoAlias(): void {
		$this->setEnv('MY_API_SERVER_ALIAS', null);
		$this->assertRefused(fn () => (new FuzzyService())->learn(self::mail('mx1'), 2, 'admin'),
			"Fuzzy flag 2 is not configured on ''", FuzzyError::Unavailable);
	}

	/** @return array<string, array{int}> */
	public static function badWeights(): array {
		return ['zero' => [0], 'negative' => [-1], 'above 65535' => [65536]];
	}

	#[DataProvider('badWeights')]
	public function testLearnBadWeight(int $weight): void {
		$this->assertRefused(fn () => (new FuzzyService())->learn(self::mail('mx1'), 1, 'admin', $weight),
			"Invalid weight {$weight}, must be 1-65535", FuzzyError::Conflict);
		$this->assertSame([], $this->log->lines);
	}

	// checked here, for a remote server too (a local one checks again)
	#[DataProvider('badWeights')]
	public function testAddWeightBadWeight(int $weight): void {
		$this->assertRefused(fn () => (new FuzzyService())->addWeight(self::row('mx4'), $weight, 'admin'),
			"Invalid weight {$weight}, must be 1-65535", FuzzyError::Conflict);
		$this->assertSame([], $this->log->lines);
	}

	// a weight in range, or none, passes on to the server check
	/** @return array<string, array{?int}> */
	public static function goodWeights(): array {
		return ['one' => [1], 'max' => [65535], 'none' => [null]];
	}

	#[DataProvider('goodWeights')]
	public function testLearnServerNotEnabled(?int $weight): void {
		$this->assertRefused(fn () => (new FuzzyService())->learn(self::mail('mx2'), 1, 'admin', $weight),
			"Fuzzy learning is not available for server 'mx2'", FuzzyError::Unavailable);
		$this->assertSame(["error [FuzzyService_learn] Q1 no fuzzy_url for API server 'mx2'. Check config.local.php"], $this->log->lines);
	}

	public function testAddWeightServerNotEnabled(): void {
		$this->assertRefused(fn () => (new FuzzyService())->addWeight(self::row('mx3'), 65535, 'admin'),
			"Fuzzy learning is not available for server 'mx3'", FuzzyError::Unavailable);
		$this->assertSame(["error [FuzzyService_addWeight] Q1 no fuzzy_url for API server 'mx3'. Check config.local.php"], $this->log->lines);
	}

	public function testLearnLocalNotStored(): void {
		$this->assertRefused(fn () => (new FuzzyService())->learnLocal(self::mail('mx1', false), 1, 'admin'),
			'Mail Q1 is not stored', FuzzyError::Conflict);
	}

	// a mail without a qid is named ''
	public function testNoQid(): void {
		$this->assertRefused(fn () => (new FuzzyService())->learn(self::mail('mx1', true, null), 2, 'admin'),
			"Fuzzy flag 2 is not configured on 'mx1'", FuzzyError::Unavailable);
		$this->assertSame(["error [FuzzyService]  fuzzy flag 2 is not in fuzzy_learn_flags on 'mx1'. Check config.local.php"], $this->log->lines);
		$this->assertRefused(fn () => (new FuzzyService())->learnLocal(self::mail('mx1', false, null), 1, 'admin'),
			'Mail  is not stored', FuzzyError::Conflict);
	}

	// by symbol in upper case; a list without a symbol has none
	public function testSymbolLabels(): void {
		Config::set('fuzzy_learn_flags', [
			1 => ['label' => 'Spam', 'symbol' => 'rqwatch_fuzzy_denied'],
			2 => ['label' => 'Ham'],
			3 => ['label' => 'Phish', 'symbol' => 'RQWATCH_FUZZY_PHISH'],
		]);
		$this->assertSame(['RQWATCH_FUZZY_DENIED' => 'Spam', 'RQWATCH_FUZZY_PHISH' => 'Phish'], FuzzyService::symbolLabels());
	}

	public function testHitsEnabled(): void {
		$status = new MigrationStatus(null, $this->log);
		App::swap(new AppContainer(0.0, 0, $this->log, $this->log, null, $status, null, false));
		$this->assertFalse(FuzzyService::hitsEnabled());
		$status->setMigrationState(Migrations::MAIL_LOG_FUZZY_HITS, Migrations::STATUS_RUNNING);
		$this->assertFalse(FuzzyService::hitsEnabled());
		$status->setMigrationState(Migrations::MAIL_LOG_FUZZY_HITS, Migrations::STATUS_COMPLETED);
		$this->assertTrue(FuzzyService::hitsEnabled());
	}

	/** @return array<string, array{string, non-empty-string}> */
	public static function sorts(): array {
		return [
			'hit' => ['hit', 'order by last_hit_at IS NULL, `last_hit_at` desc, `id` desc'],
			'newest' => ['', 'order by `id` desc'],
			'anything else' => ['Hit', 'order by `id` desc'],
		];
	}

	/** @param non-empty-string $order */
	#[DataProvider('sorts')]
	public function testSortLearned(string $sort, string $order): void {
		$capsule = new Capsule();
		$capsule->addConnection(['driver' => 'mysql', 'host' => '192.0.2.1', 'database' => 'rqwatch', 'username' => 'none', 'password' => 'none']);
		$capsule->bootEloquent();
		/** @var Builder<MailLogFuzzy> $query */
		$query = (new ReflectionMethod(FuzzyService::class, 'sortLearned'))->invoke(null, MailLogFuzzy::query(), $sort);
		$this->assertStringEndsWith($order, $query->toSql());
	}
}
