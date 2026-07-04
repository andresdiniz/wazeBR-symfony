<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\CemadenHydroDataRepository;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/hidrologico', name: 'hydro_')]
#[IsGranted('ROLE_USER')]
class HydroController extends AbstractController
{
    public function __construct(
        private readonly TenantContext              $tenantContext,
        private readonly CemadenHydroDataRepository $hydroRepo,
    ) {}

    /**
     * Tela ao vivo: última leitura de cada estação do parceiro.
     * Atualiza automaticamente via JS a cada 60 s.
     */
    #[Route('/live', name: 'live')]
    public function live(): Response
    {
        $partner = $this->tenantContext->requirePartner();
        $rows    = $this->hydroRepo->findLatestByPartner($partner->getId());

        return $this->render('hydro/live.html.twig', [
            'partner' => $partner,
            'rows'    => $rows,
        ]);
    }

    /**
     * Endpoint JSON para o polling da tela ao vivo.
     */
    #[Route('/live/data', name: 'live_data')]
    public function liveData(): JsonResponse
    {
        $partner = $this->tenantContext->requirePartner();
        $rows    = $this->hydroRepo->findLatestByPartner($partner->getId());

        return $this->json(array_map(fn($r) => [
            'station_code' => $r['station_code'],
            'station_name' => $r['station_name'],
            'municipality' => $r['municipality'],
            'state'        => $r['state'],
            'water_level'  => $r['water_level'],
            'alert_level'  => $r['alert_level'],
            'cota_atencao' => $r['cota_atencao'],
            'cota_alerta'  => $r['cota_alerta'],
            'cota_transbordamento' => $r['cota_transbordamento'],
            'measured_at'  => $r['measured_at'],
        ], $rows));
    }

    /**
     * Histórico: leituras filtradas por data, estação e nível de alerta.
     */
    #[Route('/historico', name: 'historico')]
    public function historico(Request $request): Response
    {
        $partner = $this->tenantContext->requirePartner();

        $station  = $request->query->get('station', '');
        $level    = $request->query->get('level', '');
        $dateFrom = $request->query->get('date_from', date('Y-m-d', strtotime('-7 days')));
        $dateTo   = $request->query->get('date_to',   date('Y-m-d'));
        $page     = max(1, (int) $request->query->get('page', 1));
        $perPage  = 50;

        [$rows, $total] = $this->hydroRepo->findHistorico(
            partnerId: $partner->getId(),
            stationCode: $station ?: null,
            alertLevel:  $level   ?: null,
            dateFrom:    $dateFrom,
            dateTo:      $dateTo,
            page:        $page,
            perPage:     $perPage,
        );

        $stations = $this->hydroRepo->findStationsByPartner($partner->getId());

        return $this->render('hydro/historico.html.twig', [
            'partner'   => $partner,
            'rows'      => $rows,
            'total'     => $total,
            'page'      => $page,
            'perPage'   => $perPage,
            'pages'     => (int) ceil($total / $perPage),
            'stations'  => $stations,
            'station'   => $station,
            'level'     => $level,
            'date_from' => $dateFrom,
            'date_to'   => $dateTo,
        ]);
    }
}
