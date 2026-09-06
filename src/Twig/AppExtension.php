<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Extensão Twig da aplicação com filtros utilitários customizados.
 *
 * O Twig "puro" (sem twig/extra-bundle + twig/string-extra) não possui
 * um filtro nativo chamado "truncate". Esta extensão adiciona um filtro
 * simples e leve, sem exigir dependências extras no composer.
 */
final class AppExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('truncate', $this->truncate(...)),
        ];
    }

    /**
     * Trunca uma string para o tamanho máximo informado, adicionando
     * reticências ao final quando o texto for cortado.
     *
     * Uso no template: {{ alert.title|truncate(40) }}
     * Uso com sufixo customizado: {{ alert.title|truncate(40, '...') }}
     */
    public function truncate(?string $value, int $length = 80, string $suffix = '…'): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (mb_strlen($value) <= $length) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, $length)) . $suffix;
    }
}
