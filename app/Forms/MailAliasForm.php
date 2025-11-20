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

use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Constraints\NotBlank;

use Symfony\Component\HttpFoundation\Request;

use App\Utils\FormHelper;

class MailAliasForm extends AbstractType
{
	public function buildForm(FormBuilderInterface $formFactory, array $options): void {
        $formFactory
            ->add('username', TextType::class, [
                'required' => true,
                'label' => 'Username: ',
					 'attr' => [
						'class' => 'username',
						'title' => "Enter user's username",
					 ],
					 'constraints' => [
						new NotBlank(),
						new Assert\Length(
							min: 2,
							max: 128,
						),
						new Assert\Regex(
							pattern: '/^[a-zA-Z0-9._+-]+(@[a-zA-Z0-9-]+(\.[a-zA-Z0-9-]+)+)?$/',
							message: 'The value can only contain letters, numbers and ._+-@',
						),
					 ],
            ])
            ->add('alias', EmailType::class, [
                'required' => true,
                'label' => 'E-mail Alias: ',
					 'attr' => [
						'class' => 'email',
						'title' => 'Enter user e-mail alias',
					 ],
					 'constraints' => [
						new NotBlank(),
						new Assert\Length(
							min: 6,
							max: 128,
						),
					 ],
            ])
            ->add('add', SubmitType::class, [
                'label' => 'Add',
            ]);
	}

	public static function create(
			FormFactoryInterface $formFactory,
			Request $request,
			array $data = null): FormInterface {

		return FormHelper::formCreator($formFactory, $request, self::class, $data);
	}

}
