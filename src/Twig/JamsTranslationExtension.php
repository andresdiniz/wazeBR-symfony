<?php

declare(strict_types=1);

namespace App\Twig;

use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class JamsTranslationExtension extends AbstractExtension
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('jams_i18n', [$this, 'messages']),
            new TwigFunction('jams_label', [$this, 'label']),
            new TwigFunction('jams_date', [$this, 'date']),
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
            $chain[] = $catalogue;
            $catalogue = $catalogue->getFallbackCatalogue();
        } while ($catalogue !== null);

        $messages = [];
        foreach (['dashboard_ui', 'alerts_ui', 'jams_ui'] as $domain) {
            foreach (array_reverse($chain) as $catalogue) {
                $messages = array_replace($messages, $catalogue->all($domain));
            }
        }

        return $messages;
    }

    public function label(mixed $value, string $fallback = '—'): string
    {
        if ($value === null || $value === '') {
            return $fallback;
        }

        $text = (string) $value;
        foreach (['jams_ui', 'alerts_ui', 'dashboard_ui'] as $domain) {
            foreach ([$text, 'code.' . $text] as $key) {
                $translated = $this->translator->trans($key, [], $domain);
                if ($translated !== $key) {
                    return $translated;
                }
            }
        }

        return $text;
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
