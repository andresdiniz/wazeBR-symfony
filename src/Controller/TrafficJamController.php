<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\WazeTrafficJamRepository;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/congestionamentos', name: 'jam_')]
#[IsGranted('ROLE_USER')]
class TrafficJamController extends AbstractController
{
    public function __construct(
        private readonly TenantContext            $tenantContext,
        private readonly WazeTrafficJamRepository $jamRepo,
    ) {}

    #[Route('', name: 'index')]
    public function index(Request $request): Response
    {
        $partner  = $this->tenantContext->requirePartner();
        $minLevel = (int) $request->query->get('level', 0);
        $city     = $request->query->get('city');
        $page     = max(1, (int) $request->query->get('page', 1));

        $jams = $this->jamRepo->findFilteredByPartner(
            partner: $partner,
            minLevel: $minLevel > 0 ? $minLevel : null,
            city: $city ?: null,
            page: $page,
            limit: 30,
        );

        return $this->render('traffic_jam/index.html.twig', [
            'partner'  => $partner,
            'jams'     => $jams,
            'minLevel' => $minLevel,
            'city'     => $city,
            'page'     => $page,
        ]);
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'])]
    public function show(int $id): Response
    {
        $partner = $this->tenantContext->requirePartner();
        $jam     = $this->jamRepo->findOneByPartner($id, $partner);

        if (!$jam) {
            throw $this->createNotFoundException('Congestionamento não encontrado.');
        }

        return $this->render('traffic_jam/show.html.twig', [
            'partner' => $partner,
            'jam'     => $jam,
        ]);
    }
}
