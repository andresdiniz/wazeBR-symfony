<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class AuthController extends AbstractController
{
    #[Route('/login', name: 'app_login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('dashboard_index');
        }

        return $this->render('auth/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
        ]);
    }

    #[Route('/logout', name: 'app_logout')]
    public function logout(): void
    {
        throw new \LogicException('This method can be blank - it will be intercepted by the logout key on your firewall.');
    }

    #[Route('/forgot-password', name: 'app_forgot_password')]
    public function requestForgotPassword(Request $request): Response
    {
        return $this->render('auth/forgot.html.twig', [
            'requestForm' => null,
        ]);
    }

    #[Route('/reset-password/{token}', name: 'app_reset_password')]
    public function resetPassword(Request $request, ?string $token = null): Response
    {
        return $this->render('auth/reset.html.twig', [
            'resetForm' => null,
            'token' => $token,
        ]);
    }
}
