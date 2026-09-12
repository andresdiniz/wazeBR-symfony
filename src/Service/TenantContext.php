<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Partner;

/**
 * Service to store the current tenant (partner) context.
 * Used for multi-tenancy to isolate data per partner.
 */
class TenantContext
{
    private ?Partner $partner = null;

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
