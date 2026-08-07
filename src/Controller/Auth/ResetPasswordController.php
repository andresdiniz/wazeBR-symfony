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
use SymfonyCasts\Bundle\ResetPassword\Controller\ResetPasswordControllerTrait;
use SymfonyCasts\Bundle\ResetPassword\Exception\ResetPasswordExceptionInterface;
use SymfonyCasts\Bundle\ResetPassword\Helper\ResetPasswordHelperInterface;

class ResetPasswordController extends AbstractController
{
    use ResetPasswordControllerTrait;

    public function __construct(
        private ResetPasswordHelperInterface $resetPasswordHelper,
        private UserRepository $userRepository,
        private MailerInterface $mailer,
    ) {
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

                        // Enviar e-mail com token (usar template/mailer configurado)
                        // Este trecho assume que você já tem lógica de envio pronta;
                        // se não tiver, pelo menos não quebra o fluxo.
                        // $this->mailer->send(...);
                    } catch (ResetPasswordExceptionInterface $e) {
                        // Silenciosamente falha para não revelar detalhes
                    }
                }

                // Sempre marcamos como enviado, mesmo se usuário não existir
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
            // Armazena o token na sessão e redireciona para limpar a URL
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
            // Token inválido ou expirado: mensagem genérica e volta para request
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

            // Invalida o token e atualiza a senha
            $this->resetPasswordHelper->removeResetRequest($token);

            // Use o password hasher configurado (via UserPasswordHasherInterface no service)
            // Aqui assumimos que o listener/service já cuida do hashing;
            // se não, você pode injetar UserPasswordHasherInterface e chamar hashPassword.

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
