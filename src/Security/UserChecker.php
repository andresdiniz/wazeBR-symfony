<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Verificador de status de conta usado pelo firewall "main".
 *
 * security.yaml apontava para o serviço padrão `security.user_checker`
 * (um no-op do próprio Symfony) em vez de uma implementação própria —
 * ou seja, a flag `active` do usuário nunca era checada no login.
 * Um usuário desativado por um ROLE_ACCOUNT_ADMIN via
 * AccountUserController::toggle() continuava conseguindo autenticar
 * normalmente, pois nada no fluxo real de login (form_login) validava
 * isso. Este checker fecha essa lacuna.
 *
 * checkPreAuth roda ANTES da senha ser verificada (bloqueia cedo);
 * checkPostAuth roda depois, como defesa em profundidade caso a conta
 * seja desativada entre o carregamento do usuário e a validação da
 * senha (condição de corrida rara, mas de custo zero cobrir aqui).
 */
class UserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof User) {
            return;
        }

        if ($user->isActive() === false) {
            throw new CustomUserMessageAccountStatusException(
                'Sua conta está desativada. Entre em contato com um administrador.'
            );
        }
    }

    public function checkPostAuth(UserInterface $user): void
    {
        if (!$user instanceof User) {
            return;
        }

        if ($user->isActive() === false) {
            throw new CustomUserMessageAccountStatusException(
                'Sua conta está desativada. Entre em contato com um administrador.'
            );
        }
    }
}
