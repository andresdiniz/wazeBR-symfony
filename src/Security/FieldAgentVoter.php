<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Voter para permissões específicas de agentes de via (ROLE_FIELD_AGENT).
 *
 * Uso nos controllers / templates:
 *   $this->denyAccessUnlessGranted('FIELD_AGENT_view_alerts');
 *   {{ is_granted('FIELD_AGENT_submit_report') }}
 *
 * Permissões disponíveis:
 *   view_alerts      – visualizar alertas Waze
 *   view_jams        – visualizar congestionamentos
 *   view_routes      – visualizar rotas monitoradas
 *   view_reports     – visualizar relatórios e estatísticas
 *   submit_report    – registrar ocorrência de campo
 *   view_cemaden     – acessar dados CEMADEN
 */
class FieldAgentVoter extends Voter
{
    public const PERMISSIONS = [
        'view_alerts',
        'view_jams',
        'view_routes',
        'view_reports',
        'submit_report',
        'view_cemaden',
    ];

    private const PREFIX = 'FIELD_AGENT_';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return str_starts_with($attribute, self::PREFIX);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        // Super admin e account admin sempre têm acesso
        if ($user->isSuperAdmin() || $user->isAccountAdmin()) {
            return true;
        }

        // Usuário padrão tem acesso a tudo (exceto submit_report)
        $permission = substr($attribute, strlen(self::PREFIX));

        if (!$user->isFieldAgent()) {
            // ROLE_USER normal: bloqueia apenas submit_report
            return $permission !== 'submit_report';
        }

        // Agente de via: verifica permissões customizadas
        return $user->hasFieldAgentPermission($permission);
    }
}
