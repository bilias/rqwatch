<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU Affero General Public License version 3
as published by the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU Affero General Public License for more details.

You should have received a copy of the GNU Affero General Public License
along with this program.  If not, see <https://www.gnu.org/licenses/>.
*/

namespace App\Console;

use App\Console\RqwatchCliCommand;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\LockableTrait;

use App\Core\Config;
use App\Utils\Helper;

use App\Models\MapActivityLog;
use App\Inventory\MapInventory;
use App\Services\MapService;

use Psr\Log\LoggerInterface;

use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use DateTime;
use DateTimeZone;

#[AsCommand(
	name: 'cron:updatemapfiles',
	description: 'Update Map Files',
	help: 'This commands scans the rqwatch database and updates map files if needed.
',
)]
class CronUpdateMapFiles extends RqwatchCliCommand
{
	private string $app_name = "cron:updatemapfiles";
	private ?LoggerInterface $fileLogger = null;
	private ?LoggerInterface $syslogLogger = null;

	use LockableTrait;

	public function __construct(LoggerInterface $fileLogger, LoggerInterface $syslogLogger) {
		// set command name
		//parent::__construct($this->app_name);
		parent::__construct();

		$this->fileLogger = $fileLogger;
		$this->syslogLogger = $syslogLogger;
	}

	protected function configure(): void {
		$this
			// ->addArgument('param', InputArgument::REQUIRED, 'Parameter for service')
			->addOption('map', 'm', InputOption::VALUE_OPTIONAL, 'Specify map, default all maps')
		;
	}

	protected function mapFileNeedsUpdate(string $map_name, ?string $last_activity = null): bool {
		$map_dir = Config::get('MAP_DIR');
		$map_file = rtrim($map_dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $map_name . ".txt";

		if (!file_exists($map_file)) {
			return true;
		}

		if (!$handle = fopen($map_file, 'r')) {
			return true;
		}

		$firstLine = fgets($handle);
		fclose($handle);

		// Expected format: "# Last-Modified: Tue, 29 Jul 2025 10:37:04 GMT"
		if (preg_match('/^# Last-Modified: (.+)$/', trim($firstLine), $matches)) {
			$lastModifiedStr = $matches[1];

			// Convert GMT string to local time Y-m-d H:i:s
			$dt = new DateTime($lastModifiedStr, new DateTimeZone('GMT'));
			$dt->setTimezone(new DateTimeZone(date_default_timezone_get()));
			$lastModifiedLocal = $dt->format('Y-m-d H:i:s');

			if ($lastModifiedLocal < $last_activity) {
				return true;
			} else {
				return false;
			}
		}
		return true;
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		if (!$this->lock()) {
			$output->writeln('<comment>Already running in another process.</comment>');
			$this->fileLogger->warning("{$this->app_name} Already running in another process");
			return Command::FAILURE;
		}

		//$param = $input->getArgument('param');
		$map = $input->getOption('map');

		if (!empty($map)) {
			$map_config = MapInventory::getMapConfigs($map);
			if (empty($map_config)) {
				$output->writeln("<comment>Map '{$map}' not found</comment>");
				$this->fileLogger->warning("{$this->app_name} Map '{$map}' not found");
				return Command::FAILURE;
			}
			$configs = [ $map => $map_config ];
		} else {
			$configs = MapInventory::getMapConfigs();
		}

		if (empty($configs)) {
			$output->writeln("<comment>Maps configuration not found</comment>");
			$this->fileLogger->warning("{$this->app_name} Maps configuration not found");
			return Command::FAILURE;
		}

		// get last activity by map
		$maps_last_activity = MapActivityLog::pluck('last_changed_at', 'map_name')->map(function($value) {
			return (string) $value;
		})->toArray();

		$service = new MapService($this->fileLogger);

		foreach ($configs as $mapName => $config) {

			// map missing from MapActivityLog
			if (empty($maps_last_activity[$mapName])) {
				$last_activity = date("Y-m-d H:i:s");
				// update MapActivityLog
				$service->updateMapActivityLog($mapName, $last_activity);
			} else {
				$last_activity = $maps_last_activity[$mapName];
			}

			// check if file missing or is older
			if($this->mapFileNeedsUpdate($mapName, $last_activity)) {
				if ($config['model'] === 'MapCombined' &&
					 $service->updateMapFile($config['model'], $mapName, $last_activity, $config['fields'])) {
						$output->writeln("<info>Map file '{$mapName}' updated", OutputInterface::VERBOSITY_VERBOSE);
						$this->fileLogger->info("{$this->app_name} Map file '{$mapName}' updated");
				} elseif ($config['model'] == 'MapGeneric' && 
					       $service->updateMapFile($config['model'], $mapName, $last_activity)) {
						$output->writeln("<info>Map file '{$mapName}' updated", OutputInterface::VERBOSITY_VERBOSE);
						$this->fileLogger->info("{$this->app_name} Map file '{$mapName}' updated");
				} else {
						$output->writeln("<info>Wrong model '{$config['model']}' for Map file '{$mapName}'", OutputInterface::VERBOSITY_VERBOSE);
						$this->fileLogger->warning("{$this->app_name} Wrong model '{$config['model']}' for Map file '{$mapName}'");
				}
			} else {
					$output->writeln("<info>Map file '{$mapName}' does not need update", OutputInterface::VERBOSITY_VERBOSE);
					$this->fileLogger->debug("{$this->app_name} Map file '{$mapName}' does not need update");
			}
		}

		return Command::SUCCESS;
	}

}
