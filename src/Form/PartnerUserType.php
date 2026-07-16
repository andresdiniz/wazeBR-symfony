<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class PartnerUserType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $isNew = $options['is_new'];

        $builder
            ->add('name', TextType::class, [
                'label'       => 'Nome completo',
                'constraints' => [new NotBlank(), new Length(min: 3, max: 120)],
            ])
            ->add('email', EmailType::class, [
                'label'       => 'E-mail',
                'constraints' => [new NotBlank(), new Email()],
            ])
            ->add('roles', ChoiceType::class, [
                'label'    => 'Perfil de acesso',
                'choices'  => [
                    'Administrador da conta' => 'ROLE_ACCOUNT_ADMIN',
                    'Usuário padrão'         => 'ROLE_USER',
                    'Agente de via'          => 'ROLE_FIELD_AGENT',
                ],
                'multiple' => false,
                'expanded' => true,
                'data'     => 'ROLE_ACCOUNT_ADMIN',
                // roles é array na entidade — usamos mapped: false e tratamos no controller
                'mapped'   => false,
            ])
            ->add('isActive', \Symfony\Component\Form\Extension\Core\Type\CheckboxType::class, [
                'label'    => 'Usuário ativo',
                'required' => false,
            ]);

        if ($isNew) {
            $builder->add('plainPassword', PasswordType::class, [
                'label'       => 'Senha inicial',
                'mapped'      => false,
                'constraints' => [new NotBlank(), new Length(min: 8, max: 64)],
                'attr'        => ['autocomplete' => 'new-password'],
                'help'        => 'Mínimo 8 caracteres.',
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'is_new'     => true,
        ]);
    }
}
