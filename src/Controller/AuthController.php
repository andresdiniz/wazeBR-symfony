<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class AuthController extends AbstractController
{
    public function __construct(
        private readonly UserRepository              $userRepository,
        private readonly UserPasswordHasherInterface $hasher,
    ) {}

    #[Route('/login', name: 'auth_login')]
    public function login(AuthenticationUtils $authUtils): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('dashboard_index');
        }

        return $this->render('auth/login.html.twig', [
            'last_username' => $authUtils->getLastUsername(),
            'error'         => $authUtils->getLastAuthenticationError(),
        ]);
    }

    #[Route('/logout', name: 'auth_logout')]
    public function logout(): never
    {
        throw new \LogicException('Interceptado pelo firewall do Symfony.');
    }

    #[Route('/esqueci-senha', name: 'auth_forgot', methods: ['GET', 'POST'])]
    public function forgot(Request $request): Response
    {
        $sent = false;

        if ($request->isMethod('POST')) {
            $email = (string) $request->request->get('email', '');
            $user  = $this->userRepository->findOneBy(['email' => $email]);

            if ($user) {
                $this->userRepository->generateResetToken($user);
                // TODO: disparar Symfony Mailer com o token
            }

            // Sempre mostra a mesma mensagem (evita enumeração de e-mails)
            $sent = true;
        }

        return $this->render('auth/forgot.html.twig', ['sent' => $sent]);
    }

    #[Route('/redefinir-senha/{token}', name: 'auth_reset', methods: ['GET', 'POST'])]
    public function reset(string $token, Request $request): Response
    {
        $user = $this->userRepository->findByResetToken($token);

        if (!$user || !$this->userRepository->isResetTokenValid($user)) {
            $this->addFlash('error', 'Link inválido ou expirado.');
            return $this->redirectToRoute('auth_forgot');
        }

        if ($request->isMethod('POST')) {
            $password = (string) $request->request->get('password', '');
            $confirm  = (string) $request->request->get('confirm', '');

            if (strlen($password) < 8) {
                $this->addFlash('error', 'A senha deve ter ao menos 8 caracteres.');
            } elseif ($password !== $confirm) {
                $this->addFlash('error', 'As senhas não coincidem.');
            } else {
                $user->setPassword($this->hasher->hashPassword($user, $password));
                $this->userRepository->clearResetToken($user);
                $this->addFlash('success', 'Senha redefinida com sucesso. Faça login.');
                return $this->redirectToRoute('auth_login');
            }
        }

        return $this->render('auth/reset.html.twig', ['token' => $token]);
    }

    #[Route('/perfil', name: 'auth_profile')]
    #[IsGranted('ROLE_USER')]
    public function profile(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if ($request->isMethod('POST')) {
            $name     = (string) $request->request->get('name', '');
            $password = (string) $request->request->get('password', '');

            if ($name) {
                $user->setName($name);
            }

            if ($password) {
                if (strlen($password) < 8) {
                    $this->addFlash('error', 'A senha deve ter ao menos 8 caracteres.');
                    return $this->redirectToRoute('auth_profile');
                }
                $user->setPassword($this->hasher->hashPassword($user, $password));
            }

            $this->userRepository->save($user);
            $this->addFlash('success', 'Perfil atualizado.');
            return $this->redirectToRoute('auth_profile');
        }

        return $this->render('auth/profile.html.twig', ['user' => $user]);
    }
}
