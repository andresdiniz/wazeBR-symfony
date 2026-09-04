<?php

namespace App\Form;

use App\Entity\Partner;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class PartnerType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Nome',
                'required' => true,
            ])
            ->add('code', TextType::class, [
                'label' => 'Código',
                'required' => false,
            ])
            ->add('slug', TextType::class, [
                'label' => 'Slug',
                'required' => false,
                'help' => 'Identificador único na URL. Ex: prefeitura-sp',
            ])
            ->add('email', EmailType::class, [
                'label' => 'E-mail',
                'required' => false,
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Descrição',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('bbox', TextareaType::class, [
                'label' => 'BBox (WKT)',
                'required' => false,
                'attr' => ['rows' => 2, 'placeholder' => 'POLYGON((-43.8 -20.6, ...))'],
                'help' => 'Polígono de cobertura no formato WKT.',
            ])
            ->add('active', CheckboxType::class, [
                'label' => 'Ativo',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Partner::class,
        ]);
    }
}
