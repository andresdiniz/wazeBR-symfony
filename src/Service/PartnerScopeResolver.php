<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Partner;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Define o escopo de dados do usuário logado.
 *
 *   - admin global            → null (sem filtro de parceiro)
 *   - usuário com parceiro    → o parceiro
 *   - usuário SEM parceiro    → 403 (nunca recebe escopo global por engano)
 */
final class PartnerScopeResolver
{
    public function __construct(private readonly Security $security)
    {
    }

    public function user(): User
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new AccessDeniedException();
        }

        return $user;
    }

    public function resolve(): ?Partner
    {
        $user = $this->user();

        if ($user->isGlobalAdmin()) {
            return null;
        }

        return $user->getPartner()
            ?? throw new AccessDeniedException('Usuário sem parceiro associado.');
    }
}
