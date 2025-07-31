<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Utils;

use Symfony\Component\HttpFoundation\Request;

use Symfony\Component\Form\Form;
use Symfony\Component\Form\FormFactoryInterface;

class FormHelper
{
	public static function formCreator(
		FormFactoryInterface $formFactory, 
		Request $request, 
		string $formTypeClass,
		$data = null,
		array $options = []
	): Form {

		// Get form name from options, default to null or '' for unnamed form
		$formName = $options['form_name'] ?? null;
		if ($formName) {
			// Create named form with prefix
			$form = $formFactory->createNamed($formName, $formTypeClass, $data, $options);
		} else {
			$form = $formFactory->create($formTypeClass, $data, $options);
		}

		// handle form submit
      $form->handleRequest($request);

		return $form;
	}

	public static function getFilters(): array {
		return array(
			'Date' => 'created_at',
			'Subject' => 'subject',
			'Action' => 'action',
			'Score' => 'score',
			'Stored (0/1)' => 'mail_stored',
			'Received from IP' => 'ip',
			'MAIL From' => 'mail_from',
			'RCPT To' => 'rcpt_to',
			'MIME From' => 'mime_from',
			'MIME To' => 'mime_to',
			'Has Virus (0/1)' => 'has_virus',
			'Size' => 'size',
			'Headers' => 'headers',
			'Rspamd symbols' => 'symbols',
			'Released (0/1)' => 'released',
			'Notified (0/1)' => 'notified',
			'Notification pending (0/1)' => 'notification_pending',
			'Server' => 'server',
		);
	}
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

	public static function getFilterByName(array $active_filters): array {
		$filters = self::getFilters();
		$choices = self::getChoices();
		$ar = array();

		foreach ($active_filters as $key => $filter) {
			if (isset($filters[$filter['filter']])) {
				$ar[$key]['filter'] = $filters[$filter['filter']];
			}
			if (isset($choices[$filter['choice']])) {
				$ar[$key]['choice'] = $choices[$filter['choice']];
			}
			$ar[$key]['value'] = $filter['value'];
		}

		return $ar;
	}

	public static function getKeysToArr(array $ar): array {
		return array_combine(array_keys($ar), array_keys($ar));
	}

	public static function getSearchFilters(): array {
		return self::getKeysToArr(self::getFilters());
	}

	public static function getSearchChoices(): array {
		return self::getKeysToArr(self::getChoices());
	}
}
