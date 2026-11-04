<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Compatibilidade com o comando de briefing existente.
 * A narrativa externa foi removida: os boletins usam somente os dados locais.
 */
final class AiNarrativeService
{
    /** @param array<string, mixed> $dados */
    public function generate(array $dados, ?string $prompt = null): ?string
    {
        return null;
    }
}
