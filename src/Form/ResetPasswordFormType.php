<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;

class ResetPasswordRequestFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => false,
                'required' => true,
                'mapped' => false,
                'trim' => true,
                'attr' => [
                    'id' => 'reset_email',
                    'autocomplete' => 'email',
                    'placeholder' => 'seu@email.com',
                    'class' => 'auth-input',
                ],
                'constraints' => [
                    new NotBlank(
                        message: 'Informe seu e-mail.'
                    ),
                    new Email(
                        message: 'Informe um e-mail válido.'
                    ),
                ],
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'Enviar instruções',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([]);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
