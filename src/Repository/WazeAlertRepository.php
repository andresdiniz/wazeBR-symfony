<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Partner;
use App\Entity\WazeAlert;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;

final class WazeAlertRepository extends ServiceEntityRepository
{
    private Connection $connection;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WazeAlert::class);
        $this->connection = $registry->getConnection();
    }

    /** Mantido por compatibilidade com o controller antigo. */
    public function findAllLatest(int $limit = 100): array
    {
        return $this->findBy([], ['collectedAt' => 'DESC'], $limit);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Sincronização — usados pelo FetchWazeFeedCommand / WazeFeedSynchronizer
    // ─────────────────────────────────────────────────────────────────────

    public function findOneByPartnerAndUuid(
        Partner $partner,
        string $uuid,
    ): ?WazeAlert {
        return $this->createQueryBuilder('a')
            ->andWhere('a.partner = :partner')
            ->andWhere('a.uuid = :uuid')
            ->setParameter('partner', $partner)
            ->setParameter('uuid', $uuid)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Desativa alerts do partner cujo uuid não aparece no feed atual.
     * Usa DBAL nativo — mais rápido e sem surpresas com DQL UPDATE.
     *
     * @param string[] $currentUuids
     */
    public function deactivateMissingForPartner(
        Partner $partner,
        array $currentUuids,
        \DateTimeImmutable $now,
    ): int {
        $currentUuids = array_values(array_unique(array_filter(
            $currentUuids,
            static fn ($u) => is_string($u) && $u !== '',
        )));

        if ($currentUuids === []) {
            return 0;
        }

        $total = 0;

        foreach (array_chunk($currentUuids, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));

            $total += (int) $this->connection->executeStatement(
                sprintf(
                    'UPDATE waze_alerts
                     SET is_active = 0, deactivated_at = ?
                     WHERE partner_id = ?
                       AND is_active = 1
                       AND uuid NOT IN (%s)',
                    $placeholders,
                ),
                array_merge(
                    [$now->format('Y-m-d H:i:s'), $partner->getId()],
                    $chunk,
                ),
            );
        }

        return $total;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Stats / KPIs
    // ─────────────────────────────────────────────────────────────────────

    /** @param array<string,mixed> $filters */
    public function getStats(?Partner $partner, array $filters): array
    {
        $filters = $this->normalize($filters);
        [$where, $params] = $this->buildWhere($partner, $filters);

        $sql = "SELECT
                    COUNT(*) AS total,
                    SUM(CASE WHEN a.is_active = 1 THEN 1 ELSE 0 END) AS active,
                    SUM(CASE WHEN a.collected_at >= :recent THEN 1 ELSE 0 END) AS recent,
                    MAX(a.collected_at) AS last_seen,
                    AVG(a.confidence) AS avg_confidence
                FROM waze_alerts a
                WHERE $where";

        $params['recent'] = (new \DateTimeImmutable('-1 hour', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $row = $this->connection->executeQuery($sql, $params)->fetchAssociative() ?: [];

        $topType = $this->connection->executeQuery(
            "SELECT a.type FROM waze_alerts a WHERE $where GROUP BY a.type ORDER BY COUNT(*) DESC LIMIT 1",
            array_diff_key($params, ['recent' => null])
        )->fetchOne();

        return [
            'total'          => (int) ($row['total'] ?? 0),
            'active'         => (int) ($row['active'] ?? 0),
            'recent'         => (int) ($row['recent'] ?? 0),
            'last_seen'      => $this->toIso($row['last_seen'] ?? null),
            'avg_confidence' => $row['avg_confidence'] !== null ? (float) $row['avg_confidence'] : null,
            'top_type'       => $topType ?: '—',
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Charts
    // ─────────────────────────────────────────────────────────────────────

    /** @param array<string,mixed> $filters */
    public function getByType(?Partner $partner, array $filters): array
    {
        $filters = $this->normalize($filters);
        [$where, $params] = $this->buildWhere($partner, $filters);

        $sql = "SELECT COALESCE(a.type, 'Outro') AS label, COUNT(*) AS total
                FROM waze_alerts a WHERE $where
                GROUP BY label ORDER BY total DESC LIMIT 12";
        $rows = $this->connection->executeQuery($sql, $params)->fetchAllAssociative();

        return array_map(static fn ($r) => [
            'label' => (string) $r['label'],
            'total' => (int) $r['total'],
        ], $rows);
    }

    /** @param array<string,mixed> $filters */
    public function getBySubtype(?Partner $partner, array $filters, int $limit = 8): array
    {
        $filters = $this->normalize($filters);
        [$where, $params] = $this->buildWhere($partner, $filters);
        $where .= " AND a.subtype IS NOT NULL AND a.subtype <> ''";

        $sql = "SELECT a.subtype AS label, COUNT(*) AS total
                FROM waze_alerts a WHERE $where
                GROUP BY a.subtype ORDER BY total DESC LIMIT " . (int) $limit;
        $rows = $this->connection->executeQuery($sql, $params)->fetchAllAssociative();

        return array_map(static fn ($r) => [
            'label' => (string) $r['label'],
            'total' => (int) $r['total'],
        ], $rows);
    }

    /** @param array<string,mixed> $filters */
    public function getByHour(?Partner $partner, array $filters): array
    {
        $filters = $this->normalize($filters);
        [$where, $params] = $this->buildWhere($partner, $filters);

        $bucket = $this->spBucket('a.collected_at', 'HOUR');

        $sql = "SELECT $bucket AS h, COUNT(*) AS total
                FROM waze_alerts a WHERE $where
                GROUP BY h";
        $rows = $this->connection->executeQuery($sql, $params)->fetchAllKeyValue();

        $points = [];
        for ($h = 0; $h < 24; $h++) {
            $points[] = ['hour' => sprintf('%02d:00', $h), 'total' => (int) ($rows[$h] ?? 0)];
        }
        return $points;
    }

    /** @param array<string,mixed> $filters */
    public function getByCity(?Partner $partner, array $filters, int $limit = 10): array
    {
        $filters = $this->normalize($filters);
        [$where, $params] = $this->buildWhere($partner, $filters);
        $where .= " AND a.city IS NOT NULL AND a.city <> ''";

        $sql = "SELECT a.city AS label, COUNT(*) AS total
                FROM waze_alerts a WHERE $where
                GROUP BY a.city ORDER BY total DESC LIMIT " . (int) $limit;
        $rows = $this->connection->executeQuery($sql, $params)->fetchAllAssociative();

        return array_map(static fn ($r) => [
            'label' => (string) $r['label'],
            'total' => (int) $r['total'],
        ], $rows);
    }

    /** @param array<string,mixed> $filters */
    public function getByDay(?Partner $partner, array $filters, int $days = 14): array
    {
        $filters = $this->normalize($filters);
        [$where, $params] = $this->buildWhere($partner, $filters);

        $bucket = $this->spBucket('a.collected_at', 'DATE');

        $sql = "SELECT $bucket AS d, COUNT(*) AS total
                FROM waze_alerts a
                WHERE $where AND a.collected_at >= :since
                GROUP BY d ORDER BY d ASC";
        $params['since'] = (new \DateTimeImmutable("-{$days} days", new \DateTimeZone('UTC')))->format('Y-m-d 00:00:00');

        $rows = $this->connection->executeQuery($sql, $params)->fetchAllKeyValue();

        $tz = new \DateTimeZone('America/Sao_Paulo');
        $points = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = (new \DateTimeImmutable("-{$i} days", $tz))->format('Y-m-d');
            $points[] = ['day' => $day, 'total' => (int) ($rows[$day] ?? 0)];
        }
        return $points;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Map — live
    // ─────────────────────────────────────────────────────────────────────

    /** @param array<string,mixed> $filters */
    public function getLiveAlerts(?Partner $partner, array $filters, int $limit = 2000): array
    {
        $filters = $this->normalize($filters);
        [$where, $params] = $this->buildWhere($partner, $filters);
        $where .= " AND a.is_active = 1
                    AND a.latitude IS NOT NULL
                    AND a.longitude IS NOT NULL";

        $sql = "SELECT a.id, a.uuid, a.type, a.subtype, a.city, a.street, a.confidence,
                       a.pub_millis, a.collected_at,
                       CAST(a.latitude AS DECIMAL(10,7)) AS lat,
                       CAST(a.longitude AS DECIMAL(10,7)) AS lng
                FROM waze_alerts a
                WHERE $where
                ORDER BY a.collected_at DESC
                LIMIT " . (int) $limit;

        $rows = $this->connection->executeQuery($sql, $params)->fetchAllAssociative();

        return array_map(fn ($r) => [
            'id'         => (int) $r['id'],
            'uuid'       => $r['uuid'],
            'type'       => $r['type'],
            'subtype'    => $r['subtype'],
            'city'       => $r['city'],
            'street'     => $r['street'],
            'confidence' => (int) $r['confidence'],
            'lat'        => (float) $r['lat'],
            'lng'        => (float) $r['lng'],
            'when'       => $this->toIso($r['collected_at']),
            'pubMillis'  => $r['pub_millis'] !== null ? (int) $r['pub_millis'] : null,
        ], $rows);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Map — histórico (clustering por proximidade — grid server-side)
    // ─────────────────────────────────────────────────────────────────────

    public function getHistoricalClusters(?Partner $partner, array $filters, int $precision = 2): array
    {
        $precision = max(1, min(5, $precision));
        $filters   = $this->normalize($filters);
        [$where, $params] = $this->buildWhere($partner, $filters);

        $where .= " AND a.latitude IS NOT NULL AND a.longitude IS NOT NULL";

        $sql = "SELECT
                    ROUND(a.latitude,  $precision) AS lat,
                    ROUND(a.longitude, $precision) AS lng,
                    COUNT(*)                        AS total,
                    MAX(a.type)                     AS top_type,
                    MIN(a.collected_at)             AS first_seen,
                    MAX(a.collected_at)             AS last_seen
                FROM waze_alerts a
                WHERE $where
                GROUP BY lat, lng
                ORDER BY total DESC
                LIMIT 5000";

        $rows = $this->connection->executeQuery($sql, $params)->fetchAllAssociative();

        return array_map(fn ($r) => [
            'lat'       => (float) $r['lat'],
            'lng'       => (float) $r['lng'],
            'total'     => (int) $r['total'],
            'type'      => $r['top_type'],
            'firstSeen' => $this->toIso($r['first_seen']),
            'lastSeen'  => $this->toIso($r['last_seen']),
        ], $rows);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Filter helpers
    // ─────────────────────────────────────────────────────────────────────

    public function getAvailableTypes(?Partner $partner): array
    {
        $params = [];
        $where  = '1=1';
        if ($partner !== null) {
            $where = 'partner_id = :partner_id';
            $params['partner_id'] = $partner->getId();
        }
        $sql = "SELECT DISTINCT type FROM waze_alerts WHERE $where AND type IS NOT NULL ORDER BY type";
        return $this->connection->executeQuery($sql, $params)->fetchFirstColumn();
    }

    public function getAvailableSubtypes(?Partner $partner): array
    {
        $params = [];
        $where  = '1=1';
        if ($partner !== null) {
            $where = 'partner_id = :partner_id';
            $params['partner_id'] = $partner->getId();
        }
        $sql = "SELECT DISTINCT subtype FROM waze_alerts
                WHERE $where AND subtype IS NOT NULL AND subtype <> '' ORDER BY subtype LIMIT 200";
        return $this->connection->executeQuery($sql, $params)->fetchFirstColumn();
    }

    public function getAvailableCities(?Partner $partner, int $limit = 300): array
    {
        $params = [];
        $where  = '1=1';
        if ($partner !== null) {
            $where = 'partner_id = :partner_id';
            $params['partner_id'] = $partner->getId();
        }
        $sql = "SELECT city, COUNT(*) AS total FROM waze_alerts
                WHERE $where AND city IS NOT NULL AND city <> ''
                GROUP BY city ORDER BY total DESC LIMIT " . (int) $limit;
        return $this->connection->executeQuery($sql, $params)->fetchAllKeyValue();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Recent list & Export
    // ─────────────────────────────────────────────────────────────────────

    public function getRecentList(?Partner $partner, array $filters, int $limit = 50, int $offset = 0): array
    {
        $filters = $this->normalize($filters);
        [$where, $params] = $this->buildWhere($partner, $filters);

        $sql = "SELECT a.id, a.uuid, a.type, a.subtype, a.city, a.street, a.confidence,
                       a.reliability, a.is_active, a.pub_millis, a.collected_at,
                       CAST(a.latitude AS DECIMAL(10,7)) AS lat,
                       CAST(a.longitude AS DECIMAL(10,7)) AS lng
                FROM waze_alerts a
                WHERE $where
                ORDER BY a.collected_at DESC
                LIMIT " . (int) $limit . " OFFSET " . (int) $offset;

        $rows = $this->connection->executeQuery($sql, $params)->fetchAllAssociative();

        return array_map(fn ($r) => [
            'id'          => (int) $r['id'],
            'uuid'        => $r['uuid'],
            'type'        => $r['type'],
            'subtype'     => $r['subtype'],
            'city'        => $r['city'],
            'street'      => $r['street'],
            'confidence'  => (int) $r['confidence'],
            'reliability' => (int) $r['reliability'],
            'isActive'    => (bool) $r['is_active'],
            'lat'         => $r['lat'] !== null ? (float) $r['lat'] : null,
            'lng'         => $r['lng'] !== null ? (float) $r['lng'] : null,
            'when'        => $this->toIso($r['collected_at']),
            'pubMillis'   => $r['pub_millis'] !== null ? (int) $r['pub_millis'] : null,
        ], $rows);
    }

    public function exportRows(?Partner $partner, array $filters, int $limit = 20000): array
    {
        $filters = $this->normalize($filters);
        [$where, $params] = $this->buildWhere($partner, $filters);

        $sql = "SELECT a.id, a.uuid, a.type, a.subtype, a.city, a.street,
                       a.confidence, a.reliability, a.is_active, a.pub_millis, a.collected_at,
                       CAST(a.latitude AS DECIMAL(10,7)) AS lat,
                       CAST(a.longitude AS DECIMAL(10,7)) AS lng
                FROM waze_alerts a
                WHERE $where
                ORDER BY a.collected_at DESC
                LIMIT " . (int) $limit;

        $rows = $this->connection->executeQuery($sql, $params)->fetchAllAssociative();
        $tzSp = new \DateTimeZone('America/Sao_Paulo');

        return array_map(function ($r) use ($tzSp) {
            $iso = $this->toIso($r['collected_at']);

            $sp = null;
            if ($r['collected_at'] !== null && $r['collected_at'] !== '') {
                try {
                    $sp = (new \DateTimeImmutable($r['collected_at'], new \DateTimeZone('UTC')))
                        ->setTimezone($tzSp)
                        ->format('d/m/Y H:i:s');
                } catch (\Throwable) {
                    $sp = null;
                }
            }

            $r['collected_at']    = $iso;
            $r['collected_at_sp'] = $sp;
            return $r;
        }, $rows);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Internals
    // ─────────────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function normalize(array $raw): array
    {
        return [
            'period'         => in_array($raw['period'] ?? 'all', ['all','today','week','month','year'], true)
                ? (string) $raw['period'] : 'all',
            'type'           => trim((string) ($raw['type'] ?? '')) ?: null,
            'subtype'        => trim((string) ($raw['subtype'] ?? '')) ?: null,
            'city'           => trim((string) ($raw['city'] ?? '')) ?: null,
            'query'          => trim((string) ($raw['query'] ?? '')),
            'min_confidence' => max(0, min(100, (int) ($raw['min_confidence'] ?? 0))),
            'active'         => (bool) ($raw['active'] ?? false),
        ];
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private function buildWhere(?Partner $partner, array $filters): array
    {
        $where  = ['1=1'];
        $params = [];

        if ($partner !== null) {
            $where[]              = 'a.partner_id = :partner_id';
            $params['partner_id'] = $partner->getId();
        }

        $tzSp  = new \DateTimeZone('America/Sao_Paulo');
        $nowSp = new \DateTimeImmutable('now', $tzSp);

        $sinceSp = match ($filters['period']) {
            'today' => $nowSp->setTime(0, 0),
            'week'  => $nowSp->modify('-7 days'),
            'month' => $nowSp->modify('-30 days'),
            'year'  => $nowSp->modify('-365 days'),
            default => null,
        };

        if ($sinceSp !== null) {
            $where[]         = 'a.collected_at >= :since';
            $params['since'] = $sinceSp
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s');
        }

        if ($filters['type']) {
            $where[]         = 'a.type = :type';
            $params['type']  = $filters['type'];
        }

        if ($filters['subtype']) {
            $where[]            = 'a.subtype = :subtype';
            $params['subtype']  = $filters['subtype'];
        }

        if ($filters['city']) {
            $where[]        = 'a.city = :city';
            $params['city'] = $filters['city'];
        }

        if ($filters['min_confidence'] > 0) {
            $where[]            = 'a.confidence >= :min_conf';
            $params['min_conf'] = $filters['min_confidence'];
        }

        if ($filters['active']) {
            $where[] = 'a.is_active = 1';
        }

        if ($filters['query'] !== '') {
            $where[]     = '(a.type LIKE :q OR a.subtype LIKE :q OR a.city LIKE :q OR a.street LIKE :q OR a.uuid LIKE :q)';
            $params['q'] = '%' . $filters['query'] . '%';
        }

        return [implode(' AND ', $where), $params];
    }

    private function toIso(?string $mysql): ?string
    {
        if ($mysql === null || $mysql === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($mysql, new \DateTimeZone('UTC')))
                ->format(DATE_ATOM);
        } catch (\Throwable) {
            return null;
        }
    }

    private function spBucket(string $col, string $bucket = 'DATE', string $fmt = ''): string
    {
        return match ($bucket) {
            'HOUR'        => "HOUR(DATE_ADD($col, INTERVAL -3 HOUR))",
            'DATE'        => "DATE(DATE_ADD($col, INTERVAL -3 HOUR))",
            'DATE_FORMAT' => "DATE_FORMAT(DATE_ADD($col, INTERVAL -3 HOUR), '$fmt')",
            default       => $col,
        };
    }
}
