<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

final class TimezoneExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('app_date', [$this, 'formatDate']),
        ];
    }

    public function formatDate(
        \DateTimeInterface|string|null $value,
        string $format = 'd/m/Y H:i',
        ?string $timezone = null,
    ): string {
        if ($value === null || $value === '') {
            return '—';
        }

        try {
            if (is_string($value)) {
                $date = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
            } else {
                $date = \DateTimeImmutable::createFromInterface($value);
            }

            $timezone ??= $_ENV['APP_TIMEZONE'] ?? 'America/Sao_Paulo';

            return $date
                ->setTimezone(new \DateTimeZone($timezone))
                ->format($format);
        } catch (\Throwable) {
            return '—';
        }
    }
}
