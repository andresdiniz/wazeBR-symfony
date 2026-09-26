<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Partner;
use App\Entity\User;
use App\Repository\JamRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

#[Route('/jams', name: 'jam_')]
final class JamController extends AbstractController
{
    private const CACHE_TTL = 120;

    public function __construct(
        private readonly TagAwareCacheInterface $tvCache,
        private readonly JamRepository $jamRepository,   // #6 — injetado, não mais via container
    ) {
    }

    // ─────────────────────────────────────────────────────────────────

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response   // #7 — $request real agora recebido
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $partner = $this->resolvePartnerScope();
        $filters = $this->extractFilters($request);     // #7 — usa o $request correto

        return $this->render('jam/index.html.twig', [
            'partner'        => $partner,
            'filters'        => $filters,
            'filter_options' => [
                'cities' => $this->jamRepository->fetchDistinctCities($partner),  // #6
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────

    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id, JamRepository $repository): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $partner = $this->resolvePartnerScope();
        $jam     = $repository->findJamById($id, $partner);

        if ($jam === null) {
            throw new NotFoundHttpException('Congestionamento não encontrado.');
        }

        $nearbyAlerts = $repository->findNearbyAlertsForJam($jam, 30, 300);

        return $this->render('jam/show.html.twig', [
            'partner'       => $partner,
            'jam'           => $jam,
            'nearby_alerts' => $nearbyAlerts,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────

    #[Route('/api/data', name: 'api_data', methods: ['GET'])]
    public function apiData(JamRepository $repository, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $partner = $this->resolvePartnerScope();
        $filters = $this->extractFilters($request);

        // #9 — json_encode seguro: garante string mesmo em edge-cases de charset
        $filterJson = json_encode($filters, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $filterHash = md5($filterJson);

        $cacheTag = $partner ? 'tv_partner_' . $partner->getId() : 'tv_global';
        $cacheKey = 'jam_dashboard_' . md5(
            ($partner?->getId() ?? 'global') . '|' . ($partner?->getCode() ?? '') . '|' . $filterHash
        );

        /** @var array<string,mixed> $payload */
        $payload = $this->tvCache->get(
            $cacheKey,
            function (ItemInterface $item) use ($repository, $partner, $filters, $cacheTag) {
                $item->expiresAfter(self::CACHE_TTL);
                $item->tag([$cacheTag]);

                $data = $repository->getDashboard($partner, $filters);

                $data['blockedActive'] = $repository->attachNearbyAlerts($data['blockedActive'] ?? [], 30, 300);
                $data['blockedStale']  = $repository->attachNearbyAlerts($data['blockedStale']  ?? [], 30, 300);
                $data['topJams']       = $repository->attachNearbyAlerts($data['topJams']       ?? [], 30, 300);

                if (isset($data['map']['jams']) && is_array($data['map']['jams'])) {
                    $data['map']['jams'] = $repository->attachNearbyAlerts($data['map']['jams'], 30, 300);
                }

                return $data;
            }
        );

        $response = $this->json([
            'ok'     => true,
            'data'   => $this->normalizeDatesForJson($payload),
            'filter' => $filters,
        ]);

        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private, max-age=0');
        $response->headers->set('Vary', 'Cookie, Authorization');

        return $response;
    }

    // ─────────────────────────────────────────────────────────────────

    #[Route('/export', name: 'export', methods: ['GET'])]
    public function export(JamRepository $repository, Request $request): StreamedResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $partner  = $this->resolvePartnerScope();
        $filters  = $this->extractFilters($request);
        $rows     = $repository->loadExportRows($partner, $filters);

        $filename = sprintf(
            'jams_%s_%s.csv',
            date('Ymd_His'),
            $partner?->getCode() ?? 'global',
        );

        $response = new StreamedResponse(function () use ($rows) {
            $out = fopen('php://output', 'w');

            // BOM UTF-8 para Excel
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, [
                'ID', 'UUID', 'Status', 'Nível', 'Nível (label)',
                'Atraso (s)', 'Atraso (min)', 'Velocidade (km/h)',
                'Extensão (m)', 'Extensão (km)',
                'Rua', 'Cidade', 'País', 'Pontos da linha',
                'Coletado em', 'Visto pela última vez', 'Idade (min)',
            ], ';');

            $now = time();

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

                $lastSeen = $r['last_seen_at'] ? strtotime((string) $r['last_seen_at']) : null;
                $ageMin   = $lastSeen !== null ? round(($now - $lastSeen) / 60, 1) : null;

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
                    $r['street'],
                    $r['city'],
                    $r['country'],
                    $r['line_points'],   // #11 — garantido no SELECT do repo
                    $r['collected_at'],
                    $r['last_seen_at'],
                    $ageMin,
                ], ';');
            }

            fclose($out);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');

        return $response;
    }

    // ─────────────────────────────────────────────────────────────────
    // Helpers privados
    // ─────────────────────────────────────────────────────────────────

    private function extractFilters(Request $request): array
    {
        return [
            'level_min'    => max(0, min(5, (int) $request->query->get('level_min', 3))),
            'city'         => trim((string) $request->query->get('city', '')),
            'street'       => trim((string) $request->query->get('street', '')),
            'window_hours' => max(1, min(48, (int) $request->query->get('window_hours', 2))),
            'only_blocked' => $request->query->getBoolean('only_blocked', false),
            'hide_stale'   => $request->query->getBoolean('hide_stale', false),
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
        if (!$user instanceof User) {
            return null;
        }
        if ($user->isGlobalAdmin()) {
            return null;
        }

        return $user->getPartner();
    }

    /**
     * #8 — substituído por array_walk_recursive para evitar recursão profunda
     * e o custo de criar arrays intermediários a cada nível.
     */
    private function normalizeDatesForJson(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value instanceof \DateTimeInterface
                ? $value->format(DATE_ATOM)
                : $value;
        }

        array_walk_recursive($value, static function (mixed &$v): void {
            if ($v instanceof \DateTimeInterface) {
                $v = $v->format(DATE_ATOM);
            }
        });

        return $value;
    }
}
