<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
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

use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Question\ConfirmationQuestion;

use App\Core\Config;
use App\Utils\Helper;

use App\Services\UserService;

use Psr\Log\LoggerInterface;

use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use DateTime;
use DateTimeZone;

#[AsCommand(
	name: 'user:add',
	description: 'Create a local user',
	help: 'This command creates a local user to Rqwatch.
',
)]
class UserAdd extends RqwatchCliCommand
{
	private string $app_name = "user:add";
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
			->addOption('first', 'f', InputOption::VALUE_REQUIRED, 'First name')
			->addOption('surname', 's', InputOption::VALUE_REQUIRED, 'Surname')
			->addOption('admin', 'a', InputOption::VALUE_NONE, 'Create user with admin privileges')
			->addOption('ldap', 'l', InputOption::VALUE_NONE, 'Create an LDAP user')
			->addOption('no-notifications', 'd', InputOption::VALUE_NONE, 'Disable notifications')
			->addOption('password', 'p', InputOption::VALUE_OPTIONAL, 'Specify user password')
			->addArgument('username', InputArgument::REQUIRED, 'Username for the user')
			->addArgument('mail', InputArgument::REQUIRED, 'Email for the user')
		;
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		if (!$this->lock()) {
			$output->writeln('<comment>Already running in another process.</comment>');
			$this->fileLogger->warning("{$this->app_name} Already running in another process");
			return Command::FAILURE;
		}

		//$param = $input->getArgument('param');
		$username = strtolower(trim($input->getArgument('username')));

		$service = new UserService($this->fileLogger);

		if ($service->userExists($username)) {
			$output->writeln("<comment>Username '{$username}' already exists.</comment>");
			return Command::FAILURE;
		}

		$email = $input->getArgument('mail');

		$is_admin = 0;
		$external_auth = false;

		$admin = $input->getOption('admin');
		$ldap = $input->getOption('ldap');

		if ($admin && $ldap) {
			$output->writeln("<comment>Cannot create admin LDAP users from cli.\nSee LDAP_ADMINS in .env</comment>");
			return Command::FAILURE;
		// LDAP user and not admin
		} elseif ($ldap) {
			$external_auth = true;
			$auth_provider = 1;
		// Admin user DB
		} elseif ($admin) {
			$is_admin = 1;
			$auth_provider = 0;
		// Normal user DB
		} else {
			$auth_provider = 0;
		}

		$first = $input->getOption('first');
		$surname = $input->getOption('surname');
		$password = $input->getOption('password');

		$is_admin_str = $is_admin ? 'Yes' : 'No';
		$auth_provider_str = Helper::getAuthProvider($auth_provider);

		if (!$external_auth) {
			// pass not given with -p
			if (!$password) {
				$output->writeln("<comment>Creating user with</comment>:\n
<comment>Username</comment>: <info>{$username}</info>
<comment>Authentication provider</comment>: <info>{$auth_provider_str}</info>
<comment>Admin User</comment>: <info>{$is_admin_str}</info>
<comment>First Name</comment>: <info>{$first}</info>
<comment>Last Name</comment>: <info>{$surname}</info>\n");

				//$helper = $this->getHelper('question');
				$helper = new QuestionHelper();

				$question = new Question("Please enter password for {$username}: ");
				$question->setHidden(true);
				$question->setHiddenFallback(false);
				$password = $helper->ask($input, $output, $question);

				// Password confirmation
				$confirmQuestion = new Question('Please confirm the password: ');
				$confirmQuestion->setHidden(true);
				$confirmQuestion->setHiddenFallback(false);
				$confirmPassword = $helper->ask($input, $output, $confirmQuestion);

				if ($password !== $confirmPassword) {
					$output->writeln('<error>Passwords do not match!</error>');
					return Command::FAILURE;
				}

				if (!$password) {
					$output->writeln('<error>Empty password given!</error>');
					return Command::FAILURE;
				}
			}
			$password_hash = Helper::passwordHash(trim($password));
		// we are on external auth
		} else {
			$password_hash = 'EXTERNAL_AUTH';
		}

		$notifications = $input->getOption('no-notifications');

		$data = [
			'username' => $username,
			'email' => $email,
			'firstname' => $first,
			'lastname' => $surname,
			'disable_notifications' => $notifications,
			'is_admin' => $is_admin,
			'auth_provider' => $auth_provider,
			'password' => $password_hash,
		];

		if ($service->userAdd($data)) {
			$output->writeln("<info>User '{$username}' created</info>", OutputInterface::VERBOSITY_NORMAL);
			return Command::SUCCESS;
		}

		$output->writeln("<comment>Error creating user '{$username}'. Check logs</comment>");
		return Command::FAILURE;
	}

}
