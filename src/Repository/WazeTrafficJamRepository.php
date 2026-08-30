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

    /**
     * Congestionamentos ativos (entidades completas, com geometria) nas
     * últimas $hours horas — usado pelas telas "ao vivo" (operador,
     * mapa de congestionamentos, resumo ao vivo).
     *
     * @return WazeTrafficJam[]
     */
    public function findLiveByPartner(Partner $partner, int $hours = 3, int $limit = 500): array
    {
        $since = self::toMillis(new \DateTimeImmutable("-{$hours} hours"));

        return $this->createQueryBuilder('j')
            ->where('j.partner = :partner')
            ->andWhere('j.pubMillis >= :since')
            ->setParameter('partner', $partner)
            ->setParameter('since', $since)
            ->orderBy('j.pubMillis', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Médias/soma dos congestionamentos ao vivo (últimas $hours horas):
     * velocidade média, atraso médio e extensão total congestionada.
     *
     * @return array{avgSpeed: ?float, avgDelay: ?float, totalLength: float}
     */
    public function avgStats(Partner $partner, int $hours = 3): array
    {
        $since = self::toMillis(new \DateTimeImmutable("-{$hours} hours"));

        $rows = $this->createQueryBuilder('j')
            ->select(
                'AVG(j.speedKmh) AS avgSpeed',
                'AVG(j.delay) AS avgDelay',
                'SUM(j.length) AS totalLength',
            )
            ->where('j.partner = :partner')
            ->andWhere('j.pubMillis >= :since')
            ->setParameter('partner', $partner)
            ->setParameter('since', $since)
            ->getQuery()
            ->getArrayResult();

        $row = $rows[0] ?? [];

        return [
            'avgSpeed'    => $row['avgSpeed'] !== null ? round((float) $row['avgSpeed'], 1) : null,
            'avgDelay'    => $row['avgDelay'] !== null ? round((float) $row['avgDelay']) : null,
            'totalLength' => (float) ($row['totalLength'] ?? 0),
        ];
    }

    /**
     * Filtros comuns às telas de congestionamento: nível mínimo, cidade
     * (contém, case-insensitive), tipo exato e intervalo de datas (dia
     * inteiro, fuso America/Sao_Paulo) — mesmo padrão do WazeAlertRepository.
     */
    private function applyFilters(\Doctrine\ORM\QueryBuilder $qb, Partner $partner, ?int $minLevel, ?string $city, ?string $type, ?string $dateFrom, ?string $dateTo): void
    {
        $qb->andWhere('j.partner = :partner')->setParameter('partner', $partner);

        if ($minLevel !== null) {
            $qb->andWhere('j.level >= :minLevel')->setParameter('minLevel', $minLevel);
        }
        if ($city) {
            $qb->andWhere('LOWER(j.city) LIKE :city')->setParameter('city', '%' . mb_strtolower($city) . '%');
        }
        if ($type) {
            $qb->andWhere('j.type = :type')->setParameter('type', $type);
        }

        $timezone = new \DateTimeZone('America/Sao_Paulo');
        if ($dateFrom) {
            $from = new \DateTimeImmutable($dateFrom, $timezone);
            $qb->andWhere('j.pubMillis >= :dateFrom')->setParameter('dateFrom', self::toMillis($from->setTime(0, 0, 0)));
        }
        if ($dateTo) {
            $to = new \DateTimeImmutable($dateTo, $timezone);
            $qb->andWhere('j.pubMillis <= :dateTo')->setParameter('dateTo', self::toMillis($to->setTime(23, 59, 59)));
        }
    }

    /**
     * Histórico paginado com filtros — usado por TrafficJamController::index().
     *
     * @return array{items: WazeTrafficJam[], total: int, pages: int}
     */
    public function findFilteredByPartner(Partner $partner, ?int $minLevel = null, ?string $city = null, ?string $type = null, ?string $dateFrom = null, ?string $dateTo = null, int $page = 1, int $limit = 30): array
    {
        $base = $this->createQueryBuilder('j');
        $this->applyFilters($base, $partner, $minLevel, $city, $type, $dateFrom, $dateTo);

        $total = (int) (clone $base)->select('COUNT(j.id)')->getQuery()->getSingleScalarResult();
        $pages = max(1, (int) ceil($total / $limit));
        $page = min(max(1, $page), $pages);

        $items = $base->orderBy('j.pubMillis', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return ['items' => $items, 'total' => $total, 'pages' => $pages];
    }

    /** @return string[] */
    public function findDistinctCities(Partner $partner): array
    {
        return array_column(
            $this->createQueryBuilder('j')
                ->select('DISTINCT j.city AS city')
                ->where('j.partner = :partner')
                ->andWhere('j.city IS NOT NULL')
                ->setParameter('partner', $partner)
                ->orderBy('j.city')
                ->getQuery()
                ->getArrayResult(),
            'city'
        );
    }

    /** @return string[] */
    public function findDistinctTypes(Partner $partner): array
    {
        return array_column(
            $this->createQueryBuilder('j')
                ->select('DISTINCT j.type AS type')
                ->where('j.partner = :partner')
                ->andWhere('j.type IS NOT NULL')
                ->setParameter('partner', $partner)
                ->orderBy('j.type')
                ->getQuery()
                ->getArrayResult(),
            'type'
        );
    }

    public function findOneByPartner(int $id, Partner $partner): ?WazeTrafficJam
    {
        return $this->createQueryBuilder('j')
            ->where('j.id = :id')
            ->andWhere('j.partner = :partner')
            ->setParameter('id', $id)
            ->setParameter('partner', $partner)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function countByPartner(Partner $partner): int
    {
        return (int) $this->createQueryBuilder('j')
            ->select('COUNT(j.id)')
            ->where('j.partner = :partner')
            ->setParameter('partner', $partner)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Snapshot "ao vivo" dos congestionamentos reportados nas últimas $hours horas:
     * contagem, nível máximo, velocidade média, atraso médio e extensão somada.
     * Uma única query agregada (evita várias idas ao banco para o mesmo cartão).
     */
    public function liveSnapshot(Partner $partner, int $hours = 3): array
    {
        $since = self::toMillis(new \DateTimeImmutable("-{$hours} hours"));

        $rows = $this->createQueryBuilder('j')
            ->select(
                'COUNT(j.id) AS total',
                'MAX(j.level) AS maxLevel',
                'AVG(j.speedKmh) AS avgSpeed',
                'AVG(j.delay) AS avgDelay',
                'SUM(j.length) AS sumLength',
            )
            ->where('j.partner = :partner')
            ->andWhere('j.pubMillis >= :since')
            ->setParameter('partner', $partner)
            ->setParameter('since', $since)
            ->getQuery()
            ->getArrayResult();

        $row = $rows[0] ?? [];

        return [
            'total'       => (int) ($row['total'] ?? 0),
            'maxLevel'    => $row['maxLevel'] !== null ? (int) $row['maxLevel'] : null,
            'avgSpeedKmh' => $row['avgSpeed'] !== null ? round((float) $row['avgSpeed'], 1) : null,
            'avgDelaySec' => $row['avgDelay'] !== null ? round((float) $row['avgDelay']) : null,
            'lengthKm'    => round(((float) ($row['sumLength'] ?? 0)) / 1000, 1),
        ];
    }

    /**
     * Linha de base histórica (todo o período monitorado) para comparar com o "ao vivo".
     */
    public function historicalBaseline(Partner $partner): array
    {
        $rows = $this->createQueryBuilder('j')
            ->select(
                'AVG(j.speedKmh) AS avgSpeed',
                'SUM(j.length) AS sumLength',
            )
            ->where('j.partner = :partner')
            ->setParameter('partner', $partner)
            ->getQuery()
            ->getArrayResult();

        $row = $rows[0] ?? [];

        return [
            'avgSpeedKmh' => $row['avgSpeed'] !== null ? round((float) $row['avgSpeed'], 1) : null,
            'lengthKm'    => round(((float) ($row['sumLength'] ?? 0)) / 1000, 1),
        ];
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

    /**
     * ATENÇÃO — SEM FILTRO DE PARCEIRO (diferente do resto deste
     * repository). Usado por ApiController::traffic(), o mesmo endpoint
     * legado sem TenantContext citado em WazeAlertRepository::findFiltered().
     * Mesma recomendação: revisar antes de expor em produção.
     *
     * @return WazeTrafficJam[]
     */
    public function findFiltered(int $hours, ?string $city, ?int $level, int $limit = 500): array
    {
        $since = self::toMillis(new \DateTimeImmutable("-{$hours} hours"));

        $qb = $this->createQueryBuilder('j')
            ->where('j.pubMillis >= :since')
            ->setParameter('since', $since)
            ->orderBy('j.pubMillis', 'DESC')
            ->setMaxResults($limit);

        if ($city) {
            $qb->andWhere('LOWER(j.city) LIKE :city')->setParameter('city', '%' . mb_strtolower($city) . '%');
        }
        if ($level !== null) {
            $qb->andWhere('j.level = :level')->setParameter('level', $level);
        }

        return $qb->getQuery()->getResult();
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
