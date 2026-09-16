<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class ChangePasswordFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('plainPassword', RepeatedType::class, [
            'type'            => PasswordType::class,
            'options'         => ['attr' => ['autocomplete' => 'new-password']],
            'first_options'   => [
                'label' => 'Nova senha',
                'attr'  => [
                    'placeholder' => 'Mínimo 6 caracteres',
                    'class'       => 'auth-input',
                    'id'          => 'new_password',
                    'minlength'   => '6',
                    'autofocus'   => true,
                ],
                'constraints' => [
                    new NotBlank(['message' => 'Digite uma senha.']),
                    new Length([
                        'min'        => 6,
                        'minMessage' => 'A senha deve ter no mínimo {{ limit }} caracteres.',
                        'max'        => 4096,
                    ]),
                ],
            ],
            'second_options'  => [
                'label' => 'Confirmar nova senha',
                'attr'  => [
                    'placeholder' => 'Repita a nova senha',
                    'class'       => 'auth-input',
                    'id'          => 'confirm_password',
                ],
            ],
            'invalid_message' => 'As senhas não coincidem.',
            'mapped'          => false,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([]);
    }
}
