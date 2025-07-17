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

namespace App\Inventory;

use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Type;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Regex;

use App\Forms\MapWithTwoFieldsForm;
use App\Forms\MapSmtpFromRcptToForm;
use App\Forms\MapMimeFromRcptToForm;
use App\Forms\MapSmtpFromRcptToUserForm;
use App\Forms\MapMimeFromRcptToUserForm;
use App\Forms\MapSmtpFromForm;
use App\Forms\MapMimeFromForm;
use App\Forms\MapIpForm;

class MapInventory
{
	private const USER_FORM_OVERRIDES = [
		MapSmtpFromRcptToForm::class => MapSmtpFromRcptToUserForm::class,
		MapMimeFromRcptToForm::class => MapMimeFromRcptToUserForm::class,
	];

	private static function getUserOverrideFormClass(string $adminFormClass): string {
		return self::USER_FORM_OVERRIDES[$adminFormClass] ?? $adminFormClass;
	}

	// also need to create child form class in app/Forms
	// and add the Form use at top
	public static function getMapConfigs(?string $map = null): ?array {
		$configs = [
			'smtp_from_rcpt_to_whitelist' => [
				'model' => 'MapCombined',
				'description' => 'SMTP From/RCPT_TO Whitelist',
				'fields' => ['smtp_from', 'rcpt_to'],
				'map_form' => MapSmtpFromRcptToForm::class,
				//'api_handler' => \handleSmtpFromRcptToWhitelist::class,
				'access' => ['admin', 'user'],
			],
			'smtp_from_rcpt_to_blacklist' => [
				'model' => 'MapCombined',
				'description' => 'SMTP From/RCPT_TO Blacklist',
				'fields' => ['smtp_from', 'rcpt_to'],
				'map_form' => MapSmtpFromRcptToForm::class,
				'access' => ['admin', 'user'],
			],
			'mime_from_rcpt_to_whitelist' => [
				'model' => 'MapCombined',
				'description' => 'MIME From/RCPT_TO Whitelist',
				'fields' => ['mime_from', 'rcpt_to'],
				'map_form' => MapMimeFromRcptToForm::class,
				'access' => ['admin', 'user'],
			],
			'mime_from_rcpt_to_blacklist' => [
				'model' => 'MapCombined',
				'description' => 'MIME From/RCPT_TO Blacklist',
				'fields' => ['mime_from', 'rcpt_to'],
				'map_form' => MapMimeFromRcptToForm::class,
				'access' => ['admin', 'user'],
			],
			'smtp_from_whitelist' => [
				'model' => 'MapCombined',
				'description' => 'SMTP From Whitelist',
				'fields' => ['smtp_from'],
				'map_form' => MapSmtpFromForm::class,
				//'api_handler' => \handleSmtpFromWhitelist::class,
				'access' => ['admin'],
			],
			'smtp_from_blacklist' => [
				'model' => 'MapCombined',
				'description' => 'SMTP From Blacklist',
				'fields' => ['smtp_from'],
				'map_form' => MapSmtpFromForm::class,
				'access' => ['admin'],
			],
			'mime_from_whitelist' => [
				'model' => 'MapCombined',
				'description' => 'MIME From Whitelist',
				'fields' => ['mime_from'],
				'map_form' => MapMimeFromForm::class,
				'access' => ['admin'],
			],
			'mime_from_blacklist' => [
				'model' => 'MapCombined',
				'description' => 'MIME From Blacklist',
				'fields' => ['mime_from'],
				'map_form' => MapMimeFromForm::class,
				'access' => ['admin'],
			],
			'ip_whitelist' => [
				'model' => 'MapCombined',
				'description' => 'IP Whitelist',
				'fields' => ['ip'],
				'map_form' => MapIpForm::class,
				'access' => ['admin'],
			],
			'ip_blacklist' => [
				'model' => 'MapCombined',
				'description' => 'IP Blacklist',
				'fields' => ['ip'],
				'map_form' => MapIpForm::class,
				'access' => ['admin'],
			],
			// Add more maps here...
		];

		if ($map) {
			if (array_key_exists($map, $configs)) {
				return $configs[$map];
			} else {
				return null;
			}
		}
		// no map given, return all map configs
		return $configs;
	}

	// Reusable field definitions
	public static function getFieldDefinitions(?string $field = null): array {
		$definitions = [
			'smtp_from' => [
				'description' => 'SMTP From',
				'type' => EmailType::class,
				'field_options' => [
					'label' => 'SMTP From:',
					'required' => true,
					'attr' => ['class' => 'uniform-input'],
					'constraints' => [
						new NotBlank(),
						new Email(),
					],
				],
			],
			'mime_from' => [
				'description' => 'MIME From',
				'type' => EmailType::class,
				'field_options' => [
					'label' => 'MIME From:',
					'required' => true,
					'attr' => ['class' => 'uniform-input'],
					'constraints' => [
						new NotBlank(),
						new Email(),
					],
				],
			],
			'rcpt_to' => [
				'description' => 'RCPT To',
				'type' => EmailType::class,
				'field_options' => [
					'label' => 'RCPT To:',
					'required' => true,
					'attr' => ['class' => 'uniform-input'],
					'constraints' => [
						new NotBlank(),
						new Email(),
					],
				],
			],
			'ip' => [
				'description' => 'IP Address',
				'type' => TextType::class,
				'field_options' => [
					'label' => 'IP Address:',
					'required' => true,
					'attr' => ['class' => 'uniform-input'],
					'constraints' => [
						new NotBlank(),
						new Regex([
							'pattern' => '/^(\d{1,3}\.){3}\d{1,3}(\/\d{1,2})?$/',
							'message' => 'Invalid IP or CIDR format.',
						]),
					],
				],
			],
			// Add more fields here...
		];

		if ($field) {
			if (array_key_exists($field, $definitions)) {
				return $definitions[$field];
			}
			return [];
		}

		// no field given, return all field definitions
		return $definitions;
	}

	public static function getAvailableMapConfigs(
		string $role = 'admin',
		?string $map = null
	): array {

		$configs = self::getMapConfigs();

		// map exists in config
		if ($map !== null) {
			if (!array_key_exists($map, $configs)) {
				return [];
			}

			// check role 'access' in map config
			$config = $configs[$map];
			if (in_array($role, $config['access'] ?? ['admin'])) {

				// check for override form class for user forms
				if (
					($role === 'user') &&
					in_array('user', $config['access'] ?? []) &&
					is_a($config['map_form'], MapWithTwoFieldsForm::class, true) &&
					isset($config['fields'][1]) &&
					$config['fields'][1] === 'rcpt_to'
					) {
						// override the form class and apply user form
						$config['map_form'] = self::getUserOverrideFormClass($config['map_form']);
					}

				return $config;
			}

			return []; // Not accessible for this role
		}

		// No specific map requested — filter all
		return array_filter($configs, function ($config) use ($role) {
			return in_array($role, $config['access'] ?? ['admin']);
		});
	}

	public static function filterMapsByModel(string $model, array $configs): array {
		if (!$model) {
			return [];
		}

		$maps = [];
		foreach ($configs as $key => $config) {
			if (array_key_exists('model', $config) && $config['model'] === $model) {
				$maps[] = $key;
			}
		}
		return $maps;
	}

}
