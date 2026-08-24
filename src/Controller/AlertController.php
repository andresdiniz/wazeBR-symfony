<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\WazeAlertRepository;
use App\Repository\WazeAlertTypeRepository;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/alertas', name: 'alert_')]
#[IsGranted('ROLE_USER')]
class AlertController extends AbstractController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly WazeAlertRepository $alertRepo,
        private readonly WazeAlertTypeRepository $alertTypeRepo,
    ) {}

    #[Route('/ao-vivo', name: 'live', methods: ['GET'])]
    public function live(Request $request): Response
    {
        $partner = $this->tenantContext->requirePartner();
        $locale = $request->getLocale() ?: 'pt';
        $alerts = $this->alertRepo->findActiveByPartner($partner, 10);
        $regions = [];
        foreach ($alerts as $alert) {
            $city = $alert->getCity() ?: 'Sem cidade';
            $regions[$city] = ($regions[$city] ?? 0) + 1;
        }
        arsort($regions);
        $regionRows = array_map(static fn ($city, $count) => ['city' => $city, 'count' => $count], array_keys($regions), array_values($regions));
        dump([
            'rota' => 'alert_live',
            'total_alertas_ativos' => count($alerts),
            'regioes' => $regionRows,
            'ids' => array_map(static fn ($alert) => $alert->getId(), $alerts),
            'pub_millis' => array_map(static fn ($alert) => $alert->getPubMillis(), $alerts),
            'agora_millis' => time() * 1000,
            'limite_millis' => (time() - 600) * 1000,
        ]);
        return $this->render('alert/live.html.twig', [
            'partner' => $partner,
            'regions' => $regionRows,
            'alerts' => $alerts,
            'hours' => 0,
            'total' => count($alerts),
            'typesMap' => $this->alertTypeRepo->getTypesMap($locale),
            'subtypesMap' => $this->alertTypeRepo->getSubtypesMap($locale),
        ]);
    }
}
