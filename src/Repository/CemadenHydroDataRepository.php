<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CemadenHydroData;
use App\Entity\Partner;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CemadenHydroData>
 */
class CemadenHydroDataRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CemadenHydroData::class);
    }

    // ── KPI: sumário geral ─────────────────────────────────────────────────

    /**
     * Sumário de KPIs hidrológicos para o partner.
     * Retorna array com totais e breakdown por status.
     */
    public function kpiSummaryByPartner(Partner $partner): array
    {
        $latest = $this->findLatestReadingsByPartner($partner);

        $summary = [
            'total'        => count($latest),
            'normal'       => 0,
            'attention'    => 0,
            'alert'        => 0,
            'flood'        => 0,
            'overflow'     => 0,
            'critical'     => 0, // alert + flood + overflow
            'stations'     => [],
        ];

        foreach ($latest as $h) {
            $status = $h->getStatus() ?? 'normal';
            if (isset($summary[$status])) {
                $summary[$status]++;
            }

            $overflow = $h->getOverflowLevel();
            $current  = $h->getLevel();
            $pct = ($overflow && $overflow > 0)
                ? round($current / $overflow * 100, 1)
                : null;

            $summary['stations'][] = [
                'id'             => $h->getId(),
                'name'           => $h->getStationName(),
                'river'          => $h->getRiverName(),
                'city'           => $h->getCity(),
                'status'         => $status,
                'level'          => $current,
                'overflow_level' => $overflow,
                'overflow_pct'   => $pct,
                'collected_at'   => $h->getCollectedAt()?->format('Y-m-d H:i'),
            ];
        }

        $summary['critical'] = $summary['alert'] + $summary['flood'] + $summary['overflow'];
        return $summary;
    }

    /**
     * Estações em estado crítico (alert|flood|overflow) agora.
     */
    public function findCriticalByPartner(Partner $partner): array
    {
        $latest = $this->findLatestReadingsByPartner($partner);
        return array_filter($latest, static fn($h) => in_array($h->getStatus(), ['alert', 'flood', 'overflow'], true));
    }

    /**
     * Série temporal de cota de uma estação específica.
     * Retorna [['level'=>2.45,'collected_at'=>'2026-07-20 08:00'], ...]
     */
    public function levelSeriesByStation(int $stationId, int $hours = 48): array
    {
        $since = new \DateTimeImmutable("-{$hours} hours");

        $rows = $this->createQueryBuilder('h')
            ->select('h.level AS level, h.collectedAt AS collected_at')
            ->where('h.id = :station')
            ->andWhere('h.collectedAt >= :since')
            ->setParameter('station', $stationId)
            ->setParameter('since', $since)
            ->orderBy('h.collectedAt', 'ASC')
            ->getQuery()->getArrayResult();

        return array_map(static fn($r) => [
            'level'        => (float)$r['level'],
            'collected_at' => $r['collected_at'] instanceof \DateTimeInterface
                ? $r['collected_at']->format('Y-m-d H:i')
                : (string)$r['collected_at'],
        ], $rows);
    }

    /**
     * Distribuição de status entre todas as estações do partner.
     * Retorna [['status'=>'normal','total'=>8], ...]
     */
    public function statusBreakdownByPartner(Partner $partner): array
    {
        $latest = $this->findLatestReadingsByPartner($partner);
        $counts = [];
        foreach ($latest as $h) {
            $s = $h->getStatus() ?? 'normal';
            $counts[$s] = ($counts[$s] ?? 0) + 1;
        }
        arsort($counts);
        $result = [];
        foreach ($counts as $status => $total) {
            $result[] = ['status' => $status, 'total' => $total];
        }
        return $result;
    }

    /**
     * Leitura mais recente de cada estação do partner.
     * Usa subquery para pegar o MAX(collectedAt) por stationCode.
     *
     * Nota: o MariaDB/PDO não suporta reutilizar o mesmo named parameter
     * dentro de subqueries na mesma query. Por isso usamos :partner1 e :partner2.
     */
    public function findLatestReadingsByPartner(Partner $partner): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $sql = '
            SELECT h.id
            FROM cemaden_hydro_data h
            INNER JOIN (
                SELECT station_code, MAX(collected_at) AS max_dt
                FROM cemaden_hydro_data
                WHERE partner_id = :partner1
                GROUP BY station_code
            ) latest ON h.station_code = latest.station_code
                    AND h.collected_at = latest.max_dt
            WHERE h.partner_id = :partner2
        ';

        $ids = $conn->prepare($sql)
            ->executeQuery([
                'partner1' => $partner->getId(),
                'partner2' => $partner->getId(),
            ])
            ->fetchFirstColumn();

        if (empty($ids)) {
            return [];
        }

        return $this->createQueryBuilder('h')
            ->where('h.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('h.status', 'DESC')
            ->addOrderBy('h.stationName', 'ASC')
            ->getQuery()->getResult();
    }
}
