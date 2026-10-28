<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Contracts\Translation\TranslatorInterface;

/** Localizes known historical audit messages without rewriting audit records. */
final class ForecastExplanationTranslator
{
    private const CONTEXT = 'Nenhum sinal relevante encontrado nos dados consultados; isso nao confirma ausencia de incidentes. Contexto associado, nao causa comprovada.';

    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public function translate(?string $original): ?string
    {
        if ($original === null || trim($original) === '') {
            return $original;
        }

        $pattern = '~\APrevis(?:ao|ão)\s+(?<seconds>\d+(?:[.,]\d+)?)\s+s\s+(?<direction>acima|abaixo)\s+do\s+medido\.\s*(?<context>.*)\z~us';
        if (preg_match($pattern, trim($original), $match) !== 1) {
            return $original;
        }

        // Never replace another context (e.g. an observed incident) with "no signal".
        if ($this->normalize($match['context']) !== $this->normalize(self::CONTEXT)) {
            return $original;
        }

        $key = $match['direction'] === 'acima' ? 'above_no_signal' : 'below_no_signal';
        $translated = $this->translator->trans(
            $key,
            ['%seconds%' => $match['seconds']],
            'forecast_explanation',
        );

        // A missing catalogue must not expose an internal message ID to the user.
        return $translated === $key ? $original : $translated;
    }

    private function normalize(string $value): string
    {
        $value = strtr($value, [
            'ã' => 'a', 'á' => 'a', 'à' => 'a', 'â' => 'a',
            'é' => 'e', 'ê' => 'e', 'í' => 'i',
            'ó' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ú' => 'u', 'ç' => 'c',
        ]);

        return strtolower(trim((string) preg_replace('/\s+/u', ' ', $value)));
    }
}
