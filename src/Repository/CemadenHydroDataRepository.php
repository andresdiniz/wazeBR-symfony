<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CemadenHydroData;
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

    // -------------------------------------------------------------------------
    // Idempotência
    // -------------------------------------------------------------------------

    /**
     * Verifica se já existe uma leitura para a estação no instante informado.
     * Usado pelo FetchCemadenHydroHandler para evitar duplicatas.
     *
     * Consulta cemaden_hydro_data (tabela mapeada pela entity CemadenHydroData,
     * com colunas station_code e measured_at).
     */
    public function existsByStationAndTime(string $stationCode, \DateTimeImmutable $measuredAt): bool
    {
        return (bool) $this->getEntityManager()
            ->getConnection()
            ->fetchOne(
                'SELECT 1
                 FROM cemaden_hydro_data
                 WHERE station_code = ?
                   AND measured_at  = ?
                 LIMIT 1',
                [$stationCode, $measuredAt->format('Y-m-d H:i:s')],
            );
    }

    // -------------------------------------------------------------------------
    // Tela AO VIVO
    // -------------------------------------------------------------------------

    /**
     * Última leitura de cada estação do parceiro.
     */
    public function findLatestByPartner(int $partnerId): array
    {
        $conn = $this->getEntityManager()->getConnection();

        return $conn->fetchAllAssociative(
            "SELECT
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
                 SELECT station_code, MAX(measured_at) AS max_at
                 FROM cemaden_hydro_data
                 GROUP BY station_code
             ) latest ON latest.station_code = h.station_code
                      AND latest.max_at      = h.measured_at
             INNER JOIN partner p ON p.id = h.partner_id
             WHERE h.partner_id = :pid
             ORDER BY h.alert_level DESC, h.municipality, h.station_name",
            ['pid' => $partnerId],
        );
    }

    // -------------------------------------------------------------------------
    // Histórico
    // -------------------------------------------------------------------------

    /**
     * Histórico paginado com filtros por estação, nível de alerta e intervalo de datas.
     *
     * @return array{0: array, 1: int}  [rows, total]
     */
    public function findHistorico(
        int     $partnerId,
        ?string $stationCode,
        ?string $alertLevel,
        string  $dateFrom,
        string  $dateTo,
        int     $page,
        int     $perPage,
    ): array {
        $conn   = $this->getEntityManager()->getConnection();
        $where  = ['h.partner_id = :pid'];
        $params = ['pid' => $partnerId];

        if ($stationCode) {
            $where[]      = 'h.station_code = :sc';
            $params['sc'] = $stationCode;
        }

        if ($alertLevel) {
            $where[]      = 'h.alert_level = :lv';
            $params['lv'] = $alertLevel;
        }

        $where[]       = 'DATE(h.measured_at) BETWEEN :df AND :dt';
        $params['df']  = $dateFrom;
        $params['dt']  = $dateTo;

        $whereClause = implode(' AND ', $where);

        $total = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM cemaden_hydro_data h WHERE {$whereClause}",
            $params,
        );

        $offset = ($page - 1) * $perPage;
        $rows   = $conn->fetchAllAssociative(
            "SELECT
                 h.station_code,
                 h.station_name,
                 h.municipality,
                 h.state,
                 h.water_level,
                 h.offset_value,
                 h.qualificacao,
                 h.alert_level,
                 h.cota_atencao,
                 h.cota_alerta,
                 h.cota_transbordamento,
                 h.measured_at
             FROM cemaden_hydro_data h
             WHERE {$whereClause}
             ORDER BY h.measured_at DESC, h.station_name
             LIMIT {$perPage} OFFSET {$offset}",
            $params,
        );

        return [$rows, $total];
    }

    // -------------------------------------------------------------------------
    // Listas auxiliares
    // -------------------------------------------------------------------------

    /**
     * Estações distintas do parceiro com leituras registradas
     * (para o filtro da tela de histórico).
     */
    public function findStationsByPartner(int $partnerId): array
    {
        $conn = $this->getEntityManager()->getConnection();

        return $conn->fetchAllAssociative(
            'SELECT DISTINCT
                 station_code,
                 station_name,
                 municipality,
                 state
             FROM cemaden_hydro_data
             WHERE partner_id = :pid
             ORDER BY municipality, station_name',
            ['pid' => $partnerId],
        );
    }
}
