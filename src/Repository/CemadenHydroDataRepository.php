<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CemadenHydroData;
use App\Entity\Partner;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;

/**
 * @extends ServiceEntityRepository<CemadenHydroData>
 */
class CemadenHydroDataRepository extends ServiceEntityRepository
{
    private LoggerInterface $logger;

    public function __construct(ManagerRegistry $registry, LoggerInterface $logger)
    {
        parent::__construct($registry, CemadenHydroData::class);
        $this->logger = $logger;
    }

    // ── Métodos existentes (mantidos) ────────────────────────────────

    public function countByPartner(Partner $partner): int
    {
        return (int) $this->createQueryBuilder('h')
            ->select('COUNT(h.id)')
            ->where('h.partner = :p')->setParameter('p', $partner)
            ->getQuery()->getSingleScalarResult();
    }

    public function countDistinctMunicipalities(Partner $partner): int
    {
        return (int) $this->createQueryBuilder('h')
            ->select('COUNT(DISTINCT h.municipality)')
            ->where('h.partner = :p')->setParameter('p', $partner)
            ->getQuery()->getSingleScalarResult();
    }

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
            'critical'     => 0,
            'stations'     => [],
        ];

        foreach ($latest as $h) {
            $status = $h->getAlertLevel() ?? 'normal';
            if (isset($summary[$status])) {
                $summary[$status]++;
            }

            $overflow = $h->getCotaTransbordamento();
            $current  = $h->getWaterLevel();
            $pct = ($overflow && $overflow > 0)
                ? round($current / $overflow * 100, 1)
                : null;

            $summary['stations'][] = [
                'id'             => $h->getId(),
                'name'           => $h->getStationName(),
                'river'          => null,
                'city'           => $h->getMunicipality(),
                'status'         => $status,
                'level'          => $current,
                'overflow_level' => $overflow,
                'overflow_pct'   => $pct,
                'collected_at'   => $h->getMeasuredAt()?->format('Y-m-d H:i'),
            ];
        }

        $summary['critical'] = $summary['alert'] + $summary['flood'] + $summary['overflow'];
        return $summary;
    }

    public function findCriticalByPartner(Partner $partner): array
    {
        $latest = $this->findLatestReadingsByPartner($partner);
        return array_filter($latest, static fn($h) => in_array($h->getAlertLevel(), ['alerta', 'transbordamento'], true));
    }

    public function levelSeriesByStation(int $stationId, int $hours = 48): array
    {
        $since = new \DateTimeImmutable("-{$hours} hours");

        $rows = $this->createQueryBuilder('h')
            ->select('h.waterLevel AS level, h.measuredAt AS collected_at')
            ->where('h.id = :station')
            ->andWhere('h.measuredAt >= :since')
            ->setParameter('station', $stationId)
            ->setParameter('since', $since)
            ->orderBy('h.measuredAt', 'ASC')
            ->getQuery()->getArrayResult();

        return array_map(static fn($r) => [
            'level'        => (float)$r['level'],
            'collected_at' => $r['collected_at'] instanceof \DateTimeInterface
                ? $r['collected_at']->format('Y-m-d H:i')
                : (string)$r['collected_at'],
        ], $rows);
    }

    public function statusBreakdownByPartner(Partner $partner): array
    {
        $latest = $this->findLatestReadingsByPartner($partner);
        $counts = [];
        foreach ($latest as $h) {
            $s = $h->getAlertLevel() ?? 'normal';
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
     * Retorna objetos CemadenHydroData da última leitura de cada estação.
     */
    public function findLatestReadingsByPartner(Partner $partner): array
    {
        $partnerId = $partner->getId();
        $this->logger->debug('[HydroRepo] findLatestReadingsByPartner chamado para partner ID: ' . $partnerId);

        $conn = $this->getEntityManager()->getConnection();
        $sql = '
            SELECT h.id
            FROM cemaden_hydro_data h
            INNER JOIN (
                SELECT station_code, MAX(measured_at) AS max_dt
                FROM cemaden_hydro_data
                WHERE partner_id = ?
                GROUP BY station_code
            ) latest ON h.station_code = latest.station_code
                    AND h.measured_at = latest.max_dt
            WHERE h.partner_id = ?
        ';

        $ids = $conn->executeQuery($sql, [$partnerId, $partnerId])
            ->fetchFirstColumn();

        $this->logger->debug('[HydroRepo] findLatestReadingsByPartner encontrou ' . count($ids) . ' IDs.');

        if (empty($ids)) {
            return [];
        }

        $result = $this->createQueryBuilder('h')
            ->where('h.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('h.alertLevel', 'DESC')
            ->addOrderBy('h.stationName', 'ASC')
            ->getQuery()
            ->getResult();

        $this->logger->debug('[HydroRepo] findLatestReadingsByPartner retornou ' . count($result) . ' objetos.');

        return $result;
    }

    // ── NOVOS MÉTODOS PARA O HydroController ───────────────────────────

    /**
     * Retorna um array com a última leitura de cada estação do parceiro,
     * com as chaves em snake_case para uso direto nos templates e JSON.
     */
    public function findLatestByPartner(Partner $partner): array
    {
        $partnerId = $partner->getId();
        $this->logger->info('[HydroRepo] findLatestByPartner chamado para partner ID: ' . $partnerId);

        $conn = $this->getEntityManager()->getConnection();
        $sql = '
            SELECT
                h.station_code,
                h.station_name,
                h.municipality,
                h.state,
                h.water_level,
                h.alert_level,
                h.cota_atencao,
                h.cota_alerta,
                h.cota_transbordamento,
                h.measured_at
            FROM cemaden_hydro_data h
            INNER JOIN (
                SELECT station_code, MAX(measured_at) AS max_dt
                FROM cemaden_hydro_data
                WHERE partner_id = ?
                GROUP BY station_code
            ) latest ON h.station_code = latest.station_code
                    AND h.measured_at = latest.max_dt
            WHERE h.partner_id = ?
            ORDER BY h.municipality, h.station_name
        ';

        $result = $conn->fetchAllAssociative($sql, [$partnerId, $partnerId]);
        $this->logger->info('[HydroRepo] findLatestByPartner retornou ' . count($result) . ' registros.');

        return $result;
    }

    /**
     * Lista paginada de registros históricos com filtros.
     * Retorna um array com duas posições: [dados, total].
     */
    public function findHistorico(
        Partner $partner,
        ?string $stationCode,
        ?string $alertLevel,
        string $dateFrom,
        string $dateTo,
        int $page,
        int $perPage
    ): array {
        $this->logger->info('[HydroRepo] findHistorico chamado com filtros: ' . json_encode([
            'partnerId' => $partner->getId(),
            'stationCode' => $stationCode,
            'alertLevel' => $alertLevel,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'page' => $page,
            'perPage' => $perPage,
        ]));

        $qb = $this->createQueryBuilder('h')
            ->select('
                h.stationCode,
                h.stationName,
                h.municipality,
                h.state,
                h.waterLevel,
                h.alertLevel,
                h.cotaAtencao,
                h.cotaAlerta,
                h.cotaTransbordamento,
                h.measuredAt
            ')
            ->where('h.partner = :partner')
            ->setParameter('partner', $partner)
            ->andWhere('h.measuredAt BETWEEN :from AND :to')
            ->setParameter('from', new \DateTimeImmutable($dateFrom . ' 00:00:00'))
            ->setParameter('to',   new \DateTimeImmutable($dateTo   . ' 23:59:59'));

        if ($stationCode) {
            $qb->andWhere('h.stationCode = :station')
               ->setParameter('station', $stationCode);
        }
        if ($alertLevel) {
            $qb->andWhere('h.alertLevel = :level')
               ->setParameter('level', $alertLevel);
        }

        // Total de resultados
        $totalQb = clone $qb;
        $totalQb->select('COUNT(h.id)');
        $total = (int) $totalQb->getQuery()->getSingleScalarResult();

        // Paginação
        $qb->orderBy('h.measuredAt', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);

        $rows = $qb->getQuery()->getArrayResult();

        // Converte para snake_case
        $result = array_map(static function ($row) {
            return [
                'station_code'           => $row['stationCode'],
                'station_name'           => $row['stationName'],
                'municipality'           => $row['municipality'],
                'state'                  => $row['state'],
                'water_level'            => $row['waterLevel'],
                'alert_level'            => $row['alertLevel'],
                'cota_atencao'           => $row['cotaAtencao'],
                'cota_alerta'            => $row['cotaAlerta'],
                'cota_transbordamento'   => $row['cotaTransbordamento'],
                'measured_at'            => $row['measuredAt'] instanceof \DateTimeInterface
                    ? $row['measuredAt']->format('Y-m-d H:i:s')
                    : $row['measuredAt'],
            ];
        }, $rows);

        $this->logger->info('[HydroRepo] findHistorico retornou ' . count($result) . ' registros de um total de ' . $total);

        return [$result, $total];
    }

    /**
     * Lista de estações (código, nome, município, estado) do parceiro
     * para uso no filtro de estação.
     */
    public function findStationsByPartner(Partner $partner): array
    {
        $this->logger->debug('[HydroRepo] findStationsByPartner chamado para partner ID: ' . $partner->getId());

        $qb = $this->createQueryBuilder('h')
            ->select('
                h.stationCode,
                h.stationName,
                h.municipality,
                h.state
            ')
            ->where('h.partner = :partner')
            ->setParameter('partner', $partner)
            ->groupBy('h.stationCode')
            ->orderBy('h.municipality, h.stationName');

        $rows = $qb->getQuery()->getArrayResult();

        $result = array_map(static function ($row) {
            return [
                'station_code' => $row['stationCode'],
                'station_name' => $row['stationName'],
                'municipality' => $row['municipality'],
                'state'        => $row['state'],
            ];
        }, $rows);

        $this->logger->debug('[HydroRepo] findStationsByPartner retornou ' . count($result) . ' estações.');

        return $result;
    }
}
