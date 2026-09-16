<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Ponto único de redirecionamento pós-login.
 *
 * O firewall (form_login.default_target_path) manda o usuário pra cá
 * logo após autenticar. Aqui decidimos o destino conforme a role.
 */
final class PostLoginRedirectController extends AbstractController
{
    #[Route('/post-login-redirect', name: 'app_post_login_redirect', methods: ['GET'])]
    public function __invoke(): Response
    {
        $user = $this->getUser();

        if ($user === null) {
            // Não deveria acontecer (rota protegida por IS_AUTHENTICATED_FULLY),
            // mas por segurança:
            return $this->redirectToRoute('auth_login');
        }

        // Ordem importa: do mais específico/privilegiado para o mais genérico,
        // porque isGranted() respeita a role_hierarchy e um super admin
        // também "é" ROLE_ADMIN, ROLE_ACCOUNT_ADMIN etc.
        if ($this->isGranted('ROLE_SUPER_ADMIN') || $this->isGranted('ROLE_ADMIN')) {
            return $this->redirectToRoute('dashboard'); // ou uma rota admin, se existir
        }

        if ($this->isGranted('ROLE_ACCOUNT_ADMIN')) {
            return $this->redirectToRoute('dashboard'); // trocar por rota dedicada
        }

        if ($this->isGranted('ROLE_PARTNER_ADMIN') || $this->isGranted('ROLE_OPERATOR')) {
            return $this->redirectToRoute('dashboard'); // trocar por rota dedicada
        }

        if ($this->isGranted('ROLE_FIELD_AGENT')) {
            return $this->redirectToRoute('dashboard'); // trocar por rota dedicada
        }

        // Fallback (VIEWER e qualquer outro autenticado)
        return $this->redirectToRoute('dashboard');
    }
}
