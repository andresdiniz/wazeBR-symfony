<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Decide quais parceiros recebem análise de IA no dia de hoje.
 *
 * Estratégias disponíveis (configurável em config/packages/briefing.yaml):
 *
 *   always   — todos os parceiros recebem IA todos os dias (default quando
 *               a quota da API é suficiente — Gemini Flash gratuito suporta
 *               1.500 req/dia, o que cobre ~10 parceiros tranquilamente).
 *
 *   rotate   — rotaciona os parceiros por dia da semana. Útil se o número
 *               de parceiros crescer ou se a quota for mais restrita.
 *               Cada parceiro recebe IA 2x por semana (dias úteis) + 1x no
 *               resumo semanal do fim de semana.
 *
 *   weekly   — cada parceiro recebe IA somente uma vez por semana, no dia
 *               definido na configuração do parceiro.
 *
 * Independentemente da estratégia, TODOS os parceiros recebem o e-mail com
 * os dados brutos (tabelas de KPIs). A IA é apenas o bloco narrativo extra.
 */
final class BriefingSchedulerService
{
    /**
     * @param array  $parceiros   Lista de parceiros do briefing.yaml
     * @param string $strategy    'always' | 'rotate' | 'weekly'
     */
    public function __construct(
        private readonly array  $parceiros,
        private readonly string $strategy = 'always',
    ) {}

    /**
     * Retorna todos os parceiros que devem receber o briefing hoje,
     * com o flag `use_ai` indicando se a análise de IA deve ser gerada.
     *
     * @return array<array{cidade: string, email: string[], use_ai: bool, ...}>
     */
    public function getParceirosPorDia(?\DateTimeImmutable $hoje = null): array
    {
        $hoje ??= new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo'));
        $diaSemana = (int) $hoje->format('N'); // 1=Seg … 7=Dom

        return array_map(function (array $parceiro) use ($diaSemana): array {
            $parceiro['use_ai'] = match ($this->strategy) {
                'always'  => true,
                'rotate'  => $this->rotateStrategy($parceiro, $diaSemana),
                'weekly'  => $this->weeklyStrategy($parceiro, $diaSemana),
                default   => true,
            };
            return $parceiro;
        }, $this->parceiros);
    }

    /**
     * Retorna somente os parceiros que precisam de chamada à IA hoje.
     * Útil para calcular o total de requisições antes de executar.
     */
    public function getParceiroComIA(?\DateTimeImmutable $hoje = null): array
    {
        return array_filter(
            $this->getParceirosPorDia($hoje),
            fn(array $p) => $p['use_ai']
        );
    }

    // ─── Estratégias ──────────────────────────────────────────────────────────

    /**
     * Rotaciona 2 parceiros por dia útil + todos no fim de semana (resumo semanal).
     *
     * Seg: parceiros 0,5  |  Ter: 1,6  |  Qua: 2,7  |  Qui: 3,8  |  Sex: 4,9
     * Sáb/Dom: todos (resumo semanal — prompt diferente, mais tokens)
     */
    private function rotateStrategy(array $parceiro, int $diaSemana): bool
    {
        if ($diaSemana >= 6) {
            return true; // fim de semana: todos recebem resumo semanal
        }

        $indice = array_search($parceiro, $this->parceiros, true);
        if ($indice === false) {
            return false;
        }

        // Dia 1(Seg)=slot 0, 2(Ter)=slot 1 ... 5(Sex)=slot 4
        $slot = $diaSemana - 1; // 0–4
        $n    = count($this->parceiros);

        // Divide os parceiros em 5 grupos (um por dia útil)
        // Cada parceiro vai aparecer em ceil(n/5) dias
        $grupoSize = (int) ceil($n / 5);
        $inicio    = $slot * $grupoSize;
        $fim       = $inicio + $grupoSize - 1;

        return $indice >= $inicio && $indice <= $fim;
    }

    /**
     * Cada parceiro tem um `ai_day` definido em sua configuração (1=Seg … 7=Dom).
     * A IA só roda naquele dia.
     */
    private function weeklyStrategy(array $parceiro, int $diaSemana): bool
    {
        $aiDay = $parceiro['ai_day'] ?? null;

        return $aiDay !== null && (int) $aiDay === $diaSemana;
    }
}
