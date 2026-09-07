<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Form\RegistrationFormType;
use App\Service\UserRegistrationService;
use App\Service\EmailService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(path: '/')]
class AuthController extends AbstractController
{
    public function __construct(
        private readonly AuthenticationUtils $authenticationUtils,
        private readonly UserRegistrationService $registrationService,
        private readonly EmailService $emailService,
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
            
            // TODO: Find user by email in database
            // $user = $this->getDoctrine()->getRepository(User::class)->findOneBy(['email' => $email]);
            
            // if (!$user) {
            //     $this->addFlash('info', 'Se o email estiver cadastrado, enviamos as instru\u00e7\u00f5es.');
            //     return $this->redirectToRoute('app_reset_password_check_email');
            // }
            
            // Generate reset token (64 characters)
            $token = bin2hex(random_bytes(32));
            
            // TODO: Save token to database with expiration (1 hour)
            // $resetToken = new PasswordResetToken();
            // $resetToken->setUser($user);
            // $resetToken->setToken($token);
            // $resetToken->setExpiresAt(new \DateTime('+1 hour'));
            // $this->getDoctrine()->getManager()->persist($resetToken);
            // $this->getDoctrine()->getManager()->flush();
            
            // Send email
            $emailSent = $this->emailService->sendPasswordResetEmail($email, $token);
            
            if ($emailSent) {
                $this->addFlash('success', 'Email enviado com sucesso!');
            } else {
                $this->addFlash('error', 'Erro ao enviar email. Tente novamente.');
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

        // TODO: Validate token
        // 1. Find token in database
        // 2. Check if token is expired
        // 3. Get user from token
        
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
            
            // TODO: Update user password
            // 1. Hash new password
            // 2. Save to database
            // 3. Delete used token
            
            $this->addFlash('success', 'Senha alterada com sucesso! Fa\u00e7a login.');
            return $this->redirectToRoute('app_login');
        }

        return $this->render('auth/reset_password_reset.html.twig', [
            'token' => $token,
        ]);
    }
}
