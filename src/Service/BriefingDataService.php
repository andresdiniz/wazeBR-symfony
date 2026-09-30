<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;

/**
 * Agrega os dados do dia anterior (em BRT = UTC-3) para o briefing diário.
 *
 * Todos os campos de data no banco estão em UTC.
 * A conversão CONVERT_TZ(campo, '+00:00', '-03:00') resolve o fuso de forma
 * transparente, sem depender do timezone do servidor MySQL ou do PHP.
 */
final class BriefingDataService
{
    public function __construct(private readonly Connection $connection) {}

    /**
     * Retorna o array de dados completo para um parceiro/cidade.
     *
     * @param string $cidade  Valor da coluna `cidade` nas tabelas (ex: "Conselheiro Lafaiete")
     * @param string $dataRef Data de referência no formato Y-m-d (BRT). Se null, usa ontem.
     */
    public function getForCity(string $cidade, ?string $dataRef = null): array
    {
        $dataRef ??= (new \DateTimeImmutable('yesterday', new \DateTimeZone('America/Sao_Paulo')))
            ->format('Y-m-d');

        return [
            'cidade'       => $cidade,
            'data_ref'     => $dataRef,
            'gerado_em'    => (new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo')))->format('d/m/Y H:i'),
            'jams'         => $this->getJamKpis($cidade, $dataRef),
            'alertas'      => $this->getAlertKpis($cidade, $dataRef),
            'interdicoes'  => $this->getInterdicoes($cidade, $dataRef),
            'top_ruas'     => $this->getTopRuas($cidade, $dataRef),
            'pico_hora'    => $this->getPicoHora($cidade, $dataRef),
            'media_7dias'  => $this->getMedia7Dias($cidade),
        ];
    }

    // ─── Congestionamentos ────────────────────────────────────────────────────

    private function getJamKpis(string $cidade, string $dataRef): array
    {
        $sql = <<<SQL
            SELECT
                COUNT(*)                                          AS total,
                ROUND(AVG(speed), 1)                             AS velocidade_media,
                ROUND(AVG(`delay`), 1)                           AS atraso_medio,
                ROUND(AVG(length) / 1000, 2)                     AS extensao_media_km,
                MAX(`delay`)                                      AS atraso_max,
                SUM(CASE WHEN level = 5 THEN 1 ELSE 0 END)      AS interdicoes,
                SUM(CASE WHEN level >= 4 THEN 1 ELSE 0 END)     AS nivel_alto_mais,
                SUM(CASE WHEN level BETWEEN 2 AND 3 THEN 1 ELSE 0 END) AS nivel_moderado
            FROM waze_traffic_jam
            WHERE DATE(CONVERT_TZ(pub_millis_dt, '+00:00', '-03:00')) = :data
              AND city = :cidade
        SQL;

        $row = $this->connection->fetchAssociative($sql, [
            'data'   => $dataRef,
            'cidade' => $cidade,
        ]);

        return $row ?: [
            'total' => 0, 'velocidade_media' => null, 'atraso_medio' => null,
            'extensao_media_km' => null, 'atraso_max' => null,
            'interdicoes' => 0, 'nivel_alto_mais' => 0, 'nivel_moderado' => 0,
        ];
    }

    private function getPicoHora(string $cidade, string $dataRef): array
    {
        $sql = <<<SQL
            SELECT
                HOUR(CONVERT_TZ(pub_millis_dt, '+00:00', '-03:00')) AS hora_brt,
                COUNT(*) AS total
            FROM waze_traffic_jam
            WHERE DATE(CONVERT_TZ(pub_millis_dt, '+00:00', '-03:00')) = :data
              AND city = :cidade
            GROUP BY hora_brt
            ORDER BY total DESC
            LIMIT 3
        SQL;

        return $this->connection->fetchAllAssociative($sql, [
            'data'   => $dataRef,
            'cidade' => $cidade,
        ]);
    }

    private function getTopRuas(string $cidade, string $dataRef): array
    {
        $sql = <<<SQL
            SELECT
                street                                  AS rua,
                COUNT(*)                                AS total_jams,
                ROUND(AVG(`delay`), 1)                  AS atraso_medio,
                ROUND(AVG(speed), 1)                    AS velocidade_media
            FROM waze_traffic_jam
            WHERE DATE(CONVERT_TZ(pub_millis_dt, '+00:00', '-03:00')) = :data
              AND city = :cidade
              AND street IS NOT NULL
              AND street != ''
            GROUP BY street
            ORDER BY total_jams DESC
            LIMIT 8
        SQL;

        return $this->connection->fetchAllAssociative($sql, [
            'data'   => $dataRef,
            'cidade' => $cidade,
        ]);
    }

    private function getMedia7Dias(string $cidade): array
    {
        // Média dos últimos 7 dias completos (excluindo hoje e ontem já em análise)
        $sql = <<<SQL
            SELECT
                ROUND(AVG(daily_total), 0)       AS media_jams_dia,
                ROUND(AVG(daily_vel), 1)         AS media_velocidade,
                ROUND(AVG(daily_atraso), 1)      AS media_atraso
            FROM (
                SELECT
                    DATE(CONVERT_TZ(pub_millis_dt, '+00:00', '-03:00')) AS dia,
                    COUNT(*)                                              AS daily_total,
                    AVG(speed)                                            AS daily_vel,
                    AVG(`delay`)                                          AS daily_atraso
                FROM waze_traffic_jam
                WHERE city = :cidade
                  AND DATE(CONVERT_TZ(pub_millis_dt, '+00:00', '-03:00'))
                      BETWEEN DATE_SUB(CURDATE(), INTERVAL 8 DAY)
                          AND DATE_SUB(CURDATE(), INTERVAL 2 DAY)
                GROUP BY dia
            ) AS sub
        SQL;

        $row = $this->connection->fetchAssociative($sql, ['cidade' => $cidade]);

        return $row ?: ['media_jams_dia' => null, 'media_velocidade' => null, 'media_atraso' => null];
    }

    // ─── Alertas ──────────────────────────────────────────────────────────────

    private function getAlertKpis(string $cidade, string $dataRef): array
    {
        $sql = <<<SQL
            SELECT
                COUNT(*)                                                        AS total,
                SUM(CASE WHEN type = 'HAZARD' THEN 1 ELSE 0 END)              AS hazard,
                SUM(CASE WHEN type = 'JAM' THEN 1 ELSE 0 END)                 AS jam,
                SUM(CASE WHEN type = 'ACCIDENT' THEN 1 ELSE 0 END)            AS acidente,
                SUM(CASE WHEN type = 'ROAD_CLOSED' THEN 1 ELSE 0 END)        AS via_fechada,
                SUM(CASE WHEN subtype = 'HAZARD_ON_ROAD_POT_HOLE' THEN 1 ELSE 0 END) AS buracos,
                SUM(CASE WHEN subtype = 'HAZARD_WEATHER_FOG' THEN 1 ELSE 0 END)      AS neblina,
                SUM(CASE WHEN subtype = 'HAZARD_WEATHER_FLOOD' THEN 1 ELSE 0 END)    AS alagamento,
                SUM(CASE WHEN subtype = 'ACCIDENT_MAJOR' THEN 1 ELSE 0 END)          AS acidente_grave,
                ROUND(AVG(reliability), 1)                                     AS confiabilidade_media
            FROM waze_alert
            WHERE DATE(CONVERT_TZ(pub_millis_dt, '+00:00', '-03:00')) = :data
              AND city = :cidade
        SQL;

        $row = $this->connection->fetchAssociative($sql, [
            'data'   => $dataRef,
            'cidade' => $cidade,
        ]);

        // Top ruas com buracos no dia
        $buracosSql = <<<SQL
            SELECT street AS rua, COUNT(*) AS qtd
            FROM waze_alert
            WHERE DATE(CONVERT_TZ(pub_millis_dt, '+00:00', '-03:00')) = :data
              AND city = :cidade
              AND subtype = 'HAZARD_ON_ROAD_POT_HOLE'
              AND street IS NOT NULL
            GROUP BY street
            ORDER BY qtd DESC
            LIMIT 3
        SQL;

        $topBuracos = $this->connection->fetchAllAssociative($buracosSql, [
            'data'   => $dataRef,
            'cidade' => $cidade,
        ]);

        return array_merge($row ?: [], ['top_ruas_buracos' => $topBuracos]);
    }

    // ─── Interdições ativas ───────────────────────────────────────────────────

    private function getInterdicoes(string $cidade, string $dataRef): array
    {
        // Interdições = jams nível 5 com pub_millis_dt dentro da janela do dia
        // OU que iniciaram antes e ainda estão sem registro de encerramento
        $sql = <<<SQL
            SELECT
                street                                          AS rua,
                ROUND(length / 1000, 2)                        AS extensao_km,
                DATE_FORMAT(
                    CONVERT_TZ(pub_millis_dt, '+00:00', '-03:00'),
                    '%d/%m %H:%i'
                )                                               AS inicio_brt,
                `delay`                                         AS atraso_min,
                speed                                           AS velocidade
            FROM waze_traffic_jam
            WHERE city = :cidade
              AND level = 5
              AND DATE(CONVERT_TZ(pub_millis_dt, '+00:00', '-03:00')) <= :data
            ORDER BY pub_millis_dt ASC
            LIMIT 20
        SQL;

        return $this->connection->fetchAllAssociative($sql, [
            'data'   => $dataRef,
            'cidade' => $cidade,
        ]);
    }
}
