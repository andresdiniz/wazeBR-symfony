<?php
declare(strict_types=1);
namespace App\Service;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
final class RouteForecastInsights
{
    public const SLOT_MINUTES = 15;
    private readonly \DateTimeZone $tz;
    public function __construct(private readonly Connection $connection, #[Autowire('%env(APP_TIMEZONE)%')] string $displayTimezone = 'America/Sao_Paulo')
    {
        try { $this->tz = new \DateTimeZone($displayTimezone ?: 'America/Sao_Paulo'); } catch (\Throwable) { $this->tz = new \DateTimeZone('America/Sao_Paulo'); }
    }
    public function timezone(): \DateTimeZone { return $this->tz; }
    public function actualSeries(int $routeId, int $hours = 6): array
    {
        $utc = new \DateTimeZone('UTC'); $now = new \DateTimeImmutable('now', $utc);
        $rows = $this->connection->fetchAllAssociative("SELECT CONCAT(DATE_FORMAT(recorded_at, '%Y-%m-%d %H:'), LPAD(FLOOR(MINUTE(recorded_at)/15)*15,2,'0'), ':00') AS slot, AVG(time) AS t, AVG(historic_time) AS h, COUNT(*) AS n FROM waze_tvt_route_snapshot WHERE route_id = :id AND recorded_at >= :since AND recorded_at <= :now AND time >= 0 GROUP BY slot ORDER BY slot", ['id' => $routeId, 'since' => $now->modify('-' . max(1, min(24, $hours)) . ' hours')->format('Y-m-d H:i:s'), 'now' => $now->format('Y-m-d H:i:s')]);
        return array_map(static fn(array $r): array => ['at' => (new \DateTimeImmutable($r['slot'], $utc))->format(DATE_ATOM), 'avgTime' => (float) $r['t'], 'avgHistoricTime' => $r['h'] !== null ? (float) $r['h'] : null, 'samples' => (int) $r['n']], $rows);
    }
    public function pastForecasts(int $routeId, int $hours = 6): array
    {
        return $this->comparisons($routeId, max(1, min(24, $hours)));
    }
    private function comparisons(int $routeId, int $hours): array
    {
        $utc = new \DateTimeZone('UTC'); $now = new \DateTimeImmutable('now', $utc);
        $rows = $this->connection->fetchAllAssociative('SELECT id, issued_at, target_start_at, target_end_at, horizon_minutes, predicted_time, actual_time, error_seconds, within_tolerance, error_explanation, error_cause_type FROM waze_tvt_forecast_audit WHERE route_id = :id AND status = \'evaluated\' AND actual_time IS NOT NULL AND predicted_time IS NOT NULL AND error_seconds IS NOT NULL AND horizon_minutes > 0 AND issued_at < target_start_at AND target_start_at >= :since AND target_end_at <= :now ORDER BY target_start_at DESC, ABS(horizon_minutes - 60) ASC, horizon_minutes ASC, id DESC', ['id' => $routeId, 'since' => $now->modify('-' . $hours . ' hours')->format('Y-m-d H:i:s'), 'now' => $now->format('Y-m-d H:i:s')]);
        $best = [];
        foreach ($rows as $r) {
            $key = $r['target_start_at'] . '/' . $r['target_end_at'];
            if (isset($best[$key])) continue;
            $best[$key] = ['at' => (new \DateTimeImmutable($r['target_start_at'], $utc))->format(DATE_ATOM), 'targetStartAt' => (new \DateTimeImmutable($r['target_start_at'], $utc))->format(DATE_ATOM), 'targetEndAt' => (new \DateTimeImmutable($r['target_end_at'], $utc))->format(DATE_ATOM), 'issuedAt' => (new \DateTimeImmutable($r['issued_at'], $utc))->format(DATE_ATOM), 'predicted' => (float) $r['predicted_time'], 'predictedTime' => (float) $r['predicted_time'], 'actual' => (float) $r['actual_time'], 'actualTime' => (float) $r['actual_time'], 'horizonMinutes' => (float) $r['horizon_minutes'], 'errorSeconds' => (float) $r['predicted_time'] - (float) $r['actual_time'], 'withinTolerance' => $r['within_tolerance'] === null ? null : (bool) $r['within_tolerance'], 'explanation' => $r['error_explanation'], 'causeType' => $r['error_cause_type']];
        }
        return array_values($best);
    }
    public function forecastErrors(int $routeId): array
    {
        return array_slice(array_values(array_filter($this->comparisons($routeId, 168), static fn(array $r): bool => $r['withinTolerance'] === false)), 0, 20);
    }
    public function accuracy(int $routeId, int $days = 7): ?array
    {
        $rows = $this->comparisons($routeId, max(1, min(90, $days)) * 24);
        if ($rows === []) return null;
        $summarize = static function(array $items): array {
            $n = count($items); $sum = 0.; $abs = 0.; $sq = 0.; $hits = 0; $eligible = 0;
            foreach ($items as $r) { $e = $r['errorSeconds']; $sum += $e; $abs += abs($e); $sq += $e*$e; if ($r['withinTolerance'] !== null) { $eligible++; $hits += (int) $r['withinTolerance']; } }
            return ['samples' => $n, 'mae' => $abs/$n, 'rmse' => sqrt($sq/$n), 'bias' => $sum/$n, 'hitRate' => $eligible ? 100*$hits/$eligible : null];
        };
        $bands = [];
        foreach ([[0,30,'ate 30 min'],[30,60,'30-60 min'],[60,180,'1-3 h'],[180,360,'3-6 h']] as [$lo,$hi,$label]) {
            $items = array_values(array_filter($rows, static fn(array $r): bool => $r['horizonMinutes'] > $lo && $r['horizonMinutes'] <= $hi));
            if ($items !== []) $bands[] = ['label' => $label] + $summarize($items);
        }
        return $summarize($rows) + ['days' => $days, 'bands' => $bands, 'selection' => 'one_emission_per_window_nearest_60_minutes'];
    }
    public function congestionDuration(int $routeId, int $days = 30): array
    {
        $utc = new \DateTimeZone('UTC'); $now = new \DateTimeImmutable('now', $utc);
        $rows = $this->connection->fetchAllAssociative('SELECT recorded_at, time, historic_time, jam_level FROM waze_tvt_route_snapshot WHERE route_id = :id AND recorded_at >= :since AND recorded_at <= :now AND time >= 0 ORDER BY recorded_at DESC LIMIT 12000', ['id' => $routeId, 'since' => $now->modify('-' . max(7,min(90,$days)) . ' days')->format('Y-m-d H:i:s'), 'now' => $now->format('Y-m-d H:i:s')]);
        $rows = array_reverse($rows); $durations = []; $start = null; $last = null; $leftCensored = false; $first = true; $latest = null;
        foreach ($rows as $r) {
            $at = new \DateTimeImmutable($r['recorded_at'], $utc); $latest = $at; $h = (float) ($r['historic_time'] ?? 0); $t = (float) $r['time'];
            $slow = (float) ($r['jam_level'] ?? 0) >= 3 || ($h > 0 && $t-$h >= 10 && $t/$h >= 1.15);
            if ($last !== null && $at->getTimestamp()-$last->getTimestamp() > 720) { $start = $last = null; $leftCensored = $slow; }
            if ($slow) { if ($start === null) { $start = $at; $leftCensored = $first || $leftCensored; } $last = $at; }
            elseif ($start !== null) { if (!$leftCensored) $durations[] = max(1, ($at->getTimestamp()-$start->getTimestamp())/60); $start = $last = null; $leftCensored = false; }
            else $leftCensored = false;
            $first = false;
        }
        sort($durations); $n = count($durations); $median = static function(array $a): ?int { $n = count($a); return $n ? (int) round(($a[intdiv($n-1,2)]+$a[intdiv($n,2)])/2) : null; };
        $active = $start !== null && $latest !== null && $now->getTimestamp()-$latest->getTimestamp() <= 900;
        $elapsed = $active ? max(0,(int) floor(($now->getTimestamp()-$start->getTimestamp())/60)) : null;
        $remaining = $active && !$leftCensored ? array_values(array_map(static fn(float $d): float => $d-$elapsed, array_filter($durations, static fn(float $d): bool => $d > $elapsed))) : [];
        return ['active' => $active, 'startedAt' => $active ? $start->setTimezone($this->tz)->format(DATE_ATOM) : null, 'elapsedMinutes' => $elapsed, 'typicalDurationMinutes' => $n >= 4 ? $median($durations) : null, 'expectedRemainingMinutes' => count($remaining) >= 4 ? $median($remaining) : null, 'episodes' => $n, 'confidence' => $n >= 10 ? 'moderada' : 'baixa', 'stale' => $latest === null || $now->getTimestamp()-$latest->getTimestamp() > 900, 'truncated' => count($rows) >= 12000, 'note' => 'Estimativa descritiva; exclui episodios cortados por lacunas. Nao e garantia de termino.'];
    }
}
