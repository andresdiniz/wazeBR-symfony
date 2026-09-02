<?php
// src/Form/PartnerUserType.php - ATUALIZADO

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
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
                'label' => 'Email',
                'attr' => ['placeholder' => 'usuario@empresa.com.br'],
                'constraints' => [
                    new NotBlank(['message' => 'Email é obrigatório']),
                ],
            ])
            ->add('fullName', TextType::class, [
                'label' => 'Nome completo',
                'required' => false,
                'attr' => ['placeholder' => 'Nome da pessoa'],
            ])
            ->add('active', CheckboxType::class, [
                'label' => 'Usuário ativo',
                'required' => false,
            ])
        ;

        // Adicionar campos de senha apenas para criação (novo usuário)
        if ($options['include_password']) {
            $builder->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'first_options' => [
                    'label' => 'Senha',
                    'attr' => ['autocomplete' => 'new-password'],
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
                    'attr' => ['autocomplete' => 'new-password'],
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
