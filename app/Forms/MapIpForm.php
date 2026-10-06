<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Forms;

use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\FormBuilderInterface;

use App\Inventory\MapInventory;

class MapIpForm extends MapWithOneFieldForm
{
	protected static string $fieldName = 'ip';

	#[\Override]
	public function buildForm(FormBuilderInterface $builder, array $options): void {
		parent::buildForm($builder, $options);

		// store IPs in canonical form so duplicates compare equal
		$builder->get(static::$fieldName)->addModelTransformer(new CallbackTransformer(
			fn($value) => $value,
			fn($value) => is_string($value) ? MapInventory::canonicalIpOrCidr($value) : $value,
		));
	}

}
