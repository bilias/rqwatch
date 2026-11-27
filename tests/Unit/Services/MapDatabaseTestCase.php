<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 MapService on RecordingDatabase, with a MAP_DIR of its own: each SELECT is
 answered by the first of $answers whose text it holds (rows, or a closure
 of the bindings giving them), with no rows when none does. An admin and a
 user (alice, id 7, with an alias) as the service's user.
*/

namespace Tests\Unit\Services;

use App\Configuration\Config;
use App\Core\App;
use App\Core\AppContainer;
use App\Core\Database\MigrationStatus;
use App\Services\MapService;
use Closure;
use Illuminate\Database\Eloquent\Model;
use PDO;
use Tests\Unit\RecordingDatabase;
use Tests\Unit\UnitTestCase;

abstract class MapDatabaseTestCase extends UnitTestCase
{
	protected const string HEADER = '/^# Last-Modified: \w{3}, \d\d \w{3} \d{4} \d\d:\d\d:\d\d GMT$/D';

	protected RecordingDatabase $db;

	protected MigrationStatus $status;

	protected string $dir = '';

	/** @var list<array{string, list<array<string, mixed>>|Closure(list<mixed>): list<array<string, mixed>>}> */
	protected array $answers = [];

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->db = new RecordingDatabase($this->createStub(PDO::class));
		$this->db->capsule->bootEloquent();
		$this->db->answer = $this->answer(...);
		$this->status = new MigrationStatus(null, $this->log);
		App::swap(new AppContainer(0.0, 0, $this->log, $this->log, $this->db->capsule, $this->status, null, false));
		$this->dir = sys_get_temp_dir() . '/rqwatch-mapdb-' . bin2hex(random_bytes(4));
		mkdir($this->dir);
		Config::set('MAP_DIR', $this->dir);
		Config::set('MAP_FILE_PERM', 0640);
	}

	/**
	 * @param list<mixed> $bindings
	 * @return list<array<string, mixed>>
	 */
	private function answer(string $sql, array $bindings): array {
		foreach ($this->answers as [$needle, $rows]) {
			if (str_contains($sql, $needle)) {
				return $rows instanceof Closure ? $rows($bindings) : $rows;
			}
		}
		return [];
	}

	#[\Override]
	protected function tearDown(): void {
		$files = glob("{$this->dir}/*");
		foreach ($files === false ? [] : $files as $file) {
			unlink($file);
		}
		rmdir($this->dir);
		Model::unsetConnectionResolver();
		RecordingDatabase::forget();
		parent::tearDown();
	}

	protected static function admin(): MapService {
		return new MapService(['is_admin' => true, 'username' => 'root', 'user_id' => 1, 'email' => 'root@example.org']);
	}

	protected static function user(): MapService {
		return new MapService(['is_admin' => false, 'username' => 'alice', 'user_id' => 7, 'email' => 'alice@example.org', 'user_aliases' => ['alias@example.org']]);
	}

	// the map file's lines after its header, null when there is none
	/** @return list<string>|null */
	protected function mapFile(string $map): ?array {
		$file = "{$this->dir}/{$map}.txt";
		if (!is_file($file)) {
			return null;
		}
		$lines = explode("\n", rtrim((string) file_get_contents($file), "\n"));
		$this->assertMatchesRegularExpression(self::HEADER, array_shift($lines));
		return $lines;
	}

	/** @return list<mixed> the maps whose activity log was written */
	protected function changedMaps(): array {
		return array_column($this->db->rows('map_activity_logs'), 'map_name');
	}
}
