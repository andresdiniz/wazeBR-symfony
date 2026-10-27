<?php
declare(strict_types=1);
namespace App\Controller;
use App\Service\PartnerScopeResolver;
use App\Service\RouteForecastInsights;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
#[Route('/routes/{id}/forecast.json', name: 'route_forecast_')]
final class RouteForecastController extends AbstractController
{
    public function __construct(private readonly Connection $connection, private readonly RouteForecastInsights $insights, private readonly PartnerScopeResolver $scope) {}
    #[Route('', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY'); $partner = $this->scope->resolve();
        $params = ['id' => $id]; $sql = 'SELECT id FROM waze_tvt_route WHERE id = :id AND is_active = 1';
        if ($partner !== null) { $sql .= ' AND partner_id = :pid'; $params['pid'] = $partner->getId(); }
        if ($this->connection->fetchOne($sql, $params) === false) throw $this->createNotFoundException('Rota nao encontrada.');
        $tz = $this->insights->timezone(); $utc = new \DateTimeZone('UTC'); $now = new \DateTimeImmutable('now', $tz);
        $first = $now->setTime((int)$now->format('G'), intdiv((int)$now->format('i'),15)*15,0)->modify('+15 minutes');
        $rows = $this->connection->fetchAllAssociative('SELECT forecast_at, id, avg_time, avg_historic_time, avg_jam_level, samples, risk_score, risk_level, updated_at FROM waze_tvt_route_forecast WHERE route_id = :id AND slot_minutes = 15 AND forecast_at >= :start AND forecast_at < :end ORDER BY forecast_at', ['id' => $id, 'start' => $first->format('Y-m-d H:i:s'), 'end' => $first->modify('+6 hours')->format('Y-m-d H:i:s')]);
        $bySlot = []; foreach ($rows as $r) $bySlot[$r['forecast_at']] = $r;
        $items = []; $available = 0;
        for ($i=0; $i<24; $i++) {
            $at = $first->modify('+' . ($i*15) . ' minutes'); $r = $bySlot[$at->format('Y-m-d H:i:s')] ?? null;
            $updated = !empty($r['updated_at']) ? new \DateTimeImmutable($r['updated_at'], $utc) : null;
            $age = $updated ? max(0,$now->getTimestamp()-$updated->getTimestamp()) : null;
            $valid = $r !== null && (int)$r['samples'] > 0 && $r['avg_time'] !== null && (float)$r['avg_time'] >= 0 && (float)($r['avg_historic_time'] ?? 0) > 0 && $age !== null && $age <= 1800;
            if ($valid) $available++;
            $items[] = ['forecastId' => $r ? (int)$r['id'] : null, 'forecastAt' => $at->format(DATE_ATOM), 'forecastEndAt' => $at->modify('+15 minutes')->format(DATE_ATOM), 'hour' => (int)$at->format('G'), 'minute' => (int)$at->format('i'), 'slotMinutes' => 15, 'hasData' => $valid, 'avgTime' => $valid ? (float)$r['avg_time'] : null, 'avgHistoricTime' => $valid ? (float)$r['avg_historic_time'] : null, 'avgJamLevel' => $valid && $r['avg_jam_level'] !== null ? (float)$r['avg_jam_level'] : null, 'samples' => (int)($r['samples'] ?? 0), 'riskScore' => $valid ? (float)$r['risk_score'] : null, 'riskLevel' => $valid ? $r['risk_level'] : 'unknown', 'updatedAt' => $updated?->setTimezone($tz)->format(DATE_ATOM), 'ageSeconds' => $age, 'stale' => $age === null || $age > 1800, 'quality' => !$valid ? 'insuficiente_ou_desatualizada' : ((int)$r['samples'] >= 12 ? 'mais_amostras_nao_garante_precisao' : 'poucas_amostras')];
        }
        $signals = $this->connection->fetchAllAssociative('SELECT i.type, i.subtype, i.street, i.city, i.last_seen_at FROM waze_tvt_irregularity i INNER JOIN waze_tvt_route r ON r.id = i.route_id AND r.partner_id = i.partner_id WHERE i.route_id = :id AND i.is_active = 1 AND i.last_seen_at >= :since ORDER BY i.last_seen_at DESC LIMIT 8', ['id' => $id, 'since' => $now->setTimezone($utc)->modify('-30 minutes')->format('Y-m-d H:i:s')]);
        $response = $this->json(['ok' => true, 'routeId' => $id, 'source' => 'persisted_forecasts', 'generatedAt' => $now->format(DATE_ATOM), 'timezone' => $tz->getName(), 'slotMinutes' => 15, 'slotsAhead' => 24, 'horizonMinutes' => 360, 'firstForecastAt' => $first->format(DATE_ATOM), 'lastForecastEndAt' => $first->modify('+6 hours')->format(DATE_ATOM), 'availableSlots' => $available, 'missingSlots' => 24-$available, 'items' => $items, 'actual' => $this->insights->actualSeries($id), 'pastForecasts' => $this->insights->pastForecasts($id), 'accuracy' => $this->insights->accuracy($id), 'forecastErrors' => $this->insights->forecastErrors($id), 'congestionDuration' => $this->insights->congestionDuration($id), 'nearbySignals' => array_map(static fn(array $s): array => ['type' => $s['type'], 'subtype' => $s['subtype'], 'street' => $s['street'], 'city' => $s['city'], 'distanceMeters' => null, 'collectedAt' => (new \DateTimeImmutable($s['last_seen_at'], $utc))->format(DATE_ATOM)], $signals), 'contextSource' => 'TVT route irregularities; not all nearby Waze alerts']);
        $response->headers->set('Cache-Control', 'private, no-store'); return $response;
    }
}
