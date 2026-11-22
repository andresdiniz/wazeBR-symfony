<?php

declare(strict_types=1);

namespace App\Service;

/** Blocks private, local and credential-bearing HTTP targets before server-side requests. */
final class RemoteUrlGuard
{
    public function isAllowedPublicHttpUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $host = strtolower(rtrim((string) $parts['host'], '.'));
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            return false;
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP)
            ? [$host]
            : array_values(array_unique(array_filter(array_map(
                static fn (array $record): string => (string) ($record['ip'] ?? $record['ipv6'] ?? ''),
                dns_get_record($host, DNS_A | DNS_AAAA) ?: [],
            ))));

        if ($addresses === []) {
            return false;
        }

        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
        }

        return true;
    }
}
