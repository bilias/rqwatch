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

use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Type;

use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;

use App\Core\RouteName;
use App\Utils\FormHelper;
use App\Models\User;

class MailReleaseForm extends AbstractType
{
	public function buildForm(FormBuilderInterface $formFactory, array $options): void {
        $formFactory
            ->add('email', EmailType::class, [
                'required' => false,
                'disabled' => true,
                'label' => 'E-mail: ',
					 'attr' => [
						'class' => 'email',
						'title' => 'E-mail for release',
					 ],
            ])
            ->add('email_alt', EmailType::class, [
                'required' => false,
                'label' => 'Alternative E-mail: ',
					 'invalid_message' => 'Please enter a valid E-mail address',
					 'attr' => [
						'class' => 'email',
						'title' => 'Enter alternative e-mail for release',
					 ],
            ])
				->add('release', CheckboxType::class, [
					'label' => 'Release',
					'required' => false,
					 'attr' => [
						'class' => 'release',
						'title' => 'Release mail to original recipient',
					 ],
				])
				->add('release_alt', CheckboxType::class, [
					'label' => 'Release to alternate recipient',
					'required' => false,
					 'attr' => [
						'class' => 'release',
						'title' => 'Release mail to alternate recipient',
					 ],
				])
            ->add('submit', SubmitType::class, [
                'label' => 'Submit and Release Mail',
            ]);
	}

	public static function create(
			FormFactoryInterface $formFactory,
			Request $request,
			array $data = null,
			array $options = []): FormInterface {

		return FormHelper::formCreator($formFactory, $request, self::class, $data, $options);
	}

	public static function check_form(FormInterface $form, UrlGeneratorInterface $urlGenerator): ?RedirectResponse {
		if ($form->isSubmitted() && $form->isValid()) {
			$data = $form->getData();
			$username = $data['username'];

			$url = $urlGenerator->generate(RouteName::DETAIL->value, [
				'type' => 'username',
				'value' => $username,
			]);
			return new RedirectResponse($url);
         /*
         $response = new RedirectResponse($url);
         $response->prepare($this->request);
         return $response->send();
         */
      }
		return null;
	}
}
