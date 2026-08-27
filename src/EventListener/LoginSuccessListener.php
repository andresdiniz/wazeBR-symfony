<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Grava data/hora e IP do último login bem-sucedido no usuário.
 *
 * LoginSuccessEvent dispara em toda autenticação bem-sucedida do
 * firewall "main" — tanto login normal via formulário (form_login)
 * quanto reautenticação automática por remember-me. Isso significa
 * que o "último login" reflete qualquer acesso autenticado, não só
 * quando a pessoa digita a senha de novo.
 */
#[AsEventListener(event: LoginSuccessEvent::class)]
class LoginSuccessListener
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly RequestStack $requestStack,
    ) {}

    public function __invoke(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();

        if (!$user instanceof User) {
            return;
        }

        $ip = $this->requestStack->getCurrentRequest()?->getClientIp();

        $this->userRepository->recordLogin($user, $ip);
    }
}
