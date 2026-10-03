<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;  // <-- ADICIONE ESTA LINHA!
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class UserType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Nome',
                'attr' => ['class' => 'form-control'],
            ])
            ->add('email', EmailType::class, [
                'label' => 'E-mail',
                'attr' => ['class' => 'form-control'],
            ])
            ->add('phone', TextType::class, [
                'label' => 'Telefone',
                'required' => false,
                'attr' => ['class' => 'form-control'],
            ])
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'label' => 'Senha',
                'required' => $options['require_password'],
                'first_options' => [
                    'label' => 'Senha',
                    'attr' => ['class' => 'form-control', 'autocomplete' => 'new-password'],
                    'constraints' => $options['require_password'] ? [
                        new NotBlank(['message' => 'Por favor, digite uma senha']),
                        new Length([
                            'min' => 6,
                            'minMessage' => 'Sua senha deve ter pelo menos {{ limit }} caracteres',
                            'max' => 4096,
                        ]),
                    ] : [],
                ],
                'second_options' => [
                    'label' => 'Confirmar senha',
                    'attr' => ['class' => 'form-control', 'autocomplete' => 'new-password'],
                ],
                'invalid_message' => 'As senhas devem coincidir.',
            ])
            ->add('roles', ChoiceType::class, [
                'label' => 'Permissões',
                'choices' => [
                    'Usuário Comum' => 'ROLE_USER',
                    'Admin' => 'ROLE_ADMIN',
                    'Admin Partner' => 'ROLE_ADMIN_PARTNER',
                ],
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'attr' => ['class' => 'form-check'],
            ])
            ->add('isActive', CheckboxType::class, [
                'label' => 'Usuário ativo',
                'required' => false,
                'attr' => ['class' => 'form-check-input'],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'require_password' => true,
        ]);

        $resolver->setAllowedTypes('require_password', 'bool');
    }
}
