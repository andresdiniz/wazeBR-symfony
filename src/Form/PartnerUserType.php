<?php

namespace App\Form;

use App\Entity\User;
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

class PartnerUserType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => 'E-mail',
                'attr' => ['placeholder' => 'usuario@empresa.com.br'],
                'constraints' => [
                    new NotBlank(['message' => 'E-mail é obrigatório']),
                ],
            ])
            ->add('name', TextType::class, [
                'label' => 'Nome completo',
                'required' => true,
                'attr' => ['placeholder' => 'Nome da pessoa'],
            ])
            ->add('isActive', CheckboxType::class, [  // ← ALTERADO: active → isActive
                'label' => 'Usuário ativo',
                'required' => false,
            ])
            ->add('roles', ChoiceType::class, [
                'label' => 'Perfil de acesso',
                'choices' => [
                    'Administrador da conta' => 'ROLE_ACCOUNT_ADMIN',
                    'Agente de via' => 'ROLE_FIELD_AGENT',
                    'Visualizador' => 'ROLE_USER',
                ],
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'attr' => ['class' => 'form-check-input'],
            ])
        ;

        if ($options['include_password']) {
            $builder->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'first_options' => [
                    'label' => 'Senha',
                    'attr' => ['autocomplete' => 'new-password', 'class' => 'form-control'],
                    'constraints' => [
                        new NotBlank(['message' => 'Senha é obrigatória']),
                        new Length([
                            'min' => 6,
                            'minMessage' => 'Senha deve ter pelo menos {{ limit }} caracteres',
                        ]),
                    ],
                ],
                'second_options' => [
                    'label' => 'Confirmar senha',
                    'attr' => ['autocomplete' => 'new-password', 'class' => 'form-control'],
                ],
                'invalid_message' => 'As senhas não coincidem',
                'mapped' => false,
                'required' => true,
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'include_password' => false,
        ]);

        $resolver->setAllowedTypes('include_password', 'boolean');
    }
}
