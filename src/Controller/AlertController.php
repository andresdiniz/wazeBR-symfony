<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\WazeAlertRepository;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/alertas', name: 'alert_')]
#[IsGranted('ROLE_USER')]
class AlertController extends AbstractController
{
    public function __construct(
        private readonly TenantContext       $tenantContext,
        private readonly WazeAlertRepository $alertRepo,
    ) {}

    #[Route('', name: 'index')]
    public function index(Request $request): Response
    {
        $partner = $this->tenantContext->requirePartner();
        $type    = $request->query->get('type');
        $city    = $request->query->get('city');
        $page    = max(1, (int) $request->query->get('page', 1));

        $alerts = $this->alertRepo->findFilteredByPartner(
            partner: $partner,
            type: $type ?: null,
            city: $city ?: null,
            page: $page,
            limit: 30,
        );

        return $this->render('alert/index.html.twig', [
            'partner' => $partner,
            'alerts'  => $alerts,
            'type'    => $type,
            'city'    => $city,
            'page'    => $page,
        ]);
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'])]
    public function show(int $id): Response
    {
        $partner = $this->tenantContext->requirePartner();
        $alert   = $this->alertRepo->findOneByPartner($id, $partner);

        if (!$alert) {
            throw $this->createNotFoundException('Alerta não encontrado.');
        }

        return $this->render('alert/show.html.twig', [
            'partner' => $partner,
            'alert'   => $alert,
        ]);
    }
}
