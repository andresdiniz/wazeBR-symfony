<?php

namespace App\Twig;

use App\Entity\User;
use Twig\Extension\AbstractExtension;
use Twig\Extension\RuntimeExtensionInterface;

class UserExtension extends AbstractExtension implements RuntimeExtensionInterface
{
    public function getFunctions(): array
    {
        return [
            new \Twig\TwigFunction('user_has_role', [$this, 'userHasRole']),
        ];
    }

    public function userHasRole(?User $user, string $role): bool
    {
        if (!$user) {
            return false;
        }

        return in_array($role, $user->getRoles(), true);
    }
}
