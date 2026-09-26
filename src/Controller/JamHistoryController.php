<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Partner;
use App\Entity\User;
use App\Repository\JamHistoryRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/jams/historico', name: 'jam_history_')]
final class JamHistoryController extends AbstractController
{
    public function __construct(
        private readonly JamHistoryRepository $repository,
    ) {
    }

    // ──────────────────────────────────────────────────────────────────

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $partner = $this->resolvePartnerScope();
        $filters = $this->extractFilters($request);

        return $this->render('jam/history.html.twig', [
            'partner'        => $partner,
            'filters'        => $filters,
            'filter_options' => [
                'cities' => $this->repository->fetchDistinctCities($partner),
            ],
        ]);
    }

    // ──────────────────────────────────────────────────────────────────

    #[Route('/api/data', name: 'api_data', methods: ['GET'])]
    public function apiData(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $partner = $this->resolvePartnerScope();
        $filters = $this->extractFilters($request);

        $data = $this->repository->getHistoricalDashboard($partner, $filters);

        $response = $this->json([
            'ok'     => true,
            'data'   => $data,
            'filter' => $filters,
        ]);

        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');

        return $response;
    }

    // ──────────────────────────────────────────────────────────────────

    #[Route('/export', name: 'export', methods: ['GET'])]
    public function export(Request $request): StreamedResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $partner = $this->resolvePartnerScope();
        $filters = $this->extractFilters($request);
        $rows    = $this->repository->loadExportRows($partner, $filters);

        $filename = sprintf(
            'jams_historico_%s_%s.csv',
            date('Ymd_His'),
            $partner?->getCode() ?? 'global',
        );

        $response = new StreamedResponse(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, [
                'ID', 'UUID', 'Status',
                'Nível', 'Nível (label)',
                'Atraso (s)', 'Atraso (min)',
                'Velocidade (km/h)',
                'Extensão (m)', 'Extensão (km)',
                'Duração (min)',
                'Rua', 'Cidade', 'País',
                'Pontos da linha',
                'Coletado em', 'Visto pela última vez',
                'Ativo',
            ], ';');

            foreach ($rows as $r) {
                $level   = (int) $r['level'];
                $delay   = (int) $r['delay'];
                $isBlock = (bool) $r['is_blocked'];
                $isStale = (bool) $r['is_stale'];

                $status = match (true) {
                    $isBlock && $isStale => 'Interdição antiga',
                    $isBlock             => 'Interdição ativa',
                    default              => 'Congestionamento',
                };

                fputcsv($out, [
                    $r['id'],
                    $r['uuid'],
                    $status,
                    $level,
                    $this->labelLevel($level),
                    $delay >= 0 ? $delay : '',
                    $delay >= 0 ? round($delay / 60, 1) : '',
                    $r['speed_kmh'],
                    $r['length'],
                    round(((int) $r['length']) / 1000, 3),
                    (int) ($r['duration_min'] ?? 0),
                    $r['street'],
                    $r['city'],
                    $r['country'],
                    $r['line_points'],
                    $r['collected_at'],
                    $r['last_seen_at'],
                    ((bool) $r['is_active']) ? 'sim' : 'não',
                ], ';');
            }

            fclose($out);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');

        return $response;
    }

    // ──────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────

    private function extractFilters(Request $request): array
    {
        return [
            'date_from'        => trim((string) $request->query->get('date_from', '')),
            'date_to'          => trim((string) $request->query->get('date_to',   '')),
            'level_min'        => max(0, min(5, (int) $request->query->get('level_min', 0))),
            'city'             => trim((string) $request->query->get('city', '')),
            'street'           => trim((string) $request->query->get('street', '')),
            'only_blocked'     => $request->query->getBoolean('only_blocked', false),
            'include_inactive' => $request->query->getBoolean('include_inactive', true),
        ];
    }

    private function labelLevel(int $level): string
    {
        return match ($level) {
            0 => 'Livre', 1 => 'Baixo', 2 => 'Moderado',
            3 => 'Alto',  4 => 'Muito alto', 5 => 'Parado',
            default => 'Nível ' . $level,
        };
    }

    private function resolvePartnerScope(): ?Partner
    {
        /** @var User|null $user */
        $user = $this->getUser();
        if (!$user instanceof User) return null;
        if ($user->isGlobalAdmin()) return null;

        return $user->getPartner();
    }
}
