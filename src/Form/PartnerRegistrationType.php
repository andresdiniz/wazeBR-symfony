<?php
// src/Form/PartnerRegistrationType.php - NOVO FORMULÁRIO COMBINADO

namespace App\Form;

use App\Entity\Partner;
use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\IsTrue;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class PartnerRegistrationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // Dados do Parceiro
            ->add('partnerName', TextType::class, [
                'label' => 'Nome da Empresa/Parceiro',
                'attr' => ['placeholder' => 'Ex: Prefeitura Municipal de...'],
                'constraints' => [
                    new NotBlank(['message' => 'Nome do parceiro é obrigatório']),
                ],
            ])
            ->add('partnerEmail', EmailType::class, [
                'label' => 'Email institucional',
                'attr' => ['placeholder' => 'contato@empresa.com.br'],
                'constraints' => [
                    new NotBlank(['message' => 'Email é obrigatório']),
                ],
            ])
            ->add('partnerPhone', TelType::class, [
                'label' => 'Telefone/WhatsApp',
                'attr' => ['placeholder' => '(XX) XXXXX-XXXX'],
                'required' => false,
            ])

            // Dados do Usuário Admin
            ->add('userEmail', EmailType::class, [
                'label' => 'Email do administrador',
                'attr' => ['placeholder' => 'admin@empresa.com.br'],
                'help' => 'Email que será usado para login',
                'constraints' => [
                    new NotBlank(['message' => 'Email do administrador é obrigatório']),
                ],
            ])
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'first_options' => [
                    'label' => 'Senha',
                    'attr' => ['autocomplete' => 'new-password', 'placeholder' => 'Mínimo 6 caracteres'],
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
            ])
            ->add('userFullName', TextType::class, [
                'label' => 'Nome completo do administrador',
                'attr' => ['placeholder' => 'Nome da pessoa responsável'],
                'required' => false,
            ])

            // Termos
            ->add('agreeTerms', CheckboxType::class, [
                'label' => 'Concordo com os termos de uso e política de privacidade',
                'constraints' => [
                    new IsTrue(['message' => 'Você precisa concordar com os termos']),
                ],
                'mapped' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([]);
    }
}
