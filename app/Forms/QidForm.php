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
use Symfony\Component\Form\Extension\Core\Type\SubmitType;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Constraints\NotBlank;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;

use App\Core\Routing\RouteName;
use App\Utils\FormHelper;

/** @extends AbstractType<array<string, mixed>> */
final class QidForm extends AbstractType
{
	#[\Override]
	public function buildForm(FormBuilderInterface $builder, array $options): void {
        $builder
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
							min: 6,
							max: 30,
						),
						new Assert\Regex(
							pattern: '/^[a-zA-Z0-9]+$/D',
							message: 'The value can only contain letters and digits.',
						),
					 ],
            ])
            ->add('go', SubmitType::class, [
                'label' => 'Go',
            ]);
	}

	/**
	 * @psalm-return FormInterface<array<string, mixed>>
	 * @phpstan-return FormInterface
	 */
	public static function create(
			FormFactoryInterface $formFactory,
			Request $request): FormInterface {

		return FormHelper::formCreator($formFactory, $request, self::class);
	}

	/**
	 * @psalm-param FormInterface<array<string, mixed>> $form
	 * @phpstan-param FormInterface $form
	 */
	public static function check_form(
		FormInterface $form,
		UrlGeneratorInterface $urlGenerator,
		bool $is_admin = false
	): ?RedirectResponse {
		if ($form->isSubmitted() && $form->isValid()) {
			$data = FormHelper::submittedData($form);
			$qid = FormHelper::text($data['qid'], 'qid');

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
