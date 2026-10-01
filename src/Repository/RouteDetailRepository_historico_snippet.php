<?php
// ─────────────────────────────────────────────────────────────────────────────
// ADICIONAR ao RouteDetailRepository.php
//
// 1. O método público getHistorico() — chamado pelo controller
// 2. O método privado loadHistorico() — a query bruta
//
// Inserir antes do método privado loadRoute() ou no final da classe,
// antes do fechamento da chave `}`
// ─────────────────────────────────────────────────────────────────────────────

    // ─────────────────────────────────────────────────────────────────────
    // Histórico bruto para exportação CSV
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Retorna os snapshots brutos da rota para exportação.
     *
     * Janela configurável via query string ?days= (padrão 30, máximo 365).
     * Todos os timestamps são entregues em BRT (UTC-3) já convertidos no SQL.
     *
     * Colunas retornadas:
     *   recorded_at_brt — data/hora da coleta (BRT)
     *   time_s          — tempo de percurso medido (segundos)
     *   historic_time_s — tempo histórico de referência (segundos)
     *   delay_s         — atraso absoluto (time - historic), pode ser negativo
     *   delay_pct       — atraso relativo em % vs histórico (ratio * 100)
     *   speed_kmh       — velocidade estimada (length / time × 3.6), quando disponível
     *   jam_level       — nível de congestionamento (0–5)
     *   city            — cidade do snapshot
     *   state           — UF do snapshot
     *
     * @return array{route: array, rows: list<array<string,mixed>>, total: int, days: int}|null
     */
    public function getHistorico(?Partner $partner, int $routeId, int $days = 30): ?array
    {
        $days  = max(1, min(365, $days));
        $route = $this->loadRoute($partner, $routeId);
        if ($route === null) {
            return null;
        }

        $rows  = $this->loadHistorico($partner, $routeId, $days, $route['lengthMeters']);

        return [
            'route' => $route,
            'rows'  => $rows,
            'total' => count($rows),
            'days'  => $days,
        ];
    }

    /**
     * Query bruta — sem agrupamento, linha a linha.
     *
     * O LIMIT é 50.000 para não travar o servidor; é mais do que suficiente
     * para 365 dias com coletas a cada minuto (525.600 snapshots teóricos —
     * na prática são bem menos, pois o Waze envia dados com intervalos maiores).
     * Se precisar de mais, adicionar paginação via ?page=.
     *
     * @return list<array<string,mixed>>
     */
    private function loadHistorico(?Partner $partner, int $routeId, int $days, ?int $lengthMeters): array
    {
        $since = (new \DateTimeImmutable("-{$days} days", new \DateTimeZone('UTC')))
            ->format('Y-m-d H:i:s');

        $params        = ['id' => $routeId, 'since' => $since];
        $partnerFilter = '';
        if ($partner !== null) {
            $partnerFilter    = ' AND s.partner_id = :pid';
            $params['pid']    = $partner->getId();
        }

        // speed_kmh: só calculável quando a rota tem comprimento cadastrado e time > 0
        $speedExpr = $lengthMeters !== null && $lengthMeters > 0
            ? "ROUND(({$lengthMeters} / NULLIF(s.time, 0)) * 3.6, 1)"
            : 'NULL';

        $sql = "SELECT
                    DATE_FORMAT(
                        DATE_ADD(s.recorded_at, INTERVAL -3 HOUR),
                        '%Y-%m-%d %H:%i:%s'
                    )                                                   AS recorded_at_brt,
                    ROUND(s.time, 1)                                    AS time_s,
                    ROUND(s.historic_time, 1)                          AS historic_time_s,
                    ROUND(s.time - s.historic_time, 1)                 AS delay_s,
                    CASE
                        WHEN s.historic_time > 0
                        THEN ROUND(
                            ((s.time - s.historic_time) / s.historic_time) * 100,
                            2
                        )
                        ELSE NULL
                    END                                                  AS delay_pct,
                    {$speedExpr}                                         AS speed_kmh,
                    s.jam_level,
                    s.city,
                    s.state
                FROM waze_tvt_route_snapshot s
                WHERE s.route_id = :id
                  AND s.recorded_at >= :since
                  AND s.time IS NOT NULL
                  AND s.historic_time IS NOT NULL
                  {$partnerFilter}
                ORDER BY s.recorded_at ASC
                LIMIT 50000";

        $rows = $this->connection->executeQuery($sql, $params)->fetchAllAssociative();

        return array_map(static fn (array $r) => [
            'recorded_at_brt' => (string) $r['recorded_at_brt'],
            'time_s'          => $r['time_s']          !== null ? (float) $r['time_s']          : null,
            'historic_time_s' => $r['historic_time_s'] !== null ? (float) $r['historic_time_s'] : null,
            'delay_s'         => $r['delay_s']         !== null ? (float) $r['delay_s']         : null,
            'delay_pct'       => $r['delay_pct']       !== null ? (float) $r['delay_pct']       : null,
            'speed_kmh'       => $r['speed_kmh']       !== null ? (float) $r['speed_kmh']       : null,
            'jam_level'       => $r['jam_level']       !== null ? (int)   $r['jam_level']       : null,
            'city'            => $r['city']  ?? null,
            'state'           => $r['state'] ?? null,
        ], $rows);
    }
