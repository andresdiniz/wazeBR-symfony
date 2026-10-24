<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Partner;
use App\Entity\User;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

final class UserType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Nome completo',
                'attr' => ['autocomplete' => 'name', 'class' => 'form-control'],
            ])
            ->add('email', EmailType::class, [
                'label' => 'E-mail',
                'attr' => ['autocomplete' => 'email', 'class' => 'form-control'],
            ])
            ->add('phone', TextType::class, [
                'label' => 'Telefone',
                'required' => false,
                'attr' => ['autocomplete' => 'tel', 'class' => 'form-control'],
            ])
            ->add('partner', EntityType::class, [
                'class' => Partner::class,
                'choice_label' => 'name',
                'label' => 'Parceiro vinculado',
                'placeholder' => 'Sem parceiro (sem escopo de parceiro)',
                'required' => false,
                'mapped' => false,
                'data' => $options['partner_data'],
                'disabled' => $options['partner_locked'] || $options['profile_locked'],
                'attr' => ['class' => 'form-select', 'data-partner-select' => ''],
                'help' => 'Os perfis de parceiro, operador e agente de via precisam de vínculo com parceiro.',
            ])
            ->add('roles', ChoiceType::class, [
                'label' => 'Perfil de acesso',
                'choices' => $options['available_roles'],
                'data' => $options['roles_data'],
                'multiple' => false,
                'expanded' => true,
                'required' => true,
                'disabled' => $options['roles_locked'],
                'mapped' => false,
                'attr' => ['class' => 'admin-role-picker'],
                'help' => 'Escolha apenas o perfil necessário para as funções deste usuário.',
            ])
            ->add('isActive', CheckboxType::class, [
                'label' => 'Conta ativa',
                'required' => false,
                'disabled' => $options['profile_locked'],
            ]);

        $builder->add('plainPassword', RepeatedType::class, [
            'type' => PasswordType::class,
            'mapped' => false,
            'label' => 'Senha',
            'required' => $options['require_password'],
            'first_options' => [
                'label' => 'Senha',
                'attr' => ['autocomplete' => 'new-password'],
                'constraints' => $options['require_password'] ? [
                    new NotBlank(['message' => 'Informe uma senha.']),
                    new Length(['min' => 8, 'minMessage' => 'Use pelo menos {{ limit }} caracteres.', 'max' => 4096]),
                    new Regex(['pattern' => '/^(?=.*[A-Za-z])(?=.*\\d).+$/', 'message' => 'A senha deve conter letras e números.']),
                ] : [],
            ],
            'second_options' => [
                'label' => 'Confirmar senha',
                'attr' => ['autocomplete' => 'new-password'],
            ],
            'invalid_message' => 'As senhas precisam ser iguais.',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'require_password' => true,
            'available_roles' => [],
            'roles_data' => User::ROLE_VIEWER,
            'partner_data' => null,
            'partner_locked' => false,
            'profile_locked' => false,
            'roles_locked' => false,
        ]);
        $resolver->setAllowedTypes('require_password', 'bool');
        $resolver->setAllowedTypes('available_roles', 'array');
        $resolver->setAllowedTypes('roles_data', 'string');
        $resolver->setAllowedTypes('partner_data', ['null', Partner::class]);
        $resolver->setAllowedTypes('partner_locked', 'bool');
        $resolver->setAllowedTypes('profile_locked', 'bool');
        $resolver->setAllowedTypes('roles_locked', 'bool');
    }
}
