<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
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

                $this->addFlash('success', "Usuário {$name} criado com sucesso.");

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
}
