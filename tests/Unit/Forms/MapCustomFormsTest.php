<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The forms built from custom_map_config, on RecordingDatabase. The map
 picker offers the role's maps of its model by description, sorted, after
 All Map Entries; for custom maps Manage Custom Maps first; it carries
 the model. The custom map form has the map's field, labelled as
 configured: several lines to add; one line to edit, the entry's pattern
 (# in front when disabled) as the field's value unless the entry has
 it. An edit without the entry or its pattern is a bug; a map no longer in
 custom_map_config (deleted since the caller read its config) is a
 MapConfigGoneException. A URL field stores IP hosts canonical. Both are created through FormHelper, the
 custom map form with the onefieldform class.
*/

namespace Tests\Unit\Forms;

use App\Core\Exception\MapConfigGoneException;
use App\Forms\MapSelectForm;
use App\Forms\MapWithCustomFieldForm;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\HttpFoundation\HttpFoundationExtension;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Validation;
use Tests\Unit\Services\MapDatabaseTestCase;

final class MapCustomFormsTest extends MapDatabaseTestCase
{
	/** @var array<string, array{string, string}> map => field name, label */
	private const array FIELDS = ['phish' => ['url', 'URL'], 'senders' => ['sender', 'Sender']];

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->answers = [
			['from `custom_map_config` where `map_name` = ?', function (array $bindings): array {
				/** @var mixed $map */
				$map = $bindings[0] ?? null;
				$field = is_string($map) ? self::FIELDS[$map] ?? null : null;
				return $field === null ? [] : [['field_name' => $field[0], 'field_label' => $field[1]]];
			}],
			['from `custom_map_config`', [
				['id' => 1, 'map_name' => 'senders', 'map_description' => 'Senders', 'field_name' => 'sender', 'field_label' => 'Sender', 'created_at' => null],
				['id' => 2, 'map_name' => 'phish', 'map_description' => 'Phishing URLs', 'field_name' => 'url', 'field_label' => 'URL', 'created_at' => null],
			]],
		];
	}

	private static function factory(): FormFactoryInterface {
		return Forms::createFormFactoryBuilder()
			->addExtension(new HttpFoundationExtension())
			->addExtension(new ValidatorExtension(Validation::createValidator()))
			->getFormFactory();
	}

	/**
	 * @psalm-param FormInterface<array<string, mixed>> $form
	 * @phpstan-param FormInterface $form
	 * @return array<array-key, mixed>
	 */
	private static function choices(FormInterface $form): array {
		/** @var mixed $choices */
		$choices = $form->get('map_name')->getConfig()->getOption('choices');
		return is_array($choices) ? $choices : [];
	}

	/** @return array<string, array{string, string, array<string, string>}> role, model, choices */
	public static function pickers(): array {
		return [
			'admin, built-in' => ['admin', 'MapCombined', [
				'All Map Entries' => 'all',
				'IP Blacklist' => 'ip_blacklist',
				'IP Whitelist' => 'ip_whitelist',
				'MIME From Blacklist' => 'mime_from_blacklist',
				'MIME From Whitelist' => 'mime_from_whitelist',
				'MIME From/RCPT_TO Blacklist' => 'mime_from_rcpt_to_blacklist',
				'MIME From/RCPT_TO Whitelist' => 'mime_from_rcpt_to_whitelist',
				'Mail From Blacklist' => 'mail_from_blacklist',
				'Mail From Whitelist' => 'mail_from_whitelist',
				'Mail From/RCPT_TO Blacklist' => 'mail_from_rcpt_to_blacklist',
				'Mail From/RCPT_TO Whitelist' => 'mail_from_rcpt_to_whitelist',
			]],
			'admin, custom' => ['admin', 'MapCustom', [
				'Manage Custom Maps' => 'manage_custom_maps',
				'All Map Entries' => 'all',
				'Phishing URLs' => 'phish',
				'Senders' => 'senders',
			]],
			'user, built-in' => ['user', 'MapCombined', [
				'All Map Entries' => 'all',
				'MIME From/RCPT_TO Blacklist' => 'mime_from_rcpt_to_blacklist',
				'MIME From/RCPT_TO Whitelist' => 'mime_from_rcpt_to_whitelist',
				'Mail From/RCPT_TO Blacklist' => 'mail_from_rcpt_to_blacklist',
				'Mail From/RCPT_TO Whitelist' => 'mail_from_rcpt_to_whitelist',
			]],
			'user, custom' => ['user', 'MapCustom', [
				'Manage Custom Maps' => 'manage_custom_maps',
				'All Map Entries' => 'all',
			]],
		];
	}

	/** @param array<string, string> $choices */
	#[DataProvider('pickers')]
	public function testPicker(string $role, string $model, array $choices): void {
		$form = MapSelectForm::create(self::factory(), Request::create('/'), null, ['form_name' => 'pick', 'role' => $role, 'model' => $model]);
		$this->assertSame('pick', $form->getName());
		$this->assertSame($choices, self::choices($form));
		$this->assertSame(['map_name', 'select', 'model'], array_keys($form->all()));
		$this->assertSame($model, $form->get('model')->getData());
		$config = $form->get('map_name')->getConfig();
		$this->assertSame(['Maps:', '---', true], [$config->getOption('label'), $config->getOption('placeholder'), $config->getOption('required')]);
		$this->assertSame('Select', $form->get('select')->getConfig()->getOption('label'));
	}

	public function testPickerSubmitted(): void {
		$request = Request::create('/', 'POST', ['pick' => ['map_name' => 'phish', 'model' => 'MapCustom', 'select' => '']]);
		$form = MapSelectForm::create(self::factory(), $request, null, ['form_name' => 'pick', 'role' => 'admin', 'model' => 'MapCustom']);
		$this->assertTrue($form->isSubmitted() && $form->isValid(), (string) $form->getErrors(true));
		$this->assertSame(['map_name' => 'phish', 'model' => 'MapCustom'], array_intersect_key((array) $form->getData(), ['map_name' => 1, 'model' => 1]));
	}

	/**
	 * @param array<string, mixed>|null $data
	 * @param array<string, mixed> $options
	 * @psalm-return FormInterface<array<string, mixed>>
	 * @phpstan-return FormInterface
	 */
	private static function custom(string $map, ?array $data = null, array $options = [], ?Request $request = null): FormInterface {
		return MapWithCustomFieldForm::create(self::factory(), $request ?? Request::create('/'), $data, ['map' => $map] + $options);
	}

	public function testAdd(): void {
		$form = self::custom('senders');
		$this->assertSame(['sender', 'submit'], array_keys($form->all()));
		$this->assertSame(['class' => 'onefieldform'], $form->getConfig()->getOption('attr'));
		$config = $form->get('sender')->getConfig();
		$this->assertInstanceOf(TextareaType::class, $config->getType()->getInnerType());
		$this->assertSame('Sender:', $config->getOption('label'));
		$this->assertSame(['class' => 'uniform-input map-entries-input', 'rows' => 5], $config->getOption('attr'));
		$this->assertSame([], $config->getModelTransformers());
		$this->assertSame('Submit', $form->get('submit')->getConfig()->getOption('label'));
	}

	public function testClassKept(): void {
		$form = self::custom('senders', null, ['attr' => ['class' => 'wide']]);
		$this->assertSame(['class' => 'wide onefieldform'], $form->getConfig()->getOption('attr'));
	}

	// a map gone from custom_map_config has no field to build the form with
	public function testUnknownMapRefused(): void {
		try {
			self::custom('nosuch');
			$this->fail('built');
		} catch (MapConfigGoneException $e) {
			$this->assertSame("Custom map 'nosuch' has no config", $e->getMessage());
		}
		$this->assertSame(["error getCustomField: no custom_map_config row for map 'nosuch'"], $this->log->lines);
	}

	/** @return array<string, array{array<string, mixed>, string}> the entry, the field's value */
	public static function edits(): array {
		return [
			'enabled' => [['pattern' => 'a@example.org', 'disabled' => 0], 'a@example.org'],
			'disabled' => [['pattern' => 'a@example.org', 'disabled' => 1], '#a@example.org'],
			'no disabled flag' => [['pattern' => 'a@example.org'], 'a@example.org'],
			'with the field' => [['pattern' => 'a@example.org', 'sender' => 'kept@example.org', 'disabled' => 1], 'kept@example.org'],
		];
	}

	/** @param array<string, mixed> $entry */
	#[DataProvider('edits')]
	public function testEdit(array $entry, string $value): void {
		$form = self::custom('senders', $entry, ['is_edit' => true]);
		$field = $form->get('sender');
		$this->assertInstanceOf(TextType::class, $field->getConfig()->getType()->getInnerType());
		$this->assertSame(['class' => 'uniform-input map-entries-input'], $field->getConfig()->getOption('attr'));
		$this->assertSame('Lines starting with # are inserted as disabled entries', $field->getConfig()->getOption('help'));
		$this->assertSame($value, $field->getData());
		/** @var mixed $pattern */
		$pattern = ((array) $form->getData())['pattern'] ?? null;
		$this->assertSame(empty($entry['disabled']) ? 'a@example.org' : '#a@example.org', $pattern);
	}

	public function testNotAnEdit(): void {
		$form = self::custom('senders', ['pattern' => 'a@example.org', 'disabled' => 1], ['is_edit' => false]);
		$this->assertInstanceOf(TextareaType::class, $form->get('sender')->getConfig()->getType()->getInnerType());
		$this->assertNull($form->get('sender')->getData());
	}

	/** @return array<string, array{array<string, mixed>|null, string}> */
	public static function badEdits(): array {
		return [
			'no entry' => [null, 'An edit form needs the entry data'],
			'no pattern' => [['disabled' => 0], 'An edit form needs the entry pattern'],
			'pattern not text' => [['pattern' => 5], 'An edit form needs the entry pattern'],
		];
	}

	/** @param array<string, mixed>|null $entry */
	#[DataProvider('badEdits')]
	public function testBadEdit(?array $entry, string $message): void {
		$this->expectExceptionObject(new LogicException($message));
		self::custom('senders', $entry, ['is_edit' => true]);
	}

	public function testUrlHostsCanonical(): void {
		$request = Request::create('/', 'POST', ['map_with_custom_field_form' => ['url' => "http://[2001:DB8:0::1]/x\nexample.org/y"]]);
		$form = self::custom('phish', null, [], $request);
		$this->assertSame('URL:', $form->get('url')->getConfig()->getOption('label'));
		$this->assertTrue($form->isSubmitted());
		$this->assertSame("http://[2001:db8::1]/x\nexample.org/y", $form->get('url')->getData());
	}
}
