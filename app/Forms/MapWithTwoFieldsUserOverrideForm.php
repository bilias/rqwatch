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

namespace App\Forms;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Form;

use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Type;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Regex;

use App\Inventory\MapInventory;

class MapWithTwoFieldsUserOverrideForm extends MapWithTwoFieldsForm
{
	public function buildForm(FormBuilderInterface $builder, array $options): void {
		if (static::$firstFieldName === '' || static::$secondFieldName === '') {
			throw new \RuntimeException("Field names must be defined in child class: " . static::class);
		}

		$firstField = MapInventory::getFieldDefinitions(static::$firstFieldName);

		// Get the original rcpt_to field def, and replace with dropdown if needed
		if (static::$secondFieldName === 'rcpt_to') {
			$userEmails = $options['user_emails'] ?? [];

			$secondField = [
				'type' => ChoiceType::class,
				'field_options' => [
					'label' => 'RCPT To:',
					'required' => true,
					'choices' => array_combine($userEmails, $userEmails),
					'placeholder' => '--- Select ---',
					'constraints' => [
						new NotBlank()
					],
					'attr' => ['class' => 'uniform-input'],
				],
			];
		} else {
			$secondField = MapInventory::getFieldDefinitions(static::$secondFieldName);
		}

		$builder
			->add(static::$firstFieldName, $firstField['type'], $firstField['field_options'])
			->add(static::$secondFieldName, $secondField['type'], $secondField['field_options'])
			->add('submit', SubmitType::class, [
				'label' => 'Submit',
			]);
	}

	public function configureOptions(OptionsResolver $resolver): void {
		// Define allowed custom options
		$resolver->setDefined(['role']);
		$resolver->setDefined(['user_emails']);
	}
}
