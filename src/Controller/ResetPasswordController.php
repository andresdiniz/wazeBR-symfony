<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Form\ChangePasswordFormType;
use App\Form\ResetPasswordRequestFormType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use SymfonyCasts\Bundle\ResetPassword\Exception\ResetPasswordExceptionInterface;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

#[Route('/reset-password')]
class ResetPasswordController extends AbstractController
{
    public function __construct(
        private readonly ResetPasswordHelperInterface $resetPasswordHelper,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('', name: 'app_forgot_password_request', methods: ['GET', 'POST'])]
    public function request(
        Request $request,
        MailerInterface $mailer,
    ): Response {
        $form = $this->createForm(ResetPasswordRequestFormType::class);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->render('reset_password/request.html.twig', [
                'requestForm' => $form->createView(),
            ]);
        }

        $email = mb_strtolower(trim((string) $form->get('email')->getData()));

        return $this->processForm($email, $mailer);
    }

    private function processForm(
        string $email,
        MailerInterface $mailer,
    ): Response {
        $this->logger->info('Iniciando recuperação de senha.', [
            'email' => $email,
        ]);

        $user = $this->entityManager
            ->getRepository(User::class)
            ->findOneBy(['email' => $email]);

        if (!$user instanceof User) {
            $this->logger->warning('Usuário não encontrado para recuperação.', [
                'email' => $email,
            ]);

            return $this->redirectToRoute('app_check_email');
        }

        try {
            $resetToken = $this->resetPasswordHelper->generateResetToken($user);

            $this->logger->info('Token de recuperação gerado.', [
                'user_id' => $user->getId(),
            ]);

            $emailMessage = (new TemplatedEmail())
                ->from(new Address('no-reply@trafik.com.br', 'Trafik'))
                ->to((string) $user->getEmail())
                ->subject('Redefinir senha - Trafik')
                ->htmlTemplate('reset_password/email.html.twig')
                ->context([
                    'resetToken' => $resetToken,
                ]);

            $mailer->send($emailMessage);

            $this->logger->info('E-mail de recuperação enviado.', [
                'user_id' => $user->getId(),
            ]);
        } catch (ResetPasswordExceptionInterface $exception) {
            $this->logger->error('Erro ao gerar token de recuperação.', [
                'user_id' => $user->getId(),
                'exception' => $exception,
            ]);
        } catch (TransportExceptionInterface $exception) {
            $this->logger->error('Erro no transporte de e-mail de recuperação.', [
                'user_id' => $user->getId(),
                'exception' => $exception,
            ]);
        } catch (\Throwable $exception) {
            $this->logger->error('Erro inesperado na recuperação de senha.', [
                'user_id' => $user->getId(),
                'message' => $exception->getMessage(),
                'class' => $exception::class,
                'trace' => $exception->getTraceAsString(),
            ]);
        }

        return $this->redirectToRoute('app_check_email');
    }

    #[Route('/check-email', name: 'app_check_email', methods: ['GET'])]
    public function checkEmail(): Response
    {
        return $this->render('reset_password/check_email.html.twig');
    }

    #[Route('/reset/{token}', name: 'app_reset_password', methods: ['GET', 'POST'])]
    public function reset(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        string $token,
    ): Response {
        try {
            $user = $this->resetPasswordHelper
                ->validateTokenAndFetchUser($token);
        } catch (ResetPasswordExceptionInterface $exception) {
            $this->addFlash(
                'reset_password_error',
                sprintf(
                    'Houve um problema ao redefinir sua senha: %s',
                    $exception->getReason()
                )
            );

            return $this->redirectToRoute('app_forgot_password_request');
        }

        $form = $this->createForm(ChangePasswordFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $plainPassword = (string) $form
                ->get('plainPassword')
                ->getData();

            $user->setPassword(
                $passwordHasher->hashPassword($user, $plainPassword)
            );

            $this->entityManager->flush();
            $this->resetPasswordHelper->removeResetRequest($user);

            $this->addFlash(
                'success',
                'Sua senha foi alterada com sucesso.'
            );

            return $this->redirectToRoute('app_login');
        }

        return $this->render('reset_password/reset.html.twig', [
            'resetForm' => $form->createView(),
        ]);
    }
}
