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
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

use Symfony\Component\OptionsResolver\OptionsResolver;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;

use App\Core\Routing\RouteName;
use App\Utils\FormHelper;

class SearchForm extends AbstractType
{
	#[\Override]
	public function buildForm(FormBuilderInterface $formFactory, array $options): void {
        $formFactory
            ->add('filter', ChoiceType::class, [
					'required' => true,
					'label' => 'Filter: ',
					'choices' => FormHelper::getSearchFilters($options['is_admin']),
					'constraints' => [
						new NotBlank(),
					],
				])
            ->add('choice', ChoiceType::class, [
					'required' => true,
					'label' => 'Choice: ',
					'choices' => FormHelper::getSearchChoices(),
					'constraints' => [
						new NotBlank(),
					],
				])
				->add('value', TextType::class, [
					'required' => false,
					// comment out to support NULLs
					'constraints' => [
						new NotBlank(),
						new Assert\Length(
                     min: 1,
                     max: 64,
                  ),
					],
				])
            ->add('search', SubmitType::class, [
                'label' => 'Add',
            ]);
	}

	public static function create(
			FormFactoryInterface $formFactory,
			Request $request,
			bool $is_admin,
			?array $data = null): FormInterface {

			return FormHelper::formCreator($formFactory, $request, self::class, $data,
				['is_admin' => $is_admin]);
	}

	public static function check_form(FormInterface $form, UrlGeneratorInterface $urlGenerator): ?RedirectResponse {
		if ($form->isSubmitted() && $form->isValid()) {

			$url = $urlGenerator->generate(RouteName::SEARCH->value);

			return new RedirectResponse($url);
         /*
         $response = new RedirectResponse($url);
         $response->prepare($this->request);
         return $response->send();
         */
      }
		return null;
	}

	#[\Override]
	public function configureOptions(OptionsResolver $resolver): void {
		$resolver->setDefaults([
			'constraints' => [
				new Assert\Callback([self::class, 'validateRegexpValue']),
			],
		]);
		$resolver->setRequired('is_admin');
		$resolver->setAllowedTypes('is_admin', 'bool');
	}

	/*
	 A REGEXP search sends the pattern straight to MariaDB, and an invalid
	 one is a query error (1139 / 42000), not an empty result. That error
	 surfaces as an uncaught exception in getStats(), and because
	 MailLogController::search() persists the filter to the session
	 before running the stats query, a single typo left the search
	 page failing on every later visit - with the only filter-delete
	 buttons sitting in search.twig, the page that no longer rendered.

	 Rejecting the pattern here keeps it out of the session entirely and
	 puts the error next to the field. MariaDB and PHP both use PCRE2, so
	 preg_match is a close proxy.
	 It is only a filter, not a guarantee: a different PCRE2 build
	 or MariaDB's default_regex_flags could disagree, which is why the catch
	 in getStats() is still needed as the backstop.

	 The \x01 delimiter is deliberate. A printable delimiter such as / would
	 make preg treat the first unescaped / in the pattern as the end of it,
	 so a legitimate search for example\.com/path would be wrongly refused.
	*/
	public static function validateRegexpValue(mixed $data, ExecutionContextInterface $context): void {
		if (!is_array($data)) {
			return;
		}

		// getChoices() maps the submitted label to the SQL operator, so the
		// labels are not duplicated here.
		$operator = FormHelper::getChoices()[$data['choice'] ?? ''] ?? null;

		if ($operator !== 'REGEXP' && $operator !== 'NOT REGEXP') {
			return;
		}

		$pattern = (string) ($data['value'] ?? '');

		if (@preg_match("\x01" . $pattern . "\x01", '') === false) {
			$context->buildViolation('Invalid regular expression.')
				->atPath('value')
				->addViolation();
		}
	}
}
