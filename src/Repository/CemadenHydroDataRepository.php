<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CemadenHydroData;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CemadenHydroData>
 *
 * Lê dados de cemaden_hydro_readings (tabela de leituras coletadas pelo
 * comando cemaden:collect-hydro), cruzando com cemaden_stations para
 * filtrar por parceiro e obter metadados da esta\u00e7\u00e3o.
 *
 * Estrutura relevante:
 *   cemaden_hydro_readings: id, station_id, measured_at, sensor_value,
 *                           offset_value, river_level, is_offline, created_at
 *   cemaden_stations:       id, cod_estacao, nome, municipio, uf,
 *                           partner_id(*), partner_slug, cota_atencao,
 *                           cota_alerta, cota_transbordamento
 *
 * (*) partner_id pode ser null se a esta\u00e7\u00e3o n\u00e3o tiver parceiro associado.
 *     Estações sem partner_id não aparecem nos resultados.
 */
class CemadenHydroDataRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CemadenHydroData::class);
    }

    // -------------------------------------------------------------------------
    // Tela AO VIVO
    // -------------------------------------------------------------------------

    /**
     * Última leitura de cada esta\u00e7\u00e3o do parceiro.
     * Retorna colunas compatíveis com o que o HydroController / live.html.twig espera:
     *   station_code, station_name, municipality, state,
     *   water_level (alias de river_level), alert_level,
     *   cota_atencao, cota_alerta, cota_transbordamento,
     *   measured_at, is_offline
     */
    public function findLatestByPartner(int $partnerId): array
    {
        $conn = $this->getEntityManager()->getConnection();

        return $conn->fetchAllAssociative(
            "SELECT
                 s.cod_estacao          AS station_code,
                 s.nome                 AS station_name,
                 s.municipio            AS municipality,
                 s.uf                   AS state,
                 r.river_level          AS water_level,
                 r.is_offline,
                 r.measured_at,
                 s.cota_atencao,
                 s.cota_alerta,
                 s.cota_transbordamento,
                 CASE
                     WHEN r.is_offline = 1                              THEN 'offline'
                     WHEN r.river_level IS NULL                         THEN 'sem_dado'
                     WHEN s.cota_transbordamento IS NOT NULL
                          AND r.river_level >= s.cota_transbordamento   THEN 'transbordamento'
                     WHEN s.cota_alerta IS NOT NULL
                          AND r.river_level >= s.cota_alerta            THEN 'alerta'
                     WHEN s.cota_atencao IS NOT NULL
                          AND r.river_level >= s.cota_atencao           THEN 'atencao'
                     ELSE 'normal'
                 END AS alert_level
             FROM cemaden_hydro_readings r
             INNER JOIN cemaden_stations s ON s.id = r.station_id
             INNER JOIN (
                 SELECT station_id, MAX(measured_at) AS max_at
                 FROM cemaden_hydro_readings
                 GROUP BY station_id
             ) latest ON latest.station_id = r.station_id
                      AND latest.max_at    = r.measured_at
             WHERE s.partner_id = :pid
               AND s.is_active  = 1
             ORDER BY alert_level DESC, s.municipio, s.nome",
            ['pid' => $partnerId],
        );
    }

    // -------------------------------------------------------------------------
    // Histórico
    // -------------------------------------------------------------------------

    /**
     * Histórico paginado com filtros por esta\u00e7\u00e3o, nível de alerta e intervalo de datas.
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
        $where  = ['s.partner_id = :pid', 's.is_active = 1'];
        $params = ['pid' => $partnerId];

        if ($stationCode) {
            $where[]      = 's.cod_estacao = :sc';
            $params['sc'] = $stationCode;
        }

        $where[]       = "DATE(r.measured_at) BETWEEN :df AND :dt";
        $params['df']  = $dateFrom;
        $params['dt']  = $dateTo;

        // Calcula alert_level inline para poder filtrar por ele
        $alertExpr = "CASE
            WHEN r.is_offline = 1                                       THEN 'offline'
            WHEN r.river_level IS NULL                                   THEN 'sem_dado'
            WHEN s.cota_transbordamento IS NOT NULL
                 AND r.river_level >= s.cota_transbordamento             THEN 'transbordamento'
            WHEN s.cota_alerta IS NOT NULL
                 AND r.river_level >= s.cota_alerta                      THEN 'alerta'
            WHEN s.cota_atencao IS NOT NULL
                 AND r.river_level >= s.cota_atencao                     THEN 'atencao'
            ELSE 'normal'
        END";

        $whereClause = implode(' AND ', $where);

        // Subquery para filtrar alert_level se necessário
        $havingClause = '';
        if ($alertLevel) {
            $havingClause    = "HAVING alert_level = :lv";
            $params['lv']    = $alertLevel;
        }

        $baseQuery = "FROM cemaden_hydro_readings r
                      INNER JOIN cemaden_stations s ON s.id = r.station_id
                      WHERE {$whereClause}";

        $total = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM (
                SELECT 1, {$alertExpr} AS alert_level
                {$baseQuery}
                {$havingClause}
             ) sub",
            $params,
        );

        $offset = ($page - 1) * $perPage;
        $rows   = $conn->fetchAllAssociative(
            "SELECT
                 s.cod_estacao              AS station_code,
                 s.nome                     AS station_name,
                 s.municipio                AS municipality,
                 s.uf                       AS state,
                 r.river_level              AS water_level,
                 r.sensor_value,
                 r.offset_value,
                 r.is_offline,
                 r.measured_at,
                 s.cota_atencao,
                 s.cota_alerta,
                 s.cota_transbordamento,
                 {$alertExpr}               AS alert_level
             {$baseQuery}
             {$havingClause}
             ORDER BY r.measured_at DESC, s.nome
             LIMIT {$perPage} OFFSET {$offset}",
            $params,
        );

        return [$rows, $total];
    }

    // -------------------------------------------------------------------------
    // Listas auxiliares
    // -------------------------------------------------------------------------

    /**
     * Esta\u00e7\u00f5es distintas do parceiro com leituras registradas
     * (para o filtro da tela de histórico).
     */
    public function findStationsByPartner(int $partnerId): array
    {
        $conn = $this->getEntityManager()->getConnection();

        return $conn->fetchAllAssociative(
            "SELECT DISTINCT
                 s.cod_estacao AS station_code,
                 s.nome        AS station_name,
                 s.municipio   AS municipality,
                 s.uf          AS state
             FROM cemaden_hydro_readings r
             INNER JOIN cemaden_stations s ON s.id = r.station_id
             WHERE s.partner_id = :pid
               AND s.is_active  = 1
             ORDER BY s.municipio, s.nome",
            ['pid' => $partnerId],
        );
    }
}
