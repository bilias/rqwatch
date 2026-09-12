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
use App\Inventory\Migrations;

use Symfony\Component\Console\Output\OutputInterface;

use RuntimeException;
use Throwable;

class MapUserConstraints extends AbstractMigration {

	protected const string MIGRATION_NAME = Migrations::MAP_USER_CONSTRAINTS;

	private const string ALIAS_UNIQUE = 'user_id_alias_idx';
	private const string MAP_USER_FK  = 'fk_maps_combined_user_id';

	public function run(int $batch, int $sleep, bool $force, OutputInterface $output): bool {
		$this->ensureMigrationsTable();

		$name = $this->getName();
		$descr = $this->getDescr();
		$details = "'{$descr}' ($name)";

		// completed and verified
		if ($this->isApplied()) {
			$output->writeln("<comment>Migration $details is already applied\n</comment>");
			return true;
		}

		// a fresh install from db-init.sql already has both constraints
		if ($this->verifySchema()) {
			$output->writeln("<comment>Migration $details exists, recording status\n</comment>");
			$this->recordMigrationStatus(Migrations::STATUS_COMPLETED);
			return true;
		}

		// before RUNNING is recorded, so an abort leaves the status pending
		if (!$this->preflight($output)) {
			return false;
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

			return false;
		}

		$this->fileLogger->info("Migration $name completed");
		$output->writeln("<comment>Migration $details completed\n</comment>");
		return true;
	}

	// a negative user_id cannot become INT UNSIGNED. Checked before RUNNING
	// is recorded, so the status stays pending and the cause is reported.
	private function preflight(OutputInterface $output): bool {
		$min = $this->capsule->getConnection()->select(
			"SELECT MIN(user_id) AS min_id FROM `"
			. AppConfig::MAPS_COMBINED_TABLE . "`"
		);

		if (empty($min) || $min[0]->min_id === null || (int) $min[0]->min_id >= 0) {
			return true;
		}

		$msg = "Negative user_id in " . AppConfig::MAPS_COMBINED_TABLE
			. ", cannot convert to INT UNSIGNED";

		$this->fileLogger->error("Migration {$this->getName()}: $msg");
		$output->writeln("<error>$msg</error>");
		$output->writeln("<error>Migration aborted, resolve the above first</error>");

		return false;
	}

	private function runMigration(OutputInterface $output): void {
		// a configured max_statement_time could kill an ALTER while waiting
		// on the MDL and leave the status at RUNNING. Session only.
		$this->capsule
			->getConnection()
			->statement('SET SESSION max_statement_time = 0');

		$this->deleteDuplicateAliases($output);
		$this->addAliasUnique($output);
		$this->deleteOrphanMapEntries($output);
		$this->addMapUserForeignKey($output);

		if (!$this->verifySchema()) {
			throw new RuntimeException(
				"Failed to add constraints to "
				. AppConfig::MAIL_ALIASES_TABLE . " and "
				. AppConfig::MAPS_COMBINED_TABLE
			);
		}
	}

	// the unique key rejects these. The lowest id of each pair survives, so
	// the original created_at is kept and the alias itself is not lost.
	private function deleteDuplicateAliases(OutputInterface $output): void {
		$table = AppConfig::MAIL_ALIASES_TABLE;

		$deleted = $this->capsule->getConnection()->delete(
			"DELETE a FROM `{$table}` a
			 JOIN `{$table}` b
			   ON b.user_id = a.user_id AND b.alias = a.alias AND b.id < a.id"
		);

		if ($deleted > 0) {
			$this->fileLogger->warning(
				"Migration {$this->getName()}: deleted {$deleted} duplicate aliases"
			);
			$output->writeln("<info>Deleted {$deleted} duplicate aliases</info>");
		}
	}

	// enforces what MailAliasService::aliasExists() already checks in PHP.
	// Not UNIQUE(alias): one alias may legitimately serve several users.
	private function addAliasUnique(OutputInterface $output): void {
		if ($this->hasIndex(AppConfig::MAIL_ALIASES_TABLE, self::ALIAS_UNIQUE)) {
			return;
		}

		$this->capsule->getConnection()->statement(
			"ALTER TABLE `" . AppConfig::MAIL_ALIASES_TABLE . "` "
			. "ADD UNIQUE KEY `" . self::ALIAS_UNIQUE . "` (`user_id`, `alias`)"
		);

		$output->writeln(
			"<info>Added " . self::ALIAS_UNIQUE . " to "
			. AppConfig::MAIL_ALIASES_TABLE . "</info>"
		);
	}

	// the foreign key rejects these, and they are unreachable anyway: every
	// scope keys on user_id, so a row with no user is invisible to admin and
	// user alike while still being written into the generated map files.
	private function deleteOrphanMapEntries(OutputInterface $output): void {
		$deleted = $this->capsule->getConnection()->delete(
			"DELETE m FROM `" . AppConfig::MAPS_COMBINED_TABLE . "` m "
			. "LEFT JOIN `" . AppConfig::USERS_TABLE . "` u ON u.id = m.user_id "
			. "WHERE u.id IS NULL"
		);

		if ($deleted > 0) {
			$this->fileLogger->warning(
				"Migration {$this->getName()}: deleted {$deleted} map entries with no user"
			);
			$output->writeln("<info>Deleted {$deleted} orphaned map entries</info>");
		}
	}

	/*
	 One ALTER: a column change forces ALGORITHM=COPY, so splitting it from
	 the foreign key would rebuild the table twice and take two TOI windows.
	 Every part is conditional and tested independently, so a table that
	 already has some of them -- an install whose column drifted to unsigned
	 but stayed nullable, say -- still converges. A nullable user_id is not
	 constrained by the foreign key at all, so NOT NULL is what makes the
	 cascade total.
	*/
	private function addMapUserForeignKey(OutputInterface $output): void {
		$parts = [];

		if (!$this->columnIsUnsigned(AppConfig::MAPS_COMBINED_TABLE, 'user_id')
			|| $this->columnIsNullable(AppConfig::MAPS_COMBINED_TABLE, 'user_id')) {
			$parts[] = "MODIFY `user_id` INT UNSIGNED NOT NULL";
		}

		if (!$this->hasIndex(AppConfig::MAPS_COMBINED_TABLE, 'user_id_index')) {
			$parts[] = "ADD KEY `user_id_index` (`user_id`)";
		}

		if ($this->hasForeignKey(AppConfig::MAPS_COMBINED_TABLE, self::MAP_USER_FK)) {
			if (empty($parts)) {
				return;
			}
		} else {
			$parts[] = "ADD CONSTRAINT `" . self::MAP_USER_FK . "` "
				. "FOREIGN KEY (`user_id`) REFERENCES `"
				. AppConfig::USERS_TABLE . "` (`id`) ON DELETE CASCADE";
		}

		$this->capsule->getConnection()->statement(
			"ALTER TABLE `" . AppConfig::MAPS_COMBINED_TABLE . "` "
			. implode(', ', $parts)
		);

		$output->writeln(
			"<info>Updated " . AppConfig::MAPS_COMBINED_TABLE
			. " user_id constraints</info>"
		);
	}

	protected function verifySchema(): bool {
		return $this->hasIndex(AppConfig::MAIL_ALIASES_TABLE, self::ALIAS_UNIQUE)
			&& $this->columnIsUnsigned(AppConfig::MAPS_COMBINED_TABLE, 'user_id')
			&& !$this->columnIsNullable(AppConfig::MAPS_COMBINED_TABLE, 'user_id')
			&& $this->hasForeignKey(AppConfig::MAPS_COMBINED_TABLE, self::MAP_USER_FK);
	}

}
