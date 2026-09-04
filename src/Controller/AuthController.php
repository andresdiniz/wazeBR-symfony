<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Form\ResetPasswordFormType;
use App\Form\ResetPasswordRequestFormType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use SymfonyCasts\Bundle\ResetPassword\Controller\ResetPasswordControllerTrait;
use SymfonyCasts\Bundle\ResetPassword\Exception\ResetPasswordExceptionInterface;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

final class AuthController extends AbstractController
{
    use ResetPasswordControllerTrait;

    public function __construct(
        private readonly ResetPasswordHelperInterface $resetPasswordHelper,
        private readonly EntityManagerInterface $entityManager,
        private readonly MailerInterface $mailer,
    ) {}

    #[Route('/login', name: 'app_login', methods: ['GET', 'POST'])]
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

    #[Route('/logout', name: 'app_logout', methods: ['GET'])]
    public function logout(): never
    {
        throw new \LogicException('Esta rota é interceptada pelo firewall de logout.');
    }

    #[Route('/forgot-password', name: 'app_forgot_password', methods: ['GET', 'POST'])]
    public function forgotPassword(Request $request): Response
    {
        $form = $this->createForm(ResetPasswordRequestFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $email = $form->get('email')->getData();
            $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);

            if (!$user) {
                // Não revelar se o e-mail existe ou não por segurança (mas para demonstração mantemos)
                $this->addFlash('reset_password_error', 'E-mail não encontrado.');
                return $this->redirectToRoute('app_forgot_password');
            }

            try {
                $resetToken = $this->resetPasswordHelper->generateResetToken($user);
                $this->sendResetEmail($user->getEmail(), $resetToken);
                $this->addFlash('reset_password_success', 'Instruções enviadas para seu e-mail.');
            } catch (ResetPasswordExceptionInterface $e) {
                $reason = $e->getReason();
                if (str_contains($reason, 'too many') || str_contains($reason, 'already requested')) {
                    $this->addFlash('reset_password_error', 'Você já solicitou a redefinição recentemente. Verifique seu e-mail ou aguarde alguns minutos.');
                } else {
                    $this->addFlash('reset_password_error', $reason);
                }
            } catch (\Exception $e) {
                $this->addFlash('reset_password_error', 'Erro ao processar a solicitação. Tente novamente mais tarde.');
            }

            return $this->redirectToRoute('app_forgot_password');
        }

        return $this->render('auth/forgot.html.twig', [
            'requestForm' => $form->createView(),
        ]);
    }

    private function sendResetEmail(string $toEmail, \SymfonyCasts\Bundle\ResetPassword\Model\ResetPasswordToken $resetToken): void
    {
        $email = (new Email())
            ->from('no-reply@wazebr.com')
            ->to($toEmail)
            ->subject('Redefinição de senha - wazeBR')
            ->html($this->renderView('email/reset_password.html.twig', [
                'resetToken' => $resetToken,
            ]));

        $this->mailer->send($email);
    }

    #[Route('/reset-password/{token}', name: 'app_reset_password', methods: ['GET', 'POST'])]
    public function resetPassword(Request $request, string $token, UserPasswordHasherInterface $passwordHasher): Response
    {
        try {
            $user = $this->resetPasswordHelper->validateTokenAndFetchUser($token);
        } catch (ResetPasswordExceptionInterface $e) {
            $this->addFlash('reset_password_error', $e->getReason());
            return $this->redirectToRoute('app_forgot_password');
        }

        $form = $this->createForm(ResetPasswordFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $plainPassword = $form->get('plainPassword')->getData();
            $user->setPassword($passwordHasher->hashPassword($user, $plainPassword));
            $this->entityManager->flush();

            $this->resetPasswordHelper->removeResetRequest($token);

            $this->addFlash('reset_password_success', 'Senha redefinida com sucesso!');
            return $this->redirectToRoute('app_login');
        }

        return $this->render('auth/reset.html.twig', [
            'resetForm' => $form->createView(),
            'token' => $token,
        ]);
    }
}
