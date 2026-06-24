<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[Route('/senha', name: 'auth_password_')]
class ResetPasswordController extends AbstractController
{
    public function __construct(
        private readonly UserRepository              $userRepo,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly MailerInterface             $mailer,
    ) {}

    /** Passo 1: formulário de solicitação de redefinição */
    #[Route('/redefinir', name: 'request', methods: ['GET', 'POST'])]
    public function request(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $email = (string) $request->request->get('email');
            $user  = $this->userRepo->findOneBy(['email' => $email]);

            if ($user) {
                $token     = bin2hex(random_bytes(32));
                $expiresAt = new \DateTimeImmutable('+1 hour');

                $user->setResetToken($token)->setResetTokenExpiresAt($expiresAt);
                $this->userRepo->save($user);

                $link = $this->generateUrl(
                    'auth_password_reset',
                    ['token' => $token],
                    UrlGeneratorInterface::ABSOLUTE_URL,
                );

                $this->mailer->send(
                    (new Email())
                        ->from('noreply@wazebr.local')
                        ->to($user->getEmail())
                        ->subject('Redefinição de senha — WazeBR')
                        ->html("<p>Clique no link para redefinir sua senha (válido por 1h):</p><p><a href='{$link}'>{$link}</a></p>")
                );
            }

            // Sempre exibe a mesma mensagem para não vazar emails
            $this->addFlash('info', 'Se o email existir, enviaremos o link de redefinição.');
            return $this->redirectToRoute('auth_password_request');
        }

        return $this->render('auth/reset_request.html.twig');
    }

    /** Passo 2: formulário de nova senha via token */
    #[Route('/nova/{token}', name: 'reset', methods: ['GET', 'POST'])]
    public function reset(string $token, Request $request): Response
    {
        $user = $this->userRepo->findByValidResetToken($token);

        if (!$user) {
            $this->addFlash('error', 'Link inválido ou expirado.');
            return $this->redirectToRoute('auth_password_request');
        }

        if ($request->isMethod('POST')) {
            $newPassword = (string) $request->request->get('password');

            if (strlen($newPassword) < 8) {
                $this->addFlash('error', 'A senha deve ter pelo menos 8 caracteres.');
                return $this->render('auth/reset_form.html.twig', ['token' => $token]);
            }

            $user->setPassword($this->hasher->hashPassword($user, $newPassword))
                 ->setResetToken(null)
                 ->setResetTokenExpiresAt(null);

            $this->userRepo->save($user);

            $this->addFlash('success', 'Senha redefinida com sucesso. Faça login.');
            return $this->redirectToRoute('auth_login');
        }

        return $this->render('auth/reset_form.html.twig', ['token' => $token]);
    }
}
