<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class AccountUserController extends AbstractController
{
    /**
     * Gerenciar conta do usuário
     */
    #[Route(path: '/account', name: 'app_account', methods: ['GET', 'POST'])]
    public function account(Request $request): Response
    {
        // Configurações de email (hardcoded ou do .env)
        $appName = $_ENV['APP_NAME'] ?? 'wazeBR';
        $appUrl = $_ENV['APP_URL'] ?? 'http://localhost:8000';
        
        return $this->render('account/index.html.twig', [
            'appName' => $appName,
            'appUrl' => $appUrl,
        ]);
    }

    /**
     * Alterar senha do usuário
     */
    #[Route(path: '/account/change-password', name: 'app_account_change_password', methods: ['GET', 'POST'])]
    public function changePassword(Request $request): Response
    {
        return $this->render('account/change_password.html.twig');
    }

    /**
     * Configurações de notificação
     */
    #[Route(path: '/account/notifications', name: 'app_account_notifications', methods: ['GET', 'POST'])]
    public function notifications(Request $request): Response
    {
        return $this->render('account/notifications.html.twig');
    }
}
