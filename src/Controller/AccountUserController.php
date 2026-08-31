<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\PhpMailerService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/account/users', name: 'account_user_')]
class AccountUserController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly PhpMailerService $mailer,
        private readonly string $appName,
    ) {}

    // ── LIST ─────────────────────────────────────────────────────────────────

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ACCOUNT_ADMIN');

        /** @var User $me */
        $me      = $this->getUser();
        $partner = $me->getPartner();

        if (!$partner) {
            throw $this->createAccessDeniedException();
        }

        $users = $this->userRepository->findBy(
            ['partner' => $partner],
            ['name' => 'ASC']
        );

        return $this->render('account/users/index.html.twig', [
            'users'   => $users,
            'partner' => $partner,
        ]);
    }

    // ── NEW ──────────────────────────────────────────────────────────────────

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ACCOUNT_ADMIN');

        /** @var User $me */
        $me      = $this->getUser();
        $partner = $me->getPartner();

        if (!$partner) {
            throw $this->createAccessDeniedException();
        }

        $errors = [];

        if ($request->isMethod('POST')) {
            $name     = trim((string) $request->request->get('name', ''));
            $email    = trim((string) $request->request->get('email', ''));
            $password = (string) $request->request->get('password', '');
            $role     = $request->request->get('role', 'ROLE_USER');
            $perms    = $request->request->all('permissions');

            // Validation
            if ($name === '')      { $errors['name']  = 'Nome é obrigatório.'; }
            if ($email === '')     { $errors['email'] = 'E-mail é obrigatório.'; }
            if (strlen($password) < 8) { $errors['password'] = 'Senha deve ter ao menos 8 caracteres.'; }
            if (!in_array($role, ['ROLE_USER', 'ROLE_FIELD_AGENT'], true)) {
                $errors['role'] = 'Perfil inválido.';
            }
            if (empty($errors) && $this->userRepository->findOneBy(['email' => $email])) {
                $errors['email'] = 'Este e-mail já está em uso.';
            }

            if (empty($errors)) {
                $user = new User();
                $user->setName($name)
                     ->setEmail($email)
                     ->setRoles([$role])
                     ->setPartner($partner)
                     ->setIsActive(true)
                     ->setPassword($this->passwordHasher->hashPassword($user, $password));

                if ($role === 'ROLE_FIELD_AGENT' && !empty($perms)) {
                    $user->setFieldAgentPermissions(array_values($perms));
                }

                $this->em->persist($user);
                $this->em->flush();

                $emailSent = $this->mailer->send(
                    toEmail: $user->getEmail(),
                    toName: $user->getName() ?? $user->getEmail(),
                    subject: "[{$this->appName}] Sua conta foi criada",
                    htmlBody: $this->buildWelcomeEmailHtml($user, $password),
                );

                if ($emailSent) {
                    $this->addFlash('success', "Usuário {$name} criado com sucesso. Um e-mail com os dados de acesso foi enviado.");
                } else {
                    $this->addFlash('success', "Usuário {$name} criado com sucesso.");
                    $this->addFlash('warning', 'Não foi possível enviar o e-mail de boas-vindas — confira o e-mail e a senha manualmente com o usuário.');
                }

                return $this->redirectToRoute('account_user_index');
            }
        }

        return $this->render('account/users/new.html.twig', [
            'errors'  => $errors,
            'data'    => $request->request->all(),
            'partner' => $partner,
        ]);
    }

    // ── EDIT ─────────────────────────────────────────────────────────────────

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ACCOUNT_ADMIN');

        /** @var User $me */
        $me      = $this->getUser();
        $partner = $me->getPartner();
        $user    = $this->userRepository->find($id);

        if (!$user || !$user->belongsToPartner($partner)) {
            throw $this->createNotFoundException();
        }

        // Prevent editing another account admin or super admin
        if ($user->isSuperAdmin() || $user->isAccountAdmin()) {
            throw $this->createAccessDeniedException('Você não pode editar este usuário.');
        }

        $errors = [];

        if ($request->isMethod('POST')) {
            $name     = trim((string) $request->request->get('name', ''));
            $email    = trim((string) $request->request->get('email', ''));
            $password = (string) $request->request->get('password', '');
            $role     = $request->request->get('role', 'ROLE_USER');
            $perms    = $request->request->all('permissions');

            if ($name === '')  { $errors['name']  = 'Nome é obrigatório.'; }
            if ($email === '') { $errors['email'] = 'E-mail é obrigatório.'; }
            if (!in_array($role, ['ROLE_USER', 'ROLE_FIELD_AGENT'], true)) {
                $errors['role'] = 'Perfil inválido.';
            }
            if ($password !== '' && strlen($password) < 8) {
                $errors['password'] = 'Senha deve ter ao menos 8 caracteres.';
            }
            // Unique email (excluding self)
            $existing = $this->userRepository->findOneBy(['email' => $email]);
            if (empty($errors) && $existing && $existing->getId() !== $user->getId()) {
                $errors['email'] = 'Este e-mail já está em uso.';
            }

            if (empty($errors)) {
                $user->setName($name)->setEmail($email)->setRoles([$role]);

                if ($password !== '') {
                    $user->setPassword($this->passwordHasher->hashPassword($user, $password));
                }

                if ($role === 'ROLE_FIELD_AGENT') {
                    $user->setFieldAgentPermissions(!empty($perms) ? array_values($perms) : null);
                } else {
                    $user->setFieldAgentPermissions(null);
                }

                $this->em->flush();
                $this->addFlash('success', 'Usuário atualizado.');

                return $this->redirectToRoute('account_user_index');
            }
        }

        return $this->render('account/users/edit.html.twig', [
            'user'    => $user,
            'errors'  => $errors,
            'partner' => $partner,
        ]);
    }

    // ── TOGGLE ACTIVE ────────────────────────────────────────────────────────

    #[Route('/{id}/toggle', name: 'toggle', methods: ['POST'])]
    public function toggle(int $id, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ACCOUNT_ADMIN');

        /** @var User $me */
        $me      = $this->getUser();
        $partner = $me->getPartner();
        $user    = $this->userRepository->find($id);

        if (!$user || !$user->belongsToPartner($partner)) {
            throw $this->createNotFoundException();
        }

        if ($user->isSuperAdmin() || $user->isAccountAdmin()) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('toggle_user_'.$id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Token inválido.');
            return $this->redirectToRoute('account_user_index');
        }

        $user->setIsActive(!$user->isActive());
        $this->em->flush();

        $this->addFlash('success', $user->isActive() ? 'Usuário reativado.' : 'Usuário desativado.');

        return $this->redirectToRoute('account_user_index');
    }

    // ── DELETE ───────────────────────────────────────────────────────────────

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(int $id, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ACCOUNT_ADMIN');

        /** @var User $me */
        $me      = $this->getUser();
        $partner = $me->getPartner();
        $user    = $this->userRepository->find($id);

        if (!$user || !$user->belongsToPartner($partner)) {
            throw $this->createNotFoundException();
        }

        if ($user->isSuperAdmin() || $user->isAccountAdmin()) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('delete_user_'.$id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Token inválido.');
            return $this->redirectToRoute('account_user_index');
        }

        $name = $user->getName();
        $this->em->remove($user);
        $this->em->flush();

        $this->addFlash('success', "Usuário {$name} removido.");

        return $this->redirectToRoute('account_user_index');
    }

    /**
     * E-mail de boas-vindas enviado quando um admin de conta cria um
     * novo usuário. Inclui a senha definida pelo admin no formulário —
     * simples e direto para uma equipe pequena, mas é uma troca
     * consciente de segurança (a senha trafega em texto puro por
     * e-mail). Se preferir mais segurança, a alternativa é não incluir
     * a senha aqui e, em vez disso, gerar um token via
     * UserRepository::generateResetToken() e mandar um link de
     * "defina sua senha" — mesmo mecanismo já usado em
     * AuthController::forgot(), reaproveitável aqui.
     */
    private function buildWelcomeEmailHtml(User $user, string $password): string
    {
        $name    = htmlspecialchars($user->getName() ?? '', ENT_QUOTES, 'UTF-8');
        $email   = htmlspecialchars($user->getEmail(), ENT_QUOTES, 'UTF-8');
        $appName = htmlspecialchars($this->appName, ENT_QUOTES, 'UTF-8');
        $loginUrl = $this->generateUrl('auth_login', [], \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL);
        $safePassword = htmlspecialchars($password, ENT_QUOTES, 'UTF-8');

        return <<<HTML
        <div style="font-family: system-ui, -apple-system, Segoe UI, sans-serif; max-width: 480px; margin: 0 auto; background: #f7fbfc; padding: 32px 24px;">
            <div style="background: #ffffff; border-radius: 20px; padding: 32px; box-shadow: 0 10px 30px rgba(16,32,51,0.06);">
                <p style="color:#159bb2; font-weight:800; letter-spacing:-0.02em; font-size:18px; margin:0 0 20px;">{$appName}</p>
                <h2 style="color:#102033; margin:0 0 12px; font-size:20px;">Sua conta foi criada</h2>
                <p style="color:#607084; line-height:1.6; font-size:14px;">Olá, {$name}! Uma conta foi criada para você no {$appName}. Seus dados de acesso:</p>
                <table style="width:100%; margin: 20px 0; border-collapse:collapse;">
                    <tr>
                        <td style="padding:8px 0; color:#8a9aab; font-size:13px;">E-mail</td>
                        <td style="padding:8px 0; color:#102033; font-size:13px; font-weight:600;">{$email}</td>
                    </tr>
                    <tr>
                        <td style="padding:8px 0; color:#8a9aab; font-size:13px;">Senha</td>
                        <td style="padding:8px 0; color:#102033; font-size:13px; font-weight:600;">{$safePassword}</td>
                    </tr>
                </table>
                <p style="text-align:center; margin: 28px 0;">
                    <a href="{$loginUrl}" style="display:inline-block; background:#159bb2; color:#fff; text-decoration:none; font-weight:700; padding:12px 28px; border-radius:12px; font-size:14px;">Acessar o painel</a>
                </p>
                <p style="color:#8a9aab; font-size:12px; line-height:1.6;">Recomendamos trocar essa senha no primeiro acesso, em "Meu Perfil".</p>
            </div>
        </div>
        HTML;
    }
}
