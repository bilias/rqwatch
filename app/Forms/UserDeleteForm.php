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
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Type;

use Symfony\Component\Form\Form;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;

use App\Utils\FormHelper;

class UserDeleteForm extends AbstractType
{
	public function buildForm(FormBuilderInterface $formFactory, array $options): void {
		  $username = htmlspecialchars($options['data']['username'] ?? 'this user', ENT_QUOTES);
        $formFactory
				->add('id', HiddenType::class)
            ->add('delete', SubmitType::class, [
                'label' => 'Delete',
					 'attr' => [
						'class' => 'btn btn-danger',
						'onclick' => "return confirm('Are you sure you want to delete user \"{$username}\"?');",
					 ],
            ]);
	}

	public static function create(
			FormFactoryInterface $formFactory,
			Request $request,
			array $data = []): Form {

		return FormHelper::formCreator($formFactory, $request, self::class, $data);
	}

	public static function check_form(Form $form, UrlGeneratorInterface $urlGenerator): ?RedirectResponse {
		if ($form->isSubmitted() && $form->isValid()) {
			$data = $form->getData();
			$id = $data['id'];
			if (!$id) return null;

			$url = $urlGenerator->generate('admin_userdel', [
				'id' => $id,
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
