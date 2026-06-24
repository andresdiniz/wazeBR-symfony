<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Partner;
use App\Entity\User;
use App\Repository\PartnerRepository;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Resolve o parceiro (tenant) ativo para a requisição atual.
 *
 * Ordem de resolução:
 *  1. Usuário autenticado → Partner do User.
 *  2. Token externo (X-Api-Token) → resolveFromToken().
 *  3. Command/job → setPartner() manual.
 */
class TenantContext
{
    private ?Partner $current = null;

    public function __construct(
        private readonly Security           $security,
        private readonly PartnerRepository  $partnerRepository,
    ) {}

    public function getPartner(): ?Partner
    {
        if ($this->current !== null) {
            return $this->current;
        }

        $user = $this->security->getUser();

        if ($user instanceof User && $user->getPartner() !== null) {
            $this->current = $user->getPartner();
        }

        return $this->current;
    }

    public function setPartner(Partner $partner): void
    {
        $this->current = $partner;
    }

    public function resolveFromToken(string $token): ?Partner
    {
        $partner = $this->partnerRepository->findByApiToken($token);
        if ($partner !== null) {
            $this->current = $partner;
        }
        return $partner;
    }

    public function requirePartner(): Partner
    {
        $partner = $this->getPartner();
        if ($partner === null) {
            throw new \LogicException('Nenhum parceiro (tenant) resolvido para a requisição atual.');
        }
        return $partner;
    }
}
