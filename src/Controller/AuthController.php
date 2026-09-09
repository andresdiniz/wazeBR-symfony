<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Form\RegistrationFormType;
use App\Repository\UserRepository;
use App\Service\UserRegistrationService;
use App\Service\EmailService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use SymfonyCasts\Bundle\ResetPassword\Exception\ResetPasswordExceptionInterface;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

#[Route(path: '/')]
class AuthController extends AbstractController
{
    public function __construct(
        private readonly AuthenticationUtils $authenticationUtils,
        private readonly UserRegistrationService $registrationService,
        private readonly EmailService $emailService,
        private readonly UserRepository $userRepository,
        private readonly ResetPasswordHelperInterface $resetPasswordHelper,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * P\u00e1gina inicial p\u00fablica (/)
     * - Se logado: vai para dashboard
     * - Se N\u00c3O logado: mostra landing page
     */
    #[Route(path: '/', name: 'app_home')]
    public function home(): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        return $this->render('home/landing.html.twig');
    }

    #[Route(path: '/login', name: 'app_login')]
    public function login(Request $request): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        $error = $this->authenticationUtils->getLastAuthenticationError();
        $lastUsername = $this->authenticationUtils->getLastUsername();

        return $this->render('auth/login.html.twig', [
            'last_username' => $lastUsername,
            'error' => $error,
        ]);
    }

    #[Route(path: '/logout', name: 'app_logout')]
    public function logout(): void
    {
        throw new \LogicException(
            'This method can be blank - it will be intercepted by the logout key on your firewall.'
        );
    }

    #[Route(path: '/register', name: 'app_register')]
    public function register(Request $request): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        $form = $this->createForm(RegistrationFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var User $user */
            $user = $form->getData();

            try {
                $this->registrationService->register($user);

                $this->addFlash('success', 'Cadastro realizado com sucesso. Fa\u00e7a login para continuar.');

                return $this->redirectToRoute('app_login');
            } catch (\InvalidArgumentException $e) {
                $this->addFlash('error', $e->getMessage());
            } catch (\Throwable $e) {
                $this->addFlash('error', 'Ocorreu um erro ao realizar o cadastro. Tente novamente.');
            }

            return $this->render('auth/register.html.twig', [
                'registrationForm' => $form,
            ]);
        }

        return $this->render('auth/register.html.twig', [
            'registrationForm' => $form,
        ]);
    }

    /**
     * Dashboard restrito (/dashboard)
     */
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[Route(path: '/dashboard', name: 'app_dashboard')]
    public function dashboard(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->render('auth/dashboard.html.twig', [
            'user' => $user,
        ]);
    }

    /**
     * Password Reset - Request (/reset-password)
     * Formul\u00e1rio para solicitar reset de senha
     */
    #[Route(path: '/reset-password', name: 'app_reset_password_request', methods: ['GET', 'POST'])]
    public function resetPasswordRequest(Request $request): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        if ($request->isMethod('POST')) {
            $email = $request->request->get('email', '');
            $user = $this->userRepository->findOneBy(['email' => $email]);

            // S\u00f3 tenta gerar/enviar o token se o usu\u00e1rio existir e n\u00e3o estiver
            // sob throttle do bundle. A resposta ao usu\u00e1rio \u00e9 sempre a mesma
            // (redirect para check-email) independentemente do resultado, para
            // n\u00e3o revelar quais e-mails est\u00e3o cadastrados no sistema.
            if ($user) {
                try {
                    $resetToken = $this->resetPasswordHelper->generateResetToken($user);
                    $this->emailService->sendPasswordResetEmail($email, $resetToken->getToken());
                } catch (ResetPasswordExceptionInterface $e) {
                    // Token recente demais (throttle) ou outro erro esperado do
                    // bundle: ignoramos silenciosamente por seguran\u00e7a.
                }
            }

            return $this->redirectToRoute('app_reset_password_check_email');
        }

        return $this->render('auth/reset_password_request.html.twig');
    }

    /**
     * Password Reset - Check Email (/reset-password/check-email)
     * P\u00e1gina de confirma\u00e7\u00e3o ap\u00f3s envio do email
     */
    #[Route(path: '/reset-password/check-email', name: 'app_reset_password_check_email')]
    public function resetPasswordCheckEmail(): Response
    {
        return $this->render('auth/reset_password_check_email.html.twig');
    }

    /**
     * Password Reset - Reset Password (/reset-password/{token})
     * P\u00e1gina para criar nova senha
     */
    #[Route(path: '/reset-password/{token}', name: 'app_reset_password_reset', methods: ['GET', 'POST'])]
    public function resetPassword(Request $request, string $token): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        // Valida o token (assinatura + expira\u00e7\u00e3o de 1h, configurada em
        // reset_password.yaml) sem consumi-lo. Isso \u00e9 seguro tanto no GET
        // (renderizar o form s\u00f3 se o link ainda for v\u00e1lido) quanto de novo
        // no POST antes de efetivamente trocar a senha.
        try {
            $user = $this->resetPasswordHelper->validateTokenAndFetchUser($token);
        } catch (ResetPasswordExceptionInterface $e) {
            $this->addFlash('error', 'Este link de redefini\u00e7\u00e3o de senha \u00e9 inv\u00e1lido ou expirou. Solicite um novo.');
            return $this->redirectToRoute('app_reset_password_request');
        }

        if ($request->isMethod('POST')) {
            $newPassword = $request->request->get('password', '');
            $confirmPassword = $request->request->get('confirm_password', '');

            // Validate passwords match
            if ($newPassword !== $confirmPassword) {
                $this->addFlash('error', 'As senhas n\u00e3o coincidem.');
                return $this->redirectToRoute('app_reset_password_reset', ['token' => $token]);
            }

            // Validate password length
            if (strlen($newPassword) < 6) {
                $this->addFlash('error', 'A senha deve ter pelo menos 6 caracteres.');
                return $this->redirectToRoute('app_reset_password_reset', ['token' => $token]);
            }

            $user->setPassword($this->passwordHasher->hashPassword($user, $newPassword));
            // Invalida o token (e qualquer outro pendente do mesmo usu\u00e1rio)
            // para que n\u00e3o possa ser reutilizado.
            $this->resetPasswordHelper->removeResetRequest($user);
            $this->entityManager->flush();

            $this->addFlash('success', 'Senha alterada com sucesso! Fa\u00e7a login.');
            return $this->redirectToRoute('app_login');
        }

        return $this->render('auth/reset_password_reset.html.twig', [
            'token' => $token,
        ]);
    }

    /**
     * Redireciona usu\u00e1rio baseado no role ap\u00f3s login
     * Chamado automaticamente pelo security.yaml (target_path: app_post_login_redirect)
     */
    #[Route(path: '/post-login-redirect', name: 'app_post_login_redirect')]
    public function postLoginRedirect(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $roles = $user->getRoles();

        // Prioridade de roles (mais espec\u00edfico primeiro)
        if (in_array('ROLE_ACCOUNT_ADMIN', $roles)) {
            return $this->redirectToRoute('app_account_admin_dashboard');
        }

        if (in_array('ROLE_ADMIN_PARTNER', $roles)) {
            return $this->redirectToRoute('app_partner_dashboard');
        }

        if (in_array('ROLE_FIELD_AGENT', $roles)) {
            return $this->redirectToRoute('app_field_agent_dashboard');
        }

        if (in_array('ROLE_USER', $roles)) {
            return $this->redirectToRoute('app_user_dashboard');
        }

        // Fallback para dashboard padr\u00e3o
        return $this->redirectToRoute('app_dashboard');
    }

    /**
     * Dashboard para Account Admin
     */
    #[IsGranted('ROLE_ACCOUNT_ADMIN')]
    #[Route(path: '/account-admin/dashboard', name: 'app_account_admin_dashboard')]
    public function accountAdminDashboard(): Response
    {
        return $this->render('admin/account_admin/dashboard.html.twig');
    }

    /**
     * Dashboard para Admin Partner
     */
    #[IsGranted('ROLE_ADMIN_PARTNER')]
    #[Route(path: '/partner/dashboard', name: 'app_partner_dashboard')]
    public function partnerDashboard(): Response
    {
        return $this->render('admin/partner_dashboard.html.twig');
    }

    /**
     * Dashboard para Field Agent
     */
    #[IsGranted('ROLE_FIELD_AGENT')]
    #[Route(path: '/field-agent/dashboard', name: 'app_field_agent_dashboard')]
    public function fieldAgentDashboard(): Response
    {
        return $this->render('admin/field_agent_dashboard.html.twig');
    }

    /**
     * Dashboard para User comum
     */
    #[IsGranted('ROLE_USER')]
    #[Route(path: '/user/dashboard', name: 'app_user_dashboard')]
    public function userDashboard(): Response
    {
        return $this->render('admin/user_dashboard.html.twig');
    }
}
