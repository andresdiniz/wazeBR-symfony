<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Form\UserType;
use App\Repository\PartnerRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/users')]
final class AdminUserController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly FormFactoryInterface $formFactory,
    ) {
    }

    #[Route('', name: 'admin_users_index', methods: ['GET', 'POST'])]
    public function index(Request $request, UserRepository $userRepository, PartnerRepository $partnerRepository): Response
    {
        $this->requireUserAdminAccess();
        $partner = null;
        if ($this->isPartnerAdmin()) {
            $partner = $this->getUser()->getPartner();
        } elseif ($request->query->getInt('partner') > 0) {
            $partner = $partnerRepository->find($request->query->getInt('partner'));
        }

        $criteria = $partner !== null ? ['partner' => $partner] : [];
        $users = $userRepository->findBy($criteria, ['name' => 'ASC', 'email' => 'ASC']);
        if (!$this->isGlobalAdmin()) {
            $users = array_values(array_filter($users, fn (User $user): bool => $this->canViewUser($user)));
        }
        $searchQuery = trim((string) $request->query->get('q', ''));
        if ($searchQuery !== '') {
            $needle = mb_strtolower($searchQuery);
            $users = array_values(array_filter($users, static function (User $user) use ($needle): bool {
                $haystack = mb_strtolower(implode(' ', array_filter([
                    $user->getName(),
                    $user->getEmail(),
                    $user->getPhone(),
                    $user->getPartner()?->getName(),
                ])));

                return str_contains($haystack, $needle);
            }));
        }
        $inlineForms = [];
        $manageableUserIds = [];
        $submittedUserId = null;

        foreach ($users as $user) {
            if (!$this->canManageUser($user)) {
                continue;
            }

            $userId = (int) $user->getId();
            $form = $this->createUserForm($user, false, 'edit_user_' . $userId);
            $form->handleRequest($request);

            if ($form->isSubmitted()) {
                $submittedUserId = $userId;
                if ($form->isValid()) {
                    try {
                        $user->setAccessProfile($form->get('partner')->getData(), (array) $form->get('roles')->getData());
                    } catch (\InvalidArgumentException $exception) {
                        $form->get('roles')->addError(new \Symfony\Component\Form\FormError($exception->getMessage()));
                    }

                    if ($form->isValid()) {
                        $plainPassword = (string) $form->get('plainPassword')->getData();
                        if ($plainPassword !== '') {
                            $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
                        }
                        $user->setUpdatedAt(new \DateTimeImmutable());
                        $this->entityManager->flush();
                        $this->addFlash('success', sprintf('Usuário %s atualizado.', $user->getName()));

                        $filters = array_filter(['partner' => $partner?->getId(), 'q' => $searchQuery]);
                        return $this->redirectToRoute('admin_users_index', $filters);
                    }
                }
            }

            $manageableUserIds[] = $userId;
            $inlineForms[$userId] = $form->createView();
        }

        return $this->render('admin/user/index.html.twig', [
            'users' => $users,
            'manageable_user_ids' => $manageableUserIds,
            'inline_forms' => $inlineForms,
            'submitted_user_id' => $submittedUserId,
            'can_manage_roles' => $this->isGlobalAdmin(),
            'partner_filter' => $partner,
            'search_query' => $searchQuery,
        ]);
    }
    #[Route('/new', name: 'admin_users_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $this->requireUserAdminAccess();
        $user = new User();
        $form = $this->createUserForm($user, true);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $user->setAccessProfile($form->get('partner')->getData(), (array) $form->get('roles')->getData());
            } catch (\InvalidArgumentException $exception) {
                $form->get('roles')->addError(new \Symfony\Component\Form\FormError($exception->getMessage()));
            }

            if ($form->isValid()) {
                $plainPassword = (string) $form->get('plainPassword')->getData();
                $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
                $user->setCreatedAt(new \DateTimeImmutable());
                $user->setUpdatedAt(new \DateTimeImmutable());
                $this->entityManager->persist($user);
                $this->entityManager->flush();
                $this->addFlash('success', 'Usuário criado com o perfil selecionado.');

                return $this->redirectToRoute('admin_users_index');
            }
        }

        return $this->render('admin/user/new.html.twig', ['form' => $form]);
    }

    #[Route('/{id}/edit', name: 'admin_users_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, User $user): Response
    {
        $this->requireUserAdminAccess();
        $this->denyUnlessManageable($user);
        $form = $this->createUserForm($user, false);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $user->setAccessProfile($form->get('partner')->getData(), (array) $form->get('roles')->getData());
            } catch (\InvalidArgumentException $exception) {
                $form->get('roles')->addError(new \Symfony\Component\Form\FormError($exception->getMessage()));
            }

            if ($form->isValid()) {
                $plainPassword = (string) $form->get('plainPassword')->getData();
                if ($plainPassword !== '') {
                    $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
                }
                $user->setUpdatedAt(new \DateTimeImmutable());
                $this->entityManager->flush();
                $this->addFlash('success', 'Usuário atualizado.');

                return $this->redirectToRoute('admin_users_index');
            }
        }

        return $this->render('admin/user/edit.html.twig', ['form' => $form, 'user' => $user]);
    }

    #[Route('/{id}/toggle', name: 'admin_users_toggle', methods: ['POST'])]
    public function toggle(Request $request, User $user): Response
    {
        $this->requireUserAdminAccess();
        $this->denyUnlessManageable($user);
        if ($user->getId() === $this->getUser()?->getId()) {
            throw $this->createAccessDeniedException('Não é possível desativar a própria conta por esta tela.');
        }
        if (!$this->isCsrfTokenValid('admin_user_toggle_' . $user->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de segurança inválido.');
            return $this->redirectToRoute('admin_users_index');
        }

        $user->setActive(!$user->isActive());
        $this->entityManager->flush();
        $this->addFlash('success', $user->isActive() ? 'Conta reativada.' : 'Conta desativada.');

        return $this->redirectToRoute('admin_users_index');
    }

    private function createUserForm(User $user, bool $requirePassword, ?string $name = null): FormInterface
    {
        $roles = $this->availableRoles();
        $profileLocked = $user->getId() !== null && $user->getId() === $this->getUser()?->getId();
        $roleValues = array_values($roles);
        if ($profileLocked) {
            foreach ($user->getRoles() as $currentRole) {
                if (!in_array($currentRole, $roleValues, true)) {
                    $roles['Seu perfil atual (bloqueado)'] = $currentRole;
                    $roleValues[] = $currentRole;
                }
            }
        }
        $selectedRoles = array_values(array_intersect($user->getRoles(), $roleValues));
        $selectedRole = $selectedRoles[0] ?? User::ROLE_VIEWER;
        $options = [
            'require_password' => $requirePassword,
            'available_roles' => $roles,
            'roles_data' => $selectedRole,
            'partner_data' => $this->isPartnerAdmin() ? $this->getUser()->getPartner() : $user->getPartner(),
            'partner_locked' => $this->isPartnerAdmin(),
            'profile_locked' => $user->getId() !== null && $user->getId() === $this->getUser()?->getId(),
        ];

        return $name === null
            ? $this->createForm(UserType::class, $user, $options)
            : $this->formFactory->createNamed($name, UserType::class, $user, $options);
    }
    /** @return array<string, string> */
    private function availableRoles(): array
    {
        $roles = [];
        if ($this->isSuperAdmin()) {
            $roles['Super administrador'] = User::ROLE_SUPER_ADMIN;
        }
        if ($this->isGlobalAdmin()) {
            return $roles + [
                'Administrador global' => User::ROLE_ADMIN,
                'Administrador de contas' => User::ROLE_ACCOUNT_ADMIN,
                'Administrador de parceiro' => User::ROLE_PARTNER_ADMIN,
                'Operador do parceiro' => User::ROLE_OPERATOR,
                'Agente de via' => User::ROLE_FIELD_AGENT,
                'Visualizador' => User::ROLE_VIEWER,
            ];
        }

        return [
            'Operador do parceiro' => User::ROLE_OPERATOR,
            'Visualizador' => User::ROLE_VIEWER,
        ];
    }
    private function isPartnerAdmin(): bool
    {
        return $this->isGranted('ROLE_PARTNER_ADMIN') && !$this->isGlobalAdmin();
    }

    private function requireUserAdminAccess(): void
    {
        if (!$this->isGranted('ROLE_ACCOUNT_ADMIN') && !$this->isGranted('ROLE_PARTNER_ADMIN')) {
            throw $this->createAccessDeniedException();
        }
        if ($this->isPartnerAdmin() && $this->getUser()?->getPartner() === null) {
            throw $this->createAccessDeniedException('Seu perfil precisa estar vinculado a um parceiro.');
        }
    }
    private function isSuperAdmin(): bool
    {
        return $this->isGranted('ROLE_SUPER_ADMIN');
    }
    private function isGlobalAdmin(): bool
    {
        $user = $this->getUser();
        return $user instanceof User && $user->isGlobalAdmin();
    }

    private function canViewUser(User $target): bool
    {
        if ($target->getId() === $this->getUser()?->getId()) {
            return true;
        }

        $roles = $target->getRoles();
        if (array_intersect($roles, [User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN]) !== []) {
            return false;
        }

        if ($this->isPartnerAdmin()) {
            $actorPartnerId = $this->getUser()?->getPartner()?->getId();
            return $actorPartnerId !== null && $target->getPartner()?->getId() === $actorPartnerId;
        }

        if ($this->isGranted('ROLE_ACCOUNT_ADMIN')) {
            return !in_array(User::ROLE_ACCOUNT_ADMIN, $roles, true);
        }

        return false;
    }

    private function canManageUser(User $target): bool
    {
        if ($target->getId() === $this->getUser()?->getId()) {
            return true;
        }
        $targetRoles = $target->getRoles();
        if (in_array(User::ROLE_SUPER_ADMIN, $targetRoles, true) && !$this->isSuperAdmin()) {
            return false;
        }
        if ($this->isGlobalAdmin()) {
            return true;
        }
        if (array_intersect($targetRoles, [User::ROLE_ADMIN, User::ROLE_ACCOUNT_ADMIN, User::ROLE_PARTNER_ADMIN, User::ROLE_FIELD_AGENT])) {
            return false;
        }
        if ($this->isPartnerAdmin()) {
            $actorPartner = $this->getUser()?->getPartner();
            return $actorPartner !== null && $target->getPartner()?->getId() === $actorPartner->getId();
        }

        return true;
    }
    private function denyUnlessManageable(User $target): void
    {
        if (!$this->canManageUser($target)) {
            throw $this->createAccessDeniedException('Seu perfil não pode administrar este usuário.');
        }
    }
}
