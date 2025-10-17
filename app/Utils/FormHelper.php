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

		$form = $formFactory->create($formTypeClass, $data, $options);

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
