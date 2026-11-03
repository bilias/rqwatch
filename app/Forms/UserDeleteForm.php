<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Forms;

use Symfony\Component\Form\AbstractType;

use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormBuilderInterface;

use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;

use Symfony\Component\HttpFoundation\Request;

use App\Utils\FormHelper;

/** @extends AbstractType<array<string, mixed>> */
final class UserDeleteForm extends AbstractType
{
	#[\Override]
	public function buildForm(FormBuilderInterface $builder, array $options): void {
		// a JS string literal for confirm(); Twig then escapes the attribute
		$confirm = json_encode(
			'Are you sure you want to delete user "' . ($options['data']['username'] ?? 'this user') . '"?',
			JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
			| JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
		);
        $builder
				->add('id', HiddenType::class)
            ->add('delete', SubmitType::class, [
                'label' => 'Delete User',
					 'attr' => [
						'class' => 'btn btn-danger',
						'onclick' => "return confirm({$confirm});",
					 ],
            ]);
	}

	/** @param array<string, mixed> $data */
	public static function create(
			FormFactoryInterface $formFactory,
			Request $request,
			array $data = []): FormInterface {

		return FormHelper::formCreator($formFactory, $request, self::class, $data);
	}

}
