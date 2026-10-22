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

    private const CHART_W = 640;
    private const CHART_H = 150;

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
            'byHour'   => $this->bars($detail['byHour'] ?? [], static fn (array $p): string => sprintf('%02d', $p['hour'])),
            'byDow'    => $this->bars($detail['byDow'] ?? [], static fn (array $p): string => self::DOW[$p['dow']] ?? '?'),
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

    // ─── Mapa (SVG) ──────────────────────────────────────────────────────

    /** @return array<string,mixed>|null */
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

        $alerts = array_values(array_filter($d['nearbyAlerts'] ?? [], static fn (array $a): bool => isset($a['lat'], $a['lng'])));
        $irr = array_values(array_filter($d['irregularities'] ?? [], static fn (array $a): bool => isset($a['lat'], $a['lng']) && $a['lat'] !== null));

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

        $avg = $d['recentWindow']['avg'] ?? [];
        $names = [];
        foreach (($d['topSubRoutes'] ?? []) as $s) {
            $names[$s['id']] = $s['name'];
        }

        $subPaths = [];
        foreach ($subs as $id => $line) {
            $ratio = $avg[$id] ?? null;
            $subPaths[] = [
                'd'     => $toPath($line),
                'color' => $this->ratioColor($ratio) ?? '#64748b',
                'title' => ($names[$id] ?? 'Trecho') . ($ratio !== null ? sprintf(' · %d%% vs histórico', (int) round($ratio * 100)) : ''),
            ];
        }

        $dots = static function (array $items, string $color) use ($project): array {
            $out = [];
            foreach ($items as $it) {
                [$x, $y] = $project((float) $it['lat'], (float) $it['lng']);
                if ($x >= 0 && $x <= self::MAP_W && $y >= 0 && $y <= self::MAP_H) {
                    $out[] = ['x' => $x, 'y' => $y, 'color' => $color];
                }
            }

            return $out;
        };

        return [
            'w'        => self::MAP_W,
            'h'        => self::MAP_H,
            'main'     => $main !== [] ? $toPath($main) : null,
            'subs'     => $subPaths,
            'hasColor' => $avg !== [],
            'alerts'   => $dots($alerts, '#f59e0b'),
            'irr'      => $dots($irr, '#7c3aed'),
            'start'    => $subs === [] && $main !== [] ? $project((float) $main[0][0], (float) $main[0][1]) : null,
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

    // ─── Gráficos ────────────────────────────────────────────────────────

    /**
     * Linha do atraso (s) nos últimos 7 dias.
     *
     * @param list<array<string,mixed>> $points
     *
     * @return array<string,mixed>|null
     */
    private function timeline(array $points): ?array
    {
        $n = count($points);
        if ($n < 2) {
            return null;
        }

        $values = array_map(static fn (array $p): float => (float) $p['avgDelay'], $points);
        $min = min(0.0, min($values));
        $max = max(1.0, max($values));
        $padT = 8;
        $padB = 8;
        $h = self::CHART_H;
        $w = self::CHART_W;

        $coords = [];
        foreach ($values as $i => $v) {
            $coords[] = [
                round($i / ($n - 1) * $w, 1),
                round($padT + (1 - ($v - $min) / ($max - $min)) * ($h - $padT - $padB), 1),
            ];
        }

        $line = '';
        foreach ($coords as $i => [$x, $y]) {
            $line .= ($i === 0 ? 'M' : 'L') . $x . ' ' . $y . ' ';
        }

        $zeroY = round($padT + (1 - (0 - $min) / ($max - $min)) * ($h - $padT - $padB), 1);

        return [
            'w'      => $w,
            'h'      => $h,
            'line'   => trim($line),
            'area'   => trim($line) . sprintf(' L%s %s L0 %s Z', $coords[$n - 1][0], $zeroY, $zeroY),
            'zeroY'  => $zeroY,
            'max'    => (int) round($max),
            'from'   => (string) $points[0]['time'],
            'to'     => (string) $points[$n - 1]['time'],
        ];
    }

    /**
     * @param list<array<string,mixed>>        $points
     * @param callable(array<string,mixed>):string $label
     *
     * @return list<array{label:string,height:float,color:string,title:string}>
     */
    private function bars(array $points, callable $label): array
    {
        $max = 0.0;
        foreach ($points as $p) {
            $max = max($max, (float) ($p['avgDelay'] ?? 0));
        }
        $max = max($max, 1.0);

        $out = [];
        foreach ($points as $p) {
            $ok = ($p['count'] ?? 0) >= 2 && $p['avgRatio'] !== null;
            $delay = max(0.0, (float) ($p['avgDelay'] ?? 0));
            $out[] = [
                'label'  => $label($p),
                'height' => $ok ? round(max(2.0, $delay / $max * 100), 1) : 2.0,
                'color'  => $ok ? ($this->ratioColor((float) $p['avgRatio']) ?? '#cbd5e1') : '#cbd5e1',
                'title'  => $label($p) . ($ok ? sprintf(' · %s s · %d%%', $this->signed($p['avgDelay']), (int) round($p['avgRatio'] * 100)) : ' · sem amostras'),
            ];
        }

        return $out;
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
