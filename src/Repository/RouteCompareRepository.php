<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Partner;
use Doctrine\DBAL\Connection;

final class RouteCompareRepository
{
    public function __construct(private readonly Connection $db) {}

    public function compare(?Partner $p, int $aId, int $bId, array $o = []): ?array
    {
        if ($aId === $bId) return null;
        $b = (int)($o['bucketMinutes'] ?? 15);
        if (!in_array($b, [2,5,10,15,30,60], true)) $b = 15;
        $h = max(1, min(168, (int)($o['hours'] ?? ($b <= 15 ? 24 : 168))));
        $A = $this->route($p, $aId, $b, $h);
        $B = $this->route($p, $bId, $b, $h);
        if (!$A || !$B) return null;
        $tl = $this->merge($A['tl'], $B['tl'], 'time');
        $bh = $this->merge($A['bh'], $B['bh'], 'hour');
        $bd = $this->merge($A['bd'], $B['bd'], 'dow');
        $heat = [];
        for ($d = 0; $d < 7; $d++) for ($x = 0; $x < 24; $x++) {
            $ca = $A['heat']["$d:$x"] ?? null;
            $cb = $B['heat']["$d:$x"] ?? null;
            $ra = $ca && $ca['n'] >= 2 ? $ca['r'] : null;
            $rb = $cb && $cb['n'] >= 2 ? $cb['r'] : null;
            $heat[] = ['dow' => $d, 'hour' => $x, 'aRatio' => $ra, 'bRatio' => $rb, 'diff' => $ra !== null && $rb !== null ? round($ra - $rb, 4) : null, 'aDelay' => $ca['d'] ?? null, 'bDelay' => $cb['d'] ?? null];
        }
        $common = $this->common($A['subs'], $B['subs']);
        $bestA = $bestB = null;
        foreach ($heat as $c) {
            if ($c['diff'] === null) continue;
            if ($c['diff'] < 0 && ($bestA === null || $c['diff'] < $bestA['diff'])) $bestA = $c;
            if ($c['diff'] > 0 && ($bestB === null || $c['diff'] > $bestB['diff'])) $bestB = $c;
        }
        $ra = $A['stats']['avgRatio7d'];
        $rb = $B['stats']['avgRatio7d'];
        $diff = $ra !== null && $rb !== null ? round($ra - $rb, 4) : null;
        $win = $this->win($ra, $rb);
        $sa = $this->dev($A['stl']);
        $sb = $this->dev($B['stl']);
        $ins = [
            'betterOverall' => $win,
            'avgDiffRatio' => $diff,
            'bestWindowForA' => $bestA,
            'bestWindowForB' => $bestB,
            'consistencyA' => $sa,
            'consistencyB' => $sb,
            'moreConsistent' => $this->win($sa, $sb, 0.000001),
            'recommendation' => $win === null ? 'Sem dados suficientes para recomendar uma rota.' : ($win === 'tie' ? 'As rotas têm desempenho equivalente na média.' : sprintf('A rota %s apresenta %.1f p.p. menos atraso relativo médio em 7 dias.', strtoupper($win), abs((float)$diff) * 100)),
        ];
        $pa = $pb = 0;
        foreach (['avgDelay24h', 'avgDelay7d', 'avgRatio24h', 'avgRatio7d'] as $k) {
            $x = $A['stats'][$k];
            $y = $B['stats'][$k];
            if ($x === null || $y === null) continue;
            if ($x < $y) $pa++;
            elseif ($y < $x) $pb++;
        }
        $alerts = $this->alerts($p, $A['segs'], $B['segs']);
        unset($A['tl'], $A['bh'], $A['bd'], $A['heat'], $A['subs'], $A['stl']);
        unset($B['tl'], $B['bh'], $B['bd'], $B['heat'], $B['subs'], $B['stl']);
        return [
            'a' => $A, 'b' => $B, 'timeline' => $tl, 'timelineBucketMinutes' => $b, 'timelineHours' => $h,
            'heatmapDiff' => $heat, 'byHour' => $bh, 'byDow' => $bd, 'commonSubRoutes' => $common,
            'alerts' => $alerts, 'insights' => $ins,
            'stats' => [
                'winnerNow' => $this->win($A['current']['delayRatio'] ?? null, $B['current']['delayRatio'] ?? null),
                'winner7d' => $win, 'pointsA' => $pa, 'pointsB' => $pb,
            ],
        ];
    }

    private function q(string $sql, ?Partner $p, array $par = []): array
    {
        $f = '';
        if ($p !== null) { $f = ' AND partner_id = :pid'; $par['pid'] = $p->getId(); }
        return $this->db->executeQuery(str_replace('{p}', $f, $sql), $par)->fetchAllAssociative();
    }

    private function route(?Partner $p, int $id, int $b, int $h): ?array
    {
        $r = $this->q('SELECT id,route_id,name,from_name,to_name,length,geometry FROM waze_tvt_route WHERE id=:id {p} LIMIT 1', $p, ['id' => $id])[0] ?? null;
        if (!$r) return null;
        $cur = $this->q('SELECT time,historic_time,jam_level,recorded_at FROM waze_tvt_route_snapshot WHERE route_id=:id {p} ORDER BY recorded_at DESC,id DESC LIMIT 1', $p, ['id' => $id])[0] ?? null;
        if ($cur) $cur = $this->snap($cur) + ['recordedAt' => $cur['recorded_at']];
        $subs = $this->q('SELECT id,name,from_name,to_name,time,historic_time,jam_level,bbox,line FROM waze_tvt_sub_route WHERE route_id=:id AND is_active=1 {p} ORDER BY id', $p, ['id' => $id]);
        $segs = [];
        foreach ($subs as &$s) {
            $ln = $this->line($s['line']);
            if (count($ln) > 1) $segs[] = $ln;
            $s = $this->snap($s) + ['id' => (int)$s['id'], 'name' => $s['name'], 'from' => $s['from_name'], 'to' => $s['to_name'], 'bbox' => $s['bbox']];
        }
        unset($s);
        if (!$segs) { $ln = $this->line($r['geometry']); if (count($ln) > 1) $segs[] = $ln; }
        $tl = $this->series($p, $id, $h, $b);
        $stl = $this->series($p, $id, 168, 60);
        $heat = $this->agg($p, $id, 'heat');
        $bh = $this->agg($p, $id, 'hour');
        $bd = $this->agg($p, $id, 'dow');
        $wh = $wd = null;
        foreach ($bh as $c) if ($c['n'] >= 2 && $c['r'] !== null && ($wh === null || $c['r'] > $wh['r'])) $wh = $c;
        foreach ($bd as $c) if ($c['n'] >= 2 && $c['r'] !== null && ($wd === null || $c['r'] > $wd['r'])) $wd = $c;
        $rec = array_values(array_filter($stl, static fn($c) => strtotime($c['time']) >= time() - 86400));
        $stats = [
            'avgDelay24h' => $this->mean($rec, 'd', 'n'),
            'avgRatio24h' => $this->mean($rec, 'r', 'rn'),
            'avgDelay7d' => $this->mean($stl, 'd', 'n'),
            'avgRatio7d' => $this->mean($stl, 'r', 'rn'),
            'worstHour' => $wh, 'worstDow' => $wd, 'current' => $cur,
        ];
        return [
            'id' => (int)$r['id'], 'wazeRouteId' => $r['route_id'], 'name' => $r['name'],
            'from' => $r['from_name'], 'to' => $r['to_name'],
            'lengthMeters' => $r['length'] !== null ? (int)$r['length'] : null,
            'current' => $cur, 'stats' => $stats,
            'geometry' => $segs ? array_merge(...$segs) : [],
            'geometrySegments' => $segs, 'tl' => $tl, 'stl' => $stl,
            'heat' => $heat, 'bh' => $bh, 'bd' => $bd, 'subs' => $subs,
        ];
    }

    private function snap(array $r): array
    {
        $t = $r['time'] !== null ? (float)$r['time'] : null;
        $h = $r['historic_time'] !== null ? (float)$r['historic_time'] : null;
        $d = $t !== null && $h !== null ? max(0, $t - $h) : null;
        return ['time' => $t, 'historicTime' => $h, 'delaySeconds' => $d !== null ? (int)round($d) : null, 'delayRatio' => $d !== null && $h > 0 ? $d / $h : null, 'jamLevel' => $r['jam_level'] !== null ? (int)$r['jam_level'] : null];
    }

    private function series(?Partner $p, int $id, int $h, int $b): array
    {
        $s = $b * 60;
        $since = gmdate('Y-m-d H:i:s', time() - $h * 3600);
        $sql = "SELECT TIMESTAMPADD(SECOND,FLOOR(TIMESTAMPDIFF(SECOND,'1970-01-01 00:00:00',recorded_at)/$s)*$s,'1970-01-01 00:00:00') AS bucket,AVG(GREATEST(0,time-historic_time)) AS avg_delay,AVG(CASE WHEN historic_time>0 THEN GREATEST(0,time-historic_time)/historic_time END) AS avg_ratio,COUNT(*) AS total,SUM(CASE WHEN historic_time>0 THEN 1 ELSE 0 END) AS ratio_count FROM waze_tvt_route_snapshot WHERE route_id=:id AND recorded_at>=:since AND recorded_at<=UTC_TIMESTAMP() AND time IS NOT NULL AND historic_time IS NOT NULL {p} GROUP BY bucket ORDER BY bucket";
        return array_map(static fn($r) => ['time' => str_replace(' ', 'T', (string)$r['bucket']) . 'Z', 'd' => (float)$r['avg_delay'], 'r' => $r['avg_ratio'] !== null ? (float)$r['avg_ratio'] : null, 'n' => (int)$r['total'], 'rn' => (int)$r['ratio_count']], $this->q($sql, $p, ['id' => $id, 'since' => $since]));
    }

    private function agg(?Partner $p, int $id, string $k): array
    {
        $l = 'DATE_ADD(recorded_at, INTERVAL -3 HOUR)';
        $sel = $k === 'hour' ? "HOUR($l) AS hour" : ($k === 'dow' ? "WEEKDAY($l) AS dow" : "WEEKDAY($l) AS dow,HOUR($l) AS hour");
        $grp = $k === 'heat' ? 'dow,hour' : $k;
        $rows = $this->q("SELECT $sel,AVG(GREATEST(0,time-historic_time)) AS avg_delay,AVG(CASE WHEN historic_time>0 THEN GREATEST(0,time-historic_time)/historic_time END) AS avg_ratio,COUNT(*) AS total FROM waze_tvt_route_snapshot WHERE route_id=:id AND recorded_at>=:since AND recorded_at<=UTC_TIMESTAMP() AND time IS NOT NULL AND historic_time IS NOT NULL {p} GROUP BY $grp", $p, ['id' => $id, 'since' => gmdate('Y-m-d H:i:s', time() - 30 * 86400)]);
        $out = [];
        foreach ($rows as $r) {
            $c = ['d' => (float)$r['avg_delay'], 'r' => $r['avg_ratio'] !== null ? (float)$r['avg_ratio'] : null, 'n' => (int)$r['total']];
            if (isset($r['dow'])) $c['dow'] = (int)$r['dow'];
            if (isset($r['hour'])) $c['hour'] = (int)$r['hour'];
            $key = $k === 'heat' ? "{$c['dow']}:{$c['hour']}" : $c[$k];
            $out[$key] = $c;
        }
        if ($k !== 'heat') for ($i = 0; $i < ($k === 'hour' ? 24 : 7); $i++) $out[$i] ??= [$k => $i, 'd' => null, 'r' => null, 'n' => 0];
        ksort($out);
        return $out;
    }

    private function merge(array $a, array $b, string $k): array
    {
        $out = [];
        foreach (['a' => $a, 'b' => $b] as $side => $rows) foreach ($rows as $c) {
            $key = $c[$k];
            $out[$key] ??= [$k => $key, 'aDelay' => null, 'aRatio' => null, 'bDelay' => null, 'bRatio' => null, 'aCount' => 0, 'bCount' => 0];
            $out[$key][$side . 'Delay'] = $c['d'];
            $out[$key][$side . 'Ratio'] = $c['r'];
            $out[$key][$side . 'Count'] = $c['n'];
        }
        ksort($out);
        return array_values($out);
    }

    private function common(array $a, array $b): array
    {
        $out = [];
        foreach ($a as $x) foreach ($b as $y) {
            if ($x['from'] && $x['to'] && mb_strtolower(trim($x['from'])) === mb_strtolower(trim($y['from'] ?? '')) && mb_strtolower(trim($x['to'])) === mb_strtolower(trim($y['to'] ?? ''))) {
                $out[] = ['a' => $x, 'b' => $y, 'matched' => 'from→to'];
                break;
            }
        }
        return $out;
    }

    private function mean(array $rows, string $k, string $w): ?float
    {
        $s = 0.0; $n = 0;
        foreach ($rows as $c) if ($c[$k] !== null) { $s += $c[$k] * $c[$w]; $n += $c[$w]; }
        return $n ? $s / $n : null;
    }

    private function dev(array $rows): ?float
    {
        $v = array_values(array_filter(array_column($rows, 'r'), static fn($x) => $x !== null));
        if (count($v) < 2) return null;
        $m = array_sum($v) / count($v);
        return sqrt(array_sum(array_map(static fn($x) => ($x - $m) ** 2, $v)) / count($v));
    }

    private function win(?float $a, ?float $b, float $t = 0.02): ?string
    {
        if ($a === null || $b === null) return null;
        return abs($a - $b) < $t ? 'tie' : ($a < $b ? 'a' : 'b');
    }

    private function line(mixed $raw): array
    {
        if (is_string($raw)) $raw = json_decode($raw, true);
        if (!is_array($raw)) return [];
        if (($raw['type'] ?? null) === 'LineString') $raw = $raw['coordinates'] ?? [];
        $out = [];
        foreach ($raw as $c) {
            if (!is_array($c)) continue;
            if (isset($c['lat'], $c['lng'])) [$la, $ln] = [$c['lat'], $c['lng']];
            elseif (isset($c['y'], $c['x'])) [$la, $ln] = [$c['y'], $c['x']];
            elseif (isset($c[0], $c[1])) [$la, $ln] = [$c[1], $c[0]];
            else continue;
            if (!is_numeric($la) || !is_numeric($ln)) continue;
            $la = (float)$la; $ln = (float)$ln;
            if (is_finite($la) && is_finite($ln) && abs($la) <= 90 && abs($ln) <= 180) $out[] = [$la, $ln];
        }
        return $out;
    }

    private function dist(float $la, float $ln, array $segs): float
    {
        $min = INF;
        $c = cos(deg2rad($la));
        foreach ($segs as $line) for ($i = 1; $i < count($line); $i++) {
            [$ay, $al] = $line[$i - 1];
            [$by, $bl] = $line[$i];
            $ax = $al * $c; $bx = $bl * $c; $px = $ln * $c;
            $dx = $bx - $ax; $dy = $by - $ay; $len = $dx * $dx + $dy * $dy;
            $t = $len > 0 ? max(0, min(1, (($px - $ax) * $dx + ($la - $ay) * $dy) / $len)) : 0;
            $min = min($min, hypot($px - $ax - $t * $dx, $la - $ay - $t * $dy) * 111320);
        }
        return $min;
    }

    private function alerts(?Partner $p, array $a, array $b): array
    {
        $out = ['shared' => [], 'onlyA' => [], 'onlyB' => []];
        $lines = array_merge($a, $b);
        if (!$lines) return $out;
        $pts = array_merge(...$lines);
        if (!$pts) return $out;
        $lats = array_column($pts, 0);
        $lngs = array_column($pts, 1);
        $rows = $this->q('SELECT id,type,subtype,street,city,confidence,reliability,latitude,longitude,pub_millis,collected_at FROM waze_alerts WHERE collected_at>=:since AND latitude BETWEEN :minLat AND :maxLat AND longitude BETWEEN :minLng AND :maxLng {p} ORDER BY collected_at DESC LIMIT 400', $p, ['since' => gmdate('Y-m-d H:i:s', time() - 7 * 86400), 'minLat' => min($lats) - 0.002, 'maxLat' => max($lats) + 0.002, 'minLng' => min($lngs) - 0.002, 'maxLng' => max($lngs) + 0.002]);
        foreach ($rows as $r) {
            $la = (float)$r['latitude'];
            $ln = (float)$r['longitude'];
            $da = $this->dist($la, $ln, $a);
            $db = $this->dist($la, $ln, $b);
            if ($da > 60 && $db > 60) continue;
            $key = $da <= 60 ? ($db <= 60 ? 'shared' : 'onlyA') : 'onlyB';
            $out[$key][] = [
                'id' => (int)$r['id'], 'type' => $r['type'], 'subtype' => $r['subtype'],
                'street' => $r['street'], 'city' => $r['city'],
                'confidence' => (int)$r['confidence'], 'reliability' => (int)$r['reliability'],
                'lat' => $la, 'lng' => $ln,
                'distanceA' => $da <= 60 ? (int)round($da) : null,
                'distanceB' => $db <= 60 ? (int)round($db) : null,
                'pubDateTime' => $r['pub_millis'] ? gmdate(DATE_ATOM, (int)floor((float)$r['pub_millis'] / 1000)) : null,
                'collectedAt' => $r['collected_at'],
                'typeLabel' => match(strtoupper((string)$r['type'])) {
                    'ACCIDENT' => 'Acidente', 'JAM' => 'Congestionamento',
                    'ROAD_CLOSED', 'ROADCLOSED' => 'Via fechada', 'ROADWORKS' => 'Obra',
                    'WEATHERHAZARD', 'WEATHERHAZARDONROAD' => 'Risco na via',
                    default => ucfirst((string)$r['type']),
                },
            ];
        }
        return $out;
    }
}
