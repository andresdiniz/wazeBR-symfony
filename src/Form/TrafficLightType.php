<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\TrafficLight;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

final class TrafficLightType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('code', TextType::class, [
                'label'       => 'Código',
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Length(max: 100),
                    new Assert\Regex(
                        pattern: '/^[A-Z0-9\-_]+$/',
                        message: 'Use apenas letras maiúsculas, números, hífen e underscore.',
                    ),
                ],
            ])
            ->add('name', TextType::class, [
                'label'       => 'Nome',
                'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 255)],
            ])
            ->add('protocol', ChoiceType::class, [
                'label'       => 'Protocolo',
                'choices'     => array_flip(TrafficLight::PROTOCOLS),
                'constraints' => [new Assert\NotBlank()],
            ])
            ->add('endpoint', TextType::class, [
                'label'       => 'Endpoint',
                'help'        => 'host:porta (NTCIP/Modbus) ou URL base (HTTP REST)',
                'constraints' => [new Assert\NotBlank()],
            ])
            ->add('optionsJson', TextareaType::class, [
                'label'    => 'Opções (JSON)',
                'required' => false,
                'mapped'   => false,
                'data'     => $options['optionsJson'],
                'attr'     => [
                    'rows'        => 5,
                    'placeholder' => '{"community":"public","timeout":2,"vendor":"tesc"}',
                    'spellcheck'  => 'false',
                ],
                'help' => 'Deixe vazio para usar os padrões do protocolo.',
            ])
            ->add('latitude',  NumberType::class,   ['label' => 'Latitude',  'required' => false, 'scale' => 7])
            ->add('longitude', NumberType::class,   ['label' => 'Longitude', 'required' => false, 'scale' => 7])
            ->add('isActive',  CheckboxType::class, ['label' => 'Ativo', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'  => TrafficLight::class,
            'optionsJson' => null,
        ]);
    }
}
