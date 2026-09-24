<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\PartnerFeedEvent;
use App\Enum\CifsDirectionEnum;
use App\Enum\CifsTypeEnum;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Formulário de cadastro/edição de eventos CIFS do parceiro.
 * Usado pelas templates templates/partner_feed/events/*.
 */
class PartnerFeedEventType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('cifsType', EnumType::class, [
                'class' => CifsTypeEnum::class,
                'label' => 'Tipo do evento (CIFS)',
                'choice_label' => static fn (CifsTypeEnum $type) => $type->value,
                'attr' => ['class' => 'form-select'],
            ])
            ->add('cifsSubtype', ChoiceType::class, [
                'label' => 'Subtipo (CIFS)',
                'required' => false,
                'choices' => self::allSubtypes(),
                'placeholder' => 'Selecione o tipo primeiro',
                'attr' => ['class' => 'form-select'],
                'help' => 'O backend valida se o subtipo bate com o tipo escolhido.',
            ])
            ->add('street', TextType::class, [
                'label' => 'Rua (obrigatória no CIFS)',
                'attr' => ['class' => 'form-control', 'placeholder' => 'Ex: Av. Prefeito Telêmaco Máximo Resende'],
            ])
            ->add('description', TextType::class, [
                'label' => 'Descrição (ideal <= 40 caracteres, vai para TTS do Waze)',
                'required' => false,
                'attr' => ['class' => 'form-control', 'maxlength' => 180],
            ])
            ->add('city', TextType::class, [
                'label' => 'Cidade',
                'required' => false,
                'attr' => ['class' => 'form-control'],
            ])
            ->add('reference', TextType::class, [
                'label' => 'Referência (bairro, km, nº etc.)',
                'required' => false,
                'attr' => ['class' => 'form-control'],
            ])
            ->add('startTime', DateTimeType::class, [
                'label' => 'Início do evento',
                'widget' => 'single_text',
                'html5' => true,
            ])
            ->add('endTime', DateTimeType::class, [
                'label' => 'Fim previsto (opcional)',
                'widget' => 'single_text',
                'html5' => true,
                'required' => false,
                'help' => 'Se vazio, o Waze assume 14 dias após o início (fallback oficial CIFS).',
            ])
            ->add('polylineJson', TextType::class, [
                'label' => 'Polyline (JSON: [[lat, lng], [lat, lng]])',
                'required' => true,
                'mapped' => false,
                'attr' => ['class' => 'form-control', 'placeholder' => '[[-20.6601,-43.7852], [-20.6609,-43.7861]]'],
                'constraints' => [new Callback([self::class, 'validatePolylineJson'])],
            ])
            ->add('direction', EnumType::class, [
                'class' => CifsDirectionEnum::class,
                'label' => 'Direção',
                'choice_label' => static fn (CifsDirectionEnum $d) => $d->value,
                'attr' => ['class' => 'form-select'],
            ])
            ->add('isActive', CheckboxType::class, [
                'label' => 'Ativo (aparece no feed)',
                'required' => false,
                'attr' => ['class' => 'form-check-input'],
            ]);

        // Preenche o campo polylineJson na edição e convenções SUBMIT
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event): void {
            /** @var PartnerFeedEvent|null $data */
            $data = $event->getData();
            if ($data instanceof PartnerFeedEvent && $data->getPolyline() !== []) {
                $event->getForm()->get('polylineJson')->setData(json_encode($data->getPolyline()));
            }
        });

        $builder->addEventListener(FormEvents::SUBMIT, function (FormEvent $event): void {
            /** @var PartnerFeedEvent|null $data */
            $data = $event->getData();
            if (!$data instanceof PartnerFeedEvent) {
                return;
            }
            $form = $event->getForm();

            $raw = (string) $form->get('polylineJson')->getData();
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && count($decoded) >= 2) {
                $data->setPolyline($decoded);
            }

            $cifsType = $data->getCifsType();
            $subtype = $data->getCifsSubtype();
            if ($cifsType && !$cifsType->isValidSubtype($subtype)) {
                $form->get('cifsSubtype')->addError(new FormError(
                    sprintf('Subtipo "%s" não pertence ao tipo %s.', $subtype, $cifsType->value)
                ));
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PartnerFeedEvent::class,
        ]);
    }

    private static function allSubtypes(): array
    {
        $choices = [];
        foreach (CifsTypeEnum::cases() as $type) {
            foreach ($type->allowedSubtypes() as $subtype) {
                $choices[$subtype] = $subtype;
            }
        }
        return $choices;
    }

    public static function validatePolylineJson($value, ExecutionContextInterface $context): void
    {
        if (!\is_string($value) || trim($value) === '') {
            $context->buildViolation('Polyline é obrigatória.')->addViolation();
            return;
        }
        $decoded = json_decode($value, true);
        if (!\is_array($decoded) || \count($decoded) < 2) {
            $context->buildViolation('Polyline precisa ser JSON com ao menos 2 pontos: [[lat,lng],[lat,lng]]')->addViolation();
            return;
        }
        foreach ($decoded as $i => $point) {
            if (!\is_array($point) || !isset($point[0], $point[1]) || !is_numeric($point[0]) || !is_numeric($point[1])) {
                $context->buildViolation("Ponto #{$i} inválido — formato [lat, lng].")->addViolation();
                return;
            }
        }
    }
}