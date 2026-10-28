<?php

declare(strict_types=1);

namespace App\Twig;

use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class DashboardTranslationExtension extends AbstractExtension
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('dashboard_i18n', [$this, 'messages']),
            new TwigFunction('dashboard_label', [$this, 'label']),
            new TwigFunction('dashboard_date', [$this, 'date']),
        ];
    }

    public function messages(): array
    {
        if (!$this->translator instanceof TranslatorBagInterface) {
            return [];
        }

        $catalogue = $this->translator->getCatalogue($this->translator->getLocale());
        $chain = [];
        do {
            $chain[] = $catalogue->all('dashboard_ui');
            $catalogue = $catalogue->getFallbackCatalogue();
        } while ($catalogue !== null);

        $messages = [];
        foreach (array_reverse($chain) as $items) {
            $messages = array_replace($messages, $items);
        }

        return $messages;
    }

    public function label(mixed $value, string $fallback = '—'): string
    {
        if ($value === null || $value === '') {
            return $fallback;
        }

        $value = (string) $value;
        $key = 'code.' . $value;
        $translated = $this->translator->trans($key, [], 'dashboard_ui');

        return $translated !== $key
            ? $translated
            : $this->translator->trans($value, [], 'dashboard_ui');
    }

    public function date(mixed $value, bool $withYear = false): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        try {
            $date = $value instanceof \DateTimeInterface
                ? \DateTimeImmutable::createFromInterface($value)
                : new \DateTimeImmutable((string) $value);
            $date = $date->setTimezone(new \DateTimeZone('America/Sao_Paulo'));
            $pattern = str_starts_with($this->translator->getLocale(), 'en') ? 'm/d' : 'd/m';

            return $date->format($pattern . ($withYear ? '/Y' : '') . ' H:i');
        } catch (\Exception) {
            return '—';
        }
    }
}
