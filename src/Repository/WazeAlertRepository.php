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

    // ── Filtro por período (usa pub_millis, indexado em idx_partner_pub) ───────

    private static function toMillis(\DateTimeInterface $dt): int
    {
        return (int) $dt->getTimestamp() * 1000;
    }

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
     * Limitado a $limit registros (mais recentes primeiro); o cluster no
     * front-end agrupa visualmente os pontos quando o volume é grande.
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
        $now = time() * 1000;
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
