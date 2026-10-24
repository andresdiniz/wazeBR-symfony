<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Dados que completam o gráfico de previsão da página /routes/{id}:
 *
 *   - actualSeries()   o que de fato aconteceu (tempo médio por fatia de 15 min);
 *   - pastForecasts()  o que o modelo previu (≈ 1 h antes) para essas mesmas fatias;
 *   - accuracy()       erro das previsões já avaliadas (waze_tvt_forecast_audit).
 *
 * Todas as datas saem em ISO-8601 com offset. As tabelas de snapshot e de auditoria
 * guardam UTC; `waze_tvt_route_forecast.forecast_at` guarda horário local (tratado
 * no controller).
 */
final class RouteForecastInsights
{
    public const SLOT_MINUTES = 15;

    /** Horizonte de previsão considerado "a previsão de 1 hora antes". */
    private const COMPARE_HORIZON_MINUTES = 60;

    private const HORIZON_BANDS = [
        'h30'  => 'até 30 min',
        'h60'  => '30–60 min',
        'h180' => '1–3 h',
        'h360' => '3–6 h',
    ];

    private readonly \DateTimeZone $tz;

    public function __construct(
        private readonly Connection $connection,
        #[Autowire('%env(APP_TIMEZONE)%')]
        string $displayTimezone = 'America/Sao_Paulo',
    ) {
        try {
            $this->tz = new \DateTimeZone($displayTimezone !== '' ? $displayTimezone : 'America/Sao_Paulo');
        } catch (\Throwable) {
            $this->tz = new \DateTimeZone('America/Sao_Paulo');
        }
    }

    public function timezone(): \DateTimeZone
    {
        return $this->tz;
    }

    /**
     * Tempo médio medido por fatia de 15 min nas últimas $hours horas.
     *
     * @return list<array{at:string,avgTime:float,avgHistoricTime:?float,samples:int}>
     */
    public function actualSeries(int $routeId, int $hours = 6): array
    {
        $since = (new \DateTimeImmutable("-{$hours} hours", new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $rows = $this->connection->executeQuery(
            "SELECT
                CONCAT(DATE_FORMAT(s.recorded_at, '%Y-%m-%d %H:'),
                       LPAD(FLOOR(MINUTE(s.recorded_at) / 15) * 15, 2, '0'), ':00') AS slot,
                AVG(s.time)          AS avg_time,
                AVG(s.historic_time) AS avg_historic,
                COUNT(*)             AS samples
             FROM waze_tvt_route_snapshot s
             WHERE s.route_id = :id
               AND s.recorded_at >= :since
               AND s.time IS NOT NULL
             GROUP BY slot
             ORDER BY slot ASC
             LIMIT 200",
            ['id' => $routeId, 'since' => $since]
        )->fetchAllAssociative();

        $utc = new \DateTimeZone('UTC');

        return array_map(fn (array $r): array => [
            'at'              => (new \DateTimeImmutable((string) $r['slot'], $utc))->format(DATE_ATOM),
            'avgTime'         => round((float) $r['avg_time'], 1),
            'avgHistoricTime' => $r['avg_historic'] !== null ? round((float) $r['avg_historic'], 1) : null,
            'samples'         => (int) $r['samples'],
        ], $rows);
    }

    /**
     * Para cada fatia das últimas $hours horas, a previsão emitida ≈ 1 h antes
     * (a de horizonte mais próximo de 60 min) ao lado do valor medido.
     *
     * @return list<array{at:string,predicted:float,actual:?float,horizonMinutes:float}>
     */
    public function pastForecasts(int $routeId, int $hours = 6): array
    {
        $utc   = new \DateTimeZone('UTC');
        $now   = new \DateTimeImmutable('now', $utc);
        $since = $now->modify("-{$hours} hours")->format('Y-m-d H:i:s');

        $rows = $this->connection->executeQuery(
            'SELECT target_start_at, horizon_minutes, predicted_time, actual_time
             FROM waze_tvt_forecast_audit
             WHERE route_id = :id
               AND target_start_at >= :since
               AND target_start_at <= :now
             ORDER BY target_start_at ASC
             LIMIT 600',
            ['id' => $routeId, 'since' => $since, 'now' => $now->format('Y-m-d H:i:s')]
        )->fetchAllAssociative();

        $best = [];
        foreach ($rows as $r) {
            $key  = (string) $r['target_start_at'];
            $dist = abs((float) $r['horizon_minutes'] - self::COMPARE_HORIZON_MINUTES);

            if (!isset($best[$key]) || $dist < $best[$key]['dist']) {
                $best[$key] = ['dist' => $dist, 'row' => $r];
            }
        }

        $out = [];
        foreach ($best as $key => $entry) {
            $r = $entry['row'];
            $out[] = [
                'at'             => (new \DateTimeImmutable($key, $utc))->format(DATE_ATOM),
                'predicted'      => round((float) $r['predicted_time'], 1),
                'actual'         => $r['actual_time'] !== null ? round((float) $r['actual_time'], 1) : null,
                'horizonMinutes' => round((float) $r['horizon_minutes'], 0),
            ];
        }

        return $out;
    }

    /** Estima duração de episódios históricos de lentidão a partir dos snapshots. */
    public function congestionDuration(int $routeId, int $days = 30): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $since = $now->modify('-' . max(7, $days) . ' days')->format('Y-m-d H:i:s');
        $rows = $this->connection->executeQuery(
            'SELECT recorded_at, time, historic_time, jam_level
             FROM waze_tvt_route_snapshot
             WHERE route_id = :id AND recorded_at >= :since AND time IS NOT NULL
             ORDER BY recorded_at DESC LIMIT 12000',
            ['id' => $routeId, 'since' => $since],
        )->fetchAllAssociative();

        $episodes = [];
        $start = null;
        $last = null;
        $latestCongested = false;
        foreach ($rows as $row) {
            $at = new \DateTimeImmutable((string) $row['recorded_at'], new \DateTimeZone('UTC'));
            $historic = (float) ($row['historic_time'] ?? 0);
            $slow = (float) $row['time'] >= 60 && (($row['jam_level'] !== null && (float) $row['jam_level'] >= 3)
                || ($historic > 0 && (float) $row['time'] / $historic >= 1.15));
            if ($slow && ($last === null || $at->getTimestamp() - $last->getTimestamp() <= 12 * 60)) {
                $start ??= $at;
                $last = $at;
            } elseif ($slow) {
                if ($start !== null && $last !== null) $episodes[] = max(1, (int) round(($last->getTimestamp() - $start->getTimestamp()) / 60) + 5);
                $start = $at;
                $last = $at;
            } elseif ($start !== null && $last !== null) {
                $episodes[] = max(1, (int) round(($last->getTimestamp() - $start->getTimestamp()) / 60) + 5);
                $start = $last = null;
            }
            $latestCongested = $slow;
        }
        $active = $start !== null && $last !== null && $latestCongested && $now->getTimestamp() - $last->getTimestamp() <= 15 * 60;
        $activeElapsed = $active ? max(0, (int) round(($now->getTimestamp() - $start->getTimestamp()) / 60)) : null;
        $sorted = $episodes;
        sort($sorted);
        $typical = null;
        if ($sorted !== []) {
            $mid = intdiv(count($sorted), 2);
            $typical = count($sorted) % 2 ? $sorted[$mid] : (int) round(($sorted[$mid - 1] + $sorted[$mid]) / 2);
        }
        return [
            'active' => $active,
            'startedAt' => $active ? $start->setTimezone($this->tz)->format(DATE_ATOM) : null,
            'elapsedMinutes' => $activeElapsed,
            'typicalDurationMinutes' => $typical,
            'expectedRemainingMinutes' => $active && $typical !== null ? max(0, $typical - $activeElapsed) : null,
            'episodes' => count($episodes),
            'confidence' => count($episodes) >= 10 ? 'alta' : (count($episodes) >= 4 ? 'moderada' : 'baixa'),
            'note' => 'Estimativa estatística baseada no histórico; duração real pode variar.',
        ];
    }
    /**
     * Qualidade das previsões já avaliadas da rota.
     *
     * @return array{days:int,samples:int,mae:float,bias:float,hitRate:?float,bands:list<array<string,mixed>>}|null
     */    public function accuracy(int $routeId, int $days = 7): ?array
    {
        $since = (new \DateTimeImmutable("-{$days} days", new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $rows = $this->connection->executeQuery(
            "SELECT
                CASE WHEN horizon_minutes <= 30  THEN 'h30'
                     WHEN horizon_minutes <= 60  THEN 'h60'
                     WHEN horizon_minutes <= 180 THEN 'h180'
                     ELSE 'h360' END       AS band,
                COUNT(*)                  AS n,
                AVG(ABS(error_seconds))   AS mae,
                AVG(error_seconds)        AS bias,
                SUM(CASE WHEN within_tolerance IS NOT NULL THEN 1 ELSE 0 END) AS n_tol,
                SUM(CASE WHEN within_tolerance = 1 THEN 1 ELSE 0 END)         AS n_hit
             FROM waze_tvt_forecast_audit
             WHERE route_id = :id
               AND status = 'evaluated'
               AND error_seconds IS NOT NULL
               AND target_start_at >= :since
             GROUP BY band",
            ['id' => $routeId, 'since' => $since]
        )->fetchAllAssociative();

        if ($rows === []) {
            return null;
        }

        $byBand = [];
        foreach ($rows as $r) {
            $byBand[(string) $r['band']] = $r;
        }

        $total = 0;
        $sumMae = $sumBias = 0.0;
        $nTol = $nHit = 0;
        $bands = [];

        foreach (self::HORIZON_BANDS as $key => $label) {
            $r = $byBand[$key] ?? null;
            if ($r === null) {
                continue;
            }

            $n = (int) $r['n'];
            $total   += $n;
            $sumMae  += (float) $r['mae'] * $n;
            $sumBias += (float) $r['bias'] * $n;
            $nTol    += (int) $r['n_tol'];
            $nHit    += (int) $r['n_hit'];

            $bands[] = [
                'label'   => $label,
                'samples' => $n,
                'mae'     => round((float) $r['mae'], 1),
                'bias'    => round((float) $r['bias'], 1),
                'hitRate' => (int) $r['n_tol'] > 0 ? round(100 * (int) $r['n_hit'] / (int) $r['n_tol'], 1) : null,
            ];
        }

        return [
            'days'    => $days,
            'samples' => $total,
            'mae'     => round($sumMae / $total, 1),
            'bias'    => round($sumBias / $total, 1),
            'hitRate' => $nTol > 0 ? round(100 * $nHit / $nTol, 1) : null,
            'bands'   => $bands,
        ];
    }
}
