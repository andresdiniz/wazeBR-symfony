<?php

namespace App\Controller;

use App\Entity\WazeTvtRouteExecution;
use App\Repository\WazeTvtRouteExecutionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class DashboardController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WazeTvtRouteExecutionRepository $routeExecutionRepository
    ) {
    }

    #[Route('/dashboard', name: 'dashboard_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('app_login');
        }

        $partner = $user->getPartner();
        $partnerLabel = $partner ? ($partner->getName() ?? 'Parceiro') : 'Sem parceiro';

        // Query temporaria: conta todas as execucoes (sem JOIN para waze_tvt_route)
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('COUNT(e.id)')
            ->from(WazeTvtRouteExecution::class, 'e');

        $count = $qb->getQuery()->getSingleScalarResult();

        // Periodos disponiveis
        $periods = [
            '2_week' => ['label' => 'Úĺtimas 2 semanas'],
            '7_days' => ['label' => 'Úĺtimos 7 dias'],
            '24_hours' => ['label' => 'Úĺtimas 24 horas'],
        ];
        $periodKey = $request->get('period', '2_week');

        // Calcula periodo (valores temporarios - agora menos X conforme periodo)
        $now = new \DateTimeImmutable();
        $periodFrom = $now->modify('-2 weeks');
        $periodTo = $now;

        // Stats do parceiro (valores temporarios zerados)
        $partnerStats = [
            'alerts' => 0,
            'jams' => 0,
            'routes' => 0,
            'tvtRoutes' => 0,
            'monitoredLinks' => 0,
            'irregularities' => 0,
            'cifsEvents' => 0,
            'executions' => (int) ($count[1] ?? 0),
        ];

        // Hero stats (valores temporarios zerados)
        $hero = [
            'alertsLast24h' => 0,
            'alertsTotal' => 0,
            'jamsLast24h' => 0,
            'jamsTotal' => 0,
            'jamsLiveTotal' => 0,
            'jamsLiveMaxLevel' => 0,
            'jamsLiveMaxLevelLabel' => null,
            'routesMonitored' => 0,
            'monitoredLinks' => 0,
            'monitoredCities' => 0,
            'cemadenReadings' => 0,
            'cemadenCities' => 0,
        ];

        // Period stats (valores temporarios zerados)
        $periodStats = [
            'alerts' => 0,
            'jams' => 0,
            'routes' => 0,
            'tvtRoutes' => 0,
            'monitoredLinks' => 0,
            'irregularities' => 0,
            'cifsEvents' => 0,
            'executions' => (int) ($count[1] ?? 0),
        ];

        // Dados para graficos (valores temporarios zerados)
        $totalAlertsInPeriod = 0;
        $alertsBySubtype = [];
        $topStreets = [];

        return $this->render('dashboard/index.html.twig', [
            'count' => $count,
            'partnerLabel' => $partnerLabel,
            'periods' => $periods,
            'periodKey' => $periodKey,
            'periodFrom' => $periodFrom,
            'periodTo' => $periodTo,
            'partnerStats' => $partnerStats,
            'hero' => $hero,
            'periodStats' => $periodStats,
            'totalAlertsInPeriod' => $totalAlertsInPeriod,
            'alertsBySubtype' => $alertsBySubtype,
            'topStreets' => $topStreets,
        ]);
    }
}
