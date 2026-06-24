<?php

declare(strict_types=1);

namespace App\Entity\Trait;

use App\Entity\Partner;
use Doctrine\ORM\Mapping as ORM;

/**
 * Trait que associa qualquer entidade a um parceiro (tenant).
 * Inclua com `use TenantAwareTrait;` nas entidades multi-tenant.
 */
trait TenantAwareTrait
{
    #[ORM\ManyToOne(targetEntity: Partner::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Partner $partner;

    public function getPartner(): Partner
    {
        return $this->partner;
    }

    public function setPartner(Partner $partner): static
    {
        $this->partner = $partner;
        return $this;
    }
}
