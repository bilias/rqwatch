<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Utils;

use App\Models\MailLog;
use App\Services\FuzzyService;
use App\Services\MapService;
use App\Services\VirusService;

use Symfony\Component\HttpFoundation\Request;

use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormTypeInterface;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use LogicException;

/**
 * A search filter as the search form, the report links and the session hold
 * it: the filter's label, the choice's label, the value.
 * @phpstan-type SearchFilter array{filter: string, choice: string, value: string}
 * A search filter mapped by getFilterByName(): the column and the operator,
 * each absent when its label is not one the user may filter by.
 * @phpstan-type QueryFilter array{filter?: string, choice?: string, value: string}
 */
final class FormHelper
{
	/**
	 * @param class-string<FormTypeInterface<array<string, mixed>>> $formTypeClass
	 * @param array<string, mixed>|null $data
	 * @param array<string, mixed> $options
	 * @psalm-return FormInterface<array<string, mixed>>
	 * @phpstan-return FormInterface
	 */
	public static function formCreator(
		FormFactoryInterface $formFactory, 
		Request $request, 
		string $formTypeClass,
		?array $data = null,
		array $options = []
	): FormInterface {

		// Get form name from options, default to null or '' for unnamed form
		$formName = $options['form_name'] ?? null;
		if ($formName) {
			// Create named form with prefix
			$form = $formFactory->createNamed($formName, $formTypeClass, $data, $options);
		} else {
			$form = $formFactory->create($formTypeClass, $data, $options);
		}

		/**
		 * @psalm-var FormInterface<array<string, mixed>> $form the data type $formTypeClass declares
		 * @phpstan-var FormInterface $form
		 */
		// handle form submit
		$form->handleRequest($request);

		return $form;
	}

	/**
	 * A validly submitted form's data. Symfony always gives one, so null is a
	 * bug and fails instead of reading as an empty form.
	 * @psalm-param FormInterface<array<string, mixed>> $form
	 * @phpstan-param FormInterface $form
	 * @return array<string, mixed>
	 */
	public static function submittedData(FormInterface $form): array {
		return $form->getData() ?? throw new LogicException("Submitted form '{$form->getName()}' has no data");
	}

	// a text field the form requires (NotBlank) or the caller has checked: anything but a string is a bug
	public static function text(mixed $value, string $field): string {
		return is_string($value) ? $value : throw new LogicException("Form field '{$field}' must be text, not " . get_debug_type($value));
	}

	// an optional text field: null when left empty, text otherwise; anything else is a bug
	public static function optionalText(mixed $value, string $field): ?string {
		return $value === null ? null : self::text($value, $field);
	}

	/*
	 Tri-state disable_notifications for the user and profile forms.
	 NULL follows $default; the choices are strings because ChoiceType
	 cannot carry a null choice value.
	*/
	public static function addNotificationsField(FormBuilderInterface $builder, bool $default): void {
		$builder->add('disable_notifications', ChoiceType::class, [
			'label' => 'Notifications',
			'required' => true,
			'choices' => [
				'Default (' . ($default ? 'Yes' : 'No') . ')' => 'default',
				'Yes' => 'on',
				'No' => 'off',
			],
			'attr' => [
				'class' => 'disable_notifications',
				'title' => 'Mail notifications for quarantined mails',
			],
		]);

		$builder->get('disable_notifications')->addModelTransformer(new CallbackTransformer(
			fn (?bool $value): string => $value === null ? 'default' : ($value ? 'off' : 'on'),
			fn (?string $choice): ?bool => match ($choice) {
				'on' => false,
				'off' => true,
				default => null,
			},
		));
	}

	/** @return array<string, string> label => column or pseudo-column */
	public static function getFilters(bool $is_admin): array {
		$filters = array(
			'Date' => 'created_day',
			'Subject' => 'subject',
			'Action' => 'action',
			'Score' => 'score',
			'Stored (0/1)' => 'mail_stored',
			'Received from IP' => 'ip',
			'MAIL From' => 'mail_from',
			// Queries mail_log_recipients via filterByRecipient(); the key
			// is pinned to REPORT_DYN_FIELDS for the reports drill-down
			'RCPT To' => 'mail_recipients',
			'MIME From' => 'mime_from',
			'MIME To' => 'mime_to',
			// mail_log_fuzzy_hits via filterByFuzzyHit()
			'Fuzzy hit (0/1)' => 'fuzzy_hit',
			'Has Virus (0/1)' => 'has_virus',
			// mail_log_viruses via filterByVirus()
			'Virus' => 'virus',
			'Size' => 'size',
			'Headers' => 'headers',
			'Rspamd symbols' => 'symbols',
			// mail_log_symbol_hits via filterByLocalSymbol()
			'Local symbol' => 'local_symbol',
			// mail_log_symbol_hits via filterByMapHit()
			'Map hit (0/1)' => 'map_hit',
			'Released (0/1)' => 'released',
			'Notified (0/1)' => 'notified',
			'Notification pending (0/1)' => 'notification_pending',
			// not a column: notified IS NULL, via filterByNotificationSkipped()
			'Notification skipped (0/1)' => 'notification_skipped',
			'Server' => 'server',
		);

		// mail_log_viruses is complete once its migration has run
		if (!VirusService::reportEnabled()) {
			unset($filters['Virus']);
		}
		// as the Hits links: fuzzy on and mail_log_fuzzy_hits complete
		if (!FuzzyService::isEnabled() || !FuzzyService::hitMailsEnabled()) {
			unset($filters['Fuzzy hit (0/1)']);
		}
		// mail_log_symbol_hits is complete once its migration has run
		if (!MapService::mapHitsEnabled()) {
			unset($filters['Local symbol']);
			unset($filters['Map hit (0/1)']);
		}

		if ($is_admin) {
			return $filters;
		}

		return array_filter(
			$filters,
			static fn(string $field): bool =>
				!in_array($field, MailLog::ADMIN_ONLY_FIELDS, true)
		);
	}

	/** @return array<string, string> label => SQL operator */
	public static function getChoices(): array {
		return array(
			'is equal to' => '=',
			'is not equal to' => '<>',
			'contains' => 'LIKE',
			'does not contain' => 'NOT LIKE',
			'is greater than' => '>',
			'is greater than or equal' => '>=',
			'is less than' => '<',
			'is less than or equal' => '<=',
			'matches REGEXP' => 'REGEXP',
			'not matches REGEXP' => 'NOT REGEXP',
			/* support NULL/NOT NULL
			'is null' => 'is null',
			'in not null' => 'is not null',
			*/
		);
	}

	/**
	 * @param array<array-key, SearchFilter> $active_filters
	 * @return array<array-key, QueryFilter>
	 */
	public static function getFilterByName(array $active_filters, bool $is_admin): array {

		$filters = self::getFilters($is_admin);
		$choices = self::getChoices();
		$ar = array();

		foreach ($active_filters as $key => $filter) {
			$mapped = ['value' => $filter['value']];
			if (isset($filters[$filter['filter']])) {
				$mapped['filter'] = $filters[$filter['filter']];
			}
			if (isset($choices[$filter['choice']])) {
				$mapped['choice'] = $choices[$filter['choice']];
			}
			$ar[$key] = $mapped;
		}

		return $ar;
	}

	/**
	 * @param array<string, mixed> $ar
	 * @return array<string, string>
	 */
	public static function getKeysToArr(array $ar): array {
		return array_combine(array_keys($ar), array_keys($ar));
	}

	/** @return array<string, string> */
	public static function getSearchFilters(bool $is_admin): array {
		return self::getKeysToArr(self::getFilters($is_admin));
	}

	/** @return array<string, string> */
	public static function getSearchChoices(): array {
		return self::getKeysToArr(self::getChoices());
	}
}
