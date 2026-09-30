<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Partner;
use Doctrine\DBAL\Connection;

final class BriefingDataService
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getForPartner(
        Partner $partner,
        ?string $dataRef = null,
    ): array {
        $partnerId = $partner->getId();
        $cidade = trim((string) $partner->getCity());

        if ($partnerId === null || $cidade === '') {
            throw new \InvalidArgumentException(
                'O parceiro precisa estar persistido e possuir cidade.',
            );
        }

        $brt = new \DateTimeZone('America/Sao_Paulo');
        $utc = new \DateTimeZone('UTC');

        $dataRef ??= (new \DateTimeImmutable('yesterday', $brt))
            ->format('Y-m-d');

        $inicio = \DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $dataRef,
            $brt,
        );

        if (
            $inicio === false
            || $inicio->format('Y-m-d') !== $dataRef
        ) {
            throw new \InvalidArgumentException(
                'Data de referência inválida; use Y-m-d.',
            );
        }

        $fim = $inicio->modify('+1 day');
        $inicioMs = $inicio->setTimezone($utc)->getTimestamp() * 1000;
        $fimMs = $fim->setTimezone($utc)->getTimestamp() * 1000;
        $inicioMediaMs = $inicio->modify('-7 days')
            ->setTimezone($utc)->getTimestamp() * 1000;

        $params = [
            'partner_id' => $partnerId,
            'cidade' => $cidade,
            'inicio_ms' => $inicioMs,
            'fim_ms' => $fimMs,
        ];

        return [
            'cidade' => $cidade,
            'data_ref' => $dataRef,
            'gerado_em' => (new \DateTimeImmutable('now', $brt))
                ->format('d/m/Y H:i'),
            'jams' => $this->getJamKpis($params),
            'alertas' => $this->getAlertKpis($params),
            'interdicoes' => $this->getJamsNivelCinco($params, $brt),
            'top_ruas' => $this->getTopRuas($params),
            'pico_hora' => $this->getPicoHora($params, $brt),
            'media_7dias' => $this->getMedia7Dias(
                $params + ['inicio_media_ms' => $inicioMediaMs],
                $brt,
            ),
        ];
    }

    /**
     * @param array<string, int|string> $params
     * @return array<string, mixed>
     */
    private function getJamKpis(array $params): array
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT
                    COUNT(*) AS total,
                    ROUND(AVG(speed_kmh), 1) AS velocidade_media,
                    ROUND(AVG(`delay`) / 60, 1) AS atraso_medio,
                    ROUND(AVG(length) / 1000, 2) AS extensao_media_km,
                    ROUND(MAX(`delay`) / 60, 1) AS atraso_max,
                    COALESCE(SUM(CASE WHEN level = 5 THEN 1 ELSE 0 END), 0) AS interdicoes,
                    COALESCE(SUM(CASE WHEN level >= 4 THEN 1 ELSE 0 END), 0) AS nivel_alto_mais,
                    COALESCE(SUM(CASE WHEN level BETWEEN 2 AND 3 THEN 1 ELSE 0 END), 0) AS nivel_moderado
                FROM waze_jams
                WHERE partner_id = :partner_id
                  AND city = :cidade
                  AND pub_millis >= :inicio_ms
                  AND pub_millis < :fim_ms
                SQL,
            $params,
        );

        return $row ?: [
            'total' => 0,
            'velocidade_media' => null,
            'atraso_medio' => null,
            'extensao_media_km' => null,
            'atraso_max' => null,
            'interdicoes' => 0,
            'nivel_alto_mais' => 0,
            'nivel_moderado' => 0,
        ];
    }

    /**
     * @param array<string, int|string> $params
     * @return list<array<string, mixed>>
     */
    private function getTopRuas(array $params): array
    {
        return $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT
                    street AS rua,
                    COUNT(*) AS total_jams,
                    ROUND(AVG(`delay`) / 60, 1) AS atraso_medio,
                    ROUND(AVG(speed_kmh), 1) AS velocidade_media
                FROM waze_jams
                WHERE partner_id = :partner_id
                  AND city = :cidade
                  AND pub_millis >= :inicio_ms
                  AND pub_millis < :fim_ms
                  AND street IS NOT NULL
                  AND street <> ''
                GROUP BY street
                ORDER BY total_jams DESC
                LIMIT 8
                SQL,
            $params,
        );
    }

    /**
     * @param array<string, int|string> $params
     * @return list<array<string, mixed>>
     */
    private function getPicoHora(
        array $params,
        \DateTimeZone $brt,
    ): array {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT pub_millis
                FROM waze_jams
                WHERE partner_id = :partner_id
                  AND city = :cidade
                  AND pub_millis >= :inicio_ms
                  AND pub_millis < :fim_ms
                SQL,
            $params,
        );

        $totais = array_fill(0, 24, 0);

        foreach ($rows as $row) {
            $hora = (int) (new \DateTimeImmutable('@'
                . intdiv((int) $row['pub_millis'], 1000)))
                ->setTimezone($brt)
                ->format('G');

            $totais[$hora]++;
        }

        arsort($totais);
        $resultado = [];

        foreach (array_slice($totais, 0, 3, true) as $hora => $total) {
            if ($total > 0) {
                $resultado[] = [
                    'hora_brt' => $hora,
                    'total' => $total,
                ];
            }
        }

        return $resultado;
    }

    /**
     * @param array<string, int|string> $params
     * @return array<string, mixed>
     */
    private function getMedia7Dias(
        array $params,
        \DateTimeZone $brt,
    ): array {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT pub_millis, speed_kmh, `delay`
                FROM waze_jams
                WHERE partner_id = :partner_id
                  AND city = :cidade
                  AND pub_millis >= :inicio_media_ms
                  AND pub_millis < :inicio_ms
                SQL,
            [
                'partner_id' => $params['partner_id'],
                'cidade' => $params['cidade'],
                'inicio_media_ms' => $params['inicio_media_ms'],
                'inicio_ms' => $params['inicio_ms'],
            ],
        );

        if ($rows === []) {
            return [
                'media_jams_dia' => null,
                'media_velocidade' => null,
                'media_atraso' => null,
            ];
        }

        $dias = [];
        $somaVel = 0.0;
        $contVel = 0;
        $somaAtraso = 0.0;
        $contAtraso = 0;

        foreach ($rows as $row) {
            $dia = (new \DateTimeImmutable('@'
                . intdiv((int) $row['pub_millis'], 1000)))
                ->setTimezone($brt)->format('Y-m-d');

            $dias[$dia] = ($dias[$dia] ?? 0) + 1;

            if ($row['speed_kmh'] !== null) {
                $somaVel += (float) $row['speed_kmh'];
                $contVel++;
            }

            if ($row['delay'] !== null) {
                $somaAtraso += (float) $row['delay'] / 60;
                $contAtraso++;
            }
        }

        return [
            'media_jams_dia' => round(count($rows) / 7, 0),
            'media_velocidade' => $contVel > 0
                ? round($somaVel / $contVel, 1) : null,
            'media_atraso' => $contAtraso > 0
                ? round($somaAtraso / $contAtraso, 1) : null,
        ];
    }

    /**
     * @param array<string, int|string> $params
     * @return array<string, mixed>
     */
    private function getAlertKpis(array $params): array
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT
                    COUNT(*) AS total,
                    COALESCE(SUM(CASE WHEN type = 'HAZARD' THEN 1 ELSE 0 END), 0) AS hazard,
                    COALESCE(SUM(CASE WHEN type = 'JAM' THEN 1 ELSE 0 END), 0) AS jam,
                    COALESCE(SUM(CASE WHEN type = 'ACCIDENT' THEN 1 ELSE 0 END), 0) AS acidente,
                    COALESCE(SUM(CASE WHEN type = 'ROAD_CLOSED' THEN 1 ELSE 0 END), 0) AS via_fechada,
                    COALESCE(SUM(CASE WHEN subtype = 'HAZARD_ON_ROAD_POT_HOLE' THEN 1 ELSE 0 END), 0) AS buracos,
                    COALESCE(SUM(CASE WHEN subtype = 'HAZARD_WEATHER_FOG' THEN 1 ELSE 0 END), 0) AS neblina,
                    COALESCE(SUM(CASE WHEN subtype = 'HAZARD_WEATHER_FLOOD' THEN 1 ELSE 0 END), 0) AS alagamento,
                    COALESCE(SUM(CASE WHEN subtype = 'ACCIDENT_MAJOR' THEN 1 ELSE 0 END), 0) AS acidente_grave,
                    ROUND(AVG(reliability), 1) AS confiabilidade_media
                FROM waze_alerts
                WHERE partner_id = :partner_id
                  AND city = :cidade
                  AND pub_millis >= :inicio_ms
                  AND pub_millis < :fim_ms
                SQL,
            $params,
        );

        $buracos = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT street AS rua, COUNT(*) AS qtd
                FROM waze_alerts
                WHERE partner_id = :partner_id
                  AND city = :cidade
                  AND pub_millis >= :inicio_ms
                  AND pub_millis < :fim_ms
                  AND subtype = 'HAZARD_ON_ROAD_POT_HOLE'
                  AND street IS NOT NULL
                  AND street <> ''
                GROUP BY street
                ORDER BY qtd DESC
                LIMIT 3
                SQL,
            $params,
        );

        return array_merge($row ?: [], [
            'top_ruas_buracos' => $buracos,
        ]);
    }

    /**
     * A chave `interdicoes` é mantida para compatibilidade com o template,
     * mas representa jams nível 5 na data, não fechamento oficial de via.
     *
     * @param array<string, int|string> $params
     * @return list<array<string, mixed>>
     */
    private function getJamsNivelCinco(
        array $params,
        \DateTimeZone $brt,
    ): array {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT street AS rua, length, pub_millis,
                       `delay`, speed_kmh
                FROM waze_jams
                WHERE partner_id = :partner_id
                  AND city = :cidade
                  AND level = 5
                  AND pub_millis >= :inicio_ms
                  AND pub_millis < :fim_ms
                ORDER BY pub_millis ASC
                LIMIT 20
                SQL,
            $params,
        );

        return array_map(static function (array $row) use ($brt): array {
            $horario = (new \DateTimeImmutable('@'
                . intdiv((int) $row['pub_millis'], 1000)))
                ->setTimezone($brt)->format('d/m H:i');

            return [
                'rua' => $row['rua'],
                'extensao_km' => round((int) $row['length'] / 1000, 2),
                'inicio_brt' => $horario,
                'atraso_min' => round((int) $row['delay'] / 60, 1),
                'velocidade' => $row['speed_kmh'],
            ];
        }, $rows);
    }
}
