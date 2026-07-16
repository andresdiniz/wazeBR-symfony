<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Partner;
use App\Repository\PartnerRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/partners', name: 'admin_partner_')]
#[IsGranted('ROLE_SUPER_ADMIN')]
class PartnerAdminController extends AbstractController
{
    public function __construct(
        private readonly PartnerRepository $partnerRepository,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/partner/index.html.twig', [
            'partners' => $this->partnerRepository->findAllActive(),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $partner = (new Partner())
                ->setName(trim((string) $request->request->get('name')))
                ->setSlug(trim((string) $request->request->get('slug')))
                ->setEmail(trim((string) $request->request->get('email')))
                ->setBbox($request->request->get('bbox') ?: null)
                ->setCemadenStates(
                    array_values(array_filter(
                        array_map('trim', explode(',', (string) $request->request->get('cemaden_states', '')))
                    ))
                )
                ->setIsActive((bool) $request->request->get('isActive', false))
                ->generateApiToken();

            $this->partnerRepository->save($partner);

            $this->addFlash('success',
                "Parceiro '{$partner->getName()}' criado com sucesso."
            );

            return $this->redirectToRoute('admin_partner_index');
        }

        return $this->render('admin/partner/new.html.twig');
    }

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

            return $this->redirectToRoute('admin_partner_index');
        }

        return $this->render('admin/partner/edit.html.twig', [
            'partner' => $partner,
        ]);
    }

    #[Route('/{id}/show', name: 'show', methods: ['GET'])]
    public function show(Partner $partner): Response
    {
        return $this->render('admin/partner/show.html.twig', [
            'partner' => $partner,
        ]);
    }

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
