<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Inventory;

use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;

use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

use App\Forms\MapWithTwoFieldsForm;
use App\Forms\MapMailFromRcptToForm;
use App\Forms\MapMimeFromRcptToForm;
use App\Forms\MapMailFromRcptToUserForm;
use App\Forms\MapMimeFromRcptToUserForm;
use App\Forms\MapMailFromForm;
use App\Forms\MapMimeFromForm;
use App\Forms\MapIpForm;
//use App\Forms\MapUrlForm;
//use App\Forms\MapEmailForm;
use App\Forms\MapWithCustomFieldForm;

use App\Services\MapService;

use RuntimeException;

final class MapInventory
{
	private const array USER_FORM_OVERRIDES = [
		MapMailFromRcptToForm::class => MapMailFromRcptToUserForm::class,
		MapMimeFromRcptToForm::class => MapMimeFromRcptToUserForm::class,
	];

	private static function getUserOverrideFormClass(string $adminFormClass): string {
		return self::USER_FORM_OVERRIDES[$adminFormClass] ?? $adminFormClass;
	}

	// also need to create child form class in app/Forms
	// and add the Form use at top
	// $withCustom = false: the built-in maps only, without a query
	/** @return ($map is null ? array<string, array<string, mixed>> : array<string, mixed>|null) all configs by map name, or one map's config */
	public static function getMapConfigs(?string $map = null, bool $withCustom = true): ?array {
		$configs = [
			'mail_from_rcpt_to_whitelist' => [
				'model' => 'MapCombined',
				'description' => 'Mail From/RCPT_TO Whitelist',
				'fields' => ['mail_from', 'rcpt_to'],
				'map_form' => MapMailFromRcptToForm::class,
				//'api_handler' => \handleMailFromRcptToWhitelist::class,
				'access' => ['admin', 'user'],
			],
			'mail_from_rcpt_to_blacklist' => [
				'model' => 'MapCombined',
				'description' => 'Mail From/RCPT_TO Blacklist',
				'fields' => ['mail_from', 'rcpt_to'],
				'map_form' => MapMailFromRcptToForm::class,
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
			'mail_from_whitelist' => [
				'model' => 'MapCombined',
				'description' => 'Mail From Whitelist',
				'fields' => ['mail_from'],
				'map_form' => MapMailFromForm::class,
				//'api_handler' => \handleMailFromWhitelist::class,
				'access' => ['admin'],
			],
			'mail_from_blacklist' => [
				'model' => 'MapCombined',
				'description' => 'Mail From Blacklist',
				'fields' => ['mail_from'],
				'map_form' => MapMailFromForm::class,
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
			/* deprecated, moved to custom maps
			'from_whitelist' => [
				'model' => 'MapCombined',
				'description' => 'Mail/MIME From Whitelist',
				'fields' => ['mail_from'],
				'map_form' => MapMailFromForm::class,
				//'api_handler' => \handleMailFromWhitelist::class,
				'access' => ['admin'],
			],
			'from_blacklist' => [
				'model' => 'MapCombined',
				'description' => 'Mail/MIME From Blacklist',
				'fields' => ['mail_from'],
				'map_form' => MapMailFromForm::class,
				'access' => ['admin'],
			],
			*/
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
			// Add more local maps here...
		];

		// add custom map configs from db in config
		$custom_map_configs = $withCustom ? MapService::getCustomMapConfigs() : null;

		// we have custom maps
		if ($custom_map_configs !== null && $custom_map_configs->count() > 0) {
			$custom_configs = array();
			foreach ($custom_map_configs as $cmf) {
				$custom_configs[$cmf->map_name]['model'] = 'MapCustom';
				$custom_configs[$cmf->map_name]['description'] = $cmf->map_description;
				$custom_configs[$cmf->map_name]['fields'] = [$cmf->field_name];
				$custom_configs[$cmf->map_name]['map_form'] = MapWithCustomFieldForm::class;
				$custom_configs[$cmf->map_name]['access'] = ['admin'];
			}
			$configs = array_merge($configs, $custom_configs);
		}

		// Check each config has required non-null fields
		$requiredFields = ['model', 'fields', 'map_form', 'access'];
		foreach ($configs as $key => $config) {
			foreach ($requiredFields as $field) {
				/** @psalm-suppress TypeDoesNotContainNull defensive: custom map configs come from the DB */
				if (!array_key_exists($field, $config) || $config[$field] === null) { // @phpstan-ignore identical.alwaysFalse (defensive: custom map configs come from the DB)
					throw new RuntimeException("Missing or null required field '$field' in map config '$key'");
				}
			}
		}

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
	/** @return ($field is null ? array<string, array<string, mixed>> : array<string, mixed>) all definitions by field, or one ([] if unknown) */
	public static function getFieldDefinitions(?string $field = null): array {
		$definitions = [
			'mail_from' => [
				'description' => 'Mail From',
				'type' => EmailType::class,
				'field_options' => [
					'label' => 'Mail From:',
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
						new Callback([self::class, 'validateIpOrCidr']),
					],
				],
			],
			'url' => [
				'description' => 'URL',
				'type' => TextType::class,
				'field_options' => [
					'label' => 'URL:',
					'required' => true,
					'attr' => ['class' => 'uniform-input'],
					'constraints' => [
						new NotBlank(),
					],
				],
			],
			'email' => [
				'description' => 'Email',
				'type' => TextType::class,
				'field_options' => [
					'label' => 'Email:',
					'required' => true,
					'attr' => ['class' => 'uniform-input'],
					'constraints' => [
						new NotBlank(),
						new Email(),
					],
				],
			],
			'custom' => [
				'description' => 'Custom Field', // overriden in custom_map_config/MapWithCustomFieldForm
				'type' => TextareaType::class,
				'field_options' => [
					'label' => 'Custom Field:',   // overriden in custom_map_config/MapWithCustomFieldForm
					'required' => true,
					'attr' => [
						'class' => 'uniform-input map-entries-input',
						'rows' => 5,
					],
					'constraints' => [
						new NotBlank(),
					],
					'help' => "One entry per line\nLines starting with # are inserted as disabled entries",
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

	/** @return ($map is null ? array<string, array<string, mixed>> : array<string, mixed>) the role's configs by map name, or one map's config ([] if not available) */
	public static function getAvailableMapConfigs(
		string $role = 'admin',
		?string $map = null
	): array {

		// local map configs
		$configs = self::getMapConfigs();

		// map exists in config
		if ($map !== null) {
			if (!array_key_exists($map, $configs)) {
				return [];
			}

			// check role 'access' in map config
			/** @var array{map_form: class-string, fields?: list<string>, access?: list<string>} $config map_form is a ::class constant in every config */
			$config = $configs[$map];
			$isUser = $role === 'user';
			if (in_array($role, $config['access'] ?? ['admin'], true)) {

				// check for override form class for user forms
				if (
					$isUser &&
					in_array('user', $config['access'] ?? [], true) &&
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
			return in_array($role, $config['access'] ?? ['admin'], true);
		});
	}

	/**
	 * @param array<string, array<string, mixed>> $configs
	 * @return list<string> map names
	 */
	public static function getMapsByModel(string $model, array $configs): array {
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

	// map names of one model that the given role may manage
	/** @return list<string> map names */
	public static function getRoleMapsByModel(string $model, string $role): array {
		return self::getMapsByModel($model, self::getAvailableMapConfigs($role));
	}

	// canonical text form of an IP or CIDR network; anything unparseable is returned unchanged
	public static function canonicalIpOrCidr(string $value): string {
		/**
		 * explode() returns at least one string; only the second element is padding
		 * @var string $ip
		 * @var string|null $prefix
		 */
		[$ip, $prefix] = array_pad(explode('/', $value, 2), 2, null);
		if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
			return $value;
		}

		$packed = inet_pton($ip);
		$ip = $packed === false ? false : inet_ntop($packed);
		if ($ip === false) {
			return $value;
		}
		return $prefix === null ? $ip : "{$ip}/{$prefix}";
	}

	// IPv4/IPv6 address or network in CIDR notation, host bits clear
	public static function validateIpOrCidr(mixed $value, ExecutionContextInterface $context): void {
		if (!is_string($value) || $value === '') {
			return;
		}

		/**
		 * explode() returns at least one string; only the second element is padding
		 * @var string $ip
		 * @var string|null $prefix
		 */
		[$ip, $prefix] = array_pad(explode('/', $value, 2), 2, null);
		$bin = filter_var($ip, FILTER_VALIDATE_IP) !== false ? inet_pton($ip) : false;

		if ($bin === false
			|| ($prefix !== null
				&& (!preg_match('/^(0|[1-9]\d{0,2})$/', $prefix) || (int) $prefix > strlen($bin) * 8))
		) {
			$context->buildViolation('Invalid IP address or CIDR network.')->addViolation();
			return;
		}

		if ($prefix === null) {
			return;
		}

		$network = '';
		for ($i = 0, $n = strlen($bin); $i < $n; $i++) {
			$keep = max(0, min(8, (int) $prefix - $i * 8));
			$network .= chr(ord($bin[$i]) & (0xFF << (8 - $keep)) & 0xFF);
		}

		if ($network !== $bin) {
			$context->buildViolation('{{ value }} is not a network address; did you mean {{ network }}?')
				->setParameter('{{ value }}', $value)
				->setParameter('{{ network }}', (string) inet_ntop($network) . '/' . (int) $prefix)
				->addViolation();
		}
	}

}
