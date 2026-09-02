<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Partner;
use App\Entity\User;
use App\Repository\PartnerRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Resolve o parceiro (tenant) ativo para a requisição atual.
 *
 * Ordem de resolução:
 *  1. Usuário comum (tem Partner fixo no cadastro) → sempre esse.
 *  2. Admin (ROLE_ADMIN ou ROLE_SUPER_ADMIN) → NÃO fica preso a um
 *     parceiro fixo. Usa o que estiver escolhido na sessão (trocável
 *     via PartnerController::switchTo(), rota
 *     /admin/parceiros/{id}/visualizar), com fallback pro primeiro
 *     parceiro ativo se ainda não tiver escolhido nenhum.
 *  3. Token externo (X-Api-Token) → resolveFromToken().
 *  4. Command/job → setPartner() manual.
 *
 * NOTA: antes, requirePartner() lançava LogicException sempre que um
 * admin sem Partner atribuído no próprio cadastro acessava qualquer
 * página — travava o login/dashboard por completo. Isso não
 * implementa uma visão agregada de TODOS os parceiros ao mesmo tempo
 * (isso exigiria reescrever boa parte das queries do projeto, que são
 * todas filtradas por um Partner só) — implementa o admin poder ver e
 * alternar entre qualquer parceiro, sem ficar preso a nenhum fixo.
 */
class TenantContext
{
    private const SESSION_KEY = 'tenant_context_viewing_partner_id';

    private ?Partner $current = null;

    public function __construct(
        private readonly Security          $security,
        private readonly PartnerRepository  $partnerRepository,
        private readonly RequestStack       $requestStack,
    ) {}

    public function getPartner(): ?Partner
    {
        if ($this->current !== null) {
            return $this->current;
        }

        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return null;
        }

        if ($user->isAdmin()) {
            $this->current = $this->resolveAdminPartner();
            return $this->current;
        }

        if ($user->getPartner() !== null) {
            $this->current = $user->getPartner();
        }

        return $this->current;
    }

    /**
     * Chamado pela troca de parceiro (super admin) — grava na sessão
     * pra persistir entre requisições, além de já valer pra requisição
     * atual.
     */
    public function switchTo(Partner $partner): void
    {
        $this->current = $partner;

        $session = $this->requestStack->getSession();
        $session->set(self::SESSION_KEY, $partner->getId());
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

    private function resolveAdminPartner(): ?Partner
    {
        $session = $this->requestStack->getSession();
        $partnerId = $session->get(self::SESSION_KEY);

        if ($partnerId !== null) {
            $partner = $this->partnerRepository->find($partnerId);
            if ($partner !== null) {
                return $partner;
            }
            // Parceiro escolhido antes não existe mais (removido) —
            // limpa da sessão pra não ficar tentando de novo sempre.
            $session->remove(self::SESSION_KEY);
        }

        $partners = $this->partnerRepository->findActivePartners();

        return $partners[0] ?? null;
    }
}
