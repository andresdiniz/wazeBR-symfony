<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Partner;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Agregação para a página de detalhe de uma rota TVT (/routes/{id}).
 *
 * ─── Critério de criticidade (heatmap, sub-rotas, KPIs) ──────────────
 *
 * O cálculo de "quão ruim está" é baseado em RATIO, não em delay absoluto:
 *
 *     ratio = (time - historic_time) / historic_time
 *
 * Exemplos:
 *   - 180s normal, +2s atual  → ratio = 1.1%   → verde
 *   -  20s normal, +2s atual  → ratio = 10%    → amarelo
 *   - 180s normal, +90s atual → ratio = 50%    → vermelho
 *
 * ─── Fusos ───────────────────────────────────────────────────────────
 *
 * O banco guarda tudo em UTC. Os buckets de agrupamento (timeline,
 * heatmap, por hora, por dia) são calculados no fuso de exibição
 * (APP_TIMEZONE) e as strings `time` / `bucket` devolvidas JÁ ESTÃO
 * nesse fuso. Todo timestamp "cru" devolvido (reportedAt, collectedAt,
 * pubDateTime, recordedAt…) é ISO-8601 em UTC, com offset explícito,
 * para o Twig `|date` e o JS converterem sem ambiguidade.
 *
 * ─── Performance ─────────────────────────────────────────────────────
 *
 *   - heatmap, por hora e por dia saem de UMA única query (30 dias)
 *     agregada por (dia da semana, hora); as médias por hora/dia são
 *     recompostas em PHP de forma ponderada (sem média de médias);
 *   - geometria da rota e das sub-rotas é lida uma vez só e reaproveitada
 *     no mapa e no cruzamento espacial com alertas;
 *   - o resultado completo é cacheado por {@see self::CACHE_TTL} segundos;
 *   - quando a tabela waze_tvt_route_hourly (comando app:routes:rollup-hourly) cobre
 *     toda a janela dos snapshots brutos, timeline / heatmap / por hora / por dia /
 *     distribuição de jam saem dela (centenas de linhas em vez de dezenas de milhares).
 *     Se a tabela não existir, estiver incompleta ou defasada, lê os brutos — o
 *     resultado é o mesmo, só mais lento. `aggregateSource` informa qual foi usada.
 */
final class RouteDetailRepository
{
    private const WINDOW_DAYS          = 30;
    private const TIMELINE_DAYS        = 7;
    private const ALERT_DAYS           = 7;
    private const RECENT_HOURS         = 6;
    private const RECENT_FRAMES        = 13;
    private const ALERT_BUFFER_METERS  = 60.0;
    private const ALERT_BBOX_PAD_DEG   = 0.002;
    private const ALERT_SCAN_LIMIT     = 1000;
    private const IRREGULARITY_LIMIT   = 20;
    private const SUBROUTES_LIMIT      = 15;
    private const MIN_SAMPLES          = 2;
    private const STALE_AFTER_MINUTES  = 30;
    private const CACHE_TTL            = 60;
    private const CACHE_TTL_NOT_FOUND  = 5;
    private const HISTORICO_MAX_ROWS   = 50000;
    private const HISTORICO_MAX_DAYS   = 365;
    private const METERS_PER_DEGREE    = 111320.0;
    private const DEFAULT_TIMEZONE     = 'America/Sao_Paulo';

    private readonly int $offsetSeconds;

    /** O agregado horário é em UTC: só casa com buckets locais se o fuso tem offset de horas cheias. */
    private readonly bool $rollupCapable;
    private const LIVE_ALERT_DAYS = 1;
    private const LIVE_CACHE_TTL  = 10;

    public function __construct(
        private readonly Connection $connection,
        private readonly CacheInterface $cache,
        #[Autowire('%env(APP_TIMEZONE)%')]
        string $displayTimezone = self::DEFAULT_TIMEZONE,
    ) {
        try {
            $tz = new \DateTimeZone($displayTimezone !== '' ? $displayTimezone : self::DEFAULT_TIMEZONE);
        } catch (\Throwable) {
            $tz = new \DateTimeZone(self::DEFAULT_TIMEZONE);
        }

        $this->offsetSeconds = $tz->getOffset(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $this->rollupCapable = $this->offsetSeconds % 3600 === 0;
    }

    // ═════════════════════════════════════════════════════════════════════
    // API pública
    // ═════════════════════════════════════════════════════════════════════

    /**
     * @return array{
     *   route:array,
     *   current:?array,
     *   stats:array,
     *   heatmap:list<array>,
     *   timeline:list<array>,
     *   byHour:list<array>,
     *   byDow:list<array>,
     *   jamDistribution:list<array>,
     *   topSubRoutes:list<array>,
     *   irregularities:list<array>,
     *   nearbyAlerts:list<array>,
     *   occurrences:list<array>,
     *   recentWindow:array{hours:int,frames:list<array>,avg:array<int,float>,hasSubData:bool},
     *   aggregateSource:'rollup'|'raw',
     *   routePolyline:list<array{0:float,1:float}>,
     *   subRoutesPolyline:array<int,list<array{0:float,1:float}>>
     * }|null
     */
    public function getRouteDetail(?Partner $partner, int $routeId): ?array
    {
        $key = sprintf('route_detail.%s.%d', $partner?->getId() ?? 'all', $routeId);

        return $this->cache->get($key, function (ItemInterface $item) use ($partner, $routeId): ?array {
            $detail = $this->buildRouteDetail($partner, $routeId);
            $item->expiresAfter($detail === null ? self::CACHE_TTL_NOT_FOUND : self::CACHE_TTL);

            return $detail;
        });
    }

    /**
     * Alertas Waze a até $bufferMeters do traçado (rota principal OU qualquer sub-rota).
     *
     * @param list<list<array{0:float,1:float}>>|null $lines linhas já carregadas (evita refazer as queries)
     *
     * @return list<array<string,mixed>>
     */
    public function loadNearbyAlerts(
        ?Partner $partner,
        int $routeId,
        int $days = self::ALERT_DAYS,
        float $bufferMeters = self::ALERT_BUFFER_METERS,
        ?array $lines = null,
    ): array {
        if ($lines === null) {
            $geometry = $this->loadGeometry($partner, $routeId);
            $lines    = $this->collectLines($geometry['main'], $geometry['subLines']);
        }

        $prepared = $this->prepareLines($lines, $bufferMeters);
        if ($prepared === []) {
            return [];
        }

        // Bounding box global — usada só para reduzir o que o banco devolve.
        $minLat = $minLng = INF;
        $maxLat = $maxLng = -INF;
        foreach ($prepared as $line) {
            $minLat = min($minLat, $line['minLat']);
            $maxLat = max($maxLat, $line['maxLat']);
            $minLng = min($minLng, $line['minLng']);
            $maxLng = max($maxLng, $line['maxLng']);
        }

        $recentSince = $this->sinceUtcHours(self::RECENT_HOURS);

        $params = [
            'since'  => $this->sinceUtc($days),
            'minLat' => $minLat - self::ALERT_BBOX_PAD_DEG,
            'maxLat' => $maxLat + self::ALERT_BBOX_PAD_DEG,
            'minLng' => $minLng - self::ALERT_BBOX_PAD_DEG,
            'maxLng' => $maxLng + self::ALERT_BBOX_PAD_DEG,
        ];
        $partnerFilter = $this->partnerFilter($partner, 'a.partner_id', $params);

        $sql = 'SELECT
                    a.id, a.type, a.subtype, a.street, a.city,
                    a.confidence, a.reliability,
                    CAST(a.latitude  AS DECIMAL(10,7)) AS lat,
                    CAST(a.longitude AS DECIMAL(10,7)) AS lng,
                    a.pub_millis, a.collected_at
                FROM waze_alerts a
                WHERE a.collected_at >= :since
                  AND a.latitude  BETWEEN :minLat AND :maxLat
                  AND a.longitude BETWEEN :minLng AND :maxLng'
                . $partnerFilter . '
                ORDER BY a.collected_at DESC
                LIMIT ' . self::ALERT_SCAN_LIMIT;

        $out = [];
        foreach ($this->connection->executeQuery($sql, $params)->fetchAllAssociative() as $r) {
            $lat  = (float) $r['lat'];
            $lng  = (float) $r['lng'];
            $dist = $this->distanceToLines($lat, $lng, $prepared);

            if ($dist > $bufferMeters) {
                continue;
            }

            $pubMillis = (int) ($r['pub_millis'] ?? 0);

            $out[] = [
                'id'             => (int) $r['id'],
                'type'           => $r['type'],
                'subtype'        => $r['subtype'],
                'street'         => $r['street'],
                'city'           => $r['city'],
                'confidence'     => (int) $r['confidence'],
                'reliability'    => (int) $r['reliability'],
                'lat'            => $lat,
                'lng'            => $lng,
                'distanceMeters' => (int) round($dist),
                'pubDateTime'    => $pubMillis > 0
                    ? (new \DateTimeImmutable('@' . intdiv($pubMillis, 1000)))->format(DATE_ATOM)
                    : null,
                'collectedAt'    => $this->toIsoUtc($r['collected_at']),
                // Hora cheia no fuso de exibição — casa 1:1 com `timeline[].time`.
                'bucket'         => $this->localBucket($r['collected_at']),
                // Coletado dentro da janela do mapa "últimas 6 horas".
                'isRecent'       => (string) $r['collected_at'] >= $recentSince,
                'typeLabel'      => $this->labelAlertType((string) $r['type']),
            ];
        }

        return $out;
    }

    /**
     * Estado leve para a atualização ao vivo da página (GET /routes/{id}/live.json).
     *
     * Devolve a leitura atual e os alertas próximos ao traçado coletados DEPOIS de
     * $sinceIso (a página manda o instante da última atualização que ela mostra).
     * Cache curto: muitos visitantes da mesma rota compartilham a mesma consulta.
     *
     * @return array{route:array{isActive:bool},current:?array,newAlerts:list<array<string,mixed>>,generatedAt:string}|null
     */
    public function getLiveState(?Partner $partner, int $routeId, ?string $sinceIso): ?array
    {
        $floor = time() - self::LIVE_ALERT_DAYS * 86400;
        $sinceTs = $sinceIso !== null ? (strtotime($sinceIso) ?: $floor) : time();
        $sinceTs = max($floor, $sinceTs);

        $key = sprintf('route_live.%s.%d.%d', $partner?->getId() ?? 'all', $routeId, intdiv($sinceTs, 10));

        return $this->cache->get($key, function (ItemInterface $item) use ($partner, $routeId, $sinceTs): ?array {
            $item->expiresAfter(self::LIVE_CACHE_TTL);

            $route = $this->loadRoute($partner, $routeId);
            if ($route === null) {
                return null;
            }

            $geometry = $this->loadGeometry($partner, $routeId);
            $alerts = $this->loadNearbyAlerts(
                $partner,
                $routeId,
                self::LIVE_ALERT_DAYS,
                self::ALERT_BUFFER_METERS,
                $this->collectLines($geometry['main'], $geometry['subLines']),
            );

            $new = array_values(array_filter(
                $alerts,
                static fn (array $a): bool => $a['collectedAt'] !== null && strtotime($a['collectedAt']) > $sinceTs
            ));

            return [
                'route'       => ['isActive' => $route['isActive']],
                'current'     => $this->loadCurrentSnapshot($partner, $routeId),
                'newAlerts'   => $new,
                'generatedAt' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM),
            ];
        });
    }

    /**
     * Snapshots brutos da rota para exportação (linha a linha, em streaming).
     *
     * Janela via ?days= (padrão 30, máximo 365). Timestamps já em fuso de exibição.
     *
     * Colunas de cada linha:
     *   recorded_at_brt — data/hora da coleta (fuso de exibição)
     *   time_s          — tempo de percurso medido (segundos)
     *   historic_time_s — tempo histórico de referência (segundos)
     *   delay_s         — atraso absoluto (time - historic), pode ser negativo
     *   delay_pct       — atraso relativo em % vs histórico (ratio * 100)
     *   speed_kmh       — velocidade estimada (length / time × 3.6), quando disponível
     *   jam_level       — nível de congestionamento (0–5)
     *   city / state    — localização do snapshot
     *
     * `rows` é um Generator: consuma-o uma única vez, direto no stream da resposta.
     *
     * @return array{route:array, days:int, rows:\Generator<int,array<string,mixed>>}|null
     */
    public function getHistorico(?Partner $partner, int $routeId, int $days = 30): ?array
    {
        $days  = max(1, min(self::HISTORICO_MAX_DAYS, $days));
        $route = $this->loadRoute($partner, $routeId);
        if ($route === null) {
            return null;
        }

        return [
            'route' => $route,
            'days'  => $days,
            'rows'  => $this->iterateHistorico($partner, $routeId, $days, $route['lengthMeters']),
        ];
    }

    // ═════════════════════════════════════════════════════════════════════
    // Montagem do payload
    // ═════════════════════════════════════════════════════════════════════

    private function buildRouteDetail(?Partner $partner, int $routeId): ?array
    {
        $route = $this->loadRoute($partner, $routeId);
        if ($route === null) {
            return null;
        }

        $useRollup       = $this->rollupCovers($partner, $routeId, self::WINDOW_DAYS);
        $current         = $this->loadCurrentSnapshot($partner, $routeId);
        $timeline        = $this->loadTimeline($partner, $routeId, self::TIMELINE_DAYS, $useRollup);
        $patterns        = $this->loadPatterns($partner, $routeId, self::WINDOW_DAYS, $useRollup);
        $jamDistribution = $this->loadJamDistribution($partner, $routeId, self::WINDOW_DAYS, $useRollup);
        $irregularities  = $this->loadRecentIrregularities($partner, $routeId);

        // Geometria: UMA leitura, usada no mapa e no cruzamento com alertas.
        $geometry  = $this->loadGeometry($partner, $routeId);
        $subRoutes = $this->loadSubRoutes($partner, $routeId, $geometry['subLines']);

        $recentWindow = $this->loadRecentWindow($partner, $routeId, $geometry['subKeys']);

        $nearbyAlerts = $this->loadNearbyAlerts(
            $partner,
            $routeId,
            self::ALERT_DAYS,
            self::ALERT_BUFFER_METERS,
            $this->collectLines($geometry['main'], $geometry['subLines']),
        );

        return [
            'route'             => $route,
            'current'           => $current,
            'stats'             => $this->computeStats(
                $timeline,
                $patterns['heatmap'],
                $patterns['byHour'],
                $patterns['byDow'],
                $current,
            ),
            'heatmap'           => $patterns['heatmap'],
            'timeline'          => $timeline,
            'byHour'            => $patterns['byHour'],
            'byDow'             => $patterns['byDow'],
            'jamDistribution'   => $jamDistribution,
            'topSubRoutes'      => $subRoutes,
            'irregularities'    => $irregularities,
            'nearbyAlerts'      => $nearbyAlerts,
            'occurrences'       => $this->buildOccurrences($nearbyAlerts, $irregularities),
            'recentWindow'      => $recentWindow,
            'aggregateSource'   => $useRollup ? 'rollup' : 'raw',
            // Só o traçado principal. As sub-rotas vão em `subRoutesPolyline`,
            // para o mapa não ligar o fim de um trecho ao início do próximo.
            'routePolyline'     => $geometry['main'],
            'subRoutesPolyline' => $geometry['subLines'],
        ];
    }

    // ═════════════════════════════════════════════════════════════════════
    // Rota e snapshot atual
    // ═════════════════════════════════════════════════════════════════════

    private function loadRoute(?Partner $partner, int $routeId): ?array
    {
        $params        = ['id' => $routeId];
        $partnerFilter = $this->partnerFilter($partner, 'r.partner_id', $params);

        $row = $this->connection->executeQuery(
            'SELECT
                r.id, r.route_id AS waze_route_id, r.name, r.from_name, r.to_name,
                r.length, r.is_active, r.last_seen_at, r.deactivated_at
             FROM waze_tvt_route r
             WHERE r.id = :id' . $partnerFilter . '
             LIMIT 1',
            $params
        )->fetchAssociative();

        if (!$row) {
            return null;
        }

        return [
            'id'            => (int) $row['id'],
            'wazeRouteId'   => (string) $row['waze_route_id'],
            'name'          => $row['name'] ?: 'Rota monitorada',
            'from'          => $row['from_name'],
            'to'            => $row['to_name'],
            'lengthMeters'  => $row['length'] !== null ? (int) $row['length'] : null,
            'isActive'      => (bool) $row['is_active'],
            'lastSeenAt'    => $this->toIsoUtc($row['last_seen_at']),
            'deactivatedAt' => $this->toIsoUtc($row['deactivated_at']),
        ];
    }

    private function loadCurrentSnapshot(?Partner $partner, int $routeId): ?array
    {
        $params        = ['id' => $routeId];
        $partnerFilter = $this->partnerFilter($partner, 's.partner_id', $params);

        $row = $this->connection->executeQuery(
            'SELECT
                s.id, s.name, s.city, s.state,
                s.time, s.historic_time, s.jam_level,
                s.recorded_at
             FROM waze_tvt_route_snapshot s
             WHERE s.route_id = :id' . $partnerFilter . '
             ORDER BY s.recorded_at DESC, s.id DESC
             LIMIT 1',
            $params
        )->fetchAssociative();

        if (!$row) {
            return null;
        }

        $time     = $row['time']          !== null ? (float) $row['time']          : null;
        $historic = $row['historic_time'] !== null ? (float) $row['historic_time'] : null;
        [$delay, $ratio] = $this->delayAndRatio($time, $historic);

        $recordedAt = $this->toIsoUtc($row['recorded_at']);
        $ageMinutes = null;
        if ($recordedAt !== null) {
            $ageMinutes = max(0, intdiv(time() - (int) strtotime($recordedAt), 60));
        }

        return [
            'name'         => $row['name'],
            'city'         => $row['city'],
            'state'        => $row['state'],
            'time'         => $time,
            'historicTime' => $historic,
            'delaySeconds' => $delay,
            'delayRatio'   => $ratio,
            'level'        => $delay !== null ? $this->currentLevel($ratio) : null,
            'jamLevel'     => $row['jam_level'] !== null ? (int) $row['jam_level'] : null,
            'recordedAt'   => $recordedAt,
            'ageMinutes'   => $ageMinutes,
            'isStale'      => $ageMinutes !== null && $ageMinutes > self::STALE_AFTER_MINUTES,
        ];
    }

    // ═════════════════════════════════════════════════════════════════════
    // Séries agregadas
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Evolução hora a hora (buckets no fuso de exibição).
     *
     * @return list<array{time:string,avgDelay:float,avgRatio:?float,avgJam:?float,count:int}>
     */
    private function loadTimeline(?Partner $partner, int $routeId, int $days, bool $useRollup = false): array
    {
        $params = ['id' => $routeId, 'since' => $this->windowStart($days)];

        if ($useRollup) {
            $partnerFilter = $this->partnerFilter($partner, 'h.partner_id', $params);
            $local = $this->localExpr('h.bucket_start');

            $sql = "SELECT
                        DATE_FORMAT({$local}, '%Y-%m-%d %H:00:00') AS bucket,
                        h.sum_delay / NULLIF(h.samples, 0) AS avg_delay,
                        h.sum_ratio / NULLIF(h.n_ratio, 0) AS avg_ratio,
                        h.sum_jam   / NULLIF(h.n_jam, 0)   AS avg_jam,
                        h.samples AS total
                    FROM waze_tvt_route_hourly h
                    WHERE h.route_id = :id
                      AND h.bucket_start >= :since
                      AND h.samples > 0
                      {$partnerFilter}
                    ORDER BY h.bucket_start ASC
                    LIMIT 500";
        } else {
            $partnerFilter = $this->partnerFilter($partner, 's.partner_id', $params);
            $local = $this->localExpr('s.recorded_at');

            $sql = "SELECT
                        DATE_FORMAT({$local}, '%Y-%m-%d %H:00:00') AS bucket,
                        AVG(s.time - s.historic_time) AS avg_delay,
                        AVG(CASE WHEN s.historic_time > 0
                                 THEN (s.time - s.historic_time) / s.historic_time
                                 ELSE NULL END) AS avg_ratio,
                        AVG(s.jam_level) AS avg_jam,
                        COUNT(*) AS total
                    FROM waze_tvt_route_snapshot s
                    WHERE s.route_id = :id
                      AND s.recorded_at >= :since
                      AND s.time IS NOT NULL
                      AND s.historic_time IS NOT NULL
                      {$partnerFilter}
                    GROUP BY bucket
                    ORDER BY bucket ASC
                    LIMIT 500";
        }

        return array_map(static fn (array $r): array => [
            'time'     => (string) $r['bucket'],
            'avgDelay' => $r['avg_delay'] !== null ? round((float) $r['avg_delay'], 1) : 0.0,
            'avgRatio' => $r['avg_ratio'] !== null ? round((float) $r['avg_ratio'], 4) : null,
            'avgJam'   => $r['avg_jam']   !== null ? round((float) $r['avg_jam'], 2)   : null,
            'count'    => (int) $r['total'],
        ], $this->connection->executeQuery($sql, $params)->fetchAllAssociative());
    }

    /**
     * Uma única query por (dia da semana × hora) e dela saem as três visões:
     * heatmap, média por hora e média por dia da semana.
     *
     * As médias agregadas são ponderadas pelas somas/contagens de cada célula
     * — idênticas às que o banco calcularia com AVG() direto no grupo maior.
     *
     * @return array{
     *   heatmap:list<array{dow:int,hour:int,avgRatio:?float,avgDelay:?float,avgJam:?float,count:int}>,
     *   byHour:list<array{hour:int,avgRatio:?float,avgDelay:?float,count:int}>,
     *   byDow:list<array{dow:int,avgRatio:?float,avgDelay:?float,count:int}>
     * }
     */
    private function loadPatterns(?Partner $partner, int $routeId, int $days, bool $useRollup = false): array
    {
        $params = ['id' => $routeId, 'since' => $this->windowStart($days)];

        if ($useRollup) {
            $partnerFilter = $this->partnerFilter($partner, 'h.partner_id', $params);
            $local = $this->localExpr('h.bucket_start');

            $sql = "SELECT
                        WEEKDAY({$local}) AS dow,
                        HOUR({$local})    AS hour,
                        SUM(h.samples)    AS total,
                        SUM(h.sum_ratio)  AS sum_ratio,
                        SUM(h.n_ratio)    AS n_ratio,
                        SUM(h.sum_delay)  AS sum_delay,
                        SUM(h.sum_jam)    AS sum_jam,
                        SUM(h.n_jam)      AS n_jam
                    FROM waze_tvt_route_hourly h
                    WHERE h.route_id = :id
                      AND h.bucket_start >= :since
                      AND h.samples > 0
                      {$partnerFilter}
                    GROUP BY dow, hour
                    ORDER BY dow, hour";
        } else {
            $partnerFilter = $this->partnerFilter($partner, 's.partner_id', $params);
            $local = $this->localExpr('s.recorded_at');

            $sql = "SELECT
                    WEEKDAY({$local}) AS dow,
                    HOUR({$local})    AS hour,
                    COUNT(*)          AS total,
                    SUM(CASE WHEN s.historic_time > 0
                             THEN (s.time - s.historic_time) / s.historic_time
                             ELSE NULL END) AS sum_ratio,
                    SUM(CASE WHEN s.historic_time > 0 THEN 1 ELSE 0 END) AS n_ratio,
                    SUM(s.time - s.historic_time) AS sum_delay,
                    SUM(s.jam_level) AS sum_jam,
                    COUNT(s.jam_level) AS n_jam
                FROM waze_tvt_route_snapshot s
                WHERE s.route_id = :id
                  AND s.recorded_at >= :since
                  AND s.time IS NOT NULL
                  AND s.historic_time IS NOT NULL
                  {$partnerFilter}
                GROUP BY dow, hour
                ORDER BY dow, hour";
        }

        $rows = $this->connection->executeQuery($sql, $params)->fetchAllAssociative();

        $heatmap = [];
        $hours   = array_fill(0, 24, $this->emptyAccumulator());
        $dows    = array_fill(0, 7, $this->emptyAccumulator());

        foreach ($rows as $r) {
            $dow  = (int) $r['dow'];
            $hour = (int) $r['hour'];

            $cell = [
                'total'    => (int) $r['total'],
                'sumRatio' => (float) ($r['sum_ratio'] ?? 0),
                'nRatio'   => (int) $r['n_ratio'],
                'sumDelay' => (float) ($r['sum_delay'] ?? 0),
                'sumJam'   => (float) ($r['sum_jam'] ?? 0),
                'nJam'     => (int) $r['n_jam'],
            ];

            $heatmap[] = [
                'dow'      => $dow,
                'hour'     => $hour,
                'avgRatio' => $this->avgRatio($cell),
                'avgDelay' => $this->avgDelay($cell),
                'avgJam'   => $cell['nJam'] > 0 ? round($cell['sumJam'] / $cell['nJam'], 2) : null,
                'count'    => $cell['total'],
            ];

            if (isset($hours[$hour])) {
                $hours[$hour] = $this->accumulate($hours[$hour], $cell);
            }
            if (isset($dows[$dow])) {
                $dows[$dow] = $this->accumulate($dows[$dow], $cell);
            }
        }

        $byHour = [];
        foreach ($hours as $h => $acc) {
            $byHour[] = [
                'hour'     => $h,
                'avgRatio' => $this->avgRatio($acc),
                'avgDelay' => $this->avgDelay($acc),
                'count'    => $acc['total'],
            ];
        }

        $byDow = [];
        foreach ($dows as $d => $acc) {
            $byDow[] = [
                'dow'      => $d,
                'avgRatio' => $this->avgRatio($acc),
                'avgDelay' => $this->avgDelay($acc),
                'count'    => $acc['total'],
            ];
        }

        return ['heatmap' => $heatmap, 'byHour' => $byHour, 'byDow' => $byDow];
    }

    /** @return list<array{level:int,count:int}> */
    private function loadJamDistribution(?Partner $partner, int $routeId, int $days, bool $useRollup = false): array
    {
        $params = ['id' => $routeId, 'since' => $this->windowStart($days)];
        $map = [];

        if ($useRollup) {
            $partnerFilter = $this->partnerFilter($partner, 'h.partner_id', $params);

            $row = $this->connection->executeQuery(
                'SELECT SUM(h.jam_0) AS j0, SUM(h.jam_1) AS j1, SUM(h.jam_2) AS j2,
                        SUM(h.jam_3) AS j3, SUM(h.jam_4) AS j4, SUM(h.jam_5) AS j5
                 FROM waze_tvt_route_hourly h
                 WHERE h.route_id = :id AND h.bucket_start >= :since' . $partnerFilter,
                $params
            )->fetchAssociative() ?: [];

            for ($lvl = 0; $lvl <= 5; ++$lvl) {
                $map[$lvl] = (int) ($row["j{$lvl}"] ?? 0);
            }
        } else {
            $partnerFilter = $this->partnerFilter($partner, 's.partner_id', $params);

            $rows = $this->connection->executeQuery(
                'SELECT s.jam_level, COUNT(*) AS total
                 FROM waze_tvt_route_snapshot s
                 WHERE s.route_id = :id
                   AND s.recorded_at >= :since
                   AND s.jam_level IS NOT NULL' . $partnerFilter . '
                 GROUP BY s.jam_level
                 ORDER BY s.jam_level',
                $params
            )->fetchAllAssociative();

            foreach ($rows as $r) {
                $map[(int) $r['jam_level']] = (int) $r['total'];
            }
        }

        $out = [];
        for ($lvl = 0; $lvl <= 5; ++$lvl) {
            $out[] = ['level' => $lvl, 'count' => $map[$lvl] ?? 0];
        }

        return $out;
    }

    // ═════════════════════════════════════════════════════════════════════
    // Geometria e sub-rotas
    // ═════════════════════════════════════════════════════════════════════

    /**
     * @return array{
     *   main:list<array{0:float,1:float}>,
     *   subLines:array<int,list<array{0:float,1:float}>>,
     *   subKeys:array<string,int>
     * }
     */
    private function loadGeometry(?Partner $partner, int $routeId): array
    {
        $params        = ['id' => $routeId];
        $partnerFilter = $this->partnerFilter($partner, 'partner_id', $params);

        $geom = $this->connection->executeQuery(
            'SELECT geometry FROM waze_tvt_route WHERE id = :id' . $partnerFilter,
            $params
        )->fetchOne();

        $subLines = [];
        $subKeys  = [];
        $rows = $this->connection->executeQuery(
            'SELECT id, sub_route_id, line FROM waze_tvt_sub_route
             WHERE route_id = :id
               AND is_active = 1
               AND line IS NOT NULL' . $partnerFilter,
            $params
        )->fetchAllAssociative();

        foreach ($rows as $row) {
            $subKeys[(string) $row['sub_route_id']] = (int) $row['id'];

            $line = $this->normalizeLine($row['line']);
            if (count($line) >= 2) {
                $subLines[(int) $row['id']] = $line;
            }
        }

        return [
            'main'     => $this->normalizeLine($geom === false ? null : $geom),
            'subLines' => $subLines,
            // id do trecho na Waze → id interno (liga o payload dos snapshots às linhas).
            'subKeys'  => $subKeys,
        ];
    }

    /**
     * Sub-rotas ativas ordenadas por ratio desc (mais problemáticas primeiro).
     *
     * O `name` do banco pode vir nulo — quando isso acontece, monta
     * `from → to` a partir dos nomes de vias, que são mais úteis que "Trecho".
     *
     * @param array<int,list<array{0:float,1:float}>> $subLines usado só para marcar `hasLine`
     *
     * @return list<array<string,mixed>>
     */
    private function loadSubRoutes(?Partner $partner, int $routeId, array $subLines): array
    {
        $params        = ['id' => $routeId];
        $partnerFilter = $this->partnerFilter($partner, 'sr.partner_id', $params);

        $rows = $this->connection->executeQuery(
            'SELECT
                sr.id, sr.name, sr.from_name, sr.to_name,
                sr.time, sr.historic_time, sr.jam_level, sr.length
             FROM waze_tvt_sub_route sr
             WHERE sr.route_id = :id
               AND sr.is_active = 1' . $partnerFilter,
            $params
        )->fetchAllAssociative();

        $items = [];
        foreach ($rows as $r) {
            $time     = $r['time']          !== null ? (float) $r['time']          : null;
            $historic = $r['historic_time'] !== null ? (float) $r['historic_time'] : null;
            [$delay, $ratio] = $this->delayAndRatio($time, $historic);

            // Ordenação usa o ratio bruto (pode ser negativo); sem histórico vai pro fim.
            $sortKey = ($time !== null && $historic !== null && $historic > 0)
                ? ($time - $historic) / $historic
                : -2.0;

            $id = (int) $r['id'];

            $items[] = [
                '_sort'        => $sortKey,
                'id'           => $id,
                'name'         => $this->subRouteDisplayName($r['name'], $r['from_name'], $r['to_name']),
                'from'         => $r['from_name'],
                'to'           => $r['to_name'],
                'time'         => $time,
                'historicTime' => $historic,
                'delaySeconds' => $delay,
                'delayRatio'   => $ratio,
                'level'        => $this->subRouteLevel($ratio),
                'jamLevel'     => $r['jam_level'] !== null ? (int) $r['jam_level'] : null,
                'lengthMeters' => $r['length'] !== null ? (int) $r['length'] : null,
                'hasLine'      => isset($subLines[$id]),
            ];
        }

        usort(
            $items,
            static fn (array $a, array $b): int => [$b['_sort'], $a['id']] <=> [$a['_sort'], $b['id']]
        );

        $items = array_slice($items, 0, self::SUBROUTES_LIMIT);

        return array_map(static function (array $item): array {
            unset($item['_sort']);

            return $item;
        }, $items);
    }

    private function subRouteDisplayName(mixed $name, mixed $from, mixed $to): string
    {
        $display = trim((string) ($name ?? ''));
        if ($display !== '' && mb_strtolower($display) !== 'trecho') {
            return $display;
        }

        $from = trim((string) ($from ?? ''));
        $to   = trim((string) ($to ?? ''));

        return match (true) {
            $from !== '' && $to !== '' => $from . ' → ' . $to,
            $from !== ''               => $from,
            $to !== ''                 => $to,
            default                    => 'Trecho sem nome',
        };
    }

    // ═════════════════════════════════════════════════════════════════════
    // Janela recente (mapa das últimas horas)
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Estado do trânsito na rota (e em cada trecho) ao longo das últimas $hours horas,
     * em até RECENT_FRAMES "quadros" igualmente espaçados — alimenta o mapa com slider.
     *
     * Os trechos vêm do `payload` JSON de cada snapshot (chave `subRoutes`). Só os
     * snapshots escolhidos têm o payload lido, para não carregar JSON pesado à toa.
     * Se o payload não trouxer `subRoutes`, os quadros ficam só com o ratio da rota
     * e `hasSubData` = false (o JS pinta o traçado principal).
     *
     * @param array<string,int> $subKeys id Waze do trecho → id interno
     *
     * @return array{
     *   hours:int,
     *   frames:list<array{at:?string,route:?float,subs:array<int,?float>}>,
     *   avg:array<int,float>,
     *   hasSubData:bool
     * }
     */
    private function loadRecentWindow(?Partner $partner, int $routeId, array $subKeys, int $hours = self::RECENT_HOURS): array
    {
        $empty = ['hours' => $hours, 'frames' => [], 'avg' => [], 'hasSubData' => false];

        $params = ['id' => $routeId, 'since' => $this->sinceUtcHours($hours)];
        $partnerFilter = $this->partnerFilter($partner, 's.partner_id', $params);

        $rows = $this->connection->executeQuery(
            'SELECT s.id, s.recorded_at
             FROM waze_tvt_route_snapshot s
             WHERE s.route_id = :id
               AND s.recorded_at >= :since' . $partnerFilter . '
             ORDER BY s.recorded_at ASC
             LIMIT 2000',
            $params
        )->fetchAllAssociative();

        if ($rows === []) {
            return $empty;
        }

        $picked = $this->pickEvenly($rows, self::RECENT_FRAMES);
        $ids    = implode(',', array_map(static fn (array $r): int => (int) $r['id'], $picked));

        $data = [];
        foreach ($this->connection->executeQuery(
            "SELECT s.id, s.time, s.historic_time, s.payload
             FROM waze_tvt_route_snapshot s
             WHERE s.id IN ({$ids})"
        )->fetchAllAssociative() as $row) {
            $data[(int) $row['id']] = $row;
        }

        $frames = [];
        $sum    = [];
        $count  = [];
        $hasSub = false;

        foreach ($picked as $p) {
            $row = $data[(int) $p['id']] ?? null;
            if ($row === null) {
                continue;
            }

            $time     = $row['time']          !== null ? (float) $row['time']          : null;
            $historic = $row['historic_time'] !== null ? (float) $row['historic_time'] : null;
            [, $routeRatio] = $this->delayAndRatio($time, $historic);

            $payload = $row['payload'];
            if (is_string($payload)) {
                $payload = json_decode($payload, true);
            }

            $subs = [];
            foreach ((is_array($payload) ? ($payload['subRoutes'] ?? []) : []) as $sub) {
                if (!is_array($sub)) {
                    continue;
                }

                $internalId = $subKeys[trim((string) ($sub['id'] ?? ''))] ?? null;
                if ($internalId === null) {
                    continue;
                }

                $subTime = isset($sub['time'])         ? (float) $sub['time']         : null;
                $subHist = isset($sub['historicTime']) ? (float) $sub['historicTime'] : null;
                [, $subRatio] = $this->delayAndRatio($subTime, $subHist);

                $subs[$internalId] = $subRatio !== null ? round($subRatio, 4) : null;
                $hasSub = true;

                if ($subRatio !== null) {
                    $sum[$internalId]   = ($sum[$internalId] ?? 0.0) + $subRatio;
                    $count[$internalId] = ($count[$internalId] ?? 0) + 1;
                }
            }

            $frames[] = [
                'at'    => $this->toIsoUtc($p['recorded_at']),
                'route' => $routeRatio !== null ? round($routeRatio, 4) : null,
                'subs'  => $subs,
            ];
        }

        $avg = [];
        foreach ($sum as $id => $total) {
            $avg[$id] = round($total / $count[$id], 4);
        }

        return ['hours' => $hours, 'frames' => $frames, 'avg' => $avg, 'hasSubData' => $hasSub];
    }

    /**
     * @param list<array<string,mixed>> $rows
     *
     * @return list<array<string,mixed>> no máximo $max linhas, incluindo a primeira e a última
     */
    private function pickEvenly(array $rows, int $max): array
    {
        $n = count($rows);
        if ($n <= $max || $max < 2) {
            return $rows;
        }

        $out = [];
        for ($i = 0; $i < $max; ++$i) {
            $idx = (int) round($i * ($n - 1) / ($max - 1));
            $out[$idx] = $rows[$idx];
        }

        return array_values($out);
    }

    // ═════════════════════════════════════════════════════════════════════
    // Irregularidades e ocorrências
    // ═════════════════════════════════════════════════════════════════════

    /** @return list<array<string,mixed>> */
    private function loadRecentIrregularities(?Partner $partner, int $routeId): array
    {
        $params        = ['id' => $routeId];
        $partnerFilter = $this->partnerFilter($partner, 'partner_id', $params);

        $rows = $this->connection->executeQuery(
            'SELECT id, type, subtype, severity, description, street, city, latitude, longitude, reported_time
             FROM waze_tvt_irregularity
             WHERE route_id = :id
               AND is_active = 1' . $partnerFilter . '
             ORDER BY reported_time DESC
             LIMIT ' . self::IRREGULARITY_LIMIT,
            $params
        )->fetchAllAssociative();

        $recentSince = $this->sinceUtcHours(self::RECENT_HOURS);

        return array_map(fn (array $r): array => [
            'id'          => (int) $r['id'],
            'type'        => $r['type'],
            'subtype'     => $r['subtype'],
            'severity'    => $r['severity'],
            'description' => $r['description'],
            'street'      => $r['street'],
            'city'        => $r['city'],
            'lat'         => $r['latitude']  !== null ? (float) $r['latitude']  : null,
            'lng'         => $r['longitude'] !== null ? (float) $r['longitude'] : null,
            'reportedAt'  => $this->toIsoUtc($r['reported_time']),
            'isRecent'    => $r['reported_time'] !== null && (string) $r['reported_time'] >= $recentSince,
        ], $rows);
    }

    /**
     * Lista única (alertas Waze + irregularidades TVT), mais recentes primeiro.
     * Usada pela seção "Ocorrências" e pelo CSV `occurrences`.
     *
     * @param list<array<string,mixed>> $alerts
     * @param list<array<string,mixed>> $irregularities
     *
     * @return list<array<string,mixed>>
     */
    private function buildOccurrences(array $alerts, array $irregularities): array
    {
        $out = [];

        foreach ($alerts as $a) {
            $out[] = [
                'source'         => 'waze',
                'icon'           => $this->alertIcon((string) $a['type']),
                'type'           => $a['typeLabel'] ?: ($a['type'] ?: 'Alerta'),
                'subtype'        => $a['subtype'],
                'street'         => $a['street'],
                'city'           => $a['city'],
                'severity'       => null,
                'distanceMeters' => $a['distanceMeters'],
                'at'             => $a['pubDateTime'] ?? $a['collectedAt'],
            ];
        }

        foreach ($irregularities as $irr) {
            $out[] = [
                'source'         => 'tvt',
                'icon'           => '⚠',
                'type'           => $irr['type'] ?: 'Irregularidade',
                'subtype'        => $irr['subtype'],
                'street'         => $irr['street'],
                'city'           => $irr['city'],
                'severity'       => $irr['severity'],
                'distanceMeters' => null,
                'at'             => $irr['reportedAt'],
            ];
        }

        usort(
            $out,
            static fn (array $a, array $b): int => strcmp((string) ($b['at'] ?? ''), (string) ($a['at'] ?? ''))
        );

        return $out;
    }

    // ═════════════════════════════════════════════════════════════════════
    // Stats agregadas
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Médias de 24h/7d são PONDERADAS pelo nº de amostras de cada bucket horário.
     * `sampleCount7d` / `sampleCount30d` = total de snapshots (não nº de buckets).
     *
     * @param list<array<string,mixed>> $timeline
     * @param list<array<string,mixed>> $heatmap
     * @param list<array<string,mixed>> $byHour
     * @param list<array<string,mixed>> $byDow
     */
    private function computeStats(array $timeline, array $heatmap, array $byHour, array $byDow, ?array $current): array
    {
        $cutoff = time() - 86400;

        $all  = $this->weightedMeans($timeline);
        $last = $this->weightedMeans(array_filter(
            $timeline,
            fn (array $p): bool => $this->bucketToUtcTimestamp((string) $p['time']) >= $cutoff
        ));

        $worstHour = $this->pickByRatio($byHour, true);
        $worstDow  = $this->pickByRatio($byDow, true);
        $bestDow   = $this->pickByRatio($byDow, false);

        // Health score — baseado em RATIO, não em delay absoluto
        $health = 100;
        if ($all['ratio'] !== null) {
            $health -= min(70, (int) round($all['ratio'] * 100));
        }
        if ($current !== null && $current['delayRatio'] !== null) {
            $health -= min(20, (int) round($current['delayRatio'] * 40));
        }
        $health = max(0, min(100, $health));

        [$healthLevel, $healthLabel] = match (true) {
            $health >= 80 => ['good', 'Estável'],
            $health >= 55 => ['warn', 'Atenção'],
            default       => ['bad', 'Crítica'],
        };

        return [
            'avgDelay24h'    => $last['delay'] !== null ? round($last['delay'], 1) : null,
            'avgRatio24h'    => $last['ratio'],
            'avgDelay7d'     => $all['delay'] !== null ? round($all['delay'], 1) : null,
            'avgRatio7d'     => $all['ratio'],
            'worstHour'      => $worstHour,
            'worstDow'       => $worstDow,
            'bestDow'        => $bestDow,
            'sampleCount7d'  => array_sum(array_column($timeline, 'count')),
            'sampleCount30d' => array_sum(array_column($heatmap, 'count')),
            'health'         => $health,
            'healthLevel'    => $healthLevel,
            'healthLabel'    => $healthLabel,
        ];
    }

    /**
     * @param iterable<array<string,mixed>> $points
     *
     * @return array{delay:?float,ratio:?float}
     */
    private function weightedMeans(iterable $points): array
    {
        $sumD = $sumR = 0.0;
        $nD = $nR = 0;

        foreach ($points as $p) {
            $w = max(1, (int) ($p['count'] ?? 1));

            if ($p['avgDelay'] !== null) {
                $sumD += (float) $p['avgDelay'] * $w;
                $nD   += $w;
            }
            if ($p['avgRatio'] !== null) {
                $sumR += (float) $p['avgRatio'] * $w;
                $nR   += $w;
            }
        }

        return [
            'delay' => $nD > 0 ? $sumD / $nD : null,
            'ratio' => $nR > 0 ? $sumR / $nR : null,
        ];
    }

    /**
     * @param list<array<string,mixed>> $points
     *
     * @return array<string,mixed>|null
     */
    private function pickByRatio(array $points, bool $highest): ?array
    {
        $best = null;

        foreach ($points as $p) {
            if ($p['avgRatio'] === null || $p['count'] < self::MIN_SAMPLES) {
                continue;
            }

            if ($best === null
                || ($highest ? $p['avgRatio'] > $best['avgRatio'] : $p['avgRatio'] < $best['avgRatio'])) {
                $best = $p;
            }
        }

        return $best;
    }

    // ═════════════════════════════════════════════════════════════════════
    // Histórico bruto (exportação)
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Linha a linha, sem agrupamento. O LIMIT protege o servidor; se precisar
     * de mais, adicionar paginação via ?page=.
     *
     * @return \Generator<int,array<string,mixed>>
     */
    private function iterateHistorico(?Partner $partner, int $routeId, int $days, ?int $lengthMeters): \Generator
    {
        $params        = ['id' => $routeId, 'since' => $this->sinceUtc($days)];
        $partnerFilter = $this->partnerFilter($partner, 's.partner_id', $params);
        $local         = $this->localExpr('s.recorded_at');

        // speed_kmh: só calculável quando a rota tem comprimento cadastrado e time > 0.
        // $lengthMeters é int vindo do banco — interpolação segura.
        $speedExpr = $lengthMeters !== null && $lengthMeters > 0
            ? 'ROUND((' . (int) $lengthMeters . ' / NULLIF(s.time, 0)) * 3.6, 1)'
            : 'NULL';

        $sql = "SELECT
                    DATE_FORMAT({$local}, '%Y-%m-%d %H:%i:%s') AS recorded_at_brt,
                    ROUND(s.time, 1)                           AS time_s,
                    ROUND(s.historic_time, 1)                  AS historic_time_s,
                    ROUND(s.time - s.historic_time, 1)         AS delay_s,
                    CASE
                        WHEN s.historic_time > 0
                        THEN ROUND(((s.time - s.historic_time) / s.historic_time) * 100, 2)
                        ELSE NULL
                    END                                        AS delay_pct,
                    {$speedExpr}                               AS speed_kmh,
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
                LIMIT " . self::HISTORICO_MAX_ROWS;

        foreach ($this->connection->executeQuery($sql, $params)->iterateAssociative() as $r) {
            yield [
                'recorded_at_brt' => (string) $r['recorded_at_brt'],
                'time_s'          => $r['time_s']          !== null ? (float) $r['time_s']          : null,
                'historic_time_s' => $r['historic_time_s'] !== null ? (float) $r['historic_time_s'] : null,
                'delay_s'         => $r['delay_s']         !== null ? (float) $r['delay_s']         : null,
                'delay_pct'       => $r['delay_pct']       !== null ? (float) $r['delay_pct']       : null,
                'speed_kmh'       => $r['speed_kmh']       !== null ? (float) $r['speed_kmh']       : null,
                'jam_level'       => $r['jam_level']       !== null ? (int) $r['jam_level']         : null,
                'city'            => $r['city']  ?? null,
                'state'           => $r['state'] ?? null,
            ];
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    // Agregado horário (waze_tvt_route_hourly)
    // ═════════════════════════════════════════════════════════════════════

    /**
     * O agregado cobre TODA a janela dos snapshots brutos da rota?
     *
     * Exige que a primeira e a última hora do agregado alcancem a primeira e a última
     * hora dos snapshots brutos. Cobre os três casos de risco: comando nunca rodou /
     * backfill parcial (início faltando), cron parado (fim defasado) e tabela
     * inexistente (migration pendente → exceção → lê os brutos).
     */
    private function rollupCovers(?Partner $partner, int $routeId, int $days): bool
    {
        if (!$this->rollupCapable) {
            return false;
        }

        try {
            $rawParams = ['id' => $routeId, 'since' => $this->windowStart($days)];
            $rawFilter = $this->partnerFilter($partner, 's.partner_id', $rawParams);

            $raw = $this->connection->executeQuery(
                'SELECT MIN(s.recorded_at) AS lo, MAX(s.recorded_at) AS hi
                 FROM waze_tvt_route_snapshot s
                 WHERE s.route_id = :id AND s.recorded_at >= :since' . $rawFilter,
                $rawParams
            )->fetchAssociative();

            if (!$raw || $raw['lo'] === null) {
                return false;
            }

            $hourParams = ['id' => $routeId, 'since' => $rawParams['since']];
            $hourFilter = $this->partnerFilter($partner, 'h.partner_id', $hourParams);

            $hourly = $this->connection->executeQuery(
                'SELECT MIN(h.bucket_start) AS lo, MAX(h.bucket_start) AS hi
                 FROM waze_tvt_route_hourly h
                 WHERE h.route_id = :id AND h.bucket_start >= :since' . $hourFilter,
                $hourParams
            )->fetchAssociative();

            return $hourly
                && $hourly['lo'] !== null
                && (string) $hourly['lo'] <= $this->floorHour((string) $raw['lo'])
                && (string) $hourly['hi'] >= $this->floorHour((string) $raw['hi']);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Início das janelas de timeline / heatmap / jam: SEMPRE em hora cheia, para que o
     * caminho dos snapshots brutos e o do agregado horário devolvam números idênticos.
     */
    private function windowStart(int $days): string
    {
        return $this->floorHour($this->sinceUtc($days));
    }

    /** 'Y-m-d H:i:s' → mesma data com minutos e segundos zerados. */
    private function floorHour(string $dateTime): string
    {
        return substr($dateTime, 0, 13) . ':00:00';
    }

    // ═════════════════════════════════════════════════════════════════════
    // Helpers de SQL e de tempo
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Acrescenta o filtro de parceiro (quando há escopo) e registra o parâmetro.
     *
     * @param array<string,mixed> $params
     */
    private function partnerFilter(?Partner $partner, string $column, array &$params): string
    {
        if ($partner === null) {
            return '';
        }

        $params['pid'] = $partner->getId();

        return " AND {$column} = :pid";
    }

    private function sinceUtc(int $days): string
    {
        return (new \DateTimeImmutable("-{$days} days", new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    private function sinceUtcHours(int $hours): string
    {
        return (new \DateTimeImmutable("-{$hours} hours", new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    /** Converte uma coluna UTC para o fuso de exibição (offset já validado como int). */
    private function localExpr(string $column): string
    {
        return "DATE_ADD({$column}, INTERVAL {$this->offsetSeconds} SECOND)";
    }

    /** 'Y-m-d H:i:s' (UTC) → ISO-8601 com offset +00:00. Aceita null/vazio. */
    private function toIsoUtc(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable((string) $value, new \DateTimeZone('UTC')))->format(DATE_ATOM);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Hora cheia no fuso de exibição, no mesmo formato de `timeline[].time`. */
    private function localBucket(mixed $utcValue): ?string
    {
        if ($utcValue === null || $utcValue === '') {
            return null;
        }

        $ts = strtotime((string) $utcValue . ' UTC');

        return $ts === false ? null : gmdate('Y-m-d H:00:00', $ts + $this->offsetSeconds);
    }

    /** Bucket local ('Y-m-d H:00:00') → timestamp UNIX real (UTC). */
    private function bucketToUtcTimestamp(string $bucket): int
    {
        $ts = strtotime($bucket . ' UTC');

        return $ts === false ? 0 : $ts - $this->offsetSeconds;
    }

    // ═════════════════════════════════════════════════════════════════════
    // Helpers de métricas
    // ═════════════════════════════════════════════════════════════════════

    /** @return array{0:?int,1:?float} [atraso em segundos (≥ 0), ratio] */
    private function delayAndRatio(?float $time, ?float $historic): array
    {
        $delay = ($time !== null && $historic !== null)
            ? max(0, (int) round($time - $historic))
            : null;

        $ratio = ($delay !== null && $historic !== null && $historic > 0)
            ? $delay / $historic
            : null;

        return [$delay, $ratio];
    }

    private function currentLevel(?float $ratio): string
    {
        return match (true) {
            $ratio !== null && $ratio >= 0.40 => 'bad',
            $ratio !== null && $ratio >= 0.15 => 'warn',
            default                           => 'good',
        };
    }

    private function subRouteLevel(?float $ratio): string
    {
        return match (true) {
            $ratio === null => 'none',
            $ratio >= 0.40  => 'severe',
            $ratio >= 0.22  => 'heavy',
            $ratio >= 0.10  => 'moderate',
            $ratio > 0.0    => 'light',
            default         => 'none',
        };
    }

    /** @return array{total:int,sumRatio:float,nRatio:int,sumDelay:float,sumJam:float,nJam:int} */
    private function emptyAccumulator(): array
    {
        return ['total' => 0, 'sumRatio' => 0.0, 'nRatio' => 0, 'sumDelay' => 0.0, 'sumJam' => 0.0, 'nJam' => 0];
    }

    /**
     * @param array{total:int,sumRatio:float,nRatio:int,sumDelay:float,sumJam:float,nJam:int} $acc
     * @param array{total:int,sumRatio:float,nRatio:int,sumDelay:float,sumJam:float,nJam:int} $cell
     *
     * @return array{total:int,sumRatio:float,nRatio:int,sumDelay:float,sumJam:float,nJam:int}
     */
    private function accumulate(array $acc, array $cell): array
    {
        foreach ($acc as $k => $v) {
            $acc[$k] = $v + $cell[$k];
        }

        return $acc;
    }

    /** @param array{total:int,sumRatio:float,nRatio:int,sumDelay:float,sumJam:float,nJam:int} $a */
    private function avgRatio(array $a): ?float
    {
        return $a['nRatio'] > 0 ? round($a['sumRatio'] / $a['nRatio'], 4) : null;
    }

    /** @param array{total:int,sumRatio:float,nRatio:int,sumDelay:float,sumJam:float,nJam:int} $a */
    private function avgDelay(array $a): ?float
    {
        return $a['total'] > 0 ? round($a['sumDelay'] / $a['total'], 1) : null;
    }

    // ═════════════════════════════════════════════════════════════════════
    // Geometria (cálculo espacial)
    // ═════════════════════════════════════════════════════════════════════

    /**
     * @param list<array{0:float,1:float}>                  $main
     * @param array<int,list<array{0:float,1:float}>>       $subLines
     *
     * @return list<list<array{0:float,1:float}>>
     */
    private function collectLines(array $main, array $subLines): array
    {
        $lines = [];

        if (count($main) >= 2) {
            $lines[] = $main;
        }
        foreach ($subLines as $line) {
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * Pré-calcula a bounding box (com folga do buffer) de cada linha. Assim
     * cada alerta só é medido contra as linhas que realmente podem estar a
     * menos de $bufferMeters dele.
     *
     * @param list<list<array{0:float,1:float}>> $lines
     *
     * @return list<array{pts:list<array{0:float,1:float}>,minLat:float,maxLat:float,minLng:float,maxLng:float}>
     */
    private function prepareLines(array $lines, float $bufferMeters): array
    {
        $prepared = [];
        $padLat   = ($bufferMeters / self::METERS_PER_DEGREE) * 2;

        foreach ($lines as $pts) {
            if ($pts === []) {
                continue;
            }

            $minLat = $maxLat = $pts[0][0];
            $minLng = $maxLng = $pts[0][1];
            foreach ($pts as [$lat, $lng]) {
                $minLat = min($minLat, $lat);
                $maxLat = max($maxLat, $lat);
                $minLng = min($minLng, $lng);
                $maxLng = max($maxLng, $lng);
            }

            $cos    = max(0.2, cos(deg2rad(($minLat + $maxLat) / 2)));
            $padLng = $padLat / $cos;

            $prepared[] = [
                'pts'    => $pts,
                'minLat' => $minLat - $padLat,
                'maxLat' => $maxLat + $padLat,
                'minLng' => $minLng - $padLng,
                'maxLng' => $maxLng + $padLng,
            ];
        }

        return $prepared;
    }

    /**
     * Menor distância (m) do ponto até qualquer linha preparada.
     * Linhas são tratadas separadamente: nunca existe segmento "fantasma"
     * ligando o fim de uma sub-rota ao início de outra.
     *
     * @param list<array{pts:list<array{0:float,1:float}>,minLat:float,maxLat:float,minLng:float,maxLng:float}> $prepared
     */
    private function distanceToLines(float $lat, float $lng, array $prepared): float
    {
        $min = INF;

        foreach ($prepared as $line) {
            if ($lat < $line['minLat'] || $lat > $line['maxLat']
                || $lng < $line['minLng'] || $lng > $line['maxLng']) {
                continue;
            }

            $min = min($min, $this->distanceToPolyline($lat, $lng, $line['pts']));
        }

        return $min;
    }

    /** @param list<array{0:float,1:float}> $polyline */
    private function distanceToPolyline(float $lat, float $lng, array $polyline): float
    {
        $n = count($polyline);
        if ($n === 0) {
            return INF;
        }
        if ($n === 1) {
            return $this->pointToSegmentDistance(
                $lat, $lng,
                $polyline[0][0], $polyline[0][1],
                $polyline[0][0], $polyline[0][1],
            );
        }

        $min = INF;
        for ($i = 0; $i < $n - 1; ++$i) {
            $d = $this->pointToSegmentDistance(
                $lat, $lng,
                $polyline[$i][0],     $polyline[$i][1],
                $polyline[$i + 1][0], $polyline[$i + 1][1],
            );
            if ($d < $min) {
                $min = $d;
            }
        }

        return $min;
    }

    private function pointToSegmentDistance(
        float $lat, float $lng,
        float $latA, float $lngA,
        float $latB, float $lngB,
    ): float {
        $cosLat = cos(deg2rad($lat));

        $px = $lng  * $cosLat;  $py = $lat;
        $ax = $lngA * $cosLat;  $ay = $latA;
        $bx = $lngB * $cosLat;  $by = $latB;

        $dx    = $bx - $ax;
        $dy    = $by - $ay;
        $lenSq = $dx * $dx + $dy * $dy;

        if ($lenSq <= 0.0) {
            $t = 0.0;
        } else {
            $t = (($px - $ax) * $dx + ($py - $ay) * $dy) / $lenSq;
            $t = max(0.0, min(1.0, $t));
        }

        $ddx = $px - ($ax + $t * $dx);
        $ddy = $py - ($ay + $t * $dy);

        return sqrt($ddx * $ddx + $ddy * $ddy) * self::METERS_PER_DEGREE;
    }

    /**
     * Normaliza GeoJSON LineString, [{lat,lng}], [{x,y}] ou [[lng,lat]] em
     * lista de [lat, lng] com 6 casas decimais (~0,1 m) — payload menor no HTML.
     *
     * @return list<array{0:float,1:float}>
     */
    private function normalizeLine(mixed $raw): array
    {
        if ($raw === null || $raw === '' || $raw === false) {
            return [];
        }

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                return [];
            }
            $raw = $decoded;
        }

        if (!is_array($raw) || $raw === []) {
            return [];
        }

        $out = [];

        if (isset($raw['type'], $raw['coordinates'])
            && $raw['type'] === 'LineString'
            && is_array($raw['coordinates'])) {
            foreach ($raw['coordinates'] as $c) {
                if (is_array($c) && count($c) >= 2) {
                    $out[] = [round((float) $c[1], 6), round((float) $c[0], 6)];
                }
            }

            return $out;
        }

        foreach ($raw as $point) {
            if (!is_array($point)) {
                continue;
            }

            if (isset($point['lat'], $point['lng'])) {
                $out[] = [round((float) $point['lat'], 6), round((float) $point['lng'], 6)];
            } elseif (isset($point['x'], $point['y'])) {
                $out[] = [round((float) $point['y'], 6), round((float) $point['x'], 6)];
            } elseif (count($point) >= 2) {
                $point = array_values($point);
                $out[] = [round((float) $point[1], 6), round((float) $point[0], 6)];
            }
        }

        return $out;
    }

    // ═════════════════════════════════════════════════════════════════════
    // Rótulos
    // ═════════════════════════════════════════════════════════════════════

    private function labelAlertType(string $type): string
    {
        return match (strtoupper($type)) {
            'HAZARD'        => 'Perigo',
            'ROAD_CLOSED'   => 'Via fechada',
            'ACCIDENT'      => 'Acidente',
            'JAM'           => 'Congestionamento',
            'POLICE'        => 'Polícia',
            'WEATHERHAZARD' => 'Perigo climático',
            'CONSTRUCTION'  => 'Obras',
            default         => ucfirst(strtolower($type)) ?: 'Alerta',
        };
    }

    private function alertIcon(string $type): string
    {
        return match (strtoupper($type)) {
            'ACCIDENT'      => '✕',
            'HAZARD'        => '▲',
            'WEATHERHAZARD' => '☂',
            'ROAD_CLOSED'   => '⊘',
            'POLICE'        => '◉',
            'JAM'           => '≋',
            default         => '⚠',
        };
    }
}
