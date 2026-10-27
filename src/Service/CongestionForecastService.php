<?php
declare(strict_types=1);
namespace App\Service;
use App\Entity\Partner;
use App\Entity\WazeTvtRoute;
use App\Entity\WazeTvtRouteForecast;
use App\Repository\WazeTvtRouteForecastRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class CongestionForecastService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly EntityManagerInterface $entityManager,
        private readonly WazeTvtRouteForecastRepository $forecastRepository,
        private readonly ParameterBagInterface $params,
    ) {}

    public function refreshUpcoming(?Partner $partner = null, int $slotMinutes = 15, int $slotsAhead = 24, int $historyDays = 30, int $minimumSamples = 1): int
    {
        ForecastHistoryWindows::validateSlot($slotMinutes);
        if ($slotsAhead < 1 || $slotsAhead > 288 || $historyDays < 1 || $historyDays > 366 || $minimumSamples < 1) {
            throw new \InvalidArgumentException('Parametros de previsao fora dos limites permitidos.');
        }
        $timezone = new \DateTimeZone($this->appTimezone());
        $asOf = new \DateTimeImmutable('now', $timezone);
        $first = $asOf->setTime((int) $asOf->format('G'), intdiv((int) $asOf->format('i'), $slotMinutes) * $slotMinutes, 0)->modify('+' . $slotMinutes . ' minutes');
        $currentConditions = $this->loadRecentConditions($partner, $asOf);
        $written = 0;
        for ($i = 0; $i < $slotsAhead; ++$i) {
            $at = $first->modify('+' . ($i * $slotMinutes) . ' minutes');
            $written += $this->refreshSlot($partner, $at, $slotMinutes, $historyDays, $minimumSamples, $currentConditions, $asOf);
        }
        return $written;
    }

    public function refreshSlot(?Partner $partner, \DateTimeImmutable $forecastAt, int $slotMinutes = 15, int $historyDays = 30, int $minimumSamples = 1, array $currentConditions = [], ?\DateTimeImmutable $asOf = null): int
    {
        $timezone = new \DateTimeZone($this->appTimezone());
        $asOf ??= new \DateTimeImmutable('now', $timezone);
        $forecastAt = $forecastAt->setTimezone($timezone);
        if ($minimumSamples < 1) throw new \InvalidArgumentException('Minimum samples must be positive.');
        $windows = ForecastHistoryWindows::build($forecastAt, $slotMinutes, $historyDays, $asOf, $timezone);
        if ($windows === []) return 0;
        $params = []; $ranges = []; $partnerFilter = '';
        foreach ($windows as $i => $window) {
            $ranges[] = '(s.recorded_at >= :start_' . $i . ' AND s.recorded_at < :end_' . $i . ')';
            $params['start_' . $i] = $window['start'];
            $params['end_' . $i] = $window['end'];
        }
        if ($partner !== null) { $partnerFilter = ' AND r.partner_id = :partner_id'; $params['partner_id'] = $partner->getId(); }
        $rows = $this->connection->fetchAllAssociative(
            'SELECT r.id AS route_id, AVG(s.time) AS avg_time, AVG(s.historic_time) AS avg_historic_time, AVG(s.jam_level) AS avg_jam_level, COUNT(*) AS samples
             FROM waze_tvt_route r
             INNER JOIN waze_tvt_route_snapshot s ON s.route_id = r.id AND s.partner_id = r.partner_id
             WHERE r.is_active = 1' . $partnerFilter . '
               AND s.time IS NOT NULL AND s.time >= 0
               AND (' . implode(' OR ', $ranges) . ')
             GROUP BY r.id',
            $params,
        );
        foreach ($rows as $row) {
            $route = $this->entityManager->getReference(WazeTvtRoute::class, (int) $row['route_id']);
            $forecast = $this->forecastRepository->findOneBy(['route' => $route, 'forecastAt' => $forecastAt]) ?? new WazeTvtRouteForecast();
            $avgTime = $row['avg_time'] !== null ? (float) $row['avg_time'] : null;
            $avgHistoricTime = $row['avg_historic_time'] !== null ? (float) $row['avg_historic_time'] : null;
            $avgJamLevel = $row['avg_jam_level'] !== null ? (float) $row['avg_jam_level'] : null;
            $samples = (int) $row['samples'];
            $condition = $currentConditions[(int) $row['route_id']] ?? null;
            if ($condition !== null && $avgTime !== null && $avgHistoricTime !== null && $avgHistoricTime > 0) {
                $minutesAhead = max(0, ($forecastAt->getTimestamp() - $asOf->getTimestamp()) / 60);
                $weight = 0.55 * exp(-$minutesAhead / 75);
                $ratio = 1 + max(-0.35, min(2.0, $condition['ratio']));
                $trend = max(-0.5, min(0.5, $condition['trend']));
                $liveTime = $avgHistoricTime * max(0.65, $ratio + $trend * min(1.0, $minutesAhead / 30));
                $avgTime = $avgTime * (1 - $weight) + $liveTime * $weight;
                if ($condition['jamLevel'] !== null) $avgJamLevel = ($avgJamLevel ?? $condition['jamLevel']) * (1 - $weight) + $condition['jamLevel'] * $weight;
            }
            [$score, $level] = $this->calculateRisk($avgTime, $avgHistoricTime, $avgJamLevel, $samples, $minimumSamples);
            $forecast->setRoute($route);
            $forecast->setForecastAt($forecastAt);
            $forecast->setSlotMinutes($slotMinutes);
            $forecast->setAvgTime($avgTime);
            $forecast->setAvgHistoricTime($avgHistoricTime);
            $forecast->setAvgJamLevel($avgJamLevel);
            $forecast->setSamples($samples);
            $forecast->setRiskScore($score);
            $forecast->setRiskLevel($level);
            $forecast->setUpdatedAt(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
            $this->entityManager->persist($forecast);
        }
        $this->entityManager->flush();
        return count($rows);
    }

    public function getUpcomingForWallboard(?Partner $partner, int $slotMinutes = 30, int $slotsAhead = 2): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone($this->appTimezone()));
        $to = $now->modify(sprintf('+%d minutes', $slotMinutes * $slotsAhead));
        $rows = $this->connection->fetchAllAssociative(
            'SELECT f.route_id, f.forecast_at, f.slot_minutes, f.avg_time, f.avg_historic_time, f.avg_jam_level, f.samples, f.risk_score, f.risk_level
             FROM waze_tvt_route_forecast f INNER JOIN waze_tvt_route r ON r.id = f.route_id
             WHERE r.is_active = 1 AND (:partner_id IS NULL OR r.partner_id = :partner_id)
               AND f.slot_minutes = :slot AND f.forecast_at >= :from AND f.forecast_at < :to
             ORDER BY f.forecast_at ASC, f.risk_score DESC',
            ['partner_id' => $partner?->getId(), 'slot' => $slotMinutes, 'from' => $now->format('Y-m-d H:i:s'), 'to' => $to->format('Y-m-d H:i:s')],
        );
        $byRoute = [];
        foreach ($rows as $row) {
            $routeId = (int) $row['route_id'];
            if (isset($byRoute[$routeId])) continue;
            $byRoute[$routeId] = [
                'forecastAt' => $row['forecast_at'], 'slotMinutes' => (int) $row['slot_minutes'],
                'avgTime' => $row['avg_time'] !== null ? round((float) $row['avg_time'], 1) : null,
                'avgHistoricTime' => $row['avg_historic_time'] !== null ? round((float) $row['avg_historic_time'], 1) : null,
                'avgJamLevel' => $row['avg_jam_level'] !== null ? round((float) $row['avg_jam_level'], 2) : null,
                'samples' => (int) $row['samples'], 'riskScore' => round((float) $row['risk_score'], 1), 'riskLevel' => (string) $row['risk_level'],
            ];
        }
        return ['slotMinutes' => $slotMinutes, 'routes' => $byRoute];
    }

    private function loadRecentConditions(?Partner $partner, \DateTimeImmutable $asOf): array
    {
        $utc = new \DateTimeZone('UTC'); $asOf = $asOf->setTimezone($utc);
        $params = ['since' => $asOf->modify('-25 minutes')->format('Y-m-d H:i:s'), 'as_of' => $asOf->format('Y-m-d H:i:s')];
        $partnerFilter = '';
        if ($partner !== null) { $partnerFilter = ' AND r.partner_id = :partner_id'; $params['partner_id'] = $partner->getId(); }
        $rows = $this->connection->fetchAllAssociative(
            'SELECT s.route_id, s.time, s.historic_time, s.jam_level FROM waze_tvt_route_snapshot s
             INNER JOIN waze_tvt_route r ON r.id = s.route_id AND r.partner_id = s.partner_id
             WHERE r.is_active = 1' . $partnerFilter . '
               AND s.recorded_at >= :since AND s.recorded_at <= :as_of
               AND s.time IS NOT NULL AND s.time >= 0 AND s.historic_time > 0
             ORDER BY s.route_id ASC, s.recorded_at DESC, s.id DESC', $params,
        );
        $recent = [];
        foreach ($rows as $row) {
            $id = (int) $row['route_id'];
            if (count($recent[$id] ?? []) >= 2) continue;
            $recent[$id][] = ['ratio' => ((float) $row['time'] / (float) $row['historic_time']) - 1, 'jamLevel' => $row['jam_level'] !== null ? (float) $row['jam_level'] : null];
        }
        $out = [];
        foreach ($recent as $id => $points) $out[$id] = ['ratio' => $points[0]['ratio'], 'trend' => $points[0]['ratio'] - ($points[1]['ratio'] ?? $points[0]['ratio']), 'jamLevel' => $points[0]['jamLevel']];
        return $out;
    }

    private function calculateRisk(?float $avgTime, ?float $avgHistoricTime, ?float $avgJamLevel, int $samples, int $minimumSamples): array
    {
        if ($samples < $minimumSamples) return [0.0, WazeTvtRouteForecast::LEVEL_LOW];
        $slowness = $avgTime !== null && $avgHistoricTime !== null && $avgHistoricTime > 0 ? (($avgTime - $avgHistoricTime) / $avgHistoricTime) * 100 : 0.0;
        $score = min(100.0, max(0.0, $slowness) * 1.5 + max(0.0, ($avgJamLevel ?? 0.0) - 1.0) * 15.0);
        $level = match (true) {
            $score >= 75.0 => WazeTvtRouteForecast::LEVEL_CRITICAL,
            $score >= 55.0 => WazeTvtRouteForecast::LEVEL_HIGH,
            $score >= 30.0 => WazeTvtRouteForecast::LEVEL_MEDIUM,
            default => WazeTvtRouteForecast::LEVEL_LOW,
        };
        return [$score, $level];
    }

    private function appTimezone(): string
    {
        try { return (string) $this->params->get('app_timezone'); } catch (\Throwable) { return 'America/Sao_Paulo'; }
    }
}
