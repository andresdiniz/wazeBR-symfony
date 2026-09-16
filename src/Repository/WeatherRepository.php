<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Partner;
use Doctrine\DBAL\Connection;

/**
 * Agregações climáticas + análise de correlação com métricas de trânsito.
 *
 * ─── Modelo ──────────────────────────────────────────────────────────
 *   - WeatherObservation registra temp/umidade/chuva/vento por hora
 *   - Cruzamos com snapshots de rotas / alertas / jams para descobrir
 *     se uma variável climática está associada a picos de trânsito.
 *
 * ─── Correlação ──────────────────────────────────────────────────────
 *   Pearson r entre:
 *     X = variável climática (bucketed por hora)
 *     Y = métrica alvo (delay ratio médio, count de alertas, etc)
 *   Só buckets com ≥ 2 amostras entram no cálculo.
 */
final class WeatherRepository
{
    private const WINDOW_DAYS   = 30;
    private const TIMELINE_DAYS = 7;

    public function __construct(private readonly Connection $connection)
    {
    }

    // ─────────────────────────────────────────────────────────────────────
    // Index — visão geral
    // ─────────────────────────────────────────────────────────────────────

    /** @return array{kpis:array,stations:list<array>} */
    public function getIndex(?Partner $partner): array
    {
        $stations = $this->loadStationsWithLatest($partner);

        $sumTemp = 0.0; $nTemp = 0;
        $sumHum  = 0.0; $nHum = 0;
        $sumRain = 0.0;
        $sumWind = 0.0; $nWind = 0;

        foreach ($stations as $s) {
            if ($s['temperature'] !== null) { $sumTemp += $s['temperature']; $nTemp++; }
            if ($s['humidity'] !== null)    { $sumHum  += $s['humidity'];    $nHum++; }
            if ($s['rain24h'] !== null)     { $sumRain += $s['rain24h']; }
            if ($s['windSpeed'] !== null)   { $sumWind += $s['windSpeed'];   $nWind++; }
        }

        return [
            'kpis' => [
                'stations'         => count($stations),
                'avgTemperature'   => $nTemp > 0 ? round($sumTemp / $nTemp, 1) : null,
                'avgHumidity'      => $nHum  > 0 ? (int) round($sumHum  / $nHum)  : null,
                'totalRain24h'     => round($sumRain, 1),
                'avgWindSpeed'     => $nWind > 0 ? round($sumWind / $nWind, 1) : null,
                'hotter'           => $this->pickExtreme($stations, 'temperature', 'max'),
                'colder'           => $this->pickExtreme($stations, 'temperature', 'min'),
                'wetter'           => $this->pickExtreme($stations, 'rain24h',     'max'),
            ],
            'stations' => $stations,
        ];
    }

    private function pickExtreme(array $stations, string $key, string $mode): ?array
    {
        $best = null;
        foreach ($stations as $s) {
            if (($s[$key] ?? null) === null) continue;
            if ($best === null) { $best = $s; continue; }
            if ($mode === 'max' && $s[$key] > $best[$key]) $best = $s;
            if ($mode === 'min' && $s[$key] < $best[$key]) $best = $s;
        }
        if ($best === null) return null;
        return ['name' => $best['name'], 'value' => $best[$key]];
    }

    /** @return list<array<string,mixed>> */
    private function loadStationsWithLatest(?Partner $partner): array
    {
        $params = [];
        $pf = '';
        if ($partner !== null) {
            $pf = ' AND l.partner_id = :pid';
            $params['pid'] = $partner->getId();
        }

        $sql = "SELECT
                    l.id, l.name, l.city, l.state, l.provider,
                    l.latitude, l.longitude,
                    o.temperature, o.apparent_temperature, o.relative_humidity,
                    o.precipitation, o.rain, o.wind_speed, o.weather_code,
                    o.observed_at,
                    (SELECT SUM(COALESCE(o2.rain, o2.precipitation, 0))
                     FROM weather_observation o2
                     WHERE o2.weather_location_id = l.id
                       AND o2.observed_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)
                    ) AS rain24h,
                    (SELECT SUM(COALESCE(o3.rain, o3.precipitation, 0))
                     FROM weather_observation o3
                     WHERE o3.weather_location_id = l.id
                       AND o3.observed_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)
                    ) AS rain1h
                FROM weather_location l
                LEFT JOIN weather_observation o
                    ON o.id = (
                        SELECT o4.id FROM weather_observation o4
                        WHERE o4.weather_location_id = l.id
                        ORDER BY o4.observed_at DESC, o4.id DESC
                        LIMIT 1
                    )
                WHERE l.active = 1 {$pf}
                ORDER BY l.name ASC";

        $rows = $this->connection->executeQuery($sql, $params)->fetchAllAssociative();

        return array_map(static fn ($r) => [
            'id'             => (int) $r['id'],
            'name'           => $r['name'] ?: 'Estação #'.$r['id'],
            'city'           => $r['city'],
            'state'          => $r['state'],
            'provider'       => $r['provider'],
            'lat'            => $r['latitude']  !== null ? (float) $r['latitude']  : null,
            'lng'            => $r['longitude'] !== null ? (float) $r['longitude'] : null,
            'temperature'    => $r['temperature'] !== null ? (float) $r['temperature'] : null,
            'apparentTemp'   => $r['apparent_temperature'] !== null ? (float) $r['apparent_temperature'] : null,
            'humidity'       => $r['relative_humidity'] !== null ? (int) $r['relative_humidity'] : null,
            'precipitation'  => $r['precipitation'] !== null ? (float) $r['precipitation'] : null,
            'windSpeed'      => $r['wind_speed'] !== null ? (float) $r['wind_speed'] : null,
            'weatherCode'    => $r['weather_code'] !== null ? (int) $r['weather_code'] : null,
            'rain24h'        => $r['rain24h'] !== null ? round((float) $r['rain24h'], 1) : 0.0,
            'rain1h'         => $r['rain1h']  !== null ? round((float) $r['rain1h'], 1)  : 0.0,
            'observedAt'     => $r['observed_at'],
        ], $rows);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Show — detalhe de uma estação
    // ─────────────────────────────────────────────────────────────────────

    /** @return array<string,mixed>|null */
    public function getDetail(?Partner $partner, int $locationId): ?array
    {
        $location = $this->loadLocation($partner, $locationId);
        if ($location === null) return null;

        $current  = $this->loadCurrentObservation($partner, $locationId);
        $timeline = $this->loadTimeline($partner, $locationId, self::TIMELINE_DAYS);
        $heatmap  = $this->loadHeatmap($partner, $locationId, self::WINDOW_DAYS);
        $byHour   = $this->loadByHour($partner, $locationId, self::WINDOW_DAYS);
        $byDow    = $this->loadByDow($partner, $locationId, self::WINDOW_DAYS);
        $rainHourly = $this->loadRainHourly($partner, $locationId, 48);
        $recent   = $this->loadRecentObservations($partner, $locationId, 20);

        return [
            'location'    => $location,
            'current'     => $current,
            'timeline'    => $timeline,
            'heatmap'     => $heatmap,
            'byHour'      => $byHour,
            'byDow'       => $byDow,
            'rainHourly'  => $rainHourly,
            'recent'      => $recent,
            'stats'       => $this->computeStationStats($timeline, $heatmap, $byHour, $byDow, $current),
        ];
    }

    private function loadLocation(?Partner $partner, int $id): ?array
    {
        $params = ['id' => $id];
        $pf = '';
        if ($partner !== null) { $pf = ' AND partner_id = :pid'; $params['pid'] = $partner->getId(); }

        $row = $this->connection->executeQuery(
            "SELECT id, name, city, state, provider, timezone,
                    CAST(latitude AS DECIMAL(10,7)) AS lat,
                    CAST(longitude AS DECIMAL(10,7)) AS lng
             FROM weather_location
             WHERE id = :id AND active = 1 {$pf}
             LIMIT 1",
            $params
        )->fetchAssociative();

        if (!$row) return null;

        return [
            'id'       => (int) $row['id'],
            'name'     => $row['name'] ?: 'Estação #'.$row['id'],
            'city'     => $row['city'],
            'state'    => $row['state'],
            'provider' => $row['provider'],
            'timezone' => $row['timezone'],
            'lat'      => (float) $row['lat'],
            'lng'      => (float) $row['lng'],
        ];
    }

    private function loadCurrentObservation(?Partner $partner, int $locationId): ?array
    {
        $params = ['id' => $locationId];
        $pf = '';
        if ($partner !== null) { $pf = ' AND partner_id = :pid'; $params['pid'] = $partner->getId(); }

        $row = $this->connection->executeQuery(
            "SELECT temperature, apparent_temperature, relative_humidity,
                    precipitation, rain, showers, snowfall, weather_code,
                    cloud_cover, surface_pressure, wind_speed, wind_direction,
                    wind_gusts, visibility, is_day, observed_at
             FROM weather_observation
             WHERE weather_location_id = :id {$pf}
             ORDER BY observed_at DESC, id DESC
             LIMIT 1",
            $params
        )->fetchAssociative();

        if (!$row) return null;

        return [
            'temperature'    => $row['temperature']         !== null ? (float) $row['temperature']         : null,
            'apparentTemp'   => $row['apparent_temperature']!== null ? (float) $row['apparent_temperature']: null,
            'humidity'       => $row['relative_humidity']   !== null ? (int)   $row['relative_humidity']   : null,
            'precipitation'  => $row['precipitation']       !== null ? (float) $row['precipitation']       : null,
            'rain'           => $row['rain']                !== null ? (float) $row['rain']                : null,
            'showers'        => $row['showers']             !== null ? (float) $row['showers']             : null,
            'snowfall'       => $row['snowfall']            !== null ? (float) $row['snowfall']            : null,
            'weatherCode'    => $row['weather_code']        !== null ? (int)   $row['weather_code']        : null,
            'cloudCover'     => $row['cloud_cover']         !== null ? (int)   $row['cloud_cover']         : null,
            'pressure'       => $row['surface_pressure']    !== null ? (float) $row['surface_pressure']    : null,
            'windSpeed'      => $row['wind_speed']          !== null ? (float) $row['wind_speed']          : null,
            'windDirection'  => $row['wind_direction']      !== null ? (int)   $row['wind_direction']      : null,
            'windGusts'      => $row['wind_gusts']          !== null ? (float) $row['wind_gusts']          : null,
            'visibility'     => $row['visibility']          !== null ? (int)   $row['visibility']          : null,
            'isDay'          => $row['is_day']              !== null ? (bool)  $row['is_day']              : null,
            'observedAt'     => $row['observed_at'],
        ];
    }

    /** @return list<array{time:string,temperature:?float,humidity:?int,rain:float,wind:?float}> */
    private function loadTimeline(?Partner $partner, int $locationId, int $days): array
    {
        $since = (new \DateTimeImmutable("-{$days} days", new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $params = ['id' => $locationId, 'since' => $since];
        $pf = '';
        if ($partner !== null) { $pf = ' AND partner_id = :pid'; $params['pid'] = $partner->getId(); }

        $sql = "SELECT
                    DATE_FORMAT(DATE_ADD(observed_at, INTERVAL -3 HOUR), '%Y-%m-%d %H:00:00') AS bucket,
                    AVG(temperature) AS temperature,
                    AVG(relative_humidity) AS humidity,
                    SUM(COALESCE(rain, precipitation, 0)) AS rain,
                    AVG(wind_speed) AS wind
                FROM weather_observation
                WHERE weather_location_id = :id
                  AND observed_at >= :since
                  {$pf}
                GROUP BY bucket
                ORDER BY bucket ASC
                LIMIT 500";

        $rows = $this->connection->executeQuery($sql, $params)->fetchAllAssociative();

        return array_map(static fn ($r) => [
            'time'        => (string) $r['bucket'],
            'temperature' => $r['temperature'] !== null ? round((float) $r['temperature'], 1) : null,
            'humidity'    => $r['humidity']    !== null ? (int) round((float) $r['humidity']) : null,
            'rain'        => $r['rain']        !== null ? round((float) $r['rain'], 2) : 0.0,
            'wind'        => $r['wind']        !== null ? round((float) $r['wind'], 1) : null,
        ], $rows);
    }

    /** @return list<array{dow:int,hour:int,avgTemp:?float,avgHum:?float,totalRain:float,count:int}> */
    private function loadHeatmap(?Partner $partner, int $locationId, int $days): array
    {
        $since = (new \DateTimeImmutable("-{$days} days", new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $params = ['id' => $locationId, 'since' => $since];
        $pf = '';
        if ($partner !== null) { $pf = ' AND partner_id = :pid'; $params['pid'] = $partner->getId(); }

        $sql = "SELECT
                    WEEKDAY(DATE_ADD(observed_at, INTERVAL -3 HOUR)) AS dow,
                    HOUR(DATE_ADD(observed_at, INTERVAL -3 HOUR)) AS hour,
                    AVG(temperature) AS avg_temp,
                    AVG(relative_humidity) AS avg_hum,
                    SUM(COALESCE(rain, precipitation, 0)) AS total_rain,
                    COUNT(*) AS total
                FROM weather_observation
                WHERE weather_location_id = :id
                  AND observed_at >= :since
                  {$pf}
                GROUP BY dow, hour
                ORDER BY dow, hour";

        $rows = $this->connection->executeQuery($sql, $params)->fetchAllAssociative();

        return array_map(static fn ($r) => [
            'dow'       => (int) $r['dow'],
            'hour'      => (int) $r['hour'],
            'avgTemp'   => $r['avg_temp'] !== null ? round((float) $r['avg_temp'], 1) : null,
            'avgHum'    => $r['avg_hum']  !== null ? (int) round((float) $r['avg_hum']) : null,
            'totalRain' => $r['total_rain'] !== null ? round((float) $r['total_rain'], 2) : 0.0,
            'count'     => (int) $r['total'],
        ], $rows);
    }

    private function loadByHour(?Partner $partner, int $locationId, int $days): array
    {
        $since = (new \DateTimeImmutable("-{$days} days", new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $params = ['id' => $locationId, 'since' => $since];
        $pf = '';
        if ($partner !== null) { $pf = ' AND partner_id = :pid'; $params['pid'] = $partner->getId(); }

        $rows = $this->connection->executeQuery(
            "SELECT
                HOUR(DATE_ADD(observed_at, INTERVAL -3 HOUR)) AS hour,
                AVG(temperature) AS avg_temp,
                AVG(relative_humidity) AS avg_hum,
                SUM(COALESCE(rain, precipitation, 0)) AS total_rain,
                COUNT(*) AS total
             FROM weather_observation
             WHERE weather_location_id = :id
               AND observed_at >= :since
               {$pf}
             GROUP BY hour",
            $params
        )->fetchAllAssociative();

        $map = [];
        foreach ($rows as $r) $map[(int) $r['hour']] = $r;

        $out = [];
        for ($h = 0; $h < 24; $h++) {
            $r = $map[$h] ?? null;
            $out[] = [
                'hour'      => $h,
                'avgTemp'   => $r && $r['avg_temp'] !== null ? round((float) $r['avg_temp'], 1) : null,
                'avgHum'    => $r && $r['avg_hum']  !== null ? (int) round((float) $r['avg_hum'])  : null,
                'totalRain' => $r && $r['total_rain'] !== null ? round((float) $r['total_rain'], 2) : 0.0,
                'count'     => $r ? (int) $r['total'] : 0,
            ];
        }
        return $out;
    }

    private function loadByDow(?Partner $partner, int $locationId, int $days): array
    {
        $since = (new \DateTimeImmutable("-{$days} days", new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $params = ['id' => $locationId, 'since' => $since];
        $pf = '';
        if ($partner !== null) { $pf = ' AND partner_id = :pid'; $params['pid'] = $partner->getId(); }

        $rows = $this->connection->executeQuery(
            "SELECT
                WEEKDAY(DATE_ADD(observed_at, INTERVAL -3 HOUR)) AS dow,
                AVG(temperature) AS avg_temp,
                AVG(relative_humidity) AS avg_hum,
                SUM(COALESCE(rain, precipitation, 0)) AS total_rain,
                COUNT(*) AS total
             FROM weather_observation
             WHERE weather_location_id = :id
               AND observed_at >= :since
               {$pf}
             GROUP BY dow",
            $params
        )->fetchAllAssociative();

        $map = [];
        foreach ($rows as $r) $map[(int) $r['dow']] = $r;

        $out = [];
        for ($d = 0; $d < 7; $d++) {
            $r = $map[$d] ?? null;
            $out[] = [
                'dow'       => $d,
                'avgTemp'   => $r && $r['avg_temp'] !== null ? round((float) $r['avg_temp'], 1) : null,
                'avgHum'    => $r && $r['avg_hum']  !== null ? (int) round((float) $r['avg_hum'])  : null,
                'totalRain' => $r && $r['total_rain'] !== null ? round((float) $r['total_rain'], 2) : 0.0,
                'count'     => $r ? (int) $r['total'] : 0,
            ];
        }
        return $out;
    }

    /** @return list<array{time:string,rain:float}> */
    private function loadRainHourly(?Partner $partner, int $locationId, int $hours): array
    {
        $since = (new \DateTimeImmutable("-{$hours} hours", new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $params = ['id' => $locationId, 'since' => $since];
        $pf = '';
        if ($partner !== null) { $pf = ' AND partner_id = :pid'; $params['pid'] = $partner->getId(); }

        $rows = $this->connection->executeQuery(
            "SELECT
                DATE_FORMAT(DATE_ADD(observed_at, INTERVAL -3 HOUR), '%Y-%m-%d %H:00:00') AS bucket,
                SUM(COALESCE(rain, precipitation, 0)) AS rain
             FROM weather_observation
             WHERE weather_location_id = :id
               AND observed_at >= :since
               {$pf}
             GROUP BY bucket
             ORDER BY bucket ASC",
            $params
        )->fetchAllAssociative();

        return array_map(static fn ($r) => [
            'time' => (string) $r['bucket'],
            'rain' => round((float) $r['rain'], 2),
        ], $rows);
    }

    /** @return list<array<string,mixed>> */
    private function loadRecentObservations(?Partner $partner, int $locationId, int $limit): array
    {
        $params = ['id' => $locationId];
        $pf = '';
        if ($partner !== null) { $pf = ' AND partner_id = :pid'; $params['pid'] = $partner->getId(); }

        $rows = $this->connection->executeQuery(
            "SELECT temperature, relative_humidity, precipitation, rain,
                    wind_speed, weather_code, observed_at
             FROM weather_observation
             WHERE weather_location_id = :id {$pf}
             ORDER BY observed_at DESC, id DESC
             LIMIT " . (int) $limit,
            $params
        )->fetchAllAssociative();

        return array_map(static fn ($r) => [
            'temperature'   => $r['temperature'] !== null ? (float) $r['temperature'] : null,
            'humidity'      => $r['relative_humidity'] !== null ? (int) $r['relative_humidity'] : null,
            'precipitation' => $r['precipitation'] !== null ? (float) $r['precipitation'] : null,
            'rain'          => $r['rain'] !== null ? (float) $r['rain'] : null,
            'windSpeed'     => $r['wind_speed'] !== null ? (float) $r['wind_speed'] : null,
            'weatherCode'   => $r['weather_code'] !== null ? (int) $r['weather_code'] : null,
            'observedAt'    => $r['observed_at'],
        ], $rows);
    }

    private function computeStationStats(array $timeline, array $heatmap, array $byHour, array $byDow, ?array $current): array
    {
        $sumT = 0.0; $nT = 0;
        $sumR = 0.0;
        $maxT = null; $minT = null;

        foreach ($timeline as $p) {
            if ($p['temperature'] !== null) {
                $sumT += $p['temperature'];
                $nT++;
                if ($maxT === null || $p['temperature'] > $maxT) $maxT = $p['temperature'];
                if ($minT === null || $p['temperature'] < $minT) $minT = $p['temperature'];
            }
            $sumR += $p['rain'] ?? 0;
        }

        $worstHour = null; $hottestHour = null;
        foreach ($byHour as $p) {
            if ($p['avgTemp'] === null) continue;
            if ($worstHour === null || $p['totalRain'] > $worstHour['totalRain']) $worstHour = $p;
            if ($hottestHour === null || $p['avgTemp'] > $hottestHour['avgTemp']) $hottestHour = $p;
        }

        return [
            'avgTemp7d'      => $nT > 0 ? round($sumT / $nT, 1) : null,
            'maxTemp7d'      => $maxT !== null ? round($maxT, 1) : null,
            'minTemp7d'      => $minT !== null ? round($minT, 1) : null,
            'totalRain7d'    => round($sumR, 1),
            'totalRain30d'   => round(array_sum(array_map(fn ($p) => $p['totalRain'] ?? 0, $heatmap)), 1),
            'rainiestHour'   => $worstHour,
            'hottestHour'    => $hottestHour,
            'sampleCount30d' => count($heatmap),
            'current'        => $current,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Analysis — correlação variável climática × métrica
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @param array{locationId:int,variable:string,metric:string,days:int,routeId:?int} $params
     * @return array<string,mixed>|null
     */
    public function getAnalysis(?Partner $partner, array $params): ?array
    {
        $locationId = (int) ($params['locationId'] ?? 0);
        $variable   = (string) ($params['variable'] ?? 'temperature');
        $metric     = (string) ($params['metric']   ?? 'route_delay');
        $days       = max(7, min(90, (int) ($params['days'] ?? 30)));
        $routeId    = $params['routeId'] !== null ? (int) $params['routeId'] : null;

        $location = $this->loadLocation($partner, $locationId);
        if ($location === null) return null;

        $since = (new \DateTimeImmutable("-{$days} days", new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $weatherSeries = $this->loadWeatherSeries($partner, $since, $locationId);
        $metricSeries  = $this->loadMetricSeries($partner, $since, $metric, $routeId);

        if ($weatherSeries === [] || $metricSeries === []) {
            return [
                'location'    => $location,
                'variable'    => $variable,
                'metric'      => $metric,
                'days'        => $days,
                'routeId'     => $routeId,
                'points'      => [],
                'bins'        => [],
                'correlation' => null,
                'samples'     => 0,
            ];
        }

        // Join por bucket horário
        $points = [];
        foreach ($weatherSeries as $bucket => $w) {
            if (!isset($metricSeries[$bucket])) continue;
            $x = $w[$variable] ?? null;
            $y = $metricSeries[$bucket]['value'] ?? null;
            $n = min((int) ($w['samples'] ?? 0), (int) ($metricSeries[$bucket]['samples'] ?? 0));

            if ($x === null || $y === null || $n < 1) continue;

            $points[] = [
                'time'   => $bucket,
                'x'      => $x,
                'y'      => $y,
                'samples'=> $n,
            ];
        }

        // Filtra pra amostra mínima (evita ruído)
        $points = array_values(array_filter($points, static fn ($p) => $p['samples'] >= 2));

        // Correlação Pearson
        $correlation = null;
        if (count($points) >= 5) {
            $xs = array_map(static fn ($p) => $p['x'], $points);
            $ys = array_map(static fn ($p) => $p['y'], $points);
            $correlation = $this->pearson($xs, $ys);
        }

        // Binagem por quantis — mais robusto que ranges fixos
        $bins = $this->computeBins($points, 5);

        return [
            'location'    => $location,
            'variable'    => $variable,
            'metric'      => $metric,
            'days'        => $days,
            'routeId'     => $routeId,
            'points'      => $points,
            'bins'        => $bins,
            'correlation' => $correlation,
            'samples'     => count($points),
        ];
    }

    /**
     * @return array<string, array<string,mixed>>  bucket => {temperature, precipitation, relative_humidity, wind_speed, samples}
     */
    private function loadWeatherSeries(?Partner $partner, string $since, int $locationId): array
    {
        $params = ['id' => $locationId, 'since' => $since];
        $pf = '';
        if ($partner !== null) { $pf = ' AND partner_id = :pid'; $params['pid'] = $partner->getId(); }

        $rows = $this->connection->executeQuery(
            "SELECT
                DATE_FORMAT(DATE_ADD(observed_at, INTERVAL -3 HOUR), '%Y-%m-%d %H:00:00') AS bucket,
                AVG(temperature) AS temperature,
                SUM(COALESCE(rain, precipitation, 0)) AS precipitation,
                AVG(relative_humidity) AS relative_humidity,
                AVG(wind_speed) AS wind_speed,
                COUNT(*) AS samples
             FROM weather_observation
             WHERE weather_location_id = :id
               AND observed_at >= :since
               {$pf}
             GROUP BY bucket
             ORDER BY bucket",
            $params
        )->fetchAllAssociative();

        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['bucket']] = [
                'temperature'        => $r['temperature']        !== null ? round((float) $r['temperature'], 1) : null,
                'precipitation'      => $r['precipitation']      !== null ? round((float) $r['precipitation'], 2) : null,
                'relative_humidity'  => $r['relative_humidity']  !== null ? round((float) $r['relative_humidity'], 1) : null,
                'wind_speed'         => $r['wind_speed']         !== null ? round((float) $r['wind_speed'], 1) : null,
                'samples'            => (int) $r['samples'],
            ];
        }
        return $out;
    }

    /**
     * Métricas alvo. Todas retornam bucket => {value, samples}.
     *
     * @return array<string, array{value:float,samples:int}>
     */
    private function loadMetricSeries(?Partner $partner, string $since, string $metric, ?int $routeId): array
    {
        switch ($metric) {
            case 'route_delay':
                return $this->metricRouteDelay($partner, $since, $routeId);
            case 'alert_count':
                return $this->metricAlertCount($partner, $since);
            case 'jam_count':
                return $this->metricJamCount($partner, $since);
            default:
                return [];
        }
    }

    /** @return array<string, array{value:float,samples:int}> */
    private function metricRouteDelay(?Partner $partner, string $since, ?int $routeId): array
    {
        $params = ['since' => $since];
        $pf = '';
        if ($partner !== null) { $pf .= ' AND partner_id = :pid'; $params['pid'] = $partner->getId(); }
        if ($routeId !== null) { $pf .= ' AND route_id = :rid'; $params['rid'] = $routeId; }

        $rows = $this->connection->executeQuery(
            "SELECT
                DATE_FORMAT(DATE_ADD(recorded_at, INTERVAL -3 HOUR), '%Y-%m-%d %H:00:00') AS bucket,
                AVG(CASE WHEN historic_time > 0
                         THEN (time - historic_time) / historic_time
                         ELSE NULL END) * 100 AS value,
                COUNT(*) AS samples
             FROM waze_tvt_route_snapshot
             WHERE recorded_at >= :since
               AND time IS NOT NULL
               AND historic_time IS NOT NULL
               {$pf}
             GROUP BY bucket
             ORDER BY bucket",
            $params
        )->fetchAllAssociative();

        $out = [];
        foreach ($rows as $r) {
            if ($r['value'] === null) continue;
            $out[(string) $r['bucket']] = [
                'value'   => round((float) $r['value'], 2),
                'samples' => (int) $r['samples'],
            ];
        }
        return $out;
    }

    /** @return array<string, array{value:float,samples:int}> */
    private function metricAlertCount(?Partner $partner, string $since): array
    {
        $params = ['since' => $since];
        $pf = '';
        if ($partner !== null) { $pf = ' AND partner_id = :pid'; $params['pid'] = $partner->getId(); }

        $rows = $this->connection->executeQuery(
            "SELECT
                DATE_FORMAT(DATE_ADD(collected_at, INTERVAL -3 HOUR), '%Y-%m-%d %H:00:00') AS bucket,
                COUNT(*) AS value
             FROM waze_alerts
             WHERE collected_at >= :since {$pf}
             GROUP BY bucket
             ORDER BY bucket",
            $params
        )->fetchAllAssociative();

        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['bucket']] = [
                'value'   => (float) $r['value'],
                'samples' => (int) $r['value'],
            ];
        }
        return $out;
    }

    /** @return array<string, array{value:float,samples:int}> */
    private function metricJamCount(?Partner $partner, string $since): array
    {
        $params = ['since' => $since];
        $pf = '';
        if ($partner !== null) { $pf = ' AND partner_id = :pid'; $params['pid'] = $partner->getId(); }

        $rows = $this->connection->executeQuery(
            "SELECT
                DATE_FORMAT(DATE_ADD(collected_at, INTERVAL -3 HOUR), '%Y-%m-%d %H:00:00') AS bucket,
                COUNT(*) AS value
             FROM waze_jams
             WHERE collected_at >= :since {$pf}
             GROUP BY bucket
             ORDER BY bucket",
            $params
        )->fetchAllAssociative();

        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['bucket']] = [
                'value'   => (float) $r['value'],
                'samples' => (int) $r['value'],
            ];
        }
        return $out;
    }

    /**
     * Binagem por quantis — divide os pontos em N grupos com a mesma
     * quantidade. Mais robusto que ranges fixos quando a distribuição
     * é enviesada (ex.: chuva, que é sempre 0 exceto em eventos).
     *
     * @param list<array{x:float,y:float,samples:int}> $points
     * @return list<array{min:float,max:float,avgY:float,count:int,label:string}>
     */
    private function computeBins(array $points, int $n): array
    {
        if (count($points) < $n * 2) return [];

        // Ordena por X (variável climática)
        usort($points, static fn ($a, $b) => $a['x'] <=> $b['x']);

        $total = count($points);
        $size  = intdiv($total, $n);
        $bins  = [];

        for ($i = 0; $i < $n; $i++) {
            $start = $i * $size;
            $end   = $i === $n - 1 ? $total : $start + $size;

            $slice = array_slice($points, $start, $end - $start);
            if ($slice === []) continue;

            $xs = array_map(static fn ($p) => $p['x'], $slice);
            $ys = array_map(static fn ($p) => $p['y'], $slice);

            $minX = min($xs);
            $maxX = max($xs);
            $avgY = array_sum($ys) / count($ys);

            $bins[] = [
                'min'   => round($minX, 1),
                'max'   => round($maxX, 1),
                'avgY'  => round($avgY, 2),
                'count' => count($slice),
                'label' => sprintf('%.1f – %.1f', $minX, $maxX),
            ];
        }

        return $bins;
    }

    /**
     * Coeficiente de Pearson. Retorna null se variância for zero
     * (todos os valores iguais → não dá pra correlacionar).
     */
    private function pearson(array $xs, array $ys): ?float
    {
        $n = count($xs);
        if ($n < 3 || $n !== count($ys)) return null;

        $meanX = array_sum($xs) / $n;
        $meanY = array_sum($ys) / $n;

        $num = 0.0; $denX = 0.0; $denY = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $dx = $xs[$i] - $meanX;
            $dy = $ys[$i] - $meanY;
            $num  += $dx * $dy;
            $denX += $dx * $dx;
            $denY += $dy * $dy;
        }

        if ($denX <= 1e-12 || $denY <= 1e-12) return null;

        return round($num / sqrt($denX * $denY), 3);
    }
}
