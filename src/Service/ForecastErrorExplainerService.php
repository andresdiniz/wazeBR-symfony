<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

final class ForecastErrorExplainerService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Dado um erro de previsão, busca irregularidades Waze e clima no período
     * e retorna causa + explicação textual.
     *
     * @return array{cause: ?string, explanation: ?string}
     */
    public function explain(int $routeId, \DateTimeImmutable $start, \DateTimeImmutable $end, float $errorSeconds): array
    {
        $cause = null;
        $explanation = null;

        try {
            $irregularity = $this->connection->fetchAssociative(
                "SELECT type, subtype, severity, description, street\n                 FROM waze_tvt_irregularity\n                 WHERE route_id = :route_id\n                   AND is_active = 1\n                   AND recorded_at <= :end\n                   AND (last_seen_at IS NULL OR last_seen_at >= :start)\n                 ORDER BY recorded_at DESC\n                 LIMIT 1",
                ['route_id' => $routeId, 'start' => $start->format('Y-m-d H:i:s'), 'end' => $end->format('Y-m-d H:i:s')],
            );

            $weather = $this->connection->fetchAssociative(
                "SELECT o.precipitation, o.weather_code, o.wind_speed\n                 FROM weather_observation o\n                 INNER JOIN weather_location l ON l.id = o.weather_location_id\n                 WHERE l.partner_id = (SELECT partner_id FROM waze_tvt_route WHERE id = :route_id)\n                   AND o.observed_at BETWEEN :start AND :end\n                 ORDER BY o.observed_at DESC\n                 LIMIT 1",
                ['route_id' => $routeId, 'start' => $start->format('Y-m-d H:i:s'), 'end' => $end->format('Y-m-d H:i:s')],
            );

            $parts = [];

            if ($irregularity !== false) {
                $type = $irregularity['type'] ?? 'irregularidade';
                $subtype = $irregularity['subtype'] ?? '';
                $street = $irregularity['street'] ?? '';
                $desc = trim((string) ($irregularity['description'] ?? ''));

                $cause = 'alerta_waze';
                $parts[] = sprintf('Alerta Waze do tipo %s%s%s detectado na rota', $type, $subtype !== '' ? ' (' . $subtype . ')' : '', $street !== '' ? ' em ' . $street : '');

                if ($desc !== '') {
                    $parts[] = 'Descrição: ' . $desc;
                }
            }

            if ($weather !== false) {
                $precip = $weather['precipitation'] !== null ? (float) $weather['precipitation'] : 0.0;
                $wind = $weather['wind_speed'] !== null ? (float) $weather['wind_speed'] : 0.0;

                if ($precip > 2.0) {
                    $cause = $cause ?? 'clima';
                    $parts[] = sprintf('Chuva registrada de %.1f mm no período', $precip);
                }

                if ($wind > 30.0) {
                    $cause = $cause ?? 'clima';
                    $parts[] = sprintf('Vento forte de %.0f km/h no período', $wind);
                }
            }

            if ($parts === []) {
                $cause = 'sem_causa_identificada';
                $explanation = sprintf('Erro de %.0f segundos sem alerta Waze ou condição climática relevante identificada na janela.', abs($errorSeconds));
            } else {
                $direction = $errorSeconds > 0 ? 'subestimou' : 'superestimou';
                $explanation = sprintf('Previsão %s o tempo em %.0f segundos. %s.', $direction, abs($errorSeconds), implode('. ', $parts));
            }
        } catch (\Throwable $e) {
            $this->logger->warning('ForecastErrorExplainerService: falha ao explicar erro', ['route' => $routeId, 'error' => $e->getMessage()]);
            $cause = 'indisponivel';
            $explanation = null;
        }

        return ['cause' => $cause, 'explanation' => $explanation];
    }
}
