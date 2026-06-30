<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Partner;
use App\Entity\WazeAlert;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
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

    // ── contagens simples ─────────────────────────────────────────────────────────────────────

    public function countByPartner(Partner $partner): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.partner = :p')
            ->setParameter('p', $partner)
            ->getQuery()->getSingleScalarResult();
    }

    public function countLast1hByPartner(Partner $partner): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.partner = :p')
            ->setParameter('p', $partner)
            ->andWhere('a.createdAt >= :since')
            ->setParameter('since', new \DateTimeImmutable('-1 hour'))
            ->getQuery()->getSingleScalarResult();
    }

    public function countLast24hByPartner(Partner $partner): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.partner = :p')
            ->setParameter('p', $partner)
            ->andWhere('a.createdAt >= :since')
            ->setParameter('since', new \DateTimeImmutable('-24 hours'))
            ->getQuery()->getSingleScalarResult();
    }

    // ── listagens ───────────────────────────────────────────────────────────────────────

    /**
     * Histórico paginado com filtros opcionais.
     *
     * @return array{items: WazeAlert[], total: int, pages: int}
     */
    public function findFilteredByPartner(
        Partner     $partner,
        ?string     $type     = null,
        ?string     $subtype  = null,
        ?string     $city     = null,
        ?string     $dateFrom = null,
        ?string     $dateTo   = null,
        int         $page     = 1,
        int         $limit    = 30,
    ): array {
        $qb = $this->createQueryBuilder('a')
            ->where('a.partner = :p')
            ->setParameter('p', $partner)
            ->orderBy('a.pubMillis', 'DESC');

        if ($type) {
            $qb->andWhere('a.type = :type')->setParameter('type', $type);
        }
        if ($subtype) {
            $qb->andWhere('a.subtype = :subtype')->setParameter('subtype', $subtype);
        }
        if ($city) {
            $qb->andWhere('a.city LIKE :city')->setParameter('city', '%' . $city . '%');
        }
        if ($dateFrom) {
            $from = new \DateTimeImmutable($dateFrom);
            $qb->andWhere('a.createdAt >= :from')->setParameter('from', $from->setTime(0, 0, 0));
        }
        if ($dateTo) {
            $to = new \DateTimeImmutable($dateTo);
            $qb->andWhere('a.createdAt <= :to')->setParameter('to', $to->setTime(23, 59, 59));
        }

        $paginator = new Paginator($qb->setFirstResult(($page - 1) * $limit)->setMaxResults($limit));
        $total     = count($paginator);

        return [
            'items' => iterator_to_array($paginator),
            'total' => $total,
            'pages' => (int) ceil($total / $limit),
        ];
    }

    /** Conta com os mesmos filtros (sem paginar) */
    public function countFiltered(
        Partner  $partner,
        ?string  $type     = null,
        ?string  $subtype  = null,
        ?string  $city     = null,
        ?string  $dateFrom = null,
        ?string  $dateTo   = null,
    ): int {
        $result = $this->findFilteredByPartner(
            $partner, $type, $subtype, $city, $dateFrom, $dateTo, 1, 1
        );
        return $result['total'];
    }

    public function findOneByPartner(int $id, Partner $partner): ?WazeAlert
    {
        return $this->createQueryBuilder('a')
            ->where('a.id = :id')->setParameter('id', $id)
            ->andWhere('a.partner = :p')->setParameter('p', $partner)
            ->getQuery()->getOneOrNullResult();
    }

    public function findRecentByPartner(Partner $partner, int $limit = 10): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.partner = :p')
            ->setParameter('p', $partner)
            ->orderBy('a.pubMillis', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    /**
     * Alertas "ao vivo" — últimas N horas, para o mapa.
     */
    public function findLiveByPartner(Partner $partner, int $hours = 3): array
    {
        $sinceMs = (new \DateTimeImmutable("-{$hours} hours"))->getTimestamp() * 1000;

        return $this->createQueryBuilder('a')
            ->where('a.partner = :p')
            ->setParameter('p', $partner)
            ->andWhere('a.pubMillis >= :since')
            ->setParameter('since', $sinceMs)
            ->orderBy('a.pubMillis', 'DESC')
            ->setMaxResults(2000)
            ->getQuery()->getResult();
    }

    public function findActiveByPartner(Partner $partner): array
    {
        return $this->findLiveByPartner($partner, 3);
    }

    // ── agregações para filtros / charts ─────────────────────────────────────────────

    /** Lista de cidades distintas do parceiro */
    public function findDistinctCities(Partner $partner): array
    {
        $rows = $this->createQueryBuilder('a')
            ->select('DISTINCT a.city')
            ->where('a.partner = :p')
            ->setParameter('p', $partner)
            ->andWhere('a.city IS NOT NULL')
            ->orderBy('a.city', 'ASC')
            ->getQuery()->getArrayResult();

        return array_column($rows, 'city');
    }

    /** Lista de tipos distintos do parceiro */
    public function findDistinctTypes(Partner $partner): array
    {
        $rows = $this->createQueryBuilder('a')
            ->select('DISTINCT a.type')
            ->where('a.partner = :p')
            ->setParameter('p', $partner)
            ->orderBy('a.type', 'ASC')
            ->getQuery()->getArrayResult();

        return array_column($rows, 'type');
    }

    /** Lista de subtipos distintos, opcionalmente filtrada por tipo */
    public function findDistinctSubtypes(Partner $partner, ?string $type = null): array
    {
        $qb = $this->createQueryBuilder('a')
            ->select('DISTINCT a.subtype')
            ->where('a.partner = :p')
            ->setParameter('p', $partner)
            ->andWhere('a.subtype IS NOT NULL')
            ->andWhere('a.subtype != :empty')
            ->setParameter('empty', '')
            ->orderBy('a.subtype', 'ASC');

        if ($type) {
            $qb->andWhere('a.type = :type')->setParameter('type', $type);
        }

        return array_column($qb->getQuery()->getArrayResult(), 'subtype');
    }

    /** Contagem por tipo de alerta: [['type' => 'ACCIDENT', 'total' => 42], ...] */
    public function countGroupByType(Partner $partner): array
    {
        return $this->createQueryBuilder('a')
            ->select('a.type, COUNT(a.id) AS total')
            ->where('a.partner = :p')
            ->setParameter('p', $partner)
            ->groupBy('a.type')
            ->orderBy('total', 'DESC')
            ->getQuery()->getArrayResult();
    }

    /** Top N subtipos: [['subtype' => 'HAZARD_ON_ROAD', 'total' => 15], ...] */
    public function countGroupBySubtype(Partner $partner, int $limit = 8): array
    {
        return $this->createQueryBuilder('a')
            ->select('a.subtype, COUNT(a.id) AS total')
            ->where('a.partner = :p')
            ->setParameter('p', $partner)
            ->andWhere('a.subtype IS NOT NULL')
            ->andWhere('a.subtype != :empty')
            ->setParameter('empty', '')
            ->groupBy('a.subtype')
            ->orderBy('total', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getArrayResult();
    }

    /**
     * Alertas por hora nas últimas 24h usando pubMillis.
     * Retorna array de ['hour' => int(0-23), 'total' => int] —
     * mesmo formato que WazeTrafficJamRepository::countPerHourLast24h.
     */
    public function countPerHourLast24h(Partner $partner): array
    {
        $sinceMs = (new \DateTimeImmutable('-24 hours'))->getTimestamp() * 1000;

        $conn = $this->getEntityManager()->getConnection();
        $sql  = '
            SELECT HOUR(FROM_UNIXTIME(pub_millis / 1000)) AS hour,
                   COUNT(*) AS total
            FROM waze_alerts
            WHERE partner_id = :pid
              AND pub_millis >= :since
            GROUP BY hour
            ORDER BY hour ASC
        ';
        $rows = $conn->executeQuery($sql, [
            'pid'   => $partner->getId(),
            'since' => $sinceMs,
        ])->fetchAllAssociative();

        return array_map(static fn(array $r) => [
            'hour'  => (int) $r['hour'],
            'total' => (int) $r['total'],
        ], $rows);
    }

    /**
     * Alertas agrupados por cidade (região) para o mapa ao vivo.
     */
    public function findLiveGroupedByRegion(Partner $partner, int $hours = 3): array
    {
        $alerts = $this->findLiveByPartner($partner, $hours);

        $regions = [];
        foreach ($alerts as $alert) {
            $key = $alert->getCity() ?? 'Desconhecido';
            if (!isset($regions[$key])) {
                $regions[$key] = [
                    'city'   => $key,
                    'count'  => 0,
                    'lat'    => $alert->getLatitude(),
                    'lng'    => $alert->getLongitude(),
                    'types'  => [],
                    'alerts' => [],
                ];
            }
            $regions[$key]['count']++;
            $t = $alert->getType();
            $regions[$key]['types'][$t] = ($regions[$key]['types'][$t] ?? 0) + 1;
            if (count($regions[$key]['alerts']) < 5) {
                $regions[$key]['alerts'][] = [
                    'id'      => $alert->getId(),
                    'type'    => $alert->getType(),
                    'subtype' => $alert->getSubtype(),
                    'street'  => $alert->getStreet(),
                    'conf'    => $alert->getConfidence(),
                ];
            }
        }

        usort($regions, fn($a, $b) => $b['count'] <=> $a['count']);
        return array_values($regions);
    }
}
