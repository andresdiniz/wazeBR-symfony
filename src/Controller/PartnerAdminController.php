<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Partner;
use App\Form\PartnerType;
use App\Repository\PartnerRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/partner-admin')]
class PartnerAdminController extends AbstractController
{
    public function __construct(
        private PartnerRepository $partnerRepository,
        private EntityManagerInterface $entityManager
    ) {}

    /**
     * Lista de parceiros - Acessado por todos os roles autenticados
     * ROLE_PARTNER e ROLE_ADMIN: veem tudo com açõ£££es
     * ROLE_OPERATOR e ROLE_USER: veem apenas dados básicos
     */
    #[Route('/', name: 'app_partner_admin_index', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $partners = $this->partnerRepository->findAll();
        $canEdit = $this->isGranted('ROLE_PARTNER') || $this->isGranted('ROLE_ADMIN');

        return $this->render('partner_admin/index.html.twig', [
            'partners' => $partners,
            'can_edit' => $canEdit,
        ]);
    }

    /**
     * Visualizar detalhes do parceiro
     * ROLE_PARTNER e ROLE_ADMIN: veem todos os detalhes
     * ROLE_OPERATOR e ROLE_USER: veem apenas dados básicos
     */
    #[Route('/{id}', name: 'app_partner_admin_show', methods: ['GET'])]
    public function show(Partner $partner): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $canViewFull = $this->isGranted('ROLE_PARTNER') || $this->isGranted('ROLE_ADMIN');

        return $this->render('partner_admin/show.html.twig', [
            'partner' => $partner,
            'can_view_full' => $canViewFull,
        ]);
    }

    /**
     * Criar novo parceiro - Apenas ROLE_PARTNER e ROLE_ADMIN
     */
    #[Route('/new', name: 'app_partner_admin_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_PARTNER', message: 'Apenas parceiros podem criar novos parceiros.')]
    public function new(Request $request): Response
    {
        // Double-check para ADMIN também poder criar
        if (!$this->isGranted('ROLE_PARTNER') && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException('Acesso negado. Apenas ROLE_PARTNER e ROLE_ADMIN podem criar parceiros.');
        }

        $partner = new Partner();
        $form = $this->createForm(PartnerType::class, $partner);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($partner);
            $this->entityManager->flush();

            $this->addFlash('success', 'Parceiro criado com sucesso!');

            return $this->redirectToRoute('app_partner_admin_index');
        }

        return $this->render('partner_admin/new.html.twig', [
            'partner' => $partner,
            'form' => $form,
        ]);
    }

    /**
     * Editar parceiro - Apenas ROLE_PARTNER e ROLE_ADMIN
     */
    #[Route('/{id}/edit', name: 'app_partner_admin_edit', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_PARTNER', message: 'Apenas parceiros podem editar parceiros.')]
    public function edit(Request $request, Partner $partner): Response
    {
        // Double-check para ADMIN também poder editar
        if (!$this->isGranted('ROLE_PARTNER') && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException('Acesso negado. Apenas ROLE_PARTNER e ROLE_ADMIN podem editar parceiros.');
        }

        $form = $this->createForm(PartnerType::class, $partner);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            $this->addFlash('success', 'Parceiro atualizado com sucesso!');

            return $this->redirectToRoute('app_partner_admin_index');
        }

        return $this->render('partner_admin/edit.html.twig', [
            'partner' => $partner,
            'form' => $form,
        ]);
    }

    /**
     * Deletar parceiro - Apenas ROLE_PARTNER e ROLE_ADMIN
     */
    #[Route('/{id}', name: 'app_partner_admin_delete', methods: ['POST'])]
    #[IsGranted('ROLE_PARTNER', message: 'Apenas parceiros podem deletar parceiros.')]
    public function delete(Request $request, Partner $partner): Response
    {
        // Double-check para ADMIN também poder deletar
        if (!$this->isGranted('ROLE_PARTNER') && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException('Acesso negado. Apenas ROLE_PARTNER e ROLE_ADMIN podem deletar parceiros.');
        }

        $csrfToken = $request->request->get('_token');

        if (!$this->isCsrfTokenValid('delete' . $partner->getId(), $csrfToken)) {
            throw $this->createAccessDeniedException('Token CSRF invá¡£lido.');
        }

        $this->entityManager->remove($partner);
        $this->entityManager->flush();

        $this->addFlash('success', 'Parceiro removido com sucesso!');

        return $this->redirectToRoute('app_partner_admin_index');
    }

    /**
     * Dados básicos do parceiro (API) - Acessado por todos os roles autenticados
     * Retorna apenas campos básicos para ROLE_OPERATOR e ROLE_USER
     */
    #[Route('/api/{id}', name: 'app_partner_admin_api_show', methods: ['GET'])]
    public function apiShow(Partner $partner): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $canViewFull = $this->isGranted('ROLE_PARTNER') || $this->isGranted('ROLE_ADMIN');

        $data = [
            'id' => $partner->getId(),
            'name' => $partner->getName(),
            'email' => $partner->getEmail(),
            'active' => $partner->isActive(),
        ];

        // Apenas ROLE_PARTNER e ROLE_ADMIN veem dados completos
        if ($canViewFull) {
            $data = array_merge($data, [
                'apiKey' => $partner->getApiKey(),
                'webhookUrl' => $partner->getWebhookUrl(),
                'settings' => $partner->getSettings(),
                'createdAt' => $partner->getCreatedAt()?->format('Y-m-d H:i:s'),
                'updatedAt' => $partner->getUpdatedAt()?->format('Y-m-d H:i:s'),
            ]);
        }

        return $this->json($data);
    }
}
