<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Partner;
use App\Service\PartnerScopeResolver;
use App\Service\RouteForecastInsights;
use App\Repository\RouteDetailRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/routes/{id}/forecast.json', name: 'route_forecast_')]
final class RouteForecastController extends AbstractController
{
    private const SLOT_MINUTES = 15;
    private const SLOTS_AHEAD = 24;

    public function __construct(
        private readonly Connection $connection,
        private readonly RouteForecastInsights $insights,
        private readonly RouteDetailRepository $routeDetails,
        private readonly PartnerScopeResolver $scope,
    ) {
    }

    #[Route(
        '',
        name: 'show',
        requirements: ['id' => '\\d+'],
        methods: ['GET'],
    )]
    public function show(int $id): JsonResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $partner = $this->resolvePartnerScope();

        if (!$this->routeExists($id, $partner)) {
            throw $this->createNotFoundException('Rota não encontrada.');
        }

        $timezone = $this->insights->timezone();
        $utc = new \DateTimeZone('UTC');

        $now = new \DateTimeImmutable('now', $timezone);

        // Próximo quarto de hora, sempre estritamente futuro.
        $currentSlot = $now->setTime(
            (int) $now->format('G'),
            intdiv(
                (int) $now->format('i'),
                self::SLOT_MINUTES,
            ) * self::SLOT_MINUTES,
            0,
        );

        $firstSlot = $currentSlot->modify(
            '+' . self::SLOT_MINUTES . ' minutes'
        );

        $lastEnd = $firstSlot->modify(
            '+' . (self::SLOT_MINUTES * self::SLOTS_AHEAD) . ' minutes'
        );

        $rows = $this->connection->fetchAllAssociative(
            'SELECT
                id,
                forecast_at,
                slot_minutes,
                avg_time,
                avg_historic_time,
                avg_jam_level,
                samples,
                risk_score,
                risk_level,
                updated_at
             FROM waze_tvt_route_forecast
             WHERE route_id = :route_id
               AND slot_minutes = :slot_minutes
               AND forecast_at >= :start_at
               AND forecast_at < :end_at
             ORDER BY forecast_at ASC',
            [
                'route_id' => $id,
                'slot_minutes' => self::SLOT_MINUTES,
                'start_at' => $firstSlot->format('Y-m-d H:i:s'),
                'end_at' => $lastEnd->format('Y-m-d H:i:s'),
            ],
        );

        $bySlot = [];

        foreach ($rows as $row) {
            $bySlot[(string) $row['forecast_at']] = $row;
        }

        $items = [];
        $available = 0;

        for ($i = 0; $i < self::SLOTS_AHEAD; ++$i) {
            $at = $firstSlot->modify(
                '+' . ($i * self::SLOT_MINUTES) . ' minutes'
            );

            $endAt = $at->modify(
                '+' . self::SLOT_MINUTES . ' minutes'
            );

            $row = $bySlot[$at->format('Y-m-d H:i:s')] ?? null;

            $time = $this->nullableFloat($row['avg_time'] ?? null);
            $historic = $this->nullableFloat(
                $row['avg_historic_time'] ?? null
            );
            $jam = $this->nullableFloat(
                $row['avg_jam_level'] ?? null
            );

            $samples = (int) ($row['samples'] ?? 0);

            $hasData = $row !== null
                && $samples > 0
                && $time !== null
                && $time >= 0
                && $historic !== null
                && $historic > 0;

            $updatedAt = null;

            if ($row !== null && !empty($row['updated_at'])) {
                $updatedAt = (
                    new \DateTimeImmutable(
                        (string) $row['updated_at'],
                        $utc,
                    )
                )->setTimezone($timezone);
            }

            if ($hasData) {
                ++$available;
            }

            $items[] = [
                'forecastId' => $row !== null
                    ? (int) $row['id']
                    : null,

                'forecastAt' => $at->format(DATE_ATOM),
                'forecastEndAt' => $endAt->format(DATE_ATOM),

                'hour' => (int) $at->format('G'),
                'minute' => (int) $at->format('i'),
                'slotMinutes' => self::SLOT_MINUTES,

                'hasData' => $hasData,

                'avgTime' => $hasData
                    ? round($time, 2)
                    : null,

                'avgHistoricTime' => $hasData
                    ? round($historic, 2)
                    : null,

                'avgJamLevel' => $hasData && $jam !== null
                    ? round($jam, 2)
                    : null,

                'samples' => $samples,

                'riskScore' => $hasData
                    ? round((float) $row['risk_score'], 2)
                    : null,

                'riskLevel' => $hasData
                    ? (string) $row['risk_level']
                    : 'unknown',

                'updatedAt' => $updatedAt?->format(DATE_ATOM),
            ];
        }

        $detail = $this->routeDetails->getRouteDetail($partner, $id);
        $nearbySignals = array_slice(array_values(array_filter($detail['nearbyAlerts'] ?? [], static fn (array $alert): bool => ($alert['isRecent'] ?? false) === true)), 0, 8);

        // Erros de previsão recentes com explicação de causa
        $forecastErrors = $this->connection->fetchAllAssociative(
            "SELECT target_start_at, target_end_at, predicted_time, actual_time,\n                    error_seconds, within_tolerance, error_explanation, error_cause_type\n             FROM waze_tvt_forecast_audit\n             WHERE route_id = :route_id\n               AND status = 'evaluated'\n               AND error_seconds IS NOT NULL\n               AND within_tolerance = 0\n               AND target_start_at >= :since\n             ORDER BY target_start_at DESC\n             LIMIT 20",
            ['route_id' => $id, 'since' => (new \DateTimeImmutable('-7 days', $utc))->format('Y-m-d H:i:s')],
        );

        $forecastErrors = array_map(static fn (array $r): array => [
            'targetStartAt' => (new \DateTimeImmutable((string) $r['target_start_at'], $utc))->format(DATE_ATOM),
            'targetEndAt' => (new \DateTimeImmutable((string) $r['target_end_at'], $utc))->format(DATE_ATOM),
            'predictedTime' => round((float) $r['predicted_time'], 1),
            'actualTime' => $r['actual_time'] !== null ? round((float) $r['actual_time'], 1) : null,
            'errorSeconds' => round((float) $r['error_seconds'], 1),
            'withinTolerance' => (bool) $r['within_tolerance'],
            'explanation' => $r['error_explanation'],
            'causeType' => $r['error_cause_type'],
        ], $forecastErrors);

        $response = $this->json([
            'ok' => true,
            'routeId' => $id,
            'source' => 'persisted_forecasts',

            // Horário da resposta; não é horário de emissão do modelo.
            'generatedAt' => $now->format(DATE_ATOM),

            'timezone' => $timezone->getName(),
            'slotMinutes' => self::SLOT_MINUTES,
            'slotsAhead' => self::SLOTS_AHEAD,
            'horizonMinutes' => (
                self::SLOT_MINUTES * self::SLOTS_AHEAD
            ),

            'firstForecastAt' => $firstSlot->format(DATE_ATOM),
            'lastForecastEndAt' => $lastEnd->format(DATE_ATOM),

            'availableSlots' => $available,
            'missingSlots' => self::SLOTS_AHEAD - $available,

            'items' => $items,

            // ── Previsão ao lado do real ─────────────────────────────────
            // `actual`: tempo medido nas últimas 6 h (fatias de 15 min, ISO com offset).
            // `pastForecasts`: o que o modelo previu (~1 h antes) para essas mesmas fatias.
            // `accuracy`: erro das previsões já avaliadas (null quando ainda não há).
            'actual' => $this->insights->actualSeries($id, 6),
            'pastForecasts' => $this->insights->pastForecasts($id, 6),
            'accuracy' => $this->insights->accuracy($id, 7),
            'congestionDuration' => $this->insights->congestionDuration($id, 30),

            // ── Erros de previsão com explicação ────────────────────────
            'forecastErrors' => $forecastErrors,

            'nearbySignals' => array_map(static fn (array $alert): array => [
                'type' => $alert['typeLabel'] ?? $alert['type'] ?? 'Alerta',
                'subtype' => $alert['subtype'] ?? null,
                'street' => $alert['street'] ?? null,
                'city' => $alert['city'] ?? null,
                'distanceMeters' => $alert['distanceMeters'] ?? null,
                'collectedAt' => $alert['collectedAt'] ?? null,
                'confidence' => $alert['confidence'] ?? null,
                'reliability' => $alert['reliability'] ?? null,
            ], $nearbySignals),
        ]);

        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null || !is_numeric($value)) {
            return null;
        }

        $number = (float) $value;

        return is_finite($number) ? $number : null;
    }

    private function routeExists(int $id, ?Partner $partner): bool
    {
        $sql = 'SELECT id
                FROM waze_tvt_route
                WHERE id = :id
                  AND is_active = 1';

        $parameters = ['id' => $id];

        if ($partner !== null) {
            $sql .= ' AND partner_id = :partner_id';
            $parameters['partner_id'] = $partner->getId();
        }

        return $this->connection->fetchOne(
            $sql . ' LIMIT 1',
            $parameters,
        ) !== false;
    }

    private function resolvePartnerScope(): ?Partner
    {
        return $this->scope->resolve();
    }
}
