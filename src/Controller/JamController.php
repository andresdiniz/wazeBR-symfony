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
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        return $this->render('jam/index.html.twig', [
            'partner'        => $this->resolvePartnerScope(),
            'filters'        => $this->extractFilters(new Request()),
            'filter_options' => [
                'cities' => $this->fetchDistinctCities(),
            ],
        ]);
    }

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

    #[Route('/api/data', name: 'api_data', methods: ['GET'])]
    public function apiData(JamRepository $repository, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $partner = $this->resolvePartnerScope();
        $filters = $this->extractFilters($request);

        $filterHash = md5(json_encode($filters));
        $cacheTag   = $partner ? 'tv_partner_' . $partner->getId() : 'tv_global';
        $cacheKey   = 'jam_dashboard_' . md5(
            ($partner?->getId() ?? 'global') . '|' . ($partner?->getCode() ?? '') . '|' . $filterHash
        );

        /** @var array<string,mixed> $payload */
        $payload = $this->tvCache->get($cacheKey, function (ItemInterface $item) use ($repository, $partner, $filters, $cacheTag) {
            $item->expiresAfter(self::CACHE_TTL);
            $item->tag([$cacheTag]);

            $data = $repository->getDashboard($partner, $filters);

            // Enriquece cada linha com o nº de alertas próximos + lista curta
            $data['blockedActive'] = $repository->attachNearbyAlerts($data['blockedActive'] ?? [], 30, 300);
            $data['blockedStale']  = $repository->attachNearbyAlerts($data['blockedStale']  ?? [], 30, 300);
            $data['topJams']       = $repository->attachNearbyAlerts($data['topJams']       ?? [], 30, 300);

            // Mapa recebe o mesmo enriquecimento (polyline + pinos no mesmo payload)
            if (isset($data['map']['jams']) && is_array($data['map']['jams'])) {
                $data['map']['jams'] = $repository->attachNearbyAlerts($data['map']['jams'], 30, 300);
            }

            return $data;
        });

        $response = $this->json([
            'ok'     => true,
            'data'   => $this->normalizeDatesForJson($payload),
            'filter' => $filters,
        ]);

        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private, max-age=0');
        $response->headers->set('Vary', 'Cookie, Authorization');

        return $response;
    }

    #[Route('/export', name: 'export', methods: ['GET'])]
    public function export(JamRepository $repository, Request $request): StreamedResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $partner = $this->resolvePartnerScope();
        $filters = $this->extractFilters($request);
        $rows    = $repository->loadExportRows($partner, $filters);

        $filename = sprintf(
            'jams_%s_%s.csv',
            date('Ymd_His'),
            $partner?->getCode() ?? 'global',
        );

        $response = new StreamedResponse(function () use ($rows) {
            $out = fopen('php://output', 'w');

            // BOM UTF-8 para Excel abrir acentos corretamente
            fwrite($out, "\xEF\xBB\xBF");

            // Cabeçalho
            fputcsv($out, [
                'ID',
                'UUID',
                'Status',
                'Nível',
                'Nível (label)',
                'Atraso (s)',
                'Atraso (min)',
                'Velocidade (km/h)',
                'Extensão (m)',
                'Extensão (km)',
                'Rua',
                'Cidade',
                'País',
                'Pontos da linha',
                'Coletado em',
                'Visto pela última vez',
                'Idade (min)',
            ], ';');

            $now = time();

            foreach ($rows as $r) {
                $level   = (int) $r['level'];
                $delay   = (int) $r['delay'];
                $isBlock = (bool) $r['is_blocked'];
                $isStale = (bool) $r['is_stale'];

                if ($isBlock && $isStale)      $status = 'Interdição antiga';
                elseif ($isBlock)              $status = 'Interdição ativa';
                else                           $status = 'Congestionamento';

                $lastSeen  = $r['last_seen_at'] ? strtotime((string) $r['last_seen_at']) : null;
                $ageMin    = $lastSeen !== null ? round(($now - $lastSeen) / 60, 1) : null;

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
                    $r['line_points'],
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

    /**
     * Lista distinta de cidades para o <select> do filtro.
     * Cacheado pela tag do parceiro também.
     */
    private function fetchDistinctCities(): array
    {
        $partner = $this->resolvePartnerScope();
        $pf = '';
        $params = [];
        if ($partner !== null) {
            $pf = ' AND partner_id = :pid';
            $params['pid'] = $partner->getId();
        }

        try {
            $rows = $this->getDoctrineConnection()->executeQuery(
                "SELECT city, COUNT(*) AS n
                 FROM waze_jams
                 WHERE is_active = 1
                   AND city IS NOT NULL
                   AND city <> ''
                   {$pf}
                 GROUP BY city
                 ORDER BY n DESC
                 LIMIT 30",
                $params
            )->fetchAllAssociative();

            $out = [];
            foreach ($rows as $r) {
                $out[(string) $r['city']] = (int) $r['n'];
            }
            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    private function getDoctrineConnection(): \Doctrine\DBAL\Connection
    {
        return $this->container->get('doctrine')->getConnection();
    }

    private function labelLevel(int $level): string
    {
        return match ($level) {
            0 => 'Livre', 1 => 'Baixo', 2 => 'Moderado',
            3 => 'Alto', 4 => 'Muito alto', 5 => 'Parado',
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

    private function normalizeDatesForJson(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) return $value->format(DATE_ATOM);
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) $out[$k] = $this->normalizeDatesForJson($v);
            return $out;
        }
        return $value;
    }
}
