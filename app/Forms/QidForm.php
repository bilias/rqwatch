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
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Type;
use Symfony\Component\Validator\Constraints\Regex;

use Symfony\Component\Form\Form;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;

use App\Core\RouteName;
use App\Utils\FormHelper;

class QidForm extends AbstractType
{
	public function buildForm(FormBuilderInterface $formFactory, array $options): void {
        $formFactory
            ->add('qid', TextType::class, [
                'required' => true,
                'label' => 'Go to message: ',
					 'attr' => [
						'class' => 'qidfield',
						'title' => 'Enter Mail Queue ID of the mail',
					 ],
					 'constraints' => [
						new NotBlank(),
						new Assert\Length(
							min: 9,
							max: 12,
						),
						new Assert\Regex(
							pattern: '/^[A-Z0-9]+$/',
							message: 'The value can only contain capital letters and digits.',
						),
					 ],
            ])
            ->add('go', SubmitType::class, [
                'label' => 'Go',
            ]);
	}

	public static function create(
			FormFactoryInterface $formFactory,
			Request $request): Form {

		return FormHelper::formCreator($formFactory, $request, self::class);
	}

	public static function check_form(
		Form $form,
		UrlGeneratorInterface $urlGenerator,
		bool $is_admin = false
	): ?RedirectResponse {
		if ($form->isSubmitted() && $form->isValid()) {
			$data = $form->getData();
			$qid = $data['qid'];

			if ($is_admin) {
				$url = $urlGenerator->generate(RouteName::ADMIN_DETAIL->value, [
					'type' => 'qid',
					'value' => $qid,
				]);
			} else {
				$url = $urlGenerator->generate(RouteName::DETAIL->value, [
					'type' => 'qid',
					'value' => $qid,
				]);

			}
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
