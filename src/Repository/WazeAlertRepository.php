<?php

namespace App\Repository;

use App\Entity\Partner;
use App\Entity\WazeAlert;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WazeAlert>
 */
class WazeAlertRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WazeAlert::class);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private static function toMillis(\DateTimeInterface $dt): int
    {
        return (int) $dt->getTimestamp() * 1000;
    }

    // ── Filtro paginado (página de histórico) ─────────────────────────────────

    /**
     * Retorna ['items' => WazeAlert[], 'total' => int, 'pages' => int]
     */
    public function findFilteredByPartner(
        Partner $partner,
        ?string $type     = null,
        ?string $subtype  = null,
        ?string $city     = null,
        ?string $dateFrom = null,
        ?string $dateTo   = null,
        int     $page     = 1,
        int     $limit    = 30,
    ): array {
        $qb = $this->createQueryBuilder('a')
            ->where('a.partner = :partner')
            ->setParameter('partner', $partner)
            ->orderBy('a.pubMillis', 'DESC');

        if ($type) {
            $qb->andWhere('a.type = :type')->setParameter('type', $type);
        }

        if ($subtype) {
            $qb->andWhere('a.subtype = :subtype')->setParameter('subtype', $subtype);
        }

        if ($city) {
            $qb->andWhere('a.city = :city')->setParameter('city', $city);
        }

        if ($dateFrom) {
            $from = new \DateTimeImmutable($dateFrom . ' 00:00:00');
            $qb->andWhere('a.pubMillis >= :from')
               ->setParameter('from', self::toMillis($from));
        }

        if ($dateTo) {
            $to = new \DateTimeImmutable($dateTo . ' 23:59:59');
            $qb->andWhere('a.pubMillis <= :to')
               ->setParameter('to', self::toMillis($to));
        }

        $total = (int) (clone $qb)
            ->select('COUNT(a.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $pages = max(1, (int) ceil($total / $limit));
        $page  = max(1, min($page, $pages));

        $items = $qb
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return [
            'items' => $items,
            'total' => $total,
            'pages' => $pages,
        ];
    }

    // ── Helpers para filtros (listas de opções) ───────────────────────────────

    public function findDistinctTypes(Partner $partner): array
    {
        $rows = $this->createQueryBuilder('a')
            ->select('DISTINCT a.type AS type')
            ->where('a.partner = :partner')
            ->setParameter('partner', $partner)
            ->orderBy('a.type', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'type');
    }

    public function findDistinctSubtypes(Partner $partner, ?string $type = null): array
    {
        $qb = $this->createQueryBuilder('a')
            ->select('DISTINCT a.subtype AS subtype')
            ->where('a.partner = :partner')
            ->andWhere('a.subtype IS NOT NULL')
            ->setParameter('partner', $partner)
            ->orderBy('a.subtype', 'ASC');

        if ($type) {
            $qb->andWhere('a.type = :type')->setParameter('type', $type);
        }

        $rows = $qb->getQuery()->getArrayResult();

        return array_column($rows, 'subtype');
    }

    public function findDistinctCities(Partner $partner): array
    {
        $rows = $this->createQueryBuilder('a')
            ->select('DISTINCT a.city AS city')
            ->where('a.partner = :partner')
            ->andWhere('a.city IS NOT NULL')
            ->setParameter('partner', $partner)
            ->orderBy('a.city', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'city');
    }

    // ── Mapa ao vivo ──────────────────────────────────────────────────────────

    /**
     * Alertas recentes (últimas $hours horas) agrupados por cidade/região.
     * Retorna [['city' => string, 'count' => int], ...]
     */
    public function findLiveGroupedByRegion(Partner $partner, int $hours = 3): array
    {
        $since = (time() - $hours * 3600) * 1000;

        $rows = $this->createQueryBuilder('a')
            ->select('a.city AS city, COUNT(a.id) AS count')
            ->where('a.partner = :partner')
            ->andWhere('a.pubMillis >= :since')
            ->setParameter('partner', $partner)
            ->setParameter('since', $since)
            ->groupBy('a.city')
            ->orderBy('count', 'DESC')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn(array $r) => [
            'city'  => $r['city'],
            'count' => (int) $r['count'],
        ], $rows);
    }

    /**
     * Alertas das últimas $hours horas para o mapa ao vivo (com coordenadas).
     *
     * @return WazeAlert[]
     */
    public function findLiveByPartner(Partner $partner, int $hours = 3): array
    {
        $since = (time() - $hours * 3600) * 1000;

        return $this->createQueryBuilder('a')
            ->where('a.partner = :partner')
            ->andWhere('a.pubMillis >= :since')
            ->setParameter('partner', $partner)
            ->setParameter('since', $since)
            ->orderBy('a.pubMillis', 'DESC')
            ->getQuery()
            ->getResult();
    }

    // ── Detalhe de um alerta ──────────────────────────────────────────────────

    public function findOneByPartner(int $id, Partner $partner): ?WazeAlert
    {
        return $this->createQueryBuilder('a')
            ->where('a.id = :id')
            ->andWhere('a.partner = :partner')
            ->setParameter('id', $id)
            ->setParameter('partner', $partner)
            ->getQuery()
            ->getOneOrNullResult();
    }

    // ── Contagem e estatísticas ───────────────────────────────────────────────

    public function countInPeriod(Partner $partner, \DateTimeInterface $from, \DateTimeInterface $to): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.partner = :partner')
            ->andWhere('a.pubMillis BETWEEN :from AND :to')
            ->setParameter('partner', $partner)
            ->setParameter('from', self::toMillis($from))
            ->setParameter('to', self::toMillis($to))
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countByPartner(Partner $partner): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.partner = :partner')
            ->setParameter('partner', $partner)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Distribuição de alertas por type + subtype dentro do período.
     * Retorna [['type'=>'JAM','subtype'=>'JAM_HEAVY_TRAFFIC','total'=>42], ...] ordenado desc.
     */
    public function countBySubtypeInPeriod(Partner $partner, \DateTimeInterface $from, \DateTimeInterface $to, int $limit = 12): array
    {
        $limit = max(1, $limit);

        $rows = $this->createQueryBuilder('a')
            ->select('a.type AS type, a.subtype AS subtype, COUNT(a.id) AS total')
            ->where('a.partner = :partner')
            ->andWhere('a.pubMillis BETWEEN :from AND :to')
            ->setParameter('partner', $partner)
            ->setParameter('from', self::toMillis($from))
            ->setParameter('to', self::toMillis($to))
            ->groupBy('a.type, a.subtype')
            ->orderBy('total', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn(array $r) => [
            'type'    => $r['type'],
            'subtype' => $r['subtype'],
            'total'   => (int) $r['total'],
        ], $rows);
    }

    /**
     * Alertas com coordenadas dentro do período, para exibição no mapa.
     */
    public function findForMapInPeriod(Partner $partner, \DateTimeInterface $from, \DateTimeInterface $to, int $limit = 600): array
    {
        return $this->createQueryBuilder('a')
            ->select('a.id, a.type, a.subtype, a.street, a.city, a.latitude, a.longitude, a.pubMillis')
            ->where('a.partner = :partner')
            ->andWhere('a.pubMillis BETWEEN :from AND :to')
            ->setParameter('partner', $partner)
            ->setParameter('from', self::toMillis($from))
            ->setParameter('to', self::toMillis($to))
            ->orderBy('a.pubMillis', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();
    }

    public function getAlertsPerHourLast24h(): array
    {
        $now     = time() * 1000;
        $lastDay = $now - 24 * 3600 * 1000;

        $conn = $this->getEntityManager()->getConnection();

        $sql = <<<SQL
            SELECT
                CONCAT(DATE_FORMAT(FROM_UNIXTIME(a.pub_millis / 1000), '%H'), 'h') AS hour_label,
                COUNT(a.id) AS total
            FROM waze_alerts a
            WHERE a.pub_millis >= :lastDay
            GROUP BY hour_label
            ORDER BY hour_label ASC
        SQL;

        return $conn->fetchAllAssociative($sql, [
            'lastDay' => $lastDay,
        ]);
    }
}
