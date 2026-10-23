<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Monta o modelo de visualização do relatório imprimível/compartilhável da rota.
 *
 * Tudo que é visual sai pronto do servidor (SVG e HTML estáticos): o relatório não
 * depende de JavaScript, de tiles de mapa nem de Chart.js — imprime e vira PDF com
 * fidelidade, inclusive no link público.
 */
final class RouteReportBuilder
{
    private const MAP_W = 640;
    private const MAP_H = 340;
    private const MAP_PAD = 18;
    private const MAX_POINTS = 400;

    private const DOW = ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'];
    private const DOW_FULL = ['segunda', 'terça', 'quarta', 'quinta', 'sexta', 'sábado', 'domingo'];

    /**
     * @param array<string,mixed> $detail resultado de RouteDetailRepository::getRouteDetail()
     *
     * @return array<string,mixed>
     */
    public function build(array $detail): array
    {
        return [
            'summary'  => $this->summary($detail),
            'map'      => $this->map($detail),
            'heatmap'  => $this->heatmap($detail['heatmap'] ?? []),
            'timeline' => $this->timeline($detail['timeline'] ?? []),
            'byHour'   => $this->barChart($detail['byHour'] ?? [], static fn (array $p): string => sprintf('%02d', $p['hour']), 460, 3),
            'byDow'    => $this->barChart($detail['byDow'] ?? [], static fn (array $p): string => self::DOW[$p['dow']] ?? '?', 300, 1),
            'subRoutes' => array_slice($detail['topSubRoutes'] ?? [], 0, 8),
            'occurrences' => array_slice($detail['occurrences'] ?? [], 0, 12),
        ];
    }

    // ─── Texto ───────────────────────────────────────────────────────────

    /** @return list<string> */
    private function summary(array $d): array
    {
        $route = $d['route'];
        $stats = $d['stats'];
        $out = [];

        $path = '';
        if (!empty($route['from']) && !empty($route['to'])) {
            $path = sprintf(' (de %s para %s', $route['from'], $route['to']);
            $path .= !empty($route['lengthMeters']) ? sprintf(', %s km)', $this->num($route['lengthMeters'] / 1000, 1)) : ')';
        } elseif (!empty($route['lengthMeters'])) {
            $path = sprintf(' (%s km)', $this->num($route['lengthMeters'] / 1000, 1));
        }

        $out[] = sprintf(
            'A rota “%s”%s está com índice de saúde %d%% (%s) nos últimos 7 dias.',
            $route['name'],
            $path,
            $stats['health'],
            mb_strtolower((string) $stats['healthLabel'])
        );

        if ($stats['avgRatio7d'] !== null) {
            $pct = (int) round($stats['avgRatio7d'] * 100);
            $out[] = $pct > 0
                ? sprintf('O tempo de percurso ficou, em média, %d%% acima do histórico (%s s por viagem).', $pct, $this->signed($stats['avgDelay7d']))
                : 'O tempo de percurso ficou em linha com o histórico.';
        }

        if (!empty($stats['worstHour']) && $stats['worstHour']['avgRatio'] > 0) {
            $w = $stats['worstHour'];
            $out[] = sprintf(
                'O pior horário é %02dh, com %d%% acima do histórico (%s s em média).',
                $w['hour'],
                (int) round($w['avgRatio'] * 100),
                $this->signed($w['avgDelay'])
            );
        }

        if (!empty($stats['worstDow']) && $stats['worstDow']['avgRatio'] > 0) {
            $w = $stats['worstDow'];
            $line = sprintf('O dia mais crítico é %s (%d%% acima do histórico)', self::DOW_FULL[$w['dow']] ?? '?', (int) round($w['avgRatio'] * 100));
            if (!empty($stats['bestDow']) && $stats['bestDow']['dow'] !== $w['dow']) {
                $line .= sprintf(' e o mais tranquilo é %s', self::DOW_FULL[$stats['bestDow']['dow']] ?? '?');
            }
            $out[] = $line . '.';
        }

        $top = ($d['topSubRoutes'] ?? [])[0] ?? null;
        if ($top !== null && ($top['level'] ?? 'none') !== 'none') {
            $out[] = sprintf(
                'O trecho mais crítico no momento é “%s”: +%d s (%d%% acima do normal).',
                $top['name'],
                (int) ($top['delaySeconds'] ?? 0),
                (int) round(($top['delayRatio'] ?? 0) * 100)
            );
        }

        $alerts = count($d['nearbyAlerts'] ?? []);
        $irr = count($d['irregularities'] ?? []);
        if ($alerts + $irr > 0) {
            $out[] = sprintf(
                'Foram registradas %d ocorrência(s) a até 60 m do traçado: %d alerta(s) Waze e %d irregularidade(s).',
                $alerts + $irr,
                $alerts,
                $irr
            );
        }

        return $out;
    }

    // ─── Mapa ────────────────────────────────────────────────────────────

    private const ALERT_COLORS = [
        'ACCIDENT'      => '#dc2626',
        'JAM'           => '#ea580c',
        'ROAD_CLOSED'   => '#111827',
        'POLICE'        => '#1d4ed8',
        'WEATHERHAZARD' => '#0891b2',
        'HAZARD'        => '#f59e0b',
        'CONSTRUCTION'  => '#a16207',
    ];
    private const IRREGULARITY_COLOR = '#7c3aed';

    /**
     * Modelo do mapa. Duas representações dos MESMOS dados:
     *   - `live`: coordenadas para o mapa com ruas (Leaflet + OpenStreetMap, montado no navegador);
     *   - `svg` : esquema do traçado em SVG, usado como reserva quando os tiles não carregam.
     *
     * Cor de cada trecho: média das últimas horas (quando os snapshots trazem dados por
     * trecho) ou, na falta dela, o estado atual do trecho (`colorSource` informa qual).
     *
     * @return array<string,mixed>|null
     */
    private function map(array $d): ?array
    {
        $main = $this->thin($d['routePolyline'] ?? []);
        $subs = [];
        foreach (($d['subRoutesPolyline'] ?? []) as $id => $line) {
            if (is_array($line) && count($line) >= 2) {
                $subs[(int) $id] = $this->thin($line);
            }
        }

        $points = $main;
        foreach ($subs as $line) {
            array_push($points, ...$line);
        }
        if (count($points) < 2) {
            return null;
        }

        // ── cor dos trechos ──
        $avg = $d['recentWindow']['avg'] ?? [];
        $current = [];
        $names = [];
        foreach (($d['topSubRoutes'] ?? []) as $sr) {
            $names[$sr['id']] = $sr['name'];
            if ($sr['delayRatio'] !== null) {
                $current[$sr['id']] = (float) $sr['delayRatio'];
            }
        }
        $colorSource = $avg !== [] ? 'avg' : ($current !== [] ? 'current' : null);
        $ratios = $colorSource === 'avg' ? $avg : ($colorSource === 'current' ? $current : []);

        $subItems = [];
        foreach ($subs as $id => $line) {
            $ratio = $ratios[$id] ?? null;
            $subItems[] = [
                'pts'   => $line,
                'color' => $this->ratioColor($ratio) ?? '#64748b',
                'title' => ($names[$id] ?? 'Trecho') . ($ratio !== null ? sprintf(' · %d%% vs histórico', (int) round($ratio * 100)) : ''),
            ];
        }

        // ── ocorrências ──
        $alerts = [];
        $legend = [];
        foreach (($d['nearbyAlerts'] ?? []) as $a) {
            if (!isset($a['lat'], $a['lng'])) {
                continue;
            }
            $color = self::ALERT_COLORS[strtoupper((string) $a['type'])] ?? '#64748b';
            $label = (string) ($a['typeLabel'] ?: ($a['type'] ?: 'Alerta'));
            $alerts[] = ['lat' => (float) $a['lat'], 'lng' => (float) $a['lng'], 'color' => $color, 'title' => $label . ($a['street'] ? ' — ' . $a['street'] : '')];
            $legend[$label] = ['label' => $label, 'color' => $color, 'count' => ($legend[$label]['count'] ?? 0) + 1];
        }

        $irr = [];
        foreach (($d['irregularities'] ?? []) as $i) {
            if (!isset($i['lat'], $i['lng']) || $i['lat'] === null) {
                continue;
            }
            $irr[] = ['lat' => (float) $i['lat'], 'lng' => (float) $i['lng'], 'color' => self::IRREGULARITY_COLOR, 'title' => (string) ($i['type'] ?: 'Irregularidade')];
        }
        if ($irr !== []) {
            $legend['Irregularidade'] = ['label' => 'Irregularidade', 'color' => self::IRREGULARITY_COLOR, 'count' => count($irr)];
        }

        // ── esquema SVG (reserva) ──
        $lats = array_column($points, 0);
        $lngs = array_column($points, 1);
        $minLat = min($lats);
        $maxLat = max($lats);
        $minLng = min($lngs);
        $maxLng = max($lngs);

        $cos = max(0.2, cos(deg2rad(($minLat + $maxLat) / 2)));
        $spanX = max(1e-9, ($maxLng - $minLng) * $cos);
        $spanY = max(1e-9, $maxLat - $minLat);

        $innerW = self::MAP_W - 2 * self::MAP_PAD;
        $innerH = self::MAP_H - 2 * self::MAP_PAD;
        $scale = min($innerW / $spanX, $innerH / $spanY);
        $offX = (self::MAP_W - $spanX * $scale) / 2;
        $offY = (self::MAP_H - $spanY * $scale) / 2;

        $project = static fn (float $lat, float $lng): array => [
            round($offX + ($lng - $minLng) * $cos * $scale, 1),
            round($offY + ($maxLat - $lat) * $scale, 1),
        ];

        $toPath = function (array $line) use ($project): string {
            $parts = [];
            foreach ($line as $i => [$lat, $lng]) {
                [$x, $y] = $project((float) $lat, (float) $lng);
                $parts[] = ($i === 0 ? 'M' : 'L') . $x . ' ' . $y;
            }

            return implode(' ', $parts);
        };

        $dots = static function (array $items) use ($project): array {
            $out = [];
            foreach ($items as $it) {
                [$x, $y] = $project($it['lat'], $it['lng']);
                if ($x >= 0 && $x <= self::MAP_W && $y >= 0 && $y <= self::MAP_H) {
                    $out[] = ['x' => $x, 'y' => $y, 'color' => $it['color']];
                }
            }

            return $out;
        };

        return [
            'w'           => self::MAP_W,
            'h'           => self::MAP_H,
            'main'        => $main !== [] ? $toPath($main) : null,
            'subs'        => array_map(static fn (array $s): array => ['d' => $toPath($s['pts']), 'color' => $s['color'], 'title' => $s['title']], $subItems),
            'alerts'      => $dots($alerts),
            'irr'         => $dots($irr),
            'colorSource' => $colorSource,
            'legend'      => array_values($legend),
            // dados do mapa com ruas (JSON no HTML; montado por assets/js/pages/route-report.js)
            'live'        => [
                'main'   => $main,
                'subs'   => $subItems,
                'alerts' => $alerts,
                'irr'    => $irr,
            ],
        ];
    }

    /**
     * @param list<array{0:float,1:float}> $line
     *
     * @return list<array{0:float,1:float}>
     */
    private function thin(array $line): array
    {
        $n = count($line);
        if ($n <= self::MAX_POINTS) {
            return array_values($line);
        }

        $step = (int) ceil($n / self::MAX_POINTS);
        $out = [];
        for ($i = 0; $i < $n; $i += $step) {
            $out[] = $line[$i];
        }
        $out[] = $line[$n - 1];

        return $out;
    }

    // ─── Heatmap ─────────────────────────────────────────────────────────

    /**
     * @param list<array<string,mixed>> $cells
     *
     * @return list<array{label:string,cells:list<array{color:?string,title:string}>}>
     */
    private function heatmap(array $cells): array
    {
        $lookup = [];
        foreach ($cells as $c) {
            $lookup[$c['dow'] . ':' . $c['hour']] = $c;
        }

        $rows = [];
        for ($dow = 0; $dow < 7; ++$dow) {
            $row = [];
            for ($h = 0; $h < 24; ++$h) {
                $c = $lookup[$dow . ':' . $h] ?? null;
                $ok = $c !== null && $c['count'] >= 2 && $c['avgRatio'] !== null;
                $row[] = [
                    'color' => $ok ? $this->ratioColor((float) $c['avgRatio']) : null,
                    'title' => sprintf('%s %02dh', self::DOW[$dow], $h) . ($ok ? sprintf(' · %d%%', (int) round($c['avgRatio'] * 100)) : ' · sem amostras'),
                ];
            }
            $rows[] = ['label' => self::DOW[$dow], 'cells' => $row];
        }

        return $rows;
    }

    // ─── Gráficos (SVG com eixos e grade) ───────────────────────────────

    private const DOW_SHORT_LOWER = ['seg', 'ter', 'qua', 'qui', 'sex', 'sáb', 'dom'];

    /**
     * Evolução do atraso (s) nos últimos 7 dias, com:
     *   - escala em segundos e linhas de grade horizontais;
     *   - linha tracejada à meia-noite e rótulo do dia ("qui 16/10");
     *   - posição horizontal PROPORCIONAL ao horário (buracos nos dados não são
     *     "esticados") e a linha é interrompida onde faltam mais de 2 h de dados.
     *
     * @param list<array<string,mixed>> $points
     *
     * @return array<string,mixed>|null
     */
    private function timeline(array $points): ?array
    {
        if (count($points) < 2) {
            return null;
        }

        $w = 640;
        $h = 200;
        $padL = 42;
        $padR = 10;
        $padT = 10;
        $padB = 26;

        $ts = [];
        $values = [];
        foreach ($points as $p) {
            $t = strtotime((string) $p['time'] . ' UTC');
            if ($t === false) {
                continue;
            }
            $ts[] = $t;
            $values[] = (float) $p['avgDelay'];
        }
        $n = count($ts);
        if ($n < 2) {
            return null;
        }

        $t0 = $ts[0];
        $t1 = $ts[$n - 1];
        $span = max(1, $t1 - $t0);

        [$ticks, $yMin, $yMax] = $this->niceTicks(min(0.0, min($values)), max(1.0, max($values)), 4);

        $plotW = $w - $padL - $padR;
        $plotH = $h - $padT - $padB;
        $x = static fn (int $t): float => round($padL + ($t - $t0) / $span * $plotW, 1);
        $y = static fn (float $v): float => round($padT + (1 - ($v - $yMin) / ($yMax - $yMin)) * $plotH, 1);
        $zeroY = $y(0.0);

        // Segmentos contínuos (lacuna > 2 h quebra a linha).
        $segments = [];
        $cur = [];
        foreach ($ts as $i => $t) {
            if ($cur !== [] && $t - $ts[$i - 1] > 7200) {
                $segments[] = $cur;
                $cur = [];
            }
            $cur[] = [$x($t), $y($values[$i])];
        }
        $segments[] = $cur;

        $paths = [];
        foreach ($segments as $seg) {
            $line = '';
            foreach ($seg as $k => [$px, $py]) {
                $line .= ($k === 0 ? 'M' : 'L') . $px . ' ' . $py . ' ';
            }
            $last = $seg[count($seg) - 1];
            $paths[] = [
                'line' => trim($line),
                'area' => count($seg) > 1 ? trim($line) . sprintf(' L%s %s L%s %s Z', $last[0], $zeroY, $seg[0][0], $zeroY) : null,
            ];
        }

        // Dias: linha à meia-noite + rótulo centralizado no trecho visível do dia.
        $days = [];
        $dayStart = (int) strtotime(gmdate('Y-m-d', $t0) . ' 00:00:00 UTC');
        for ($d = $dayStart; $d <= $t1; $d += 86400) {
            $from = max($d, $t0);
            $to = min($d + 86400, $t1);
            if ($to <= $from) {
                continue;
            }
            $days[] = [
                'line'  => $d > $t0 ? $x($d) : null,
                'cx'    => round(($x($from) + $x($to)) / 2, 1),
                'width' => $x($to) - $x($from),
                'label' => self::DOW_SHORT_LOWER[(int) gmdate('N', $d) - 1] . ' ' . gmdate('d/m', $d),
            ];
        }

        $peakIdx = array_search(max($values), $values, true);

        return [
            'w'     => $w,
            'h'     => $h,
            'padL'  => $padL,
            'padR'  => $padR,
            'padT'  => $padT,
            'padB'  => $padB,
            'plotR' => $w - $padR,
            'plotB' => $h - $padB,
            'ticks' => array_map(static fn (float $v): array => ['y' => $y($v), 'label' => (int) round($v) . ' s'], $ticks),
            'zeroY' => $zeroY,
            'paths' => $paths,
            'days'  => $days,
            'max'   => (int) round(max($values)),
            'peak'  => $peakIdx !== false ? ['x' => $x($ts[$peakIdx]), 'y' => $y($values[$peakIdx]), 'at' => (string) $points[$peakIdx]['time']] : null,
            'from'  => (string) $points[0]['time'],
            'to'    => (string) $points[count($points) - 1]['time'],
        ];
    }

    /**
     * Barras com escala em segundos e grade horizontal (média por hora / por dia).
     *
     * @param list<array<string,mixed>>            $points
     * @param callable(array<string,mixed>):string $label
     * @param int                                  $labelEvery rotula 1 a cada N barras
     *
     * @return array<string,mixed>
     */
    private function barChart(array $points, callable $label, int $w, int $labelEvery): array
    {
        $h = 170;
        $padL = 38;
        $padR = 4;
        $padT = 10;
        $padB = 20;

        $max = 0.0;
        foreach ($points as $p) {
            $max = max($max, (float) ($p['avgDelay'] ?? 0));
        }

        [$ticks, , $yMax] = $this->niceTicks(0.0, max($max, 1.0), 4);

        $n = max(1, count($points));
        $plotW = $w - $padL - $padR;
        $plotH = $h - $padT - $padB;
        $slot = $plotW / $n;
        $barW = round($slot * 0.72, 1);
        $baseY = $padT + $plotH;

        $bars = [];
        foreach ($points as $i => $p) {
            $ok = ($p['count'] ?? 0) >= 2 && $p['avgRatio'] !== null;
            $delay = max(0.0, (float) ($p['avgDelay'] ?? 0));
            $barH = $ok ? max(2.0, $delay / $yMax * $plotH) : 2.0;

            $bars[] = [
                'x'      => round($padL + $i * $slot + ($slot - $barW) / 2, 1),
                'y'      => round($baseY - $barH, 1),
                'w'      => $barW,
                'h'      => round($barH, 1),
                'color'  => $ok ? ($this->ratioColor((float) $p['avgRatio']) ?? '#cbd5e1') : '#cbd5e1',
                'label'  => $i % $labelEvery === 0 ? $label($p) : null,
                'cx'     => round($padL + $i * $slot + $slot / 2, 1),
                'title'  => $label($p) . ($ok ? sprintf(' · %s s · %d%% vs histórico', $this->signed($p['avgDelay']), (int) round($p['avgRatio'] * 100)) : ' · sem amostras'),
            ];
        }

        return [
            'w'     => $w,
            'h'     => $h,
            'padL'  => $padL,
            'plotR' => $w - $padR,
            'baseY' => $baseY,
            'labelY' => $h - 6,
            'ticks' => array_map(static fn (float $v): array => ['y' => round($baseY - $v / $yMax * $plotH, 1), 'label' => (int) round($v) . ' s'], $ticks),
            'bars'  => $bars,
        ];
    }

    /**
     * Marcações "redondas" (1, 2, 5 × 10ⁿ) cobrindo [min, max].
     *
     * @return array{0:list<float>,1:float,2:float} [ticks, min do eixo, max do eixo]
     */
    private function niceTicks(float $min, float $max, int $intervals): array
    {
        $range = max($max - $min, 1e-9);
        $rough = $range / $intervals;
        $pow = 10 ** floor(log10($rough));
        $frac = $rough / $pow;
        $step = ($frac <= 1 ? 1 : ($frac <= 2 ? 2 : ($frac <= 5 ? 5 : 10))) * $pow;

        $lo = floor($min / $step) * $step;
        $hi = ceil($max / $step) * $step;

        $ticks = [];
        for ($v = $lo; $v <= $hi + $step / 2; $v += $step) {
            $ticks[] = round($v, 6);
        }

        return [$ticks, (float) $lo, (float) $hi];
    }

    // ─── Utilitários ─────────────────────────────────────────────────────

    /** Mesma escala do heatmap da página (verde → vermelho). */
    private function ratioColor(?float $ratio): ?string
    {
        if ($ratio === null) {
            return null;
        }

        $n = max(0.0, min($ratio, 1.0));

        return sprintf('hsl(%d, %d%%, %d%%)', (int) round(140 - $n * 140), (int) round(62 + $n * 12), (int) round(44 - $n * 2));
    }

    private function num(float $v, int $dec): string
    {
        return number_format($v, $dec, ',', '.');
    }

    private function signed(mixed $seconds): string
    {
        if ($seconds === null) {
            return '—';
        }

        $r = (int) round((float) $seconds);

        return ($r >= 0 ? '+' : '−') . abs($r);
    }
}
