<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\WazeTrafficJamRepository;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/congestionamentos', name: 'jam_')]
#[IsGranted('ROLE_USER')]
class TrafficJamController extends AbstractController
{
    public function __construct(
        private readonly TenantContext             $tenantContext,
        private readonly WazeTrafficJamRepository  $jamRepo,
    ) {}

    /** Histórico paginado com filtros */
    #[Route('', name: 'index')]
    public function index(Request $request): Response
    {
        $partner  = $this->tenantContext->requirePartner();
        $minLevel = $request->query->get('minLevel') !== null && $request->query->get('minLevel') !== ''
                    ? (int) $request->query->get('minLevel') : null;
        $city     = $request->query->get('city') ?: null;
        $type     = $request->query->get('type') ?: null;
        $dateFrom = $request->query->get('dateFrom') ?: null;
        $dateTo   = $request->query->get('dateTo') ?: null;
        $page     = max(1, (int) $request->query->get('page', 1));

        $result = $this->jamRepo->findFilteredByPartner(
            partner:  $partner,
            minLevel: $minLevel,
            city:     $city,
            type:     $type,
            dateFrom: $dateFrom,
            dateTo:   $dateTo,
            page:     $page,
            limit:    30,
        );

        return $this->render('traffic_jam/index.html.twig', [
            'partner'  => $partner,
            'jams'     => $result['items'],
            'total'    => $result['total'],
            'pages'    => $result['pages'],
            'page'     => $page,
            'minLevel' => $minLevel,
            'city'     => $city,
            'type'     => $type,
            'dateFrom' => $dateFrom,
            'dateTo'   => $dateTo,
            'cities'   => $this->jamRepo->findDistinctCities($partner),
            'types'    => $this->jamRepo->findDistinctTypes($partner),
        ]);
    }

    /** Mapa ao vivo de congestionamentos */
    #[Route('/ao-vivo', name: 'live')]
    public function live(Request $request): Response
    {
        $partner = $this->tenantContext->requirePartner();
        $hours   = max(1, min(24, (int) $request->query->get('hours', 3)));
        $jams    = $this->jamRepo->findLiveByPartner($partner, $hours);
        $stats   = $this->jamRepo->avgStats($partner, $hours);

        return $this->render('traffic_jam/live.html.twig', [
            'partner' => $partner,
            'jams'    => $jams,
            'hours'   => $hours,
            'total'   => count($jams),
            'stats'   => $stats,
        ]);
    }

    /** API JSON das polylines ao vivo */
    #[Route('/api/live', name: 'api_live')]
    public function apiLive(Request $request): JsonResponse
    {
        $partner = $this->tenantContext->requirePartner();
        $hours   = max(1, min(24, (int) $request->query->get('hours', 3)));
        $jams    = $this->jamRepo->findLiveByPartner($partner, $hours);

        $data = array_map(fn($j) => [
            'id'      => $j->getId(),
            'street'  => $j->getStreet(),
            'city'    => $j->getCity(),
            'level'   => $j->getLevel(),
            'speedKmh'=> $j->getSpeedKmh(),
            'length'  => $j->getLength(),
            'delay'   => $j->getDelay(),
            'type'    => $j->getType(),
            'blocking'=> $j->getBlocking(),
            'line'    => $j->getLine(),
        ], $jams);

        return new JsonResponse($data);
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
