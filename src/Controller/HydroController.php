<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\CemadenHydroDataRepository;
use App\Service\TenantContext;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/hidrologico', name: 'hydro_')]
#[IsGranted('ROLE_USER')]
class HydroController extends AbstractController
{
    public function __construct(
        private readonly TenantContext              $tenantContext,
        private readonly CemadenHydroDataRepository $hydroRepo,
        private readonly LoggerInterface            $logger,
    ) {}

    #[Route('/live', name: 'live')]
    public function live(): Response
    {
        $this->logger->info('[Hydro] Acessando /live');

        try {
            $partner = $this->tenantContext->requirePartner();
            $rows = $this->hydroRepo->findLatestByPartner($partner);
            $error = null;
        } catch (Exception $e) {
            $this->logger->error('[Hydro] Erro ao obter partner: ' . $e->getMessage());
            $rows = [];
            $error = 'Partner não encontrado. Verifique sua conta.';
        }

        return $this->render('hydro/live.html.twig', [
            'rows'  => $rows,
            'error' => $error,
        ]);
    }

    #[Route('/live/data', name: 'live_data')]
    public function liveData(): JsonResponse
    {
        $this->logger->info('[Hydro] Acessando /live/data');

        try {
            $partner = $this->tenantContext->requirePartner();
            $rows = $this->hydroRepo->findLatestByPartner($partner);
            return $this->json($rows);
        } catch (Exception $e) {
            $this->logger->error('[Hydro] Erro ao obter partner em liveData: ' . $e->getMessage());
            return $this->json(['error' => 'Partner não encontrado'], 403);
        }
    }

    #[Route('/historico', name: 'historico')]
    public function historico(Request $request): Response
    {
        try {
            $partner = $this->tenantContext->requirePartner();
        } catch (Exception $e) {
            $this->logger->error('[Hydro] Erro ao obter partner no histórico: ' . $e->getMessage());
            throw $this->createAccessDeniedException('Partner não encontrado.');
        }

        $station  = $request->query->get('station', '');
        $level    = $request->query->get('level', '');
        $dateFrom = $request->query->get('date_from', date('Y-m-d', strtotime('-7 days')));
        $dateTo   = $request->query->get('date_to',   date('Y-m-d'));
        $page     = max(1, (int) $request->query->get('page', 1));
        $perPage  = 50;

        [$rows, $total] = $this->hydroRepo->findHistorico(
            partner:     $partner,
            stationCode: $station ?: null,
            alertLevel:  $level   ?: null,
            dateFrom:    $dateFrom,
            dateTo:      $dateTo,
            page:        $page,
            perPage:     $perPage,
        );

        $stations = $this->hydroRepo->findStationsByPartner($partner);

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

    #[Route('/historico/export.csv', name: 'historico_export')]
    public function exportCsv(Request $request): Response
    {
        try {
            $partner = $this->tenantContext->requirePartner();
        } catch (Exception $e) {
            throw $this->createAccessDeniedException('Partner não encontrado.');
        }

        $station  = $request->query->get('station', '');
        $level    = $request->query->get('level', '');
        $dateFrom = $request->query->get('date_from', date('Y-m-d', strtotime('-7 days')));
        $dateTo   = $request->query->get('date_to',   date('Y-m-d'));

        // Busca todos os registros (sem paginação)
        [$rows, $total] = $this->hydroRepo->findHistorico(
            partner:     $partner,
            stationCode: $station ?: null,
            alertLevel:  $level   ?: null,
            dateFrom:    $dateFrom,
            dateTo:      $dateTo,
            page:        1,
            perPage:     10000, // máximo
        );

        $filename = sprintf(
            'hidrologico_%s_%s_%s.csv',
            $partner->getSlug(),
            $dateFrom,
            $dateTo,
        );

        $response = new StreamedResponse(function () use ($rows) {
            $handle = fopen('php://output', 'w');

            // Cabeçalho
            fputcsv($handle, [
                'Estação',
                'Código',
                'Município',
                'UF',
                'Nível (m)',
                'Atenção (m)',
                'Alerta (m)',
                'Transbordamento (m)',
                'Alerta Nível',
                'Medido em',
            ], ';');

            foreach ($rows as $r) {
                fputcsv($handle, [
                    $r['station_name'],
                    $r['station_code'],
                    $r['municipality'],
                    $r['state'],
                    $r['water_level'] ?? '',
                    $r['cota_atencao'] ?? '',
                    $r['cota_alerta'] ?? '',
                    $r['cota_transbordamento'] ?? '',
                    $r['alert_level'] ?? '',
                    $r['measured_at'] ?? '',
                ], ';');
            }

            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

        return $response;
    }
}
