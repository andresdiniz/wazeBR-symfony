<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Form\ChangePasswordFormType;
use App\Form\ResetPasswordRequestFormType;
use App\Repository\UserRepository;
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

#[Route('/reset-password')]
final class ResetPasswordController extends AbstractController
{
    use ResetPasswordControllerTrait;

    public function __construct(
        private readonly ResetPasswordHelperInterface  $resetPasswordHelper,
        private readonly UserPasswordHasherInterface   $passwordHasher,
        private readonly MailerInterface               $mailer,
        private readonly UserRepository                $userRepository,
    ) {}

    // ─────────────────────────────────────────────────────────────────────
    // 1. Formulário "esqueceu a senha" + envio do e-mail
    // ─────────────────────────────────────────────────────────────────────

    #[Route('', name: 'app_reset_password_request', methods: ['GET', 'POST'])]
    public function request(Request $request): Response
    {
        $form = $this->createForm(ResetPasswordRequestFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var string $email */
            $email = $form->get('email')->getData();
            return $this->processSendingPasswordResetEmail($email);
        }

        return $this->render('reset_password/request.html.twig', [
            'requestForm' => $form,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // 2. Tela "verifique seu e-mail"
    // ─────────────────────────────────────────────────────────────────────

    #[Route('/check-email', name: 'app_check_email', methods: ['GET'])]
    public function checkEmail(): Response
    {
        // Gera um token falso para exibir na tela sem vazar informação
        // caso o usuário acesse esta URL diretamente sem ter solicitado reset.
        if (null === ($resetToken = $this->getTokenObjectFromSession())) {
            $resetToken = $this->resetPasswordHelper->generateFakeResetToken();
        }

        return $this->render('reset_password/check_email.html.twig', [
            'resetToken' => $resetToken,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // 3. Link do e-mail → formulário de nova senha → salva
    // ─────────────────────────────────────────────────────────────────────

    #[Route('/reset/{token}', name: 'app_reset_password', methods: ['GET', 'POST'])]
    public function reset(Request $request, ?string $token = null): Response
    {
        if ($token) {
            // Armazena o token na sessão e remove da URL para evitar
            // que fique no histórico do navegador.
            $this->storeTokenInSession($token);
            return $this->redirectToRoute('app_reset_password');
        }

        $token = $this->getTokenFromSession();

        if (null === $token) {
            throw $this->createNotFoundException(
                'Nenhum token de redefinição encontrado na sessão.'
            );
        }

        try {
            /** @var User $user */
            $user = $this->resetPasswordHelper->validateTokenAndFetchUser($token);
        } catch (ResetPasswordExceptionInterface $e) {
            $this->addFlash('reset_password_error', sprintf(
                '%s — %s',
                ResetPasswordExceptionInterface::MESSAGE_PROBLEM_VALIDATE,
                $e->getReason()
            ));
            return $this->redirectToRoute('app_reset_password_request');
        }

        $form = $this->createForm(ChangePasswordFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Remove o token uma única vez após a validação bem-sucedida
            $this->resetPasswordHelper->removeResetRequest($token);

            /** @var string $plainPassword */
            $plainPassword = $form->get('plainPassword')->getData();

            $user->setPassword(
                $this->passwordHasher->hashPassword($user, $plainPassword)
            );

            $em = $this->container->get('doctrine')->getManager();
            $em->flush();

            // Limpa a sessão depois de alterar a senha
            $this->cleanSessionAfterReset();

            $this->addFlash('success', 'Sua senha foi alterada com sucesso. Faça login para continuar.');
            return $this->redirectToRoute('auth_login');
        }

        return $this->render('reset_password/reset.html.twig', [
            'resetForm' => $form,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helper privado
    // ─────────────────────────────────────────────────────────────────────

    private function processSendingPasswordResetEmail(string $emailFormData): RedirectResponse
    {
        $user = $this->userRepository->findOneBy(['email' => $emailFormData]);

        // Redireciona sempre para check-email independentemente de o e-mail
        // existir ou não (evita user-enumeration).
        if (!$user) {
            return $this->redirectToRoute('app_check_email');
        }

        try {
            $resetToken = $this->resetPasswordHelper->generateResetToken($user);
        } catch (ResetPasswordExceptionInterface $e) {
            // Pode ocorrer se já existir um token não-expirado (throttle).
            // Silencia e redireciona igualmente para evitar enumeração.
            return $this->redirectToRoute('app_check_email');
        }

        $senderEmail = $_ENV['SENDER_EMAIL'] ?? 'noreply@wazebr.com.br';
        $appName     = $_ENV['APP_NAME']     ?? 'WazeBR';

        $email = (new TemplatedEmail())
            ->from(new Address($senderEmail, $appName))
            ->to(new Address($user->getEmail(), $user->getName() ?? ''))
            ->subject("[$appName] Redefinição de senha")
            ->htmlTemplate('reset_password/email.html.twig')
            ->context([
                'resetToken'    => $resetToken,
                'signedUrl'     => $this->generateUrl(
                    'app_reset_password',
                    ['token' => $resetToken->getToken()],
                    \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL
                ),
                'tokenLifetime' => $this->resetPasswordHelper->getTokenLifetime(),
            ]);

        $this->mailer->send($email);

        // Salva o token na sessão para exibir na tela check-email
        $this->setTokenObjectInSession($resetToken);

        return $this->redirectToRoute('app_check_email');
    }
}
