<?php

namespace App\Controller\Auth;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Annotation\Route;

class ResetPasswordController extends AbstractController
{
    public function __construct(
        private UserRepository $userRepository,
        private MailerInterface $mailer,
    ) {
    }

    private const RESET_TOKEN_SESSION_KEY = 'reset_password_token';

    private function storeTokenInSession(string $token): void
    {
        $this->get('session')->set(self::RESET_TOKEN_SESSION_KEY, $token);
    }

    private function getTokenFromSession(): ?string
    {
        return $this->get('session')->get(self::RESET_TOKEN_SESSION_KEY);
    }

    private function cleanSessionAfterReset(): void
    {
        $this->get('session')->remove(self::RESET_TOKEN_SESSION_KEY);
    }

    #[Route('/esqueci-senha', name: 'auth_forgot')]
    public function request(Request $request): Response
    {
        // TODO: implementar fluxo completo com SymfonyCasts ResetPassword bundle.
        // Por enquanto, apenas uma tela estática para não quebrar a aplicação.
        $sent = false;

        if ($request->isMethod('POST')) {
            $sent = true;
        }

        return $this->render('auth/reset_request.html.twig', [
            'sent' => $sent,
        ]);
    }

    #[Route('/resetar-senha/{token}', name: 'auth_reset')]
    public function reset(Request $request, string $token = null): Response
    {
        // Fluxo simplificado: sem validação de token, apenas exibe o formulário.
        if ($request->isMethod('POST')) {
            $password = $request->request->get('password');
            $confirm = $request->request->get('password_confirm');

            if (!is_string($password) || $password === '' || $password !== $confirm) {
                $this->addFlash('reset_password_error', 'As senhas não coincidem ou são inválidas.');

                return $this->render('auth/reset_form.html.twig', [
                    'token' => $token,
                ]);
            }

            // TODO: buscar usuário e aplicar hash de senha corretamente.
            $this->cleanSessionAfterReset();
            $this->addFlash('reset_password_success', 'Senha atualizada (fluxo simplificado).');

            return $this->redirectToRoute('auth_login');
        }

        return $this->render('auth/reset_form.html.twig', [
            'token' => $token,
        ]);
    }
}
