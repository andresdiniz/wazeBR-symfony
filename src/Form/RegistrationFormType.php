<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

class RegistrationFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => 'E-mail',
                'constraints' => [
                    new NotBlank([
                        'message' => 'Informe seu e-mail.',
                    ]),
                ],
            ])
            ->add('name', TextType::class, [
                'label' => 'Nome',
                'constraints' => [
                    new NotBlank([
                        'message' => 'Informe seu nome.',
                    ]),
                    new Length([
                        'min' => 3,
                        'max' => 255,
                        'minMessage' => 'O nome deve ter pelo menos {{ limit }} caracteres.',
                        'maxMessage' => 'O nome deve ter no máximo {{ limit }} caracteres.',
                    ]),
                ],
            ])
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'first_options' => [
                    'label' => 'Senha',
                    'constraints' => [
                        new NotBlank([
                            'message' => 'Informe uma senha.',
                        ]),
                        new Length([
                            'min' => 8,
                            'minMessage' => 'A senha deve ter pelo menos {{ limit }} caracteres.',
                            'max' => 4096,
                        ]),
                        new Regex([
                            'pattern' => '/^(?=.*[A-Za-z])(?=.*\d).+$/',
                            'message' => 'A senha deve conter letras e números.',
                        ]),
                    ],
                ],
                'second_options' => [
                    'label' => 'Confirmar senha',
                ],
                'invalid_message' => 'As senhas não coincidem.',
                'mapped' => false, // não mapeia diretamente para a entidade
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'attr' => [
                'novalidate' => 'novalidate',
            ],
        ]);
    }
}
