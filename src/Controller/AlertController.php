<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\WazeAlertRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

final class AlertController extends AbstractController
{
    #[Route('/alerts', name: 'alert_index', methods: ['GET'])]
    public function index(Request $request, WazeAlertRepository $repository): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $partner = $this->resolvePartnerScope();
        $filters = $this->extractFilters($request);

        return $this->render('alert/index.html.twig', [
            'partner'    => $partner,
            'filters'    => $filters,
            'stats'      => $repository->getStats($partner, $filters),
            'charts'     => [
                'by_type'    => $repository->getByType($partner, $filters),
                'by_subtype' => $repository->getBySubtype($partner, $filters, 8),
                'by_hour'    => $repository->getByHour($partner, $filters),
                'by_city'    => $repository->getByCity($partner, $filters, 10),
                'by_day'     => $repository->getByDay($partner, $filters, 14),
            ],
            'map'        => [
                'live'     => $repository->getLiveAlerts($partner, $filters, 2000),
                'clusters' => $repository->getHistoricalClusters($partner, $filters, 2),
            ],
            'recent'     => $repository->getRecentList($partner, $filters, 30, 0),
            'filter_options' => [
                'types'    => $repository->getAvailableTypes($partner),
                'subtypes' => $repository->getAvailableSubtypes($partner),
                'cities'   => $repository->getAvailableCities($partner, 300),
            ],
        ]);
    }

    /**
     * Página de detalhes de um alerta (show).
     *
     * Respeita o escopo do usuário:
     *   - ROLE_ADMIN global → vê qualquer alerta
     *   - demais            → só alertas do próprio partner
     */
    #[Route(
        '/alerts/{id}',
        name: 'alert_show',
        requirements: ['id' => '\d+'],
        methods: ['GET'],
    )]
    public function show(int $id, WazeAlertRepository $repository): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $partner = $this->resolvePartnerScope();
        $alert   = $repository->findOneScoped($id, $partner);

        if ($alert === null) {
            throw $this->createNotFoundException('Alerta não encontrado.');
        }

        return $this->render('alert/show.html.twig', [
            'alert'   => $alert,
            'partner' => $partner,
        ]);
    }

    /** Live map — polling a cada ~45s. */
    #[Route('/alerts/api/live', name: 'alert_api_live', methods: ['GET'])]
    public function apiLive(Request $request, WazeAlertRepository $repository): JsonResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $partner = $this->resolvePartnerScope();
        $filters = $this->extractFilters($request);

        return $this->json([
            'ok'      => true,
            'filters' => $filters,
            'stats'   => $repository->getStats($partner, $filters),
            'map'     => [
                'live' => $repository->getLiveAlerts($partner, $filters, 2000),
            ],
            'charts'  => [
                'by_type' => $repository->getByType($partner, $filters),
                'by_hour' => $repository->getByHour($partner, $filters),
            ],
            'generated_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ]);
    }

    /** Historical map — clustering server-side por proximidade. */
    #[Route('/alerts/api/history', name: 'alert_api_history', methods: ['GET'])]
    public function apiHistory(Request $request, WazeAlertRepository $repository): JsonResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $partner   = $this->resolvePartnerScope();
        $filters   = $this->extractFilters($request);
        $precision = (int) $request->query->get('precision', 2);

        return $this->json([
            'ok'        => true,
            'filters'   => $filters,
            'precision' => $precision,
            'clusters'  => $repository->getHistoricalClusters($partner, $filters, $precision),
            'charts'    => [
                'by_day'  => $repository->getByDay($partner, $filters, 14),
                'by_city' => $repository->getByCity($partner, $filters, 10),
            ],
            'generated_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ]);
    }

    /** Lista paginada para a tabela. */
    #[Route('/alerts/api/list', name: 'alert_api_list', methods: ['GET'])]
    public function apiList(Request $request, WazeAlertRepository $repository): JsonResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $partner = $this->resolvePartnerScope();
        $filters = $this->extractFilters($request);
        $limit   = max(1, min(200, (int) $request->query->get('limit', 30)));
        $offset  = max(0, (int) $request->query->get('offset', 0));

        return $this->json([
            'ok'     => true,
            'limit'  => $limit,
            'offset' => $offset,
            'rows'   => $repository->getRecentList($partner, $filters, $limit, $offset),
        ]);
    }

    /** Export CSV — respeita os filtros atuais. */
    #[Route('/alerts/export.csv', name: 'alert_export', methods: ['GET'])]
    public function export(Request $request, WazeAlertRepository $repository): StreamedResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $partner = $this->resolvePartnerScope();
        $filters = $this->extractFilters($request);
        $rows    = $repository->exportRows($partner, $filters, 20000);

        $filename = sprintf('alertas_%s.csv', (new \DateTimeImmutable())->format('Ymd_His'));

        $response = new StreamedResponse(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'ID','UUID','Tipo','Subtipo','Cidade','Rua','Confiança','Confiabilidade',
                'Ativo','Latitude','Longitude','Publicado (ms)','Coletado em (UTC)','Coletado em (SP)',
            ], ';');

            foreach ($rows as $r) {
                fputcsv($out, [
                    $r['id'],
                    $r['uuid'],
                    $r['type'],
                    $r['subtype'],
                    $r['city'],
                    $r['street'],
                    $r['confidence'],
                    $r['reliability'],
                    (int) $r['is_active'],
                    $r['lat'],
                    $r['lng'],
                    $r['pub_millis'],
                    $r['collected_at']    ?? '',
                    $r['collected_at_sp'] ?? '',
                ], ';');
            }
            fclose($out);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

        return $response;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────

    private function resolvePartnerScope(): ?\App\Entity\Partner
    {
        /** @var User|null $user */
        $user = $this->getUser();
        if (!$user instanceof User) {
            return null;
        }
        if ($user->isGlobalAdmin()) {
            return null;
        }
        return $user->getPartner();
    }

    /** @return array<string,mixed> */
    private function extractFilters(Request $request): array
    {
        return [
            'period'         => (string) $request->query->get('period', 'all'),
            'type'           => (string) $request->query->get('type', ''),
            'subtype'        => (string) $request->query->get('subtype', ''),
            'city'           => (string) $request->query->get('city', ''),
            'query'          => (string) $request->query->get('query', ''),
            'min_confidence' => (int) $request->query->get('min_confidence', 0),
            'active'         => $request->query->getBoolean('active', false),
        ];
    }
}
