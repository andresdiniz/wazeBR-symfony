<?php

declare(strict_types=1);
namespace App\Service;

use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class RouteUiTranslator
{
    private array $cache = [];
    public function __construct(private readonly TranslatorInterface $translator) {}
    public function text(string $message, array $parameters = []): string
    {
        return $this->translator->trans($message, $parameters, 'routes_ui');
    }
    public function locale(): string { return $this->translator->getLocale(); }
    public function messages(): array
    {
        $locale = $this->locale();
        if (isset($this->cache[$locale])) return $this->cache[$locale];
        if (!$this->translator instanceof TranslatorBagInterface) return [];
        $catalogue = $this->translator->getCatalogue($locale);
        $chain = [];
        do { $chain[] = $catalogue; $catalogue = $catalogue->getFallbackCatalogue(); } while ($catalogue !== null);
        $out = [];
        foreach (['dashboard_ui', 'alerts_ui', 'jams_ui', 'routes_ui'] as $domain) {
            foreach (array_reverse($chain) as $catalogue) {
                $items = $catalogue->all($domain);
                if ($domain !== 'routes_ui') $items = array_filter($items, static fn (string $key): bool => str_starts_with($key, 'code.'), ARRAY_FILTER_USE_KEY);
                $out = array_replace($out, $items);
            }
        }
        return $this->cache[$locale] = $out;
    }
    public function label(mixed $value, string $fallback = '—'): string
    {
        if ($value === null || $value === '') return $fallback;
        $text = (string) $value;
        $messages = $this->messages();
        foreach ([$text, 'code.' . $text, 'level.' . $text] as $key) {
            if (array_key_exists($key, $messages)) return $messages[$key];
        }
        return $text;
    }
    public function datePattern(): string { return str_starts_with($this->locale(), 'en') ? 'm/d' : 'd/m'; }
    public function date(mixed $value, bool $withYear = false, string $timezone = 'America/Sao_Paulo'): string
    {
        if ($value === null || $value === '') return '—';
        try {
            $date = $value instanceof \DateTimeInterface ? \DateTimeImmutable::createFromInterface($value) : new \DateTimeImmutable((string) $value);
            return $date->setTimezone(new \DateTimeZone($timezone))->format($this->datePattern() . ($withYear ? '/Y' : '') . ' H:i');
        } catch (\Exception) { return '—'; }
    }
}
