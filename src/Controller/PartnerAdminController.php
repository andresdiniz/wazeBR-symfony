<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Partner;
use App\Entity\User;
use App\Repository\PartnerRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/partners', name: 'admin_partner_')]
#[IsGranted('ROLE_SUPER_ADMIN')]
class PartnerAdminController extends AbstractController
{
    public function __construct(
        private readonly PartnerRepository          $partnerRepository,
        private readonly UserRepository             $userRepository,
        private readonly UserPasswordHasherInterface $hasher,
    ) {}

    // ────────────────────────────────────────────────────────────────────────
    // LIST
    // ────────────────────────────────────────────────────────────────────────

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/partner/index.html.twig', [
            'partners' => $this->partnerRepository->findAllActive(),
        ]);
    }

    // ────────────────────────────────────────────────────────────────────────
    // NEW  (parceiro + admin inline)
    // ────────────────────────────────────────────────────────────────────────

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $errors = [];

        if ($request->isMethod('POST')) {

            // ── Dados do parceiro ────────────────────────────────────────────
            $name   = trim((string) $request->request->get('name'));
            $slug   = trim((string) $request->request->get('slug'));
            $email  = trim((string) $request->request->get('email'));
            $bbox   = $request->request->get('bbox') ?: null;
            $states = array_values(array_filter(
                array_map('trim', explode(',', (string) $request->request->get('cemaden_states', '')))
            ));
            $isActive = (bool) $request->request->get('isActive', false);

            // ── Dados do usuário admin ───────────────────────────────────────
            $adminName     = trim((string) $request->request->get('admin_name'));
            $adminEmail    = trim((string) $request->request->get('admin_email'));
            $adminPassword = (string) $request->request->get('admin_password');
            $adminConfirm  = (string) $request->request->get('admin_password_confirm');
            $createAdmin   = (bool)   $request->request->get('create_admin', false);

            // ── Validação básica ─────────────────────────────────────────────
            if (!$name)  $errors['name']  = 'Nome do parceiro é obrigatório.';
            if (!$slug)  $errors['slug']  = 'Slug é obrigatório.';
            if (!$email) $errors['email'] = 'E-mail do parceiro é obrigatório.';

            if ($createAdmin) {
                if (!$adminName)  $errors['admin_name']  = 'Nome do administrador é obrigatório.';
                if (!$adminEmail) $errors['admin_email'] = 'E-mail do administrador é obrigatório.';
                if (strlen($adminPassword) < 8) {
                    $errors['admin_password'] = 'A senha deve ter no mínimo 8 caracteres.';
                } elseif ($adminPassword !== $adminConfirm) {
                    $errors['admin_password_confirm'] = 'As senhas não coincidem.';
                }
                if (!$errors && $this->userRepository->findByEmail($adminEmail)) {
                    $errors['admin_email'] = 'Este e-mail já está em uso.';
                }
            }

            if (!$errors) {
                // Cria parceiro
                $partner = (new Partner())
                    ->setName($name)
                    ->setSlug($slug)
                    ->setEmail($email)
                    ->setBbox($bbox)
                    ->setCemadenStates($states)
                    ->setIsActive($isActive)
                    ->generateApiToken();

                $this->partnerRepository->save($partner, flush: false);

                // Cria admin do parceiro (opcional)
                if ($createAdmin) {
                    $admin = (new User())
                        ->setName($adminName)
                        ->setEmail($adminEmail)
                        ->setRoles(['ROLE_ACCOUNT_ADMIN'])
                        ->setPartner($partner)
                        ->setIsActive(true);

                    $admin->setPassword($this->hasher->hashPassword($admin, $adminPassword));
                    $this->userRepository->save($admin, flush: false);
                }

                // Flush único
                $this->partnerRepository->getEntityManager()->flush();

                $this->addFlash('success', sprintf(
                    "Parceiro '%s' criado%s.",
                    $partner->getName(),
                    $createAdmin ? " com administrador '{$adminName}'" : ''
                ));

                return $this->redirectToRoute('admin_partner_index');
            }
        }

        return $this->render('admin/partner/new.html.twig', [
            'errors' => $errors,
            'old'    => $request->request->all(),
        ]);
    }

    // ────────────────────────────────────────────────────────────────────────
    // EDIT
    // ────────────────────────────────────────────────────────────────────────

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(Partner $partner, Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $partner
                ->setName(trim((string) $request->request->get('name')))
                ->setSlug(trim((string) $request->request->get('slug')))
                ->setEmail(trim((string) $request->request->get('email')))
                ->setBbox($request->request->get('bbox') ?: null)
                ->setCemadenStates(
                    array_values(array_filter(
                        array_map('trim', explode(',', (string) $request->request->get('cemaden_states', '')))
                    ))
                )
                ->setIsActive((bool) $request->request->get('isActive', false));

            $this->partnerRepository->save($partner);

            $this->addFlash('success', "Parceiro '{$partner->getName()}' atualizado.");

            return $this->redirectToRoute('admin_partner_show', ['id' => $partner->getId()]);
        }

        return $this->render('admin/partner/edit.html.twig', [
            'partner' => $partner,
        ]);
    }

    // ────────────────────────────────────────────────────────────────────────
    // SHOW
    // ────────────────────────────────────────────────────────────────────────

    #[Route('/{id}/show', name: 'show', methods: ['GET'])]
    public function show(Partner $partner): Response
    {
        return $this->render('admin/partner/show.html.twig', [
            'partner' => $partner,
            'admins'  => $this->userRepository->findAccountAdminsByPartner($partner),
        ]);
    }

    // ────────────────────────────────────────────────────────────────────────
    // TOKEN
    // ────────────────────────────────────────────────────────────────────────

    #[Route('/{id}/token/regenerate', name: 'regenerate_token', methods: ['POST'])]
    public function regenerateToken(Partner $partner, Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('regen_token_' . $partner->getId(), $request->request->get('_token'))) {
            return $this->json(['error' => 'Invalid CSRF token'], 403);
        }

        $partner->generateApiToken();
        $this->partnerRepository->save($partner);

        $this->addFlash('success', 'Token regenerado com sucesso.');

        return $this->json(['token' => $partner->getApiToken()]);
    }

    // ────────────────────────────────────────────────────────────────────────
    // TOGGLE ACTIVE
    // ────────────────────────────────────────────────────────────────────────

    #[Route('/{id}/toggle', name: 'toggle', methods: ['POST'])]
    public function toggle(Partner $partner): Response
    {
        $partner->setIsActive(!$partner->isActive());
        $this->partnerRepository->save($partner);

        $status = $partner->isActive() ? 'ativado' : 'desativado';
        $this->addFlash('success', "Parceiro '{$partner->getName()}' {$status}.");

        return $this->redirectToRoute('admin_partner_index');
    }
}
