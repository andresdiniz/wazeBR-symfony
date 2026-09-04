<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class ResetPasswordFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('plainPassword', PasswordType::class, [
                'label' => 'Nova senha',
                'constraints' => [
                    new NotBlank(['message' => 'Digite uma senha.']),
                    new Length([
                        'min' => 8,
                        'minMessage' => 'A senha deve ter pelo menos {{ limit }} caracteres.',
                    ]),
                    new Regex([
                        'pattern' => '/[A-Z]/',
                        'message' => 'A senha deve conter pelo menos uma letra maiúscula.',
                    ]),
                    new Regex([
                        'pattern' => '/[a-z]/',
                        'message' => 'A senha deve conter pelo menos uma letra minúscula.',
                    ]),
                    new Regex([
                        'pattern' => '/[0-9]/',
                        'message' => 'A senha deve conter pelo menos um número.',
                    ]),
                    new Regex([
                        'pattern' => '/[^a-zA-Z0-9]/',
                        'message' => 'A senha deve conter pelo menos um caractere especial (@, #, $, etc.).',
                    ]),
                ],
            ])
            ->add('confirmPassword', PasswordType::class, [
                'label' => 'Confirmar senha',
                'mapped' => false,
                'constraints' => [
                    new NotBlank(['message' => 'Confirme sua senha.']),
                    new Callback([
                        'callback' => function ($value, ExecutionContextInterface $context) {
                            $form = $context->getRoot();
                            $plainPassword = $form->get('plainPassword')->getData();
                            if ($value !== $plainPassword) {
                                $context->buildViolation('As senhas não coincidem.')
                                    ->addViolation();
                            }
                        },
                    ]),
                ],
            ])
        ;
    }
}
