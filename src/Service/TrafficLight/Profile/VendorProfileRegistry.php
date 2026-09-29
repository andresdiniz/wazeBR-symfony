<?php

declare(strict_types=1);

namespace App\Service\TrafficLight\Profile;

final class VendorProfileRegistry
{
    /** @var array<string, VendorProfileInterface> */
    private array $profiles = [];

    /** @param iterable<VendorProfileInterface> $profiles */
    public function __construct(iterable $profiles)
    {
        foreach ($profiles as $profile) {
            $key = strtoupper($profile->getProtocol()) . '|' . strtolower($profile->getName());
            $this->profiles[$key] = $profile;
        }
    }

    public function get(string $protocol, ?string $vendor): ?VendorProfileInterface
    {
        $vendorKey = strtolower($vendor ?? 'generic');
        return $this->profiles[strtoupper($protocol) . '|' . $vendorKey]
            ?? $this->profiles[strtoupper($protocol) . '|generic']
            ?? null;
    }
}
