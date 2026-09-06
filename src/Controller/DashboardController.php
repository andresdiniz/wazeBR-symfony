<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\CemadenHydroDataRepository;
use App\Repository\CifsEventRepository;
use App\Repository\MonitoredCityRepository;
use App\Repository\MonitoredLinkRepository;
use App\Repository\WazeAlertRepository;
use App\Repository\WazeIrregularityRepository;
use App\Repository\WazeRouteRepository;
use App\Repository\WazeTrafficJamRepository;
use App\Repository\WazeTvtRouteExecutionRepository;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/dashboard')]
class DashboardController extends AbstractController
{
    /**
     * Rótulos exibidos para o nível de congestionamento (Waze usa 0-5).
     * @var array<int, string>
     */
    private const JAM_LEVEL_LABELS = [
        0 => 'Livre',
        1 => 'Leve',
        2 => 'Moderado',
        3 => 'Intenso',
        4 => 'Muito intenso',
        5 => 'Parado',
    ];

    #[Route('', name: 'dashboard_index')]
    #[Route('', name: 'app_dashboard')]
    public function index(
        Request $request,
        TenantContext $tenantContext,
        WazeAlertRepository $alertRepository,
        WazeTrafficJamRepository $jamRepository,
        WazeIrregularityRepository $irregularityRepository,
        WazeRouteRepository $routeRepository,
        MonitoredLinkRepository $monitoredLinkRepository,
        MonitoredCityRepository $monitoredCityRepository,
        CemadenHydroDataRepository $cemadenRepository,
        CifsEventRepository $cifsRepository,
        WazeTvtRouteExecutionRepository $tvtExecutionRepository,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        // Resolução do parceiro (tenant) ativo — segue PRODUCT_RULES §2:
        // usuário comum sempre vê o próprio parceiro; admin usa o que
        // estiver escolhido na sessão (ou o primeiro ativo, por padrão).
        $partner = $tenantContext->getPartner();
        $partnerLabel = $partner ? $partner->getName() : 'Sem parceiro';

        $isSuperAdmin = $user->isGlobalAdmin();
        $isAdmin = $this->isGranted('ROLE_ADMIN') || $this->isGranted('ROLE_PARTNER_ADMIN');
        $showPartnerDashboard = $partner !== null;

        $periods = [
            'today' => ['label' => 'Últimas 24 horas', 'hours' => 24],
            'week' => ['label' => 'Últimos 7 dias', 'hours' => 168],
            'month' => ['label' => 'Últimos 30 dias', 'hours' => 720],
        ];
        $periodKey = $request->query->get('period', 'today');
        if (!isset($periods[$periodKey])) {
            $periodKey = 'today';
        }

        $now = new \DateTimeImmutable();
        $periodFrom = $now->modify(sprintf('-%d hours', $periods[$periodKey]['hours']));
        $last24hFrom = $now->modify('-24 hours');

        if ($partner === null) {
            // Nenhum parceiro resolvido (ex.: nenhum parceiro ativo cadastrado
            // ainda) — não há o que consultar, evita erro em vez de travar.
            return $this->render('dashboard/index.html.twig', [
                'trafficJamCount' => 0,
                'alertCount' => 0,
                'recentTrafficJams' => [],
                'recentJams' => [],
                'recentAlerts' => [],
                'partner' => null,
                'partnerLabel' => $partnerLabel,
                'periods' => $periods,
                'periodKey' => $periodKey,
                'partnerStats' => [
                    'jams' => 0, 'alerts' => 0, 'irregularities' => 0,
                    'routes' => 0, 'hydroData' => 0, 'monitoredLinks' => 0,
                    'cifsEvents' => 0, 'executions' => 0,
                ],
                'hero' => $this->emptyHero(),
                'mapJams' => [],
                'mapAlerts' => [],
                'mapJamsTruncated' => false,
                'mapAlertsTruncated' => false,
                'alertsBySubtype' => [],
                'jamsByLevel' => [],
                'totalAlertsInPeriod' => 0,
                'topStreets' => [],
                'isSuperAdmin' => $isSuperAdmin,
                'isAdmin' => $isAdmin,
                'showPartnerDashboard' => false,
                'superAdminData' => [
                    'totalPartners' => 0,
                    'totalUsers' => 0,
                    'totalAlerts' => 0,
                    'totalJams' => 0,
                    'totalLinks' => 0,
                    'totalCemaden' => 0,
                    'partnerStats' => [],
                    'storageUsed' => 0,
                    'storageLimit' => 1,
                    'activeCrons' => 0,
                    'totalCrons' => 1,
                    'lastActivity' => $now,
                ],
            ]);
        }

        // ── Métricas "no período" selecionado (usadas nos cards de stats) ──
        $jamsInPeriod = $jamRepository->countInPeriod($partner, $periodFrom, $now);
        $alertsInPeriod = $alertRepository->countInPeriod($partner, $periodFrom, $now);
        $irregularitiesInPeriod = $irregularityRepository->countInPeriod($partner, $periodFrom, $now);
        $cifsInPeriod = $cifsRepository->countInPeriod($partner, $periodFrom, $now);

        $partnerStats = [
            'jams' => $jamsInPeriod,
            'alerts' => $alertsInPeriod,
            'irregularities' => $irregularitiesInPeriod,
            'routes' => $routeRepository->countRoutesByPartner($partner),
            'hydroData' => $cemadenRepository->countByPartner($partner),
            'monitoredLinks' => $monitoredLinkRepository->countByPartner($partner),
            'cifsEvents' => $cifsInPeriod,
            'executions' => $tvtExecutionRepository->countInPeriod($periodFrom, $now),
        ];

        // ── Totais gerais + últimas 24h (usados no bloco "Ao vivo") ──
        $jamsTotal = $jamRepository->countByPartner($partner);
        $alertsTotal = $alertRepository->countByPartner($partner);
        $jamsLast24h = $jamRepository->countInPeriod($partner, $last24hFrom, $now);
        $alertsLast24h = $alertRepository->countInPeriod($partner, $last24hFrom, $now);

        $liveSnapshot = $jamRepository->liveSnapshot($partner, 3);
        $jamsLiveMaxLevel = $liveSnapshot['maxLevel'] ?? 0;

        $hero = [
            'title' => 'Dashboard',
            'subtitle' => 'Visão geral da plataforma',
            'jamsTotal' => $jamsTotal,
            'alertsTotal' => $alertsTotal,
            'jamsLast24h' => $jamsLast24h,
            'alertsLast24h' => $alertsLast24h,
            'jamsLiveTotal' => $liveSnapshot['total'] ?? 0,
            'jamsLiveMaxLevel' => $jamsLiveMaxLevel,
            'jamsLiveMaxLevelLabel' => self::JAM_LEVEL_LABELS[$jamsLiveMaxLevel] ?? 'Sem jams ativos',
            'routesMonitored' => $partnerStats['routes'],
            'monitoredLinks' => $partnerStats['monitoredLinks'],
            'monitoredCities' => $monitoredCityRepository->countByPartner($partner),
            'cemadenReadings' => $partnerStats['hydroData'],
            'cemadenCities' => $cemadenRepository->countDistinctMunicipalities($partner),
            'tvtExecutions' => $partnerStats['executions'],
        ];

        // ── Gráficos ──
        $alertsBySubtype = $alertRepository->countBySubtypeInPeriod($partner, $periodFrom, $now);
        $jamsByLevelIndexed = $jamRepository->countByLevelInPeriod($partner, $periodFrom, $now);
        $jamsByLevel = [];
        foreach ($jamsByLevelIndexed as $level => $count) {
            if ($count > 0) {
                $jamsByLevel[] = ['level' => $level, 'count' => $count];
            }
        }
        $totalAlertsInPeriod = array_sum(array_column($alertsBySubtype, 'count'));

        // ── Ranking de ruas (adapta occurrences -> count, contrato do template) ──
        $topStreetsRaw = $jamRepository->topStreetsInPeriod($partner, $periodFrom, $now, 10);
        $topStreets = array_map(static fn (array $row): array => [
            'street' => $row['street'],
            'count' => $row['occurrences'],
        ], $topStreetsRaw);

        // ── Mapa (últimos 100 registros do período) ──
        $mapJamsRaw = $jamRepository->findForMapInPeriod($partner, $periodFrom, $now, 100);
        $mapAlertsRaw = $alertRepository->findForMapFiltered($partner, [], 100);

        $mapJams = array_map(static function (array $jam): array {
            $point = $jam['line'][0] ?? null;
            return [
                'lat' => $point['y'] ?? null,
                'lng' => $point['x'] ?? null,
                'street' => $jam['street'],
                'city' => $jam['city'],
                'level' => $jam['level'],
            ];
        }, $mapJamsRaw);

        $mapAlerts = array_map(static fn (array $alert): array => [
            'lat' => $alert['latitude'],
            'lng' => $alert['longitude'],
            'type' => $alert['subtype'],
            'street' => $alert['street'],
        ], $mapAlertsRaw);

        // ── Listas de recentes (arrays simples — evita acoplar o template
        // a nomes de getter da entidade) ──
        $recentJamEntities = $jamRepository->findRecentByPartner($partner, 5);
        $recentAlertEntities = $alertRepository->findRecentByPartner($partner, 5);

        $recentJams = array_map(static fn ($jam): array => [
            'streetName' => $jam->getStreet(),
            'city' => $jam->getCity(),
            'level' => $jam->getLevel(),
            'createdAt' => $jam->getCreatedAt(),
        ], $recentJamEntities);

        $recentAlerts = array_map(static fn ($alert): array => [
            'subtype' => $alert->getSubtype(),
            'streetName' => $alert->getStreet(),
            'reportedAt' => $alert->getCreatedAt(),
        ], $recentAlertEntities);

        return $this->render('dashboard/index.html.twig', [
            'trafficJamCount' => $jamsInPeriod,
            'alertCount' => $alertsInPeriod,
            'recentTrafficJams' => $recentJamEntities,
            'recentJams' => $recentJams,
            'recentAlerts' => $recentAlerts,
            'partner' => $partner,
            'partnerLabel' => $partnerLabel,
            'periods' => $periods,
            'periodKey' => $periodKey,
            'partnerStats' => $partnerStats,
            'hero' => $hero,
            'mapJams' => $mapJams,
            'mapAlerts' => $mapAlerts,
            'mapJamsTruncated' => count($mapJamsRaw) >= 100,
            'mapAlertsTruncated' => count($mapAlertsRaw) >= 100,
            'alertsBySubtype' => $alertsBySubtype,
            'jamsByLevel' => $jamsByLevel,
            'totalAlertsInPeriod' => $totalAlertsInPeriod,
            'topStreets' => $topStreets,
            'isSuperAdmin' => $isSuperAdmin,
            'isAdmin' => $isAdmin,
            'showPartnerDashboard' => $showPartnerDashboard,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyHero(): array
    {
        return [
            'title' => 'Dashboard',
            'subtitle' => 'Visão geral da plataforma',
            'jamsTotal' => 0,
            'alertsTotal' => 0,
            'jamsLast24h' => 0,
            'alertsLast24h' => 0,
            'jamsLiveTotal' => 0,
            'jamsLiveMaxLevel' => 0,
            'jamsLiveMaxLevelLabel' => 'Sem jams ativos',
            'routesMonitored' => 0,
            'monitoredLinks' => 0,
            'monitoredCities' => 0,
            'cemadenReadings' => 0,
            'cemadenCities' => 0,
            'tvtExecutions' => 0,
        ];
    }
}
