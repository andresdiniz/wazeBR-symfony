<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Verificador de status de conta usado pelo firewall "main".
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

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
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
