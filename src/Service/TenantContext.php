<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Partner;
use App\Repository\PartnerRepository;

/**
 * Service to store the current tenant (partner) context.
 * Used for multi-tenancy to isolate data per partner.
 */
class TenantContext
{
    private ?Partner $partner = null;

    public function __construct(private readonly PartnerRepository $partners)
    {
    }

    /** Resolve the active partner for an external API token. */
    public function resolveFromToken(string $token): ?Partner
    {
        $token = trim($token);
        if ($token === '' || strlen($token) > 255) {
            return null;
        }

        foreach ($this->partners->findBy(['isActive' => true]) as $partner) {
            $candidate = $partner->getApiToken();
            if ($candidate !== null && $candidate !== '' && hash_equals($candidate, $token)) {
                return $this->partner = $partner;
            }
        }

        return null;
    }

    public function getPartner(): ?Partner
    {
        return $this->partner;
    }

    public function setPartner(?Partner $partner): void
    {
        $this->partner = $partner;
    }

    public function getPartnerId(): ?int
    {
        return $this->partner?->getId();
    }

    public function getPartnerCode(): ?string
    {
        return $this->partner?->getCode();
    }

    public function hasPartner(): bool
    {
        return null !== $this->partner;
    }

    public function clear(): void
    {
        $this->partner = null;
    }
}
