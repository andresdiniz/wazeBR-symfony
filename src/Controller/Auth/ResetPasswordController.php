<?php

namespace App\Controller\Auth;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Http\Authentication\UserAuthenticatorInterface;
use SymfonyCasts\Bundle\ResetPassword\Exception\ResetPasswordExceptionInterface;
use SymfonyCasts\Bundle\ResetPassword\Helper\ResetPasswordHelperInterface;

class ResetPasswordController extends AbstractController
{
    public function __construct(
        private ResetPasswordHelperInterface $resetPasswordHelper,
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
        $formEmail = $request->request->get('email');
        $sent = false;

        if ($request->isMethod('POST')) {
            if ($formEmail && is_string($formEmail)) {
                $email = trim(strtolower($formEmail));

                $user = $this->userRepository->findOneBy(['email' => $email]);

                if ($user instanceof User) {
                    try {
                        $resetToken = $this->resetPasswordHelper->generateResetToken($user);
                        // TODO: enviar e-mail usando $resetToken->getToken() e $resetToken->getExpiration();
                    } catch (ResetPasswordExceptionInterface $e) {
                        // Silenciosamente falha para não revelar detalhes
                    }
                }

                $sent = true;
            }
        }

        return $this->render('auth/reset_request.html.twig', [
            'sent' => $sent,
        ]);
    }

    #[Route('/resetar-senha/{token}', name: 'auth_reset')]
    public function reset(Request $request, string $token = null): Response
    {
        if ($token) {
            $this->storeTokenInSession($token);
            return $this->redirectToRoute('auth_reset');
        }

        $token = $this->getTokenFromSession();
        if (!$token) {
            return $this->redirectToRoute('auth_forgot');
        }

        try {
            $user = $this->resetPasswordHelper->validateTokenAndFetchUser($token);
        } catch (ResetPasswordExceptionInterface $e) {
            $this->addFlash('reset_password_error', 'O link de recuperação expirou ou é inválido. Solicite um novo link.');
            return $this->redirectToRoute('auth_forgot');
        }

        if ($request->isMethod('POST')) {
            $password = $request->request->get('password');
            $confirm = $request->request->get('password_confirm');

            if (!is_string($password) || $password === '' || $password !== $confirm) {
                $this->addFlash('reset_password_error', 'As senhas não coincidem ou são inválidas.');

                return $this->render('auth/reset_form.html.twig', [
                    'token' => $token,
                ]);
            }

            $this->resetPasswordHelper->removeResetRequest($token);

            // ATENÇÃO: aqui ainda está em texto puro; ideal é usar UserPasswordHasherInterface.
            $user->setPassword($password);
            $this->userRepository->save($user, true);

            $this->cleanSessionAfterReset();

            $this->addFlash('reset_password_success', 'Senha atualizada com sucesso. Você já pode entrar.');

            return $this->redirectToRoute('auth_login');
        }

        return $this->render('auth/reset_form.html.twig', [
            'token' => $token,
        ]);
    }
}
