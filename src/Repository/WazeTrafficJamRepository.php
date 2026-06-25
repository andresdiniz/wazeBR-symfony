<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Partner;
use App\Entity\WazeTrafficJam;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WazeTrafficJam>
 */
class WazeTrafficJamRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WazeTrafficJam::class);
    }

    // ── contagens ────────────────────────────────────────────────────

    public function countByPartner(Partner $partner): int
    {
        return (int) $this->createQueryBuilder('j')
            ->select('COUNT(j.id)')
            ->where('j.partner = :p')->setParameter('p', $partner)
            ->getQuery()->getSingleScalarResult();
    }

    public function countLast24hByPartner(Partner $partner): int
    {
        return (int) $this->createQueryBuilder('j')
            ->select('COUNT(j.id)')
            ->where('j.partner = :p')->setParameter('p', $partner)
            ->andWhere('j.createdAt >= :since')
            ->setParameter('since', new \DateTimeImmutable('-24 hours'))
            ->getQuery()->getSingleScalarResult();
    }

    // ── agregações simples (usadas pelo DashboardController) ─────────

    public function avgSpeedKmhByPartner(Partner $partner): float
    {
        $val = $this->createQueryBuilder('j')
            ->select('AVG(j.speedKmh)')
            ->where('j.partner = :p')->setParameter('p', $partner)
            ->getQuery()->getSingleScalarResult();

        return round((float)($val ?? 0), 1);
    }

    public function avgDelaySecsByPartner(Partner $partner): float
    {
        $val = $this->createQueryBuilder('j')
            ->select('AVG(j.delay)')
            ->where('j.partner = :p')->setParameter('p', $partner)
            ->getQuery()->getSingleScalarResult();

        return round((float)($val ?? 0));
    }

    public function totalLengthMByPartner(Partner $partner): float
    {
        $val = $this->createQueryBuilder('j')
            ->select('SUM(j.length)')
            ->where('j.partner = :p')->setParameter('p', $partner)
            ->getQuery()->getSingleScalarResult();

        return round((float)($val ?? 0));
    }

    // ── por hora (gráfico 24 h) ───────────────────────────────────────

    /**
     * Retorna array de ['hour' => int(0-23), 'total' => int]
     * para as últimas 24 horas.
     */
    public function countPerHourLast24h(Partner $partner): array
    {
        return $this->createQueryBuilder('j')
            ->select('HOUR(j.createdAt) AS hour, COUNT(j.id) AS total')
            ->where('j.partner = :p')->setParameter('p', $partner)
            ->andWhere('j.createdAt >= :since')
            ->setParameter('since', new \DateTimeImmutable('-24 hours'))
            ->groupBy('hour')
            ->orderBy('hour', 'ASC')
            ->getQuery()->getArrayResult();
    }

    // ── listagens ────────────────────────────────────────────────────

    /** Jams mais recentes (para o painel do dashboard). */
    public function findRecentByPartner(Partner $partner, int $limit = 10): array
    {
        return $this->createQueryBuilder('j')
            ->where('j.partner = :p')->setParameter('p', $partner)
            ->orderBy('j.pubMillis', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    /** Jams "ativos" = das últimas 3 horas (para o mapa). */
    public function findActiveByPartner(Partner $partner, int $hours = 3): array
    {
        return $this->findLiveByPartner($partner, $hours);
    }

    /**
     * Histórico paginado com filtros.
     *
     * @return array{items: WazeTrafficJam[], total: int, pages: int}
     */
    public function findFilteredByPartner(
        Partner  $partner,
        ?int     $minLevel = null,
        ?string  $city     = null,
        ?string  $type     = null,
        ?string  $dateFrom = null,
        ?string  $dateTo   = null,
        int      $page     = 1,
        int      $limit    = 30,
    ): array {
        $qb = $this->createQueryBuilder('j')
            ->where('j.partner = :p')->setParameter('p', $partner)
            ->orderBy('j.pubMillis', 'DESC');

        if ($minLevel !== null) {
            $qb->andWhere('j.level >= :lv')->setParameter('lv', $minLevel);
        }
        if ($city) {
            $qb->andWhere('j.city LIKE :city')->setParameter('city', '%'.$city.'%');
        }
        if ($type) {
            $qb->andWhere('j.type = :type')->setParameter('type', $type);
        }
        if ($dateFrom) {
            $qb->andWhere('j.createdAt >= :from')
               ->setParameter('from', (new \DateTimeImmutable($dateFrom))->setTime(0, 0, 0));
        }
        if ($dateTo) {
            $qb->andWhere('j.createdAt <= :to')
               ->setParameter('to', (new \DateTimeImmutable($dateTo))->setTime(23, 59, 59));
        }

        $paginator = new Paginator($qb->setFirstResult(($page - 1) * $limit)->setMaxResults($limit));
        $total     = count($paginator);

        return [
            'items' => iterator_to_array($paginator),
            'total' => $total,
            'pages' => (int) ceil($total / $limit),
        ];
    }

    public function findOneByPartner(int $id, Partner $partner): ?WazeTrafficJam
    {
        return $this->createQueryBuilder('j')
            ->where('j.id = :id')->setParameter('id', $id)
            ->andWhere('j.partner = :p')->setParameter('p', $partner)
            ->getQuery()->getOneOrNullResult();
    }

    /** Jams das últimas N horas para o mapa ao vivo */
    public function findLiveByPartner(Partner $partner, int $hours = 3): array
    {
        $sinceMs = (new \DateTimeImmutable("-{$hours} hours"))->getTimestamp() * 1000;

        return $this->createQueryBuilder('j')
            ->where('j.partner = :p')->setParameter('p', $partner)
            ->andWhere('j.pubMillis >= :since')->setParameter('since', $sinceMs)
            ->orderBy('j.level', 'DESC')
            ->addOrderBy('j.pubMillis', 'DESC')
            ->setMaxResults(1000)
            ->getQuery()->getResult();
    }

    // ── valores distintos para filtros ───────────────────────────────────

    public function findDistinctCities(Partner $partner): array
    {
        $rows = $this->createQueryBuilder('j')
            ->select('DISTINCT j.city')
            ->where('j.partner = :p')->setParameter('p', $partner)
            ->andWhere('j.city IS NOT NULL')
            ->orderBy('j.city', 'ASC')
            ->getQuery()->getArrayResult();
        return array_column($rows, 'city');
    }

    public function findDistinctTypes(Partner $partner): array
    {
        $rows = $this->createQueryBuilder('j')
            ->select('DISTINCT j.type')
            ->where('j.partner = :p')->setParameter('p', $partner)
            ->andWhere('j.type IS NOT NULL')
            ->orderBy('j.type', 'ASC')
            ->getQuery()->getArrayResult();
        return array_column($rows, 'type');
    }

    // ── agregações ───────────────────────────────────────────────────

    public function countGroupByLevel(Partner $partner): array
    {
        return $this->createQueryBuilder('j')
            ->select('j.level, COUNT(j.id) AS total')
            ->where('j.partner = :p')->setParameter('p', $partner)
            ->groupBy('j.level')
            ->orderBy('j.level', 'DESC')
            ->getQuery()->getArrayResult();
    }

    /**
     * Estatísticas agregadas dos jams ativos nas últimas N horas.
     *
     * Retorna:
     *   avgDelay    – atraso médio em segundos
     *   avgLength   – comprimento médio em metros
     *   avgSpeed    – velocidade média em km/h
     *   totalLength – soma total dos comprimentos em metros
     */
    public function avgStats(Partner $partner, int $hours = 3): array
    {
        $sinceMs = (new \DateTimeImmutable("-{$hours} hours"))->getTimestamp() * 1000;

        $row = $this->createQueryBuilder('j')
            ->select(
                'AVG(j.delay)    AS avgDelay',
                'AVG(j.length)   AS avgLength',
                'AVG(j.speedKmh) AS avgSpeed',
                'SUM(j.length)   AS totalLength',
            )
            ->where('j.partner = :p')->setParameter('p', $partner)
            ->andWhere('j.pubMillis >= :since')->setParameter('since', $sinceMs)
            ->getQuery()->getSingleResult();

        return [
            'avgDelay'    => round((float)($row['avgDelay']    ?? 0)),
            'avgLength'   => round((float)($row['avgLength']   ?? 0)),
            'avgSpeed'    => round((float)($row['avgSpeed']    ?? 0), 1),
            'totalLength' => round((float)($row['totalLength'] ?? 0)),
        ];
    }
}
