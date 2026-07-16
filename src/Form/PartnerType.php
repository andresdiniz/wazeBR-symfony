<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Partner;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

class PartnerType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label'       => 'Nome do parceiro',
                'constraints' => [new NotBlank(), new Length(min: 3, max: 120)],
                'attr'        => ['placeholder' => 'Ex: Prefeitura de Campinas'],
            ])
            ->add('slug', TextType::class, [
                'label'    => 'Slug (identificador único)',
                'required' => false,
                'constraints' => [
                    new Length(max: 80),
                    new Regex(['pattern' => '/^[a-z0-9-]*$/', 'message' => 'Use apenas letras minúsculas, números e hífen.']),
                ],
                'attr' => ['placeholder' => 'gerado-automaticamente'],
                'help' => 'Deixe em branco para gerar automaticamente a partir do nome.',
            ])
            ->add('email', EmailType::class, [
                'label'       => 'E-mail de contato',
                'constraints' => [new NotBlank(), new Email()],
                'attr'        => ['placeholder' => 'contato@parceiro.gov.br'],
            ])
            ->add('bbox', TextType::class, [
                'label'    => 'Bounding Box geográfica',
                'required' => false,
                'constraints' => [
                    new Regex([
                        'pattern' => '/^-?\d+(\.\d+)?,-?\d+(\.\d+)?,-?\d+(\.\d+)?,-?\d+(\.\d+)?$/',
                        'message' => 'Formato: lat_min,lng_min,lat_max,lng_max',
                    ]),
                ],
                'attr' => ['placeholder' => '-23.1,-47.2,-22.8,-46.8'],
                'help' => 'Coordenadas: lat_min,lng_min,lat_max,lng_max',
            ])
            ->add('isActive', CheckboxType::class, [
                'label'    => 'Parceiro ativo',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Partner::class]);
    }
}
