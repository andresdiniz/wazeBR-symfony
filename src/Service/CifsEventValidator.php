<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PartnerFeedEvent;
use App\Enum\CifsDirectionEnum;
use App\Enum\CifsTypeEnum;

/**
 * Valida um evento contra a especificação CIFS do Waze.
 *
 * - "errors": o evento deixaria o feed INVÁLIDO (a spec diz que um valor mal
 *   formatado em tag obrigatória invalida o feed inteiro). Evento com erro
 *   não é salvo e nunca entra no feed publicado.
 * - "warnings": o Waze aceita, mas a spec recomenda corrigir.
 *
 * Referência: https://developers.google.com/waze/data-feed/cifs-specification
 */
final class CifsEventValidator
{
    public const DESCRIPTION_MAX = 40;

    /** @return array{errors: string[], warnings: string[]} */
    public function validate(PartnerFeedEvent $event): array
    {
        $errors = [];
        $warnings = [];

        // type (obrigatório) e subtype (recomendado, deve bater com o type)
        $type = CifsTypeEnum::tryFrom((string) $event->getCifsType());
        if ($type === null) {
            $errors[] = 'Tipo CIFS inválido. Use: ' . implode(', ', array_map(
                static fn (CifsTypeEnum $t): string => $t->value,
                CifsTypeEnum::cases(),
            )) . '.';
        } else {
            $subtype = $event->getCifsSubtype();
            if (!$type->isValidSubtype($subtype)) {
                $errors[] = sprintf('Subtipo "%s" não é válido para o tipo %s.', $subtype, $type->value);
            } elseif (($subtype === null || $subtype === '') && $type->allowedSubtypes() !== []) {
                $warnings[] = 'Subtipo não informado (recomendado pela spec do Waze).';
            }
        }

        // street (obrigatório)
        $street = trim((string) $event->getStreet());
        if ($street === '') {
            $errors[] = 'Rua é obrigatória no CIFS.';
        } elseif (mb_strlen($street) > 255) {
            $errors[] = 'Rua excede 255 caracteres.';
        }

        // direction (recomendado; obrigatório de fato quando só há 1 ponto)
        $direction = CifsDirectionEnum::tryFrom($event->getDirection());
        if ($direction === null) {
            $errors[] = 'Direção inválida. Use BOTH_DIRECTIONS ou ONE_DIRECTION.';
        }

        // polyline (obrigatório): pares [lat, lng] em graus decimais
        $points = $event->getPolyline();
        if ($points === []) {
            $errors[] = 'Polyline é obrigatória (ao menos 1 ponto).';
        } else {
            foreach (array_values($points) as $i => $point) {
                if (!\is_array($point) || !isset($point[0], $point[1])
                    || !is_numeric($point[0]) || !is_numeric($point[1])) {
                    $errors[] = sprintf('Ponto #%d da polyline inválido (formato [lat, lng]).', $i + 1);
                    break;
                }
                $lat = (float) $point[0];
                $lng = (float) $point[1];
                if ($lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) {
                    $errors[] = sprintf('Ponto #%d fora do intervalo válido (lat -90..90, lng -180..180).', $i + 1);
                    break;
                }
            }

            if (\count($points) === 1 && $direction === CifsDirectionEnum::ONE_DIRECTION) {
                $errors[] = 'Com apenas 1 ponto não dá para inferir o sentido; use BOTH_DIRECTIONS ou adicione mais pontos na ordem do tráfego.';
            }
            if (\count($points) === 1 && $type === CifsTypeEnum::ROAD_CLOSED) {
                $warnings[] = 'Interdição com 1 ponto só: marque o trecho com 2+ pontos para o Waze fechar o segmento correto.';
            }
        }

        // starttime (obrigatório para ROAD_CLOSED) / endtime (recomendado)
        $start = $event->getStartTime();
        $end = $event->getEndTime();
        if ($start === null && $type === CifsTypeEnum::ROAD_CLOSED) {
            $errors[] = 'Início (starttime) é obrigatório para interdições (ROAD_CLOSED).';
        }
        if ($end === null) {
            $warnings[] = 'Sem fim (endtime): o Waze assume 14 dias após o início.';
        } elseif ($start !== null && $end <= $start) {
            $errors[] = 'O fim deve ser posterior ao início.';
        }

        // description (recomendado < 40 caracteres)
        $description = trim((string) $event->getDescription());
        if ($description !== '' && mb_strlen($description) > self::DESCRIPTION_MAX) {
            $warnings[] = sprintf(
                'Descrição com %d caracteres: acima de %d o Waze aceita, mas pode não exibir direito.',
                mb_strlen($description),
                self::DESCRIPTION_MAX,
            );
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    public function isPublishable(PartnerFeedEvent $event): bool
    {
        return $this->validate($event)['errors'] === [];
    }
}
