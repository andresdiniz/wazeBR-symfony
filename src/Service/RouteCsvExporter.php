<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Escreve os CSVs da página /routes/{id}.
 *
 * - Separador padrão `;` (Excel pt-BR), BOM UTF-8 no início.
 * - Células de texto que começariam com = + - @ são neutralizadas
 *   (CSV/formula injection — nomes de vias vêm de dados de terceiros).
 * - `timeline` já chega em fuso de exibição (buckets calculados no banco);
 *   campos ISO em UTC são convertidos para o fuso de exibição aqui.
 */
final class RouteCsvExporter
{
    public const TYPES = ['timeline', 'by_hour', 'by_dow', 'occurrences', 'subroutes'];

    private const DOW_LABELS = ['Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado', 'Domingo'];

    private readonly \DateTimeZone $tz;

    public function __construct(
        #[Autowire('%env(APP_TIMEZONE)%')]
        string $displayTimezone = 'America/Sao_Paulo',
    ) {
        try {
            $this->tz = new \DateTimeZone($displayTimezone !== '' ? $displayTimezone : 'America/Sao_Paulo');
        } catch (\Throwable) {
            $this->tz = new \DateTimeZone('America/Sao_Paulo');
        }
    }

    public static function isValidType(string $type): bool
    {
        return in_array($type, self::TYPES, true);
    }

    /** Nome de arquivo ASCII seguro: rota_<nome>_<tipo>_<YYYYMMDD>.csv */
    public function filename(string $routeName, int $routeId, string $suffix): string
    {
        $slug = trim((string) preg_replace('/[^a-zA-Z0-9]+/', '_', $this->ascii($routeName)), '_');
        if ($slug === '') {
            $slug = 'rota_' . $routeId;
        }

        return sprintf('rota_%s_%s_%s.csv', $slug, $suffix, date('Ymd'));
    }

    /**
     * @param resource            $out
     * @param array<string,mixed> $detail resultado de RouteDetailRepository::getRouteDetail()
     */
    public function write($out, string $type, array $detail): void
    {
        $this->bom($out);

        match ($type) {
            'by_hour'     => $this->byHour($out, $detail),
            'by_dow'      => $this->byDow($out, $detail),
            'occurrences' => $this->occurrences($out, $detail),
            'subroutes'   => $this->subRoutes($out, $detail),
            default       => $this->timeline($out, $detail),
        };
    }

    /**
     * @param resource                                  $out
     * @param array{route:array,days:int,rows:iterable} $historico resultado de RouteDetailRepository::getHistorico()
     */
    public function writeHistorico($out, array $historico, string $separator = ';'): void
    {
        $sep = $separator === ',' ? ',' : ';';

        $this->bom($out);
        $this->meta($out, $historico['route'], sprintf('Últimos %d dias', $historico['days']), $sep);

        $this->row($out, [
            'Data/Hora', 'Tempo (s)', 'Tempo histórico (s)', 'Atraso (s)',
            'Atraso (%)', 'Velocidade (km/h)', 'Nível de jam', 'Cidade', 'UF',
        ], $sep);

        foreach ($historico['rows'] as $r) {
            $this->row($out, [
                $r['recorded_at_brt'],
                $r['time_s'],
                $r['historic_time_s'],
                $r['delay_s'],
                $r['delay_pct'],
                $r['speed_kmh'],
                $r['jam_level'],
                $r['city'],
                $r['state'],
            ], $sep);
        }
    }

    // ─── Tipos ───────────────────────────────────────────────────────────

    /** @param resource $out */
    private function timeline($out, array $detail): void
    {
        $this->meta($out, $detail['route'], 'Últimos 7 dias');
        $this->row($out, ['Data/Hora', 'Atraso médio (s)', '% vs histórico', 'Amostras']);

        foreach ($detail['timeline'] ?? [] as $p) {
            // `time` já está no fuso de exibição — só reformatar, sem converter de novo.
            try {
                $when = (new \DateTimeImmutable((string) $p['time']))->format('d/m/Y H:i');
            } catch (\Throwable) {
                $when = (string) ($p['time'] ?? '');
            }

            $this->row($out, [
                $when,
                $this->num($p['avgDelay'] ?? null, 1),
                $this->pct($p['avgRatio'] ?? null),
                $p['count'] ?? '',
            ]);
        }
    }

    /** @param resource $out */
    private function byHour($out, array $detail): void
    {
        $this->meta($out, $detail['route'], 'Últimos 30 dias');
        $this->row($out, ['Hora', 'Atraso médio (s)', '% vs histórico', 'Amostras']);

        foreach ($detail['byHour'] ?? [] as $p) {
            $this->row($out, [
                sprintf('%02dh', (int) $p['hour']),
                $this->num($p['avgDelay'] ?? null, 1),
                $this->pct($p['avgRatio'] ?? null),
                $p['count'] ?? '',
            ]);
        }
    }

    /** @param resource $out */
    private function byDow($out, array $detail): void
    {
        $this->meta($out, $detail['route'], 'Últimos 30 dias');
        $this->row($out, ['Dia da semana', 'Atraso médio (s)', '% vs histórico', 'Amostras']);

        foreach ($detail['byDow'] ?? [] as $p) {
            $dow = (int) $p['dow'];
            $this->row($out, [
                self::DOW_LABELS[$dow] ?? "Dia {$dow}",
                $this->num($p['avgDelay'] ?? null, 1),
                $this->pct($p['avgRatio'] ?? null),
                $p['count'] ?? '',
            ]);
        }
    }

    /** @param resource $out */
    private function occurrences($out, array $detail): void
    {
        $this->meta($out, $detail['route'], 'Últimos 7 dias · até 60 m do traçado');
        $this->row($out, [
            'Fonte', 'Tipo', 'Subtipo', 'Via', 'Cidade',
            'Severidade', 'Distância do traçado (m)', 'Data/Hora',
        ]);

        foreach ($detail['occurrences'] ?? [] as $o) {
            $this->row($out, [
                $o['source'] === 'waze' ? 'Waze' : 'TVT',
                $o['type'] ?? '',
                $o['subtype'] ?? '',
                $o['street'] ?? '',
                $o['city'] ?? '',
                $o['severity'] ?? '',
                $o['distanceMeters'] ?? '',
                $this->localDateTime($o['at'] ?? null),
            ]);
        }
    }

    /** @param resource $out */
    private function subRoutes($out, array $detail): void
    {
        $this->meta($out, $detail['route'], 'Estado atual');
        $this->row($out, [
            'Trecho', 'De', 'Até', 'Atraso (s)', '% vs histórico',
            'Tempo histórico (s)', 'Nível de jam', 'Extensão (km)',
        ]);

        foreach ($detail['topSubRoutes'] ?? [] as $s) {
            $this->row($out, [
                $s['name'] ?? '',
                $s['from'] ?? '',
                $s['to'] ?? '',
                $s['delaySeconds'] ?? '',
                $this->pct($s['delayRatio'] ?? null),
                $this->num($s['historicTime'] ?? null, 0),
                $s['jamLevel'] ?? '',
                !empty($s['lengthMeters']) ? round($s['lengthMeters'] / 1000, 2) : '',
            ]);
        }
    }

    // ─── Utilitários ─────────────────────────────────────────────────────

    /** @param resource $out */
    private function bom($out): void
    {
        fwrite($out, "\xEF\xBB\xBF");
    }

    /** @param resource $out */
    private function meta($out, array $route, string $period, string $sep = ';'): void
    {
        $this->row($out, ['# Rota', $route['name'] ?? ''], $sep);
        $this->row($out, ['# Período', $period], $sep);
        $this->row($out, ['# Gerado em', (new \DateTimeImmutable('now', $this->tz))->format('d/m/Y H:i')], $sep);
        $this->row($out, [], $sep);
    }

    /**
     * @param resource   $out
     * @param list<mixed> $cells
     */
    private function row($out, array $cells, string $sep = ';'): void
    {
        fputcsv($out, array_map($this->cell(...), $cells), $sep, '"', '\\');
    }

    private function cell(mixed $value): mixed
    {
        if (!is_string($value) || $value === '') {
            return $value;
        }

        // Números/percentuais negativos ("-3.2%") não são fórmulas.
        if (preg_match('/^-?\d+([.,]\d+)?%?$/', $value) === 1) {
            return $value;
        }

        return in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'" . $value : $value;
    }

    private function num(mixed $value, int $precision): int|float|string
    {
        return $value === null || $value === '' ? '' : round((float) $value, $precision);
    }

    private function pct(mixed $ratio): string
    {
        return $ratio === null || $ratio === '' ? '' : round((float) $ratio * 100, 1) . '%';
    }

    private function localDateTime(?string $iso): string
    {
        if ($iso === null || $iso === '') {
            return '';
        }

        try {
            return (new \DateTimeImmutable($iso))->setTimezone($this->tz)->format('d/m/Y H:i');
        } catch (\Throwable) {
            return '';
        }
    }

    private function ascii(string $value): string
    {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        return $converted === false ? $value : $converted;
    }
}
