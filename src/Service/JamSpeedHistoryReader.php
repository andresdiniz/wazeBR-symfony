<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class JamSpeedHistoryReader
{
    public function __construct(#[Autowire('%kernel.project_dir%')] private readonly string $projectDir)
    {
    }

    public function findLatest(int $partnerId, int $jamId): ?array
    {
        if ($partnerId < 1 || $jamId < 1) throw new \InvalidArgumentException('Identificador inválido.');
        $path = $this->projectDir.'/var/jam-duration/'.$partnerId.'.json';
        if (!is_file($path)) return null;
        $raw = @file_get_contents($path);
        if ($raw === false) throw new \RuntimeException('Não foi possível ler o histórico.');
        try {
            $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('Histórico inválido.', 0, $e);
        }
        if (!is_array($payload) || ($payload['version'] ?? null) !== 1) {
            throw new \RuntimeException('Versão de histórico não suportada.');
        }
        return self::fromPayload($payload, $jamId);
    }

    /** Seleciona um episódio, sem unir observações separadas por encerramentos ou lacunas. */
    public static function fromPayload(array $payload, int $jamId): ?array
    {
        $selected = null;
        foreach (['history', 'open'] as $bucket) {
            foreach ($payload[$bucket] ?? [] as $episode) {
                if (!is_array($episode) || (int) ($episode['jam_id'] ?? 0) !== $jamId) continue;
                if ($selected === null || (int) ($episode['last'] ?? 0) >= (int) ($selected['episode']['last'] ?? 0)) {
                    $selected = ['episode' => $episode, 'is_open' => $bucket === 'open'];
                }
            }
        }
        if ($selected === null) return null;
        $episode = $selected['episode'];
        $gap = max(1, (int) ($payload['gap_seconds'] ?? 900));
        $samples = [];
        foreach ($episode['samples'] ?? [] as $sample) {
            if (!is_array($sample)) continue;
            $at = $sample['at'] ?? null;
            if ((!is_int($at) && !(is_string($at) && ctype_digit($at))) || (int) $at <= 0) continue;
            $value = $sample['speed'] ?? null;
            $speed = is_numeric($value) ? (float) $value : null;
            if ($speed !== null && (!is_finite($speed) || $speed < 0)) $speed = null;
            $samples[(int) $at] = ['at' => (int) $at, 'speed' => $speed];
        }
        ksort($samples, SORT_NUMERIC);
        $samples = array_values($samples);
        $stats = JamSpeedStatistics::summarize($samples, $gap, (int) ($episode['start'] ?? 0), (int) ($episode['last'] ?? 0));
        $first = $stats['first_sample_at'] ?? 0;
        $span = max(1, (int) ($stats['last_sample_at'] ?? 0) - $first);
        $max = max(1.0, (float) ($stats['max_speed_kmh'] ?? 0));
        foreach ($samples as &$sample) {
            $sample['x'] = round(40 + 660 * ($sample['at'] - $first) / $span, 2);
            $sample['y'] = $sample['speed'] === null ? null : round(200 - 180 * $sample['speed'] / $max, 2);
        }
        unset($sample);
        return [
            'is_open' => $selected['is_open'],
            'start' => (int) ($episode['start'] ?? 0),
            'last' => (int) ($episode['last'] ?? 0),
            'generated_at' => (int) ($payload['generated_at'] ?? 0),
            'statistics' => $stats, 'samples' => $samples, 'chart_max' => $max,
        ];
    }
}
