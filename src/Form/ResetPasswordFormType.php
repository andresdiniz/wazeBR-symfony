<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class ResetPasswordFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'invalid_message' => 'As senhas não coincidem.',
                'first_options' => [
                    'label' => 'Nova senha',
                    'attr' => [
                        'autocomplete' => 'new-password',
                        'placeholder' => 'Digite a nova senha',
                    ],
                ],
                'second_options' => [
                    'label' => 'Confirme a nova senha',
                    'attr' => [
                        'autocomplete' => 'new-password',
                        'placeholder' => 'Repita a nova senha',
                    ],
                ],
                'constraints' => [
                    new NotBlank(['message' => 'Informe a nova senha.']),
                    new Length([
                        'min' => 8,
                        'minMessage' => 'A senha deve ter pelo menos {{ limit }} caracteres.',
                    ]),
                ],
            ]);
    }
}
