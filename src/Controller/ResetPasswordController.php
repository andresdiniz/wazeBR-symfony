<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use SymfonyCasts\Bundle\ResetPassword\Controller\ResetPasswordControllerTrait;
use SymfonyCasts\Bundle\ResetPassword\Exception\ResetPasswordExceptionInterface;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

#[Route('/recuperar-senha')]
class ResetPasswordController extends AbstractController
{
    use ResetPasswordControllerTrait;

    public function __construct(
        private readonly ResetPasswordHelperInterface $resetPasswordHelper,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * Exibe e processa o formulário de solicitação de reset.
     */
    #[Route('', name: 'app_reset_password_request')]
    public function request(
        Request $request,
        MailerInterface $mailer,
        UserRepository $userRepository,
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('dashboard');
        }

        if ($request->isMethod('POST')) {
            return $this->processSendingPasswordResetEmail(
                (string) $request->request->get('email', ''),
                $mailer,
                $userRepository,
            );
        }

        return $this->render('reset_password/request.html.twig');
    }

    /**
     * Página de confirmação após o email ser enviado.
     */
    #[Route('/verifique-seu-email', name: 'app_check_email')]
    public function checkEmail(): Response
    {
        // Gera um token falso caso o usuário acesse esta página diretamente
        // (sem ter passado pelo formulário), para evitar enumeração de usuários.
        if (null === ($resetToken = $this->getTokenObjectFromSession())) {
            $resetToken = $this->resetPasswordHelper->generateFakeResetToken();
        }

        return $this->render('reset_password/check_email.html.twig', [
            'resetToken' => $resetToken,
        ]);
    }

    /**
     * Valida e processa o link de reset enviado por email.
     */
    #[Route('/redefinir/{token}', name: 'app_reset_password')]
    public function reset(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        string $token = null,
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('dashboard');
        }

        if ($token) {
            // Armazena o token na sessão e remove da URL
            // para proteger o token contra ataques de referrer
            $this->storeTokenInSession($token);
            return $this->redirectToRoute('app_reset_password');
        }

        $token = $this->getTokenFromSession();

        if (null === $token) {
            throw $this->createNotFoundException(
                'Nenhum token de redefinição de senha encontrado na URL ou na sessão.'
            );
        }

        try {
            /** @var User $user */
            $user = $this->resetPasswordHelper->validateTokenAndFetchUser($token);
        } catch (ResetPasswordExceptionInterface $e) {
            $this->addFlash('error', sprintf(
                'Houve um problema ao validar seu token de redefinição: %s',
                $e->getReason()
            ));

            return $this->redirectToRoute('app_reset_password_request');
        }

        if ($request->isMethod('POST')) {
            // Remove o token após uso — válido apenas uma vez
            $this->resetPasswordHelper->removeResetRequest($token);

            $plainPassword = (string) $request->request->get('password', '');

            $user->setPassword(
                $passwordHasher->hashPassword($user, $plainPassword)
            );

            $this->entityManager->flush();

            // Limpa a sessão
            $this->cleanSessionAfterReset();

            $this->addFlash('success', 'Sua senha foi redefinida com sucesso. Faça login para continuar.');

            return $this->redirectToRoute('auth_login');
        }

        return $this->render('reset_password/reset.html.twig');
    }

    /**
     * Envia o email de recuperação.
     * Não revela se o email existe ou não (prevenção de enumeração).
     */
    private function processSendingPasswordResetEmail(
        string $emailFormData,
        MailerInterface $mailer,
        UserRepository $userRepository,
    ): RedirectResponse {
        $user = $userRepository->findOneBy(['email' => $emailFormData]);

        // Redireciona para check-email independente de o usuário existir
        if (!$user) {
            return $this->redirectToRoute('app_check_email');
        }

        try {
            $resetToken = $this->resetPasswordHelper->generateResetToken($user);
        } catch (ResetPasswordExceptionInterface $e) {
            // Throttle: já foi enviado recentemente — redireciona sem revelar
            return $this->redirectToRoute('app_check_email');
        }

        $email = (new TemplatedEmail())
            ->from(new Address('noreply@wazebr.com.br', 'WazeBR'))
            ->to((string) $user->getEmail())
            ->subject('Redefinição de senha — WazeBR')
            ->htmlTemplate('reset_password/email.html.twig')
            ->context([
                'resetToken' => $resetToken,
                'tokenLifetime' => $this->resetPasswordHelper->getTokenLifetime(),
            ]);

        $mailer->send($email);

        // Salva o token na sessão para exibir na página check_email
        $this->setTokenObjectInSession($resetToken);

        return $this->redirectToRoute('app_check_email');
    }
}
