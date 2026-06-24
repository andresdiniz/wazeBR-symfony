<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class AuthController extends AbstractController
{
    #[Route('/login', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        return $this->render('auth/login.html.twig', [
            'error'         => $authenticationUtils->getLastAuthenticationError(),
            'last_username' => $authenticationUtils->getLastUsername(),
        ]);
    }

    #[Route('/logout', name: 'app_logout')]
    public function logout(): never
    {
        throw new \LogicException('Este método é interceptado pelo firewall do Symfony.');
    }

    #[Route('/redefinir-senha', name: 'app_reset_request', methods: ['GET', 'POST'])]
    public function resetRequest(
        Request                $request,
        UserRepository         $userRepository,
        MailerInterface        $mailer,
        EntityManagerInterface $em,
        string                 $appName,
        string                 $senderEmail,
    ): Response {
        if ($request->isMethod('POST')) {
            $email = $request->request->getString('email');
            $user  = $userRepository->findOneBy(['email' => $email]);

            if ($user) {
                $token = bin2hex(random_bytes(32));
                // Armazenar token numa entidade PasswordResetToken é o ideal;
                // aqui simplificamos usando uma session por compatibilidade inicial.
                $request->getSession()->set('reset_token_' . $token, $user->getId());
                $request->getSession()->set('reset_token_' . $token . '_exp', time() + 3600);

                $link = $this->generateUrl('app_reset_password', ['token' => $token], \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL);

                $mail = (new Email())
                    ->from($senderEmail)
                    ->to($user->getEmail())
                    ->subject("[{$appName}] Redefinição de senha")
                    ->html("<p>Clique no link para redefinir sua senha (válido por 1 hora):</p><p><a href='{$link}'>{$link}</a></p>");

                $mailer->send($mail);
            }

            $this->addFlash('success', 'Se o e-mail estiver cadastrado, você receberá as instruções em breve.');
            return $this->redirectToRoute('app_reset_request');
        }

        return $this->render('auth/reset_request.html.twig');
    }

    #[Route('/redefinir-senha/{token}', name: 'app_reset_password', methods: ['GET', 'POST'])]
    public function resetPassword(
        string                      $token,
        Request                     $request,
        UserRepository              $userRepository,
        UserPasswordHasherInterface $hasher,
        EntityManagerInterface      $em,
    ): Response {
        $session = $request->getSession();
        $userId  = $session->get('reset_token_' . $token);
        $exp     = $session->get('reset_token_' . $token . '_exp', 0);

        if (!$userId || time() > $exp) {
            $this->addFlash('error', 'Link inválido ou expirado.');
            return $this->redirectToRoute('app_reset_request');
        }

        $user = $userRepository->find($userId);
        if (!$user) {
            return $this->redirectToRoute('app_reset_request');
        }

        if ($request->isMethod('POST')) {
            $password = $request->request->getString('password');
            $confirm  = $request->request->getString('confirm');

            if ($password !== $confirm || strlen($password) < 8) {
                $this->addFlash('error', 'As senhas não coincidem ou são muito curtas (mínimo 8 caracteres).');
                return $this->redirectToRoute('app_reset_password', ['token' => $token]);
            }

            $user->setPassword($hasher->hashPassword($user, $password));
            $em->flush();

            $session->remove('reset_token_' . $token);
            $session->remove('reset_token_' . $token . '_exp');

            $this->addFlash('success', 'Senha redefinida com sucesso. Faça login.');
            return $this->redirectToRoute('app_login');
        }

        return $this->render('auth/reset_password.html.twig', ['token' => $token]);
    }
}
