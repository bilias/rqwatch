<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Core\Database\Migrations;

use App\Configuration\AppConfig;
use App\Configuration\Config;
use App\Inventory\Migrations;

use Symfony\Component\Console\Output\OutputInterface;

use RuntimeException;
use Throwable;

class VirusSkipMap extends AbstractMigration {

	protected const string MIGRATION_NAME = Migrations::VIRUS_SKIP_MAP;

	// same rule as CustomMapConfigForm and the {map} route requirement
	private const string NAME_REGEX = '/^[a-z0-9_\-]{3,64}$/';

	public function run(int $batch, int $sleep, bool $force, OutputInterface $output): bool {
		$this->ensureMigrationsTable();

		$name = $this->getName();
		$descr = $this->getDescr();
		$details = "'{$descr}' ($name)";

		// completed and the configured map exists. $force is ignored
		if ($this->isApplied()) {
			$output->writeln("<comment>Migration $details is already applied\n</comment>");
			return true;
		}

		$this->fileLogger->info("Starting migration $name");
		$output->writeln("<comment>Starting migration $details</comment>");

		try {
			$this->recordMigrationStatus(Migrations::STATUS_RUNNING);

			$this->runMigration($output);
			$this->recordMigrationStatus(Migrations::STATUS_COMPLETED);
		} catch (Throwable $e) {
			$this->fileLogger->error(
				"Migration $name failed: " . $e->getMessage()
			);

			$output->writeln(
				"<error>Migration $details failed: {$e->getMessage()}</error>"
			);

			$this->recordMigrationFailed();

			return false;
		}

		$this->fileLogger->info("Migration $name completed");
		$output->writeln("<comment>Migration $details completed\n</comment>");
		return true;
	}

	private function runMigration(OutputInterface $output): void {
		$map = self::mapName();

		if ($map === '') {
			$output->writeln("<info>notification_skip_virus_map is empty, no map created</info>");
			return;
		}

		if (!preg_match(self::NAME_REGEX, $map)) {
			throw new RuntimeException(
				"invalid notification_skip_virus_map '{$map}': use 3-64 of a-z 0-9 _ -"
			);
		}

		// reached only when not completed, or completed with the map missing,
		// so an existing map was not created here
		if ($this->mapExists($map)) {
			throw new RuntimeException(
				"custom map '{$map}' already exists. Set \$notification_skip_virus_map "
				. "in config.local.php to another name and re-run"
			);
		}

		$this->capsule->table(AppConfig::CUSTOM_MAP_CONFIG_TABLE)->insert([
			'map_name' => $map,
			'map_description' => 'Virus no notification',
			'field_name' => 'virus',
			'field_label' => 'Antivirus symbol or virus name',
		]);
		$output->writeln("<info>Custom map '{$map}' created</info>");
	}

	private static function mapName(): string {
		return trim((string) Config::get('notification_skip_virus_map'));
	}

	private function mapExists(string $map): bool {
		return $this->capsule->table(AppConfig::CUSTOM_MAP_CONFIG_TABLE)
			->where('map_name', $map)
			->exists();
	}

	protected function verifySchema(): bool {
		$map = self::mapName();
		return $map === '' || $this->mapExists($map);
	}

}
