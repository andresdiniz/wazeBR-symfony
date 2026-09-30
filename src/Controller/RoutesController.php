<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use App\Entity\Partner;
use App\Entity\User;
use App\Repository\RoutesRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use App\Repository\RouteDetailRepository;
use App\Repository\RouteCompareRepository;

#[Route('/routes', name: 'routes_')]
final class RoutesController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, RoutesRepository $repository): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $partner = $this->resolvePartnerScope();
        $filters = $this->extractFilters($request);
        $payload = $repository->getRoutesDashboard($partner, $filters);

        return $this->render('routes/index.html.twig', [
            'routes'   => $payload['routes'],
            'stats'    => $payload['stats'],
            'map'      => $payload['map'],
            'filters'  => $filters,
            'partner'  => $partner,
        ]);
    }

    #[Route('/api/data', name: 'api_data', methods: ['GET'])]
    public function apiData(Request $request, RoutesRepository $repository): JsonResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $partner = $this->resolvePartnerScope();
        $filters = $this->extractFilters($request);
        $payload = $repository->getRoutesDashboard($partner, $filters);

        return $this->json([
            'ok'           => true,
            'generated_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'filters'      => $filters,
            'data'         => $this->normalizeDatesForJson($payload),
        ]);
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id, RouteDetailRepository $repository): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $partner = $this->resolvePartnerScope();
        $detail  = $repository->getRouteDetail($partner, $id);

        if ($detail === null) {
            throw $this->createNotFoundException('Rota não encontrada.');
        }

        return $this->render('routes/show.html.twig', [
            'route'             => $detail['route'],
            'current'           => $detail['current'],
            'stats'             => $detail['stats'],
            'heatmap'           => $detail['heatmap'],
            'timeline'          => $detail['timeline'],
            'byHour'            => $detail['byHour'],
            'byDow'             => $detail['byDow'],
            'jamDistribution'   => $detail['jamDistribution'],
            'topSubRoutes'      => $detail['topSubRoutes'],
            'irregularities'    => $detail['irregularities'],
            'nearbyAlerts'      => $detail['nearbyAlerts']     ?? [],
            'routePolyline'     => $detail['routePolyline']    ?? [],
            'subRoutesPolyline' => $detail['subRoutesPolyline']?? [],
            'partner'           => $partner,
        ]);
    }

    // ─── Export CSV ───────────────────────────────────────────────────────────

    /**
     * Exporta os dados da rota em CSV.
     *
     * Tipos suportados via query string `?type=`:
     *   timeline      — evolução do atraso (últimos 7 dias)  [padrão]
     *   by_hour       — média por hora do dia (30 dias)
     *   by_dow        — média por dia da semana (30 dias)
     *   occurrences   — alertas Waze + irregularidades TVT próximos
     *   subroutes     — trechos problemáticos com métricas
     *
     * Exemplos:
     *   /routes/42/export.csv
     *   /routes/42/export.csv?type=occurrences
     */
    #[Route('/{id}/export.csv', name: 'export_csv', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function exportCsv(int $id, Request $request, RouteDetailRepository $repository): StreamedResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $partner = $this->resolvePartnerScope();
        $detail  = $repository->getRouteDetail($partner, $id);

        if ($detail === null) {
            throw $this->createNotFoundException('Rota não encontrada.');
        }

        $type      = $request->query->get('type', 'timeline');
        $routeName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $detail['route']['name'] ?? "rota_{$id}");
        $filename  = "rota_{$routeName}_{$type}_" . date('Ymd') . '.csv';

        $response = new StreamedResponse(function () use ($detail, $type): void {
            $out = fopen('php://output', 'w');
            // BOM UTF-8 para Excel abrir corretamente
            fwrite($out, "\xEF\xBB\xBF");

            match ($type) {
                'by_hour'     => $this->writeCsvByHour($out, $detail),
                'by_dow'      => $this->writeCsvByDow($out, $detail),
                'occurrences' => $this->writeCsvOccurrences($out, $detail),
                'subroutes'   => $this->writeCsvSubRoutes($out, $detail),
                default       => $this->writeCsvTimeline($out, $detail),
            };

            fclose($out);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', "attachment; filename=\"{$filename}\"");
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate');

        return $response;
    }

    // ─── Writers CSV ──────────────────────────────────────────────────────────

    /** @param resource $out */
    private function writeCsvTimeline($out, array $detail): void
    {
        $route    = $detail['route'];
        $timeline = $detail['timeline'] ?? [];

        // Metadados no topo
        fputcsv($out, ['# Rota', $route['name'] ?? ''], ';');
        fputcsv($out, ['# Período', 'Últimos 7 dias'], ';');
        fputcsv($out, ['# Gerado em', (new \DateTimeImmutable())->format('d/m/Y H:i')], ';');
        fputcsv($out, [], ';');

        // Cabeçalho
        fputcsv($out, [
            'Data/Hora (BRT)',
            'Atraso médio (s)',
            '% vs histórico',
            'Amostras',
        ], ';');

        foreach ($timeline as $p) {
            // Converte UTC→BRT se a coluna time vier em UTC
            $time = $p['time'] ?? '';
            try {
                $dt  = new \DateTimeImmutable($time, new \DateTimeZone('UTC'));
                $brt = $dt->setTimezone(new \DateTimeZone('America/Sao_Paulo'))->format('d/m/Y H:i');
            } catch (\Throwable) {
                $brt = $time;
            }

            $ratio = $p['avgRatio'] !== null ? round($p['avgRatio'] * 100, 1) : '';

            fputcsv($out, [
                $brt,
                $p['avgDelay'] !== null ? round((float) $p['avgDelay'], 1) : '',
                $ratio !== '' ? "{$ratio}%" : '',
                $p['count'] ?? '',
            ], ';');
        }
    }

    /** @param resource $out */
    private function writeCsvByHour($out, array $detail): void
    {
        $route   = $detail['route'];
        $byHour  = $detail['byHour'] ?? [];

        fputcsv($out, ['# Rota', $route['name'] ?? ''], ';');
        fputcsv($out, ['# Período', 'Últimos 30 dias'], ';');
        fputcsv($out, ['# Gerado em', (new \DateTimeImmutable())->format('d/m/Y H:i')], ';');
        fputcsv($out, [], ';');

        fputcsv($out, ['Hora', 'Atraso médio (s)', '% vs histórico', 'Amostras'], ';');

        foreach ($byHour as $p) {
            $ratio = $p['avgRatio'] !== null ? round($p['avgRatio'] * 100, 1) . '%' : '';
            fputcsv($out, [
                sprintf('%02dh', (int) $p['hour']),
                $p['avgDelay'] !== null ? round((float) $p['avgDelay'], 1) : '',
                $ratio,
                $p['count'] ?? '',
            ], ';');
        }
    }

    /** @param resource $out */
    private function writeCsvByDow($out, array $detail): void
    {
        $route  = $detail['route'];
        $byDow  = $detail['byDow'] ?? [];
        $labels = ['Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado', 'Domingo'];

        fputcsv($out, ['# Rota', $route['name'] ?? ''], ';');
        fputcsv($out, ['# Período', 'Últimos 30 dias'], ';');
        fputcsv($out, ['# Gerado em', (new \DateTimeImmutable())->format('d/m/Y H:i')], ';');
        fputcsv($out, [], ';');

        fputcsv($out, ['Dia da semana', 'Atraso médio (s)', '% vs histórico', 'Amostras'], ';');

        foreach ($byDow as $p) {
            $ratio = $p['avgRatio'] !== null ? round($p['avgRatio'] * 100, 1) . '%' : '';
            fputcsv($out, [
                $labels[(int) $p['dow']] ?? "Dia {$p['dow']}",
                $p['avgDelay'] !== null ? round((float) $p['avgDelay'], 1) : '',
                $ratio,
                $p['count'] ?? '',
            ], ';');
        }
    }

    /** @param resource $out */
    private function writeCsvOccurrences($out, array $detail): void
    {
        $route         = $detail['route'];
        $nearbyAlerts  = $detail['nearbyAlerts']   ?? [];
        $irregularities = $detail['irregularities'] ?? [];

        fputcsv($out, ['# Rota', $route['name'] ?? ''], ';');
        fputcsv($out, ['# Período', 'Últimos 7 dias · até 60 m do traçado'], ';');
        fputcsv($out, ['# Gerado em', (new \DateTimeImmutable())->format('d/m/Y H:i')], ';');
        fputcsv($out, [], ';');

        fputcsv($out, [
            'Fonte',
            'Tipo',
            'Subtipo',
            'Via',
            'Cidade',
            'Severidade',
            'Distância do traçado (m)',
            'Data/Hora (BRT)',
        ], ';');

        foreach ($nearbyAlerts as $a) {
            $dt = '';
            if (!empty($a['pubDateTime'])) {
                try {
                    $dt = (new \DateTimeImmutable($a['pubDateTime'], new \DateTimeZone('UTC')))
                        ->setTimezone(new \DateTimeZone('America/Sao_Paulo'))
                        ->format('d/m/Y H:i');
                } catch (\Throwable) {}
            }
            fputcsv($out, [
                'Waze',
                $a['typeLabel'] ?? $a['type'] ?? '',
                $a['subtype'] ?? '',
                $a['street']  ?? '',
                $a['city']    ?? '',
                '',
                $a['distanceMeters'] ?? '',
                $dt,
            ], ';');
        }

        foreach ($irregularities as $irr) {
            $dt = '';
            if (!empty($irr['reportedAt'])) {
                try {
                    $dt = (new \DateTimeImmutable($irr['reportedAt']))
                        ->setTimezone(new \DateTimeZone('America/Sao_Paulo'))
                        ->format('d/m/Y H:i');
                } catch (\Throwable) {}
            }
            fputcsv($out, [
                'TVT',
                $irr['type']    ?? 'Irregularidade',
                $irr['subtype'] ?? '',
                $irr['street']  ?? '',
                $irr['city']    ?? '',
                $irr['severity'] ?? '',
                '',
                $dt,
            ], ';');
        }
    }

    /** @param resource $out */
    private function writeCsvSubRoutes($out, array $detail): void
    {
        $route      = $detail['route'];
        $subRoutes  = $detail['topSubRoutes'] ?? [];

        fputcsv($out, ['# Rota', $route['name'] ?? ''], ';');
        fputcsv($out, ['# Gerado em', (new \DateTimeImmutable())->format('d/m/Y H:i')], ';');
        fputcsv($out, [], ';');

        fputcsv($out, [
            'Trecho',
            'De',
            'Até',
            'Atraso (s)',
            '% vs histórico',
            'Tempo histórico (s)',
            'Nível de jam',
            'Extensão (km)',
        ], ';');

        foreach ($subRoutes as $sub) {
            $ratio = $sub['delayRatio'] !== null ? round($sub['delayRatio'] * 100, 1) . '%' : '';
            $km    = $sub['lengthMeters'] ? round($sub['lengthMeters'] / 1000, 2) : '';
            fputcsv($out, [
                $sub['name']         ?? '',
                $sub['from']         ?? '',
                $sub['to']           ?? '',
                $sub['delaySeconds'] ?? '',
                $ratio,
                $sub['historicTime'] !== null ? round((float) $sub['historicTime'], 0) : '',
                $sub['jamLevel']     ?? '',
                $km,
            ], ';');
        }
    }

    // ─────────────────────────────────────────────────────────────────────────

    #[Route('/compare', name: 'compare', methods: ['GET'])]
    public function compare(
        Request $request,
        RouteCompareRepository $repository,
        Connection $connection,
    ): Response {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $partner = $this->resolvePartnerScope();
        $aId = (int) $request->query->get('a', 0);
        $bId = (int) $request->query->get('b', 0);

        $allRoutes = $this->getRoutesList($connection, $partner);

        if ($aId <= 0 || $bId <= 0 || $aId === $bId) {
            return $this->render('routes/compare.html.twig', [
                'routes'    => $allRoutes,
                'compare'   => null,
                'aId'       => $aId,
                'bId'       => $bId,
                'partner'   => $partner,
            ]);
        }

        $compare = $repository->compare($partner, $aId, $bId);
        if ($compare === null) {
            throw $this->createNotFoundException('Rota não encontrada.');
        }

        return $this->render('routes/compare.html.twig', [
            'routes'  => $allRoutes,
            'compare' => $compare,
            'aId'     => $aId,
            'bId'     => $bId,
            'partner' => $partner,
        ]);
    }

    /** @return list<array{id:int,name:string,from:?string,to:?string}> */
    private function getRoutesList(Connection $connection, ?Partner $partner): array
    {
        $params = [];
        $pf = '';
        if ($partner !== null) {
            $pf = ' AND partner_id = :pid';
            $params['pid'] = $partner->getId();
        }

        $rows = $connection->executeQuery(
            "SELECT id, name, from_name, to_name
             FROM waze_tvt_route
             WHERE is_active = 1 {$pf}
             ORDER BY name ASC",
            $params
        )->fetchAllAssociative();

        return array_map(static fn ($r) => [
            'id'   => (int) $r['id'],
            'name' => (string) ($r['name'] ?: 'Rota #'.$r['id']),
            'from' => $r['from_name'],
            'to'   => $r['to_name'],
        ], $rows);
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function resolvePartnerScope(): ?Partner
    {
        /** @var User|null $user */
        $user = $this->getUser();
        if (!$user instanceof User) return null;
        if ($user->isGlobalAdmin()) return null;
        return $user->getPartner();
    }

    /** @return array{query:string,level:string} */
    private function extractFilters(Request $request): array
    {
        return [
            'query' => trim((string) $request->query->get('q', '')),
            'level' => (string) $request->query->get('level', 'all'),
        ];
    }

    private function normalizeDatesForJson(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = $this->normalizeDatesForJson($v);
            }
            return $out;
        }
        return $value;
    }
}
