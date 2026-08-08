<?php

namespace App\Repository;

use App\Entity\Partner;
use App\Entity\WazeTrafficJam;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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

    // ── Filtro por período (usa pub_millis, indexado em idx_jam_partner_pub) ──

    private static function toMillis(\DateTimeInterface $dt): int
    {
        return (int) $dt->getTimestamp() * 1000;
    }

    public function countInPeriod(Partner $partner, \DateTimeInterface $from, \DateTimeInterface $to): int
    {
        return (int) $this->createQueryBuilder('j')
            ->select('COUNT(j.id)')
            ->where('j.partner = :partner')
            ->andWhere('j.pubMillis BETWEEN :from AND :to')
            ->setParameter('partner', $partner)
            ->setParameter('from', self::toMillis($from))
            ->setParameter('to', self::toMillis($to))
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Contagem de congestionamentos por nível (0=livre .. 5=parado) no período.
     * Sempre retorna as 6 chaves (0..5), mesmo com total 0, para manter a
     * escala do gráfico estável entre períodos diferentes.
     *
     * @return array<int, int> nível => total
     */
    public function countByLevelInPeriod(Partner $partner, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $rows = $this->createQueryBuilder('j')
            ->select('j.level AS level, COUNT(j.id) AS total')
            ->where('j.partner = :partner')
            ->andWhere('j.pubMillis BETWEEN :from AND :to')
            ->andWhere('j.level IS NOT NULL')
            ->setParameter('partner', $partner)
            ->setParameter('from', self::toMillis($from))
            ->setParameter('to', self::toMillis($to))
            ->groupBy('j.level')
            ->getQuery()
            ->getArrayResult();

        $byLevel = array_fill(0, 6, 0);
        foreach ($rows as $r) {
            $level = (int) $r['level'];
            if ($level >= 0 && $level <= 5) {
                $byLevel[$level] = (int) $r['total'];
            }
        }

        return $byLevel;
    }

    /**
     * Ranking das vias com mais ocorrências de congestionamento no período.
     * Retorna [['street'=>, 'city'=>, 'occurrences'=>, 'avgLevel'=>, 'maxLevel'=>, 'avgDelay'=>], ...]
     */
    public function topStreetsInPeriod(Partner $partner, \DateTimeInterface $from, \DateTimeInterface $to, int $limit = 12): array
    {
        $limit = max(1, $limit);

        $rows = $this->createQueryBuilder('j')
            ->select(
                'j.street AS street',
                'j.city AS city',
                'COUNT(j.id) AS occurrences',
                'AVG(j.level) AS avgLevel',
                'MAX(j.level) AS maxLevel',
                'AVG(j.delay) AS avgDelay',
            )
            ->where('j.partner = :partner')
            ->andWhere('j.pubMillis BETWEEN :from AND :to')
            ->andWhere('j.street IS NOT NULL')
            ->setParameter('partner', $partner)
            ->setParameter('from', self::toMillis($from))
            ->setParameter('to', self::toMillis($to))
            ->groupBy('j.street, j.city')
            ->orderBy('occurrences', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn(array $r) => [
            'street'      => $r['street'],
            'city'        => $r['city'],
            'occurrences' => (int) $r['occurrences'],
            'avgLevel'    => round((float) $r['avgLevel'], 1),
            'maxLevel'    => (int) $r['maxLevel'],
            'avgDelay'    => round((float) $r['avgDelay']),
        ], $rows);
    }

    /**
     * Congestionamentos com geometria (line) dentro do período, para o mapa.
     * Limitado a $limit registros mais recentes.
     */
    public function findForMapInPeriod(Partner $partner, \DateTimeInterface $from, \DateTimeInterface $to, int $limit = 400): array
    {
        return $this->createQueryBuilder('j')
            ->select('j.id, j.street, j.city, j.level, j.speedKmh, j.delay, j.line, j.pubMillis')
            ->where('j.partner = :partner')
            ->andWhere('j.pubMillis BETWEEN :from AND :to')
            ->setParameter('partner', $partner)
            ->setParameter('from', self::toMillis($from))
            ->setParameter('to', self::toMillis($to))
            ->orderBy('j.pubMillis', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();
    }

    public function getJamsPerHourLast24h(): array
    {
        $now = time() * 1000;
        $lastDay = $now - 24 * 3600 * 1000;

        $conn = $this->getEntityManager()->getConnection();

        $sql = <<<SQL
            SELECT
                CONCAT(DATE_FORMAT(FROM_UNIXTIME(j.pub_millis / 1000), '%H'), 'h') AS hour_label,
                COUNT(j.id) AS total
            FROM waze_traffic_jams j
            WHERE j.pub_millis >= :lastDay
            GROUP BY hour_label
            ORDER BY hour_label ASC
        SQL;

        return $conn->fetchAllAssociative($sql, [
            'lastDay' => $lastDay,
        ]);
    }
}
