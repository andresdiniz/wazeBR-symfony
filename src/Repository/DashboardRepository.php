<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Partner;
use App\Entity\User;
use App\Entity\WazeAlert;
use App\Entity\WazeJam;
use App\Entity\WazeTvtRoute;
use App\Entity\WazeTvtRouteSnapshot;
use App\Entity\WeatherObservation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class DashboardRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WazeAlert::class);
    }

    /**
     * Retorna todos os dados necessários para o dashboard.
     * Quando $partner é informado, filtra apenas os dados daquele partner.
     *
     * @return array<string, mixed>
     */
    public function getDashboardStats(?Partner $partner = null): array
    {
        $em = $this->getEntityManager();

        $partnerFilter = $partner !== null ? ['partner' => $partner] : [];

        // ── Contagens totais ─────────────────────────────────────────────────
        $totalAlerts   = $em->getRepository(WazeAlert::class)->count($partnerFilter);
        $totalJams     = $em->getRepository(WazeJam::class)->count($partnerFilter);
        $totalWeather  = $em->getRepository(WeatherObservation::class)->count($partnerFilter);
        $totalPartners = $partner !== null ? 1 : $em->getRepository(Partner::class)->count([]);
        $totalUsers    = $em->getRepository(User::class)->count($partnerFilter);

        // ── Registros recentes ───────────────────────────────────────────────
        $recentAlerts  = $em->getRepository(WazeAlert::class)->findBy($partnerFilter, ['id' => 'DESC'], 8);
        $recentJams    = $em->getRepository(WazeJam::class)->findBy($partnerFilter, ['id' => 'DESC'], 8);
        $recentWeather = $em->getRepository(WeatherObservation::class)->findBy($partnerFilter, ['id' => 'DESC'], 5);

        // ── Rotas com último snapshot ─────────────────────────────────────────
        $recentRoutes = $this->buildRecentRoutes($partner);

        // ── Distribuição de alertas por tipo (para o chart) ───────────────────
        $alertsByType = $this->countAlertsByType($partner);

        // ── Distribuição de jams por nível ────────────────────────────────────
        $jamLevels = $this->countJamsByLevel($partner);

        // ── Série temporal (últimas 8 horas, por hora) ────────────────────────
        $hourlyActivity = $this->buildHourlyActivity($partner);

        // ── Top cidades por alertas ───────────────────────────────────────────
        $topCities = $this->buildTopCities($partner);

        // ── Health score calculado ────────────────────────────────────────────
        $healthScore = $this->computeHealthScore($totalAlerts, $totalJams, $recentRoutes);

        return [
            'total_alerts'    => $totalAlerts,
            'total_jams'      => $totalJams,
            'total_weather'   => $totalWeather,
            'total_partners'  => $totalPartners,
            'total_users'     => $totalUsers,
            'recent_alerts'   => $recentAlerts,
            'recent_jams'     => $recentJams,
            'recent_weather'  => $recentWeather,
            'recent_routes'   => $recentRoutes,
            'alerts_by_type'  => $alertsByType,
            'jam_levels'      => $jamLevels,
            'hourly_activity' => $hourlyActivity,
            'top_cities'      => $topCities,
            'health_score'    => $healthScore,
            'updated_at'      => new \DateTimeImmutable(),
        ];
    }

    // ── Privados ──────────────────────────────────────────────────────────────

    /**
     * Monta lista de rotas com seu snapshot mais recente.
     *
     * @return list<array<string, mixed>>
     */
    private function buildRecentRoutes(?Partner $partner): array
    {
        $em = $this->getEntityManager();
        $partnerFilter = $partner !== null ? ['partner' => $partner] : [];

        $routes    = $em->getRepository(WazeTvtRoute::class)->findBy($partnerFilter, ['id' => 'DESC'], 100);
        $snapshots = $em->getRepository(WazeTvtRouteSnapshot::class)->findBy($partnerFilter, ['recordedAt' => 'DESC', 'id' => 'DESC'], 500);

        // Indexa o snapshot mais recente por wazeRouteId
        $latestSnapshots = [];
        foreach ($snapshots as $snapshot) {
            $routeId = $this->safeString($snapshot, 'getWazeRouteId');
            if ($routeId !== null && $routeId !== '' && !isset($latestSnapshots[$routeId])) {
                $latestSnapshots[$routeId] = $snapshot;
            }
        }

        $result = [];
        foreach ($routes as $route) {
            $wazeRouteId = $this->safeString($route, 'getRouteId');
            $snapshot    = $wazeRouteId !== null ? ($latestSnapshots[$wazeRouteId] ?? null) : null;

            if ($snapshot === null) {
                continue;
            }

            $time         = $this->safeFloat($snapshot, 'getTime');
            $historicTime = $this->safeFloat($snapshot, 'getHistoricTime');
            $jamLevel     = $this->safeInt($snapshot, 'getJamLevel') ?? $this->safeInt($route, 'getJamLevel');
            $delaySeconds = ($time !== null && $historicTime !== null) ? max(0, $historicTime - $time) : null;

            $result[] = [
                'id'             => $this->safeScalar($route, 'getId'),
                'waze_route_id'  => $wazeRouteId,
                'name'           => $this->safeString($route, 'getName') ?? 'Rota monitorada',
                'from_name'      => $this->safeString($route, 'getFromName'),
                'to_name'        => $this->safeString($route, 'getToName'),
                'status'         => ($delaySeconds !== null && $delaySeconds > 60) ? 'Atrasada' : 'Normal',
                'time'           => $time,
                'historic_time'  => $historicTime,
                'delay_seconds'  => $delaySeconds,
                'delay_minutes'  => $delaySeconds !== null ? round($delaySeconds / 60, 1) : null,
                'jam_level'      => $jamLevel,
                'recorded_at'    => $this->safeScalar($snapshot, 'getRecordedAt'),
            ];
        }

        // Ordena por maior atraso
        usort($result, static fn (array $a, array $b): int =>
            ($b['delay_seconds'] ?? -1) <=> ($a['delay_seconds'] ?? -1)
        );

        return array_slice($result, 0, 8);
    }

    /**
     * Conta alertas agrupados por tipo.
     *
     * @return array<string, int>
     */
    private function countAlertsByType(?Partner $partner): array
    {
        $qb = $this->getEntityManager()
            ->getRepository(WazeAlert::class)
            ->createQueryBuilder('a')
            ->select('a.type AS type, COUNT(a.id) AS total')
            ->groupBy('a.type')
            ->orderBy('total', 'DESC')
            ->setMaxResults(6);

        if ($partner !== null) {
            $qb->andWhere('a.partner = :partner')->setParameter('partner', $partner);
        }

        $rows   = $qb->getQuery()->getArrayResult();
        $result = [];
        foreach ($rows as $row) {
            $result[(string) ($row['type'] ?? 'Outro')] = (int) $row['total'];
        }

        return $result;
    }

    /**
     * Conta jams agrupados por nível.
     *
     * @return array<int, int>
     */
    private function countJamsByLevel(?Partner $partner): array
    {
        $qb = $this->getEntityManager()
            ->getRepository(WazeJam::class)
            ->createQueryBuilder('j')
            ->select('j.level AS level, COUNT(j.id) AS total')
            ->groupBy('j.level')
            ->orderBy('j.level', 'ASC');

        if ($partner !== null) {
            $qb->andWhere('j.partner = :partner')->setParameter('partner', $partner);
        }

        $rows   = $qb->getQuery()->getArrayResult();
        $result = [];
        foreach ($rows as $row) {
            $result[(int) ($row['level'] ?? 0)] = (int) $row['total'];
        }

        return $result;
    }

    /**
     * Retorna série de atividade das últimas 8 horas (alertas + jams por hora).
     *
     * @return list<array{hour: string, alerts: int, jams: int, total: int}>
     */
    private function buildHourlyActivity(?Partner $partner): array
    {
        $em = $this->getEntityManager();
        $since = new \DateTimeImmutable('-8 hours');

        // Alertas por hora (usando pubMillis ou createdAt dependendo do seu schema)
        $alertQb = $em->getRepository(WazeAlert::class)
            ->createQueryBuilder('a')
            ->select('HOUR(a.createdAt) AS hr, COUNT(a.id) AS total')
            ->where('a.createdAt >= :since')
            ->groupBy('hr')
            ->setParameter('since', $since);

        if ($partner !== null) {
            $alertQb->andWhere('a.partner = :partner')->setParameter('partner', $partner);
        }

        $jamQb = $em->getRepository(WazeJam::class)
            ->createQueryBuilder('j')
            ->select('HOUR(j.createdAt) AS hr, COUNT(j.id) AS total')
            ->where('j.createdAt >= :since')
            ->groupBy('hr')
            ->setParameter('since', $since);

        if ($partner !== null) {
            $jamQb->andWhere('j.partner = :partner')->setParameter('partner', $partner);
        }

        $alertsByHour = [];
        try {
            foreach ($alertQb->getQuery()->getArrayResult() as $row) {
                $alertsByHour[(int) $row['hr']] = (int) $row['total'];
            }
        } catch (\Throwable) {}

        $jamsByHour = [];
        try {
            foreach ($jamQb->getQuery()->getArrayResult() as $row) {
                $jamsByHour[(int) $row['hr']] = (int) $row['total'];
            }
        } catch (\Throwable) {}

        $result = [];
        for ($i = 7; $i >= 0; $i--) {
            $hourDt = new \DateTimeImmutable(sprintf('-%d hours', $i));
            $hr     = (int) $hourDt->format('G');
            $label  = $hourDt->format('H:00');
            $alerts = $alertsByHour[$hr] ?? 0;
            $jams   = $jamsByHour[$hr] ?? 0;

            $result[] = [
                'hour'   => $label,
                'alerts' => $alerts,
                'jams'   => $jams,
                'total'  => $alerts + $jams,
            ];
        }

        return $result;
    }

    /**
     * Top 8 cidades por número de alertas.
     *
     * @return list<array{city: string, total: int}>
     */
    private function buildTopCities(?Partner $partner): array
    {
        $qb = $this->getEntityManager()
            ->getRepository(WazeAlert::class)
            ->createQueryBuilder('a')
            ->select('a.city AS city, COUNT(a.id) AS total')
            ->where('a.city IS NOT NULL')
            ->groupBy('a.city')
            ->orderBy('total', 'DESC')
            ->setMaxResults(8);

        if ($partner !== null) {
            $qb->andWhere('a.partner = :partner')->setParameter('partner', $partner);
        }

        $result = [];
        try {
            foreach ($qb->getQuery()->getArrayResult() as $row) {
                if (($row['city'] ?? '') === '') {
                    continue;
                }
                $result[] = ['city' => (string) $row['city'], 'total' => (int) $row['total']];
            }
        } catch (\Throwable) {}

        return $result;
    }

    /**
     * Calcula health score (0-100) com base no estado atual da operação.
     *
     * @param list<array<string, mixed>> $routes
     */
    private function computeHealthScore(int $totalAlerts, int $totalJams, array $routes): int
    {
        $score = 100;

        // Penaliza alertas em excesso
        if ($totalAlerts > 50) {
            $score -= min(20, (int) round(($totalAlerts - 50) / 10));
        }

        // Penaliza jams em excesso
        if ($totalJams > 30) {
            $score -= min(20, (int) round(($totalJams - 30) / 5));
        }

        // Penaliza rotas com atraso maior que 5 min
        $delayedRoutes = array_filter($routes, static fn (array $r) => ($r['delay_minutes'] ?? 0) > 5);
        $score -= count($delayedRoutes) * 3;

        return max(0, min(100, $score));
    }

    // ── Helpers de reflexão segura ────────────────────────────────────────────

    private function safeScalar(object $entity, string $method): int|string|float|bool|null
    {
        if (!method_exists($entity, $method)) {
            return null;
        }
        $v = $entity->{$method}();
        return is_scalar($v) || $v instanceof \DateTimeInterface ? $v : null;
    }

    private function safeString(object $entity, string $method): ?string
    {
        $v = $this->safeScalar($entity, $method);
        return is_string($v) && $v !== '' ? $v : null;
    }

    private function safeFloat(object $entity, string $method): ?float
    {
        $v = $this->safeScalar($entity, $method);
        return is_numeric($v) ? (float) $v : null;
    }

    private function safeInt(object $entity, string $method): ?int
    {
        $v = $this->safeScalar($entity, $method);
        return is_numeric($v) ? (int) $v : null;
    }
}
