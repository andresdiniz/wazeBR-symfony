<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\PasswordResetToken;
use App\Form\ForgotPasswordFormType;
use App\Form\ResetPasswordFormType;
use App\Repository\PasswordResetTokenRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Crypto\Hasher\PasswordHasherFactoryInterface;

final class PasswordResetController extends AbstractController
{
    private const TOKEN_TTL_MINUTES = 60;

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly PasswordResetTokenRepository $tokenRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly MailerInterface $mailer,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly PasswordHasherFactoryInterface $passwordHasherFactory,
    ) {
    }

    #[Route('/forgot-password', name: 'app_forgot_password_request', methods: ['GET', 'POST'])]
    public function request(Request $request): Response
    {
        $form = $this->createForm(ForgotPasswordFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $email = mb_strtolower(trim((string) $form->get('email')->getData()));
            $user = $this->userRepository->findOneBy(['email' => $email]);

            if ($user !== null) {
                $plainToken = bin2hex(random_bytes(32));
                $selector = bin2hex(random_bytes(8));

                $hashedToken = $this->passwordHasherFactory
                    ->getPasswordHasher(PasswordResetToken::class)
                    ->hash($plainToken);

                $expiresAt = new \DateTimeImmutable(
                    sprintf('+%d minutes', self::TOKEN_TTL_MINUTES),
                );

                $this->tokenRepository->invalidateTokensForUser($user);

                $resetToken = new PasswordResetToken(
                    $user,
                    $selector,
                    $hashedToken,
                    $expiresAt,
                );

                $this->tokenRepository->save($resetToken);

                $resetUrl = $this->generateUrl(
                    'app_reset_password',
                    ['token' => $selector . '.' . $plainToken],
                    0,
                );

                $emailMessage = (new Email())
                    ->from('no-reply@acheireviews.com.br')
                    ->to($user->getEmail() ?? '')
                    ->subject('Redefinição de senha')
                    ->html(
                        $this->renderView('email/password_reset.html.twig', [
                            'user' => $user,
                            'resetUrl' => $resetUrl,
                            'expiresAt' => $expiresAt,
                        ]),
                    );

                try {
                    $this->mailer->send($emailMessage);
                } catch (\Throwable) {
                    // Não revela se o envio falhou para evitar enumeração de contas.
                }
            }

            $this->addFlash('success', 'Se o e-mail estiver cadastrado, enviaremos as instruções de redefinição.');

            return $this->redirectToRoute('app_forgot_password_request');
        }

        return $this->render('password_reset/request.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/reset-password/{token}', name: 'app_reset_password', methods: ['GET', 'POST'])]
    public function reset(string $token, Request $request): Response
    {
        $parts = explode('.', $token, 2);

        if (count($parts) !== 2) {
            $this->addFlash('error', 'Link de redefinição inválido ou expirado.');

            return $this->redirectToRoute('app_forgot_password_request');
        }

        [$selector, $plainToken] = $parts;

        $resetToken = $this->tokenRepository->findOneBySelector($selector);
        $now = new \DateTimeImmutable();

        if (
            $resetToken === null
            || $resetToken->getUsedAt() !== null
            || $resetToken->isExpired($now)
        ) {
            $this->addFlash('error', 'Link de redefinição inválido ou expirado.');

            return $this->redirectToRoute('app_forgot_password_request');
        }

        $isValid = $this->passwordHasherFactory
            ->getPasswordHasher(PasswordResetToken::class)
            ->verify($plainToken, $resetToken->getHashedToken());

        if (!$isValid) {
            $this->addFlash('error', 'Link de redefinição inválido ou expirado.');

            return $this->redirectToRoute('app_forgot_password_request');
        }

        $form = $this->createForm(ResetPasswordFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user = $resetToken->getUser();

            if ($user === null) {
                $this->addFlash('error', 'Link de redefinição inválido ou expirado.');

                return $this->redirectToRoute('app_forgot_password_request');
            }

            $plainPassword = (string) $form->get('plainPassword')->getData();

            $user->setPassword(
                $this->passwordHasher->hashPassword($user, $plainPassword),
            );

            $resetToken->markAsUsed();

            $this->entityManager->persist($user);
            $this->entityManager->persist($resetToken);
            $this->entityManager->flush();

            $this->addFlash('success', 'Senha redefinida com sucesso. Faça login com a nova senha.');

            return $this->redirectToRoute('app_login');
        }

        return $this->render('password_reset/reset.html.twig', [
            'form' => $form,
        ]);
    }
}
