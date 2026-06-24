<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[Route('/senha', name: 'password_')]
class ResetPasswordController extends AbstractController
{
    /** Tokens temporários em memória (em produção use tabela ou cache Redis). */
    private static array $tokens = [];

    public function __construct(
        private readonly EntityManagerInterface      $em,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly MailerInterface             $mailer,
    ) {}

    #[Route('/recuperar', name: 'request', methods: ['GET', 'POST'])]
    public function request(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $email = (string) $request->request->get('email');
            $user  = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);

            if ($user) {
                $token   = bin2hex(random_bytes(32));
                $expires = time() + 3600;
                self::$tokens[$token] = ['userId' => $user->getId(), 'expires' => $expires];

                $resetUrl = $this->generateUrl('password_reset', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL);

                $this->mailer->send(
                    (new Email())
                        ->from('noreply@wazebr.local')
                        ->to($user->getEmail())
                        ->subject('Redefinição de senha — WazeBR')
                        ->html("<p>Olá, {$user->getName()}!</p><p><a href='{$resetUrl}'>Clique aqui para redefinir sua senha.</a></p><p>Link válido por 1 hora.</p>")
                );
            }

            // Mensagem genérica para não revelar se o email existe
            $this->addFlash('info', 'Se este email estiver cadastrado, você receberá um link de redefinição.');
            return $this->redirectToRoute('password_request');
        }

        return $this->render('security/reset_request.html.twig');
    }

    #[Route('/redefinir/{token}', name: 'reset', methods: ['GET', 'POST'])]
    public function reset(string $token, Request $request): Response
    {
        $data = self::$tokens[$token] ?? null;

        if (!$data || $data['expires'] < time()) {
            $this->addFlash('error', 'Link inválido ou expirado.');
            return $this->redirectToRoute('password_request');
        }

        if ($request->isMethod('POST')) {
            $password = (string) $request->request->get('password');
            $confirm  = (string) $request->request->get('confirm');

            if (strlen($password) < 8) {
                $this->addFlash('error', 'A senha deve ter pelo menos 8 caracteres.');
                return $this->render('security/reset_form.html.twig', ['token' => $token]);
            }

            if ($password !== $confirm) {
                $this->addFlash('error', 'As senhas não coincidem.');
                return $this->render('security/reset_form.html.twig', ['token' => $token]);
            }

            $user = $this->em->getRepository(User::class)->find($data['userId']);
            if ($user) {
                $user->setPassword($this->hasher->hashPassword($user, $password));
                $this->em->flush();
                unset(self::$tokens[$token]);
                $this->addFlash('success', 'Senha redefinida com sucesso! Faça login.');
            }

            return $this->redirectToRoute('security_login');
        }

        return $this->render('security/reset_form.html.twig', ['token' => $token]);
    }
}
