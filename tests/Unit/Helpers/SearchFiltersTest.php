<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The search filters, label => column. Those reading a table a migration
 fills show only once it has run: Virus with mail_log_viruses, Local symbol
 and Map hit with the map hits, Fuzzy hit with fuzzy learning on and both
 fuzzy migrations done. A user does not get Server or Fuzzy hit. A search's
 filters map to their columns and operators; a filter or choice that is not
 offered maps to none, its value kept.
*/

namespace Tests\Unit\Helpers;

use App\Configuration\Config;
use App\Core\App;
use App\Core\AppContainer;
use App\Core\Database\MigrationStatus;
use App\Inventory\Migrations;
use App\Utils\FormHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Unit\UnitTestCase;

final class SearchFiltersTest extends UnitTestCase
{
	private const array ALL = [
		'Date' => 'created_day',
		'Subject' => 'subject',
		'Action' => 'action',
		'Score' => 'score',
		'Stored (0/1)' => 'mail_stored',
		'Received from IP' => 'ip',
		'MAIL From' => 'mail_from',
		'RCPT To' => 'mail_recipients',
		'MIME From' => 'mime_from',
		'MIME To' => 'mime_to',
		'Fuzzy hit (0/1)' => 'fuzzy_hit',
		'Has Virus (0/1)' => 'has_virus',
		'Virus' => 'virus',
		'Size' => 'size',
		'Headers' => 'headers',
		'Rspamd symbols' => 'symbols',
		'Local symbol' => 'local_symbol',
		'Map hit (0/1)' => 'map_hit',
		'Released (0/1)' => 'released',
		'Notified (0/1)' => 'notified',
		'Notification pending (0/1)' => 'notification_pending',
		'Notification skipped (0/1)' => 'notification_skipped',
		'Server' => 'server',
	];

	private MigrationStatus $status;

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->status = new MigrationStatus(null, $this->log);
		App::swap(new AppContainer(0.0, 0, $this->log, $this->log, null, $this->status, null, false));
	}

	public function testAllMigrationsDone(): void {
		Config::set('fuzzy_learn_enable', true);
		$this->complete(Migrations::MAIL_LOG_VIRUSES, Migrations::MAIL_LOG_FUZZY, Migrations::MAIL_LOG_FUZZY_HITS_MAILS, Migrations::MAP_HITS);
		$this->assertSame(self::ALL, FormHelper::getFilters(true));
		$user = self::ALL;
		unset($user['Fuzzy hit (0/1)'], $user['Server']);
		$this->assertSame($user, FormHelper::getFilters(false));
	}

	public function testNoMigrationsDone(): void {
		Config::set('fuzzy_learn_enable', true);
		$this->assertSame(array_diff_key(self::ALL, array_flip(['Fuzzy hit (0/1)', 'Virus', 'Local symbol', 'Map hit (0/1)'])),
			FormHelper::getFilters(true));
	}

	/** @return array<string, array{bool, list<string>, list<string>}> fuzzy learning on, migrations done, the filters they add */
	public static function migrations(): array {
		return [
			'viruses' => [false, [Migrations::MAIL_LOG_VIRUSES], ['Virus']],
			'map hits' => [false, [Migrations::MAP_HITS], ['Local symbol', 'Map hit (0/1)']],
			'fuzzy: on, both done' => [true, [Migrations::MAIL_LOG_FUZZY, Migrations::MAIL_LOG_FUZZY_HITS_MAILS], ['Fuzzy hit (0/1)']],
			'fuzzy: off' => [false, [Migrations::MAIL_LOG_FUZZY, Migrations::MAIL_LOG_FUZZY_HITS_MAILS], []],
			'fuzzy: hit mails not done' => [true, [Migrations::MAIL_LOG_FUZZY], []],
			'fuzzy: learning not done' => [true, [Migrations::MAIL_LOG_FUZZY_HITS_MAILS], []],
		];
	}

	/**
	 * @param list<string> $done
	 * @param list<string> $added
	 */
	#[DataProvider('migrations')]
	public function testFilterNeedsItsMigration(bool $fuzzy, array $done, array $added): void {
		Config::set('fuzzy_learn_enable', $fuzzy);
		$none = array_keys(FormHelper::getFilters(true));
		$this->complete(...$done);
		$this->assertSame($added, array_values(array_diff(array_keys(FormHelper::getFilters(true)), $none)));
	}

	public function testFilterByName(): void {
		$this->assertSame([
			3 => ['value' => 'invoice', 'filter' => 'subject', 'choice' => 'LIKE'],
			7 => ['value' => '10', 'filter' => 'score', 'choice' => '>='],
			8 => ['value' => 'x', 'choice' => '='],
			9 => ['value' => 'x', 'filter' => 'subject'],
		], FormHelper::getFilterByName([
			3 => ['filter' => 'Subject', 'choice' => 'contains', 'value' => 'invoice'],
			7 => ['filter' => 'Score', 'choice' => 'is greater than or equal', 'value' => '10'],
			8 => ['filter' => 'subject', 'choice' => 'is equal to', 'value' => 'x'],
			9 => ['filter' => 'Subject', 'choice' => 'LIKE', 'value' => 'x'],
		], false));
	}

	public function testAdminFilterByName(): void {
		$search = [['filter' => 'Server', 'choice' => 'is equal to', 'value' => 'mx1']];
		$this->assertSame([['value' => 'mx1', 'filter' => 'server', 'choice' => '=']], FormHelper::getFilterByName($search, true));
		$this->assertSame([['value' => 'mx1', 'choice' => '=']], FormHelper::getFilterByName($search, false));
	}

	private function complete(string ...$migrations): void {
		foreach ($migrations as $migration) {
			$this->status->setMigrationState($migration, Migrations::STATUS_COMPLETED);
		}
	}
}
