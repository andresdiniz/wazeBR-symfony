<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Form\ChangePasswordFormType;
use App\Form\ResetPasswordRequestFormType;
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

#[Route('/reset-password')]
class ResetPasswordController extends AbstractController
{
    use ResetPasswordControllerTrait;

    public function __construct(
        private ResetPasswordHelperInterface $resetPasswordHelper,
        private EntityManagerInterface $entityManager
    ) {}

    #[Route('', name: 'app_forgot_password_request')]
    public function request(Request $request, MailerInterface $mailer): Response
    {
        $form = $this->createForm(ResetPasswordRequestFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            return $this->processForm($form->get('email')->getData(), $mailer);
        }

        return $this->render('reset_password/request.html.twig', [
            'requestForm' => $form,
        ]);
    }

    private function processForm(string $email, MailerInterface $mailer): Response
    {
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);

        if ($user) {
            try {
                $resetToken = $this->resetPasswordHelper->generateResetToken($user);
            } catch (ResetPasswordExceptionInterface $e) {
                return $this->redirectToRoute('app_forgot_password_request');
            }

            // Salva o token na sessão para que checkEmail() possa usá-lo
            $this->setTokenObjectInSession($resetToken);

            $emailMessage = (new TemplatedEmail())
                ->from(new Address('no-reply@trafik.com.br', 'Trafik'))
                ->to((string) $user->getEmail())
                ->subject('Redefinir senha')
                ->htmlTemplate('reset_password/email.html.twig')
                ->context(['resetToken' => $resetToken]);

            $mailer->send($emailMessage);
        }

        return $this->redirectToRoute('app_check_email');
    }

    #[Route('/check-email', name: 'app_check_email')]
    public function checkEmail(): Response
    {
        // Se não houver token na sessão, ainda assim mostra a página de confirmação
        // (para não revelar se o e-mail existe ou não)
        $resetToken = $this->getTokenObjectFromSession();

        return $this->render('reset_password/check_email.html.twig', [
            'resetToken' => $resetToken,
        ]);
    }

    #[Route('/reset/{token}', name: 'app_reset_password')]
    public function reset(Request $request, UserPasswordHasherInterface $passwordHasher, string $token): Response
    {
        try {
            $user = $this->resetPasswordHelper->validateTokenAndFetchUser($token);
        } catch (ResetPasswordExceptionInterface $e) {
            $this->addFlash('reset_password_error', sprintf(
                'Houve um problema ao redefinir sua senha: %s',
                $e->getReason()
            ));
            return $this->redirectToRoute('app_forgot_password_request');
        }

        $session = $this->container->get('request_stack')->getSession();
        $session->set(ResetPasswordHelperInterface::TOKEN_FOR_RESET, $token);

        $form = $this->createForm(ChangePasswordFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $plainPassword = $form->get('plainPassword')->getData();
            $user->setPassword($passwordHasher->hashPassword($user, $plainPassword));

            $this->entityManager->flush();
            $this->cleanSessionAfterReset();

            return $this->redirectToRoute('app_login');
        }

        return $this->render('reset_password/reset.html.twig', [
            'resetForm' => $form,
        ]);
    }
}
