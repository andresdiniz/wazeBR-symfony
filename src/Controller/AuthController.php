<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Form\RegistrationFormType;
use App\Service\UserRegistrationService;
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
    ) {
    }

    /**
     * Página inicial pública (/)
     * - Se logado: vai para dashboard
     * - Se NÃO logado: mostra landing page
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

                $this->addFlash('success', 'Cadastro realizado com sucesso. Faça login para continuar.');

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
     * Formulário para solicitar reset de senha
     */
    #[Route(path: '/reset-password', name: 'app_reset_password_request')]
    public function resetPasswordRequest(Request $request): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        return $this->render('auth/reset_password_request.html.twig', [
            'requestForm' => null, // Form will be created in template
        ]);
    }

    /**
     * Password Reset - Check Email (/reset-password/check-email)
     * Página de confirmação após envio do email
     */
    #[Route(path: '/reset-password/check-email', name: 'app_reset_password_check_email')]
    public function resetPasswordCheckEmail(): Response
    {
        return $this->render('auth/reset_password_check_email.html.twig');
    }
}
