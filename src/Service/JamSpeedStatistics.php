<?php

declare(strict_types=1);

namespace App\Service;

/** Estatísticas das leituras observadas, não da velocidade de um veículo individual. */
final class JamSpeedStatistics
{
    /**
     * Média por degraus: a leitura anterior vale até a próxima leitura válida.
     * Exclui intervalos acima de gap e intervalos com velocidade inválida em uma ponta.
     * Não extrapola antes da primeira leitura nem depois da última.
     * Retorna null para a média quando não há intervalo coberto, inclusive com uma leitura.
     */
    public static function summarize(array $samples, int $gap, int $start = 0, int $end = 0): array
    {
        if ($gap < 1) {
            throw new \InvalidArgumentException('O limite de lacuna deve ser positivo.');
        }
        $points = [];
        foreach ($samples as $sample) {
            if (!is_array($sample)) continue;
            $at = $sample['at'] ?? null;
            if ((!is_int($at) && !(is_string($at) && ctype_digit($at))) || (int) $at <= 0) continue;
            $value = $sample['speed'] ?? null;
            $speed = is_numeric($value) ? (float) $value : null;
            if ($speed !== null && (!is_finite($speed) || $speed < 0)) $speed = null;
            // A última ocorrência do mesmo horário prevalece, inclusive se inválida.
            $points[(int) $at] = $speed;
        }
        ksort($points, SORT_NUMERIC);
        $times = array_keys($points);
        $first = $times[0] ?? null;
        $last = $times ? $times[count($times) - 1] : null;
        $speeds = array_values(array_filter($points, static fn ($v): bool => $v !== null));
        $covered = 0;
        $weighted = 0.0;
        for ($i = 1; $i < count($times); ++$i) {
            $a = $times[$i - 1];
            $b = $times[$i];
            $dt = $b - $a;
            if ($dt > $gap || $points[$a] === null || $points[$b] === null) continue;
            $covered += $dt;
            $weighted += $points[$a] * $dt;
        }
        $spanStart = $first === null ? null : ($start > 0 ? min($start, $first) : $first);
        $spanEnd = $last === null ? null : max($end, $last);
        $span = $spanStart === null ? 0 : max(0, $spanEnd - $spanStart);

        return [
            'method' => 'previous_sample_time_weighted',
            'unit' => 'km/h',
            'sample_count' => count($points),
            'valid_sample_count' => count($speeds),
            'first_sample_at' => $first,
            'last_sample_at' => $last,
            'span_start_at' => $spanStart,
            'span_end_at' => $spanEnd,
            'span_seconds' => $span,
            'covered_seconds' => $covered,
            'uncovered_seconds' => max(0, $span - $covered),
            'coverage_ratio' => $span > 0 ? round($covered / $span, 4) : null,
            'max_gap_seconds' => $gap,
            'min_speed_kmh' => $speeds ? min($speeds) : null,
            'max_speed_kmh' => $speeds ? max($speeds) : null,
            'last_speed_kmh' => $last !== null ? $points[$last] : null,
            'mean_speed_kmh' => $covered > 0 ? round($weighted / $covered, 3) : null,
        ];
    }

    /** Enriquece somente o estado do parceiro recebido; não consulta outros parceiros. */
    public static function enrichState(array $state): array
    {
        $gap = max(1, (int) ($state['gap_seconds'] ?? 900));
        foreach (['open', 'history'] as $bucket) {
            $state[$bucket] ??= [];
            foreach ($state[$bucket] as &$episode) {
                $episode['speed_statistics'] = self::summarize(
                    $episode['samples'] ?? [], $gap,
                    (int) ($episode['start'] ?? 0), (int) ($episode['last'] ?? 0)
                );
            }
            unset($episode);
        }
        $state['items'] ??= [];
        foreach ($state['items'] as &$item) {
            $item['speed_statistics'] = $state['open'][(string) $item['jam_id']]['speed_statistics'] ?? null;
        }
        unset($item);
        return $state;
    }
}
