<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\CemadenDataRepository;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/cemaden', name: 'cemaden_')]
#[IsGranted('ROLE_USER')]
class CemadenController extends AbstractController
{
    public function __construct(
        private readonly TenantContext         $tenantContext,
        private readonly CemadenDataRepository $cemadenRepo,
    ) {}

    #[Route('', name: 'index')]
    public function index(Request $request): Response
    {
        $partner = $this->tenantContext->requirePartner();
        $level   = $request->query->get('level');
        $state   = $request->query->get('state');

        $data = $this->cemadenRepo->findFilteredByPartner(
            partner: $partner,
            alertLevel: $level ?: null,
            state: $state ?: null,
        );

        return $this->render('cemaden/index.html.twig', [
            'partner' => $partner,
            'data'    => $data,
            'level'   => $level,
            'state'   => $state,
        ]);
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'])]
    public function show(int $id): Response
    {
        $partner = $this->tenantContext->requirePartner();
        $item    = $this->cemadenRepo->findOneByPartner($id, $partner);

        if (!$item) {
            throw $this->createNotFoundException('Dado CEMADEN não encontrado.');
        }

        return $this->render('cemaden/show.html.twig', [
            'partner' => $partner,
            'item'    => $item,
        ]);
    }
}
