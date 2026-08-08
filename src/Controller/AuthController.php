<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class AuthController extends AbstractController
{
    public function __construct(
        private readonly UserRepository              $userRepository,
        private readonly UserPasswordHasherInterface  $hasher,
        private readonly MailerInterface              $mailer,
        private readonly LoggerInterface              $logger,
        private readonly string                       $appName,
        private readonly string                       $senderEmail,
    ) {}

    #[Route('/login', name: 'auth_login')]
    public function login(AuthenticationUtils $authUtils): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('dashboard_index');
        }

        return $this->render('auth/login.html.twig', [
            'last_username' => $authUtils->getLastUsername(),
            'error'         => $authUtils->getLastAuthenticationError(),
        ]);
    }

    #[Route('/logout', name: 'auth_logout')]
    public function logout(): never
    {
        throw new \LogicException('Interceptado pelo firewall do Symfony.');
    }

    #[Route('/esqueci-senha', name: 'auth_forgot', methods: ['GET', 'POST'])]
    public function forgot(Request $request): Response
    {
        $sent = false;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('auth_forgot', (string) $request->request->get('_csrf_token'))) {
                $this->addFlash('error', 'Token de segurança inválido. Tente novamente.');

                return $this->render('auth/forgot.html.twig', ['sent' => false]);
            }

            $email = trim(strtolower((string) $request->request->get('email', '')));
            $user  = $email !== '' ? $this->userRepository->findOneBy(['email' => $email]) : null;

            if ($user instanceof User && $user->isEnabled()) {
                $token = $this->userRepository->generateResetToken($user);
                $this->sendResetEmail($user, $token);
            } else {
                // Não revela se o e-mail existe ou não na base (evita enumeração de contas).
                $this->logger->info('Solicitação de reset para e-mail não encontrado ou inativo.', ['email' => $email]);
            }

            // Sempre exibe a mesma mensagem, exista ou não o e-mail na base.
            $sent = true;
        }

        return $this->render('auth/forgot.html.twig', ['sent' => $sent]);
    }

    #[Route('/redefinir-senha/{token}', name: 'auth_reset', methods: ['GET', 'POST'])]
    public function reset(string $token, Request $request): Response
    {
        $user = $this->userRepository->findByResetToken($token);

        if (!$user || !$this->userRepository->isResetTokenValid($user)) {
            $this->addFlash('error', 'Link inválido ou expirado. Solicite um novo.');

            return $this->redirectToRoute('auth_forgot');
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('auth_reset', (string) $request->request->get('_csrf_token'))) {
                $this->addFlash('error', 'Token de segurança inválido. Tente novamente.');

                return $this->render('auth/reset.html.twig', ['token' => $token]);
            }

            $password = (string) $request->request->get('password', '');
            $confirm  = (string) $request->request->get('confirm', '');

            if (!$this->isPasswordStrongEnough($password)) {
                $this->addFlash('error', 'A senha deve ter ao menos 8 caracteres, incluindo letras e números.');
            } elseif ($password !== $confirm) {
                $this->addFlash('error', 'As senhas não coincidem.');
            } else {
                $user->setPassword($this->hasher->hashPassword($user, $password));
                $this->userRepository->clearResetToken($user);
                $this->addFlash('success', 'Senha redefinida com sucesso. Faça login.');

                return $this->redirectToRoute('auth_login');
            }
        }

        return $this->render('auth/reset.html.twig', ['token' => $token]);
    }

    #[Route('/perfil', name: 'auth_profile')]
    #[IsGranted('ROLE_USER')]
    public function profile(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('auth_profile', (string) $request->request->get('_csrf_token'))) {
                $this->addFlash('error', 'Token de segurança inválido. Tente novamente.');

                return $this->redirectToRoute('auth_profile');
            }

            $name     = (string) $request->request->get('name', '');
            $password = (string) $request->request->get('password', '');

            if ($name !== '') {
                $user->setName($name);
            }

            if ($password !== '') {
                if (!$this->isPasswordStrongEnough($password)) {
                    $this->addFlash('error', 'A senha deve ter ao menos 8 caracteres, incluindo letras e números.');

                    return $this->redirectToRoute('auth_profile');
                }
                $user->setPassword($this->hasher->hashPassword($user, $password));
            }

            $this->userRepository->save($user);
            $this->addFlash('success', 'Perfil atualizado.');

            return $this->redirectToRoute('auth_profile');
        }

        return $this->render('auth/profile.html.twig', ['user' => $user]);
    }

    private function isPasswordStrongEnough(string $password): bool
    {
        return strlen($password) >= 8
            && preg_match('/[A-Za-z]/', $password) === 1
            && preg_match('/[0-9]/', $password) === 1;
    }

    private function sendResetEmail(User $user, string $token): void
    {
        $resetUrl = $this->generateUrl('auth_reset', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL);

        $email = (new Email())
            ->from($this->senderEmail)
            ->to($user->getEmail())
            ->subject("[{$this->appName}] Redefinição de senha")
            ->html($this->buildResetEmailHtml($user, $resetUrl));

        try {
            $this->mailer->send($email);
        } catch (\Throwable $e) {
            $this->logger->error('Erro ao enviar e-mail de redefinição de senha', [
                'user'  => $user->getEmail(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function buildResetEmailHtml(User $user, string $resetUrl): string
    {
        $name    = htmlspecialchars($user->getName() ?? '', ENT_QUOTES, 'UTF-8');
        $appName = htmlspecialchars($this->appName, ENT_QUOTES, 'UTF-8');

        return <<<HTML
        <div style="font-family: system-ui, -apple-system, Segoe UI, sans-serif; max-width: 480px; margin: 0 auto; background: #f7fbfc; padding: 32px 24px;">
            <div style="background: #ffffff; border-radius: 20px; padding: 32px; box-shadow: 0 10px 30px rgba(16,32,51,0.06);">
                <p style="color:#159bb2; font-weight:800; letter-spacing:-0.02em; font-size:18px; margin:0 0 20px;">{$appName}</p>
                <h2 style="color:#102033; margin:0 0 12px; font-size:20px;">Redefinição de senha</h2>
                <p style="color:#607084; line-height:1.6; font-size:14px;">Olá, {$name}! Recebemos uma solicitação para redefinir a senha da sua conta. Se foi você, clique no botão abaixo. Este link expira em 60 minutos.</p>
                <p style="text-align:center; margin: 28px 0;">
                    <a href="{$resetUrl}" style="display:inline-block; background:#159bb2; color:#fff; text-decoration:none; font-weight:700; padding:12px 28px; border-radius:12px; font-size:14px;">Redefinir minha senha</a>
                </p>
                <p style="color:#8a9aab; font-size:12px; line-height:1.6;">Se você não solicitou essa alteração, ignore este e-mail — sua senha permanece a mesma. Por segurança, nunca compartilhe este link.</p>
            </div>
        </div>
        HTML;
    }
}
