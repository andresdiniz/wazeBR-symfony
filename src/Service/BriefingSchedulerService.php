<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Decide quais parceiros recebem análise de IA no dia de hoje.
 *
 * Configurar em config/packages/briefing.yaml (chave parameters:):
 *   briefing.strategy:  always | rotate | weekly
 *   briefing.parceiros: lista de parceiros
 *
 * Estratégias:
 *   always  — todos recebem IA todos os dias (padrão; Gemini Flash gratuito
 *              suporta 1.500 req/dia, suficiente para ~10 parceiros)
 *   rotate  — 2 parceiros/dia nos dias úteis, todos no fim de semana
 *   weekly  — cada parceiro recebe IA 1x/semana no `ai_day` configurado
 *
 * Independentemente da estratégia, TODOS os parceiros ativos recebem o e-mail
 * com os dados brutos. O flag `use_ai` controla apenas o bloco narrativo.
 */
final class BriefingSchedulerService
{
    /** @param array<array<string,mixed>> $parceiros Lista bruta do parâmetro briefing.parceiros */
    public function __construct(
        private readonly array  $parceiros,
        private readonly string $strategy = 'always',
    ) {}

    /**
     * Retorna os parceiros ativos (ativo != false) com o flag `use_ai` calculado.
     *
     * @return array<array<string,mixed>>
     */
    public function getParceirosPorDia(?\DateTimeImmutable $hoje = null): array
    {
        $hoje      ??= new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo'));
        $diaSemana   = (int) $hoje->format('N'); // 1=Seg … 7=Dom

        // Filtra inativos aqui — o YAML pode ter ativo: false
        $ativos = array_values(array_filter(
            $this->parceiros,
            static fn(array $p) => ($p['ativo'] ?? true) !== false,
        ));

        return array_map(function (array $parceiro) use ($diaSemana, $ativos): array {
            $parceiro['use_ai'] = match ($this->strategy) {
                'rotate' => $this->rotateStrategy($parceiro, $ativos, $diaSemana),
                'weekly' => $this->weeklyStrategy($parceiro, $diaSemana),
                default  => true, // 'always' e qualquer valor desconhecido
            };
            return $parceiro;
        }, $ativos);
    }

    /** Parceiros que precisam de chamada à IA hoje. */
    public function getParceiroComIA(?\DateTimeImmutable $hoje = null): array
    {
        return array_filter(
            $this->getParceirosPorDia($hoje),
            static fn(array $p) => $p['use_ai'],
        );
    }

    // ─── Estratégias privadas ─────────────────────────────────────────────────

    /**
     * Seg: parceiros 0,1  |  Ter: 2,3  |  Qua: 4,5  |  Qui: 6,7  |  Sex: 8,9
     * Sáb/Dom: todos (resumo semanal — prompt mais completo).
     */
    private function rotateStrategy(array $parceiro, array $ativos, int $diaSemana): bool
    {
        if ($diaSemana >= 6) {
            return true;
        }

        $indice = array_search($parceiro, $ativos, true);
        if ($indice === false) {
            return false;
        }

        $n         = count($ativos);
        $grupoSize = (int) ceil($n / 5);
        $slot      = $diaSemana - 1; // 0(Seg)–4(Sex)
        $inicio    = $slot * $grupoSize;

        return $indice >= $inicio && $indice < $inicio + $grupoSize;
    }

    private function weeklyStrategy(array $parceiro, int $diaSemana): bool
    {
        return isset($parceiro['ai_day']) && (int) $parceiro['ai_day'] === $diaSemana;
    }
}
