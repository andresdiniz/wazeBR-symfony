<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\PartnerRepository;
use App\Repository\WazeAlertRepository;
use App\Repository\WazeTrafficJamRepository;
use App\Repository\WazeTvtRouteRepository;
use App\Repository\MonitoredLinkRepository;
use App\Repository\WazeIrregularityRepository;
use App\Repository\CifsEventRepository;
use App\Repository\WazeAlertTypeRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/dashboard')]
#[IsGranted('ROLE_USER')]
class DashboardController extends AbstractController
{
    public function __construct(
        private PartnerRepository $partnerRepo,
        private WazeAlertRepository $alertRepo,
        private WazeTrafficJamRepository $jamRepo,
        private WazeTvtRouteRepository $tvtRouteRepo,
        private MonitoredLinkRepository $linkRepo,
        private WazeIrregularityRepository $irregRepo,
        private CifsEventRepository $cifsRepo,
        private WazeAlertTypeRepository $alertTypeRepo,
    ) {}

    #[Route('/', name: 'dashboard_index', methods: ['GET'])]
    public function index(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $partner = $user?->getPartner();

        if (!$partner) {
            return new Response('<div class="p-5 text-center">Usu&aacute;rio sem parceiro vinculado.</div>', 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        }

        $partnerId = $partner->getId();

        // Label amigavel do parceiro
        $partnerLabel = $partner->getName() ?: $partnerId;

        // --- Stats calculados aqui (sem getPartnerStats) ---

        $alertsCount = (int)$this->alertRepo->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.partner = :partnerId')
            ->setParameter('partnerId', $partnerId)
            ->getQuery()
            ->getSingleScalarResult();

        $jamsCount = (int)$this->jamRepo->createQueryBuilder('j')
            ->select('COUNT(j.id)')
            ->where('j.partner = :partnerId')
            ->setParameter('partnerId', $partnerId)
            ->getQuery()
            ->getSingleScalarResult();

        $tvtRoutesCount = (int)$this->tvtRouteRepo->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.partner = :partnerId')
            ->setParameter('partnerId', $partnerId)
            ->getQuery()
            ->getSingleScalarResult();

        $monitoredLinksCount = (int)$this->linkRepo->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->where('l.partner = :partnerId')
            ->setParameter('partnerId', $partnerId)
            ->getQuery()
            ->getSingleScalarResult();

        $irregularitiesCount = (int)$this->irregRepo->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->where('i.partner = :partnerId')
            ->setParameter('partnerId', $partnerId)
            ->getQuery()
            ->getSingleScalarResult();

        $cifsEventsCount = (int)$this->cifsRepo->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->where('e.partner = :partnerId')
            ->setParameter('partnerId', $partnerId)
            ->getQuery()
            ->getSingleScalarResult();

        // --- Agregados usados no template ---

        $partnerStats = [
            'alerts' => $alertsCount,
            'jams' => $jamsCount,
            'tvtRoutes' => $tvtRoutesCount,
            'monitoredLinks' => $monitoredLinksCount,
            'irregularities' => $irregularitiesCount,
            'cifsEvents' => $cifsEventsCount,
        ];

        $tvtRoutesCountForCard = $tvtRoutesCount;
        $monitoredLinksCountForCard = $monitoredLinksCount;

        $irregularities = $this->irregRepo->findBy(['partner' => $partner], ['createdAt' => 'DESC'], 10);
        $cifsEvents = $this->cifsRepo->findBy(['partner' => $partner], ['createdAt' => 'DESC'], 10);
        $alertTypes = $this->alertTypeRepo->findAll();

        // Recent alerts (ultimos 10) — formata pubMillis como data
        $recentAlertsRaw = $this->alertRepo->createQueryBuilder('a')
            ->select('a.id, a.type, a.subtype, a.city, a.street, a.pubMillis')
            ->orderBy('a.pubMillis', 'DESC')
            ->setMaxResults(10)
            ->getQuery()
            ->getArrayResult();

        $recentAlerts = array_map(function (array $r) {
            $pubMillis = (int)($r['pubMillis'] ?? 0);
            $pubAt = null;
            if ($pubMillis > 0) {
                $dt = (new \DateTimeImmutable())->setTimestamp((int)floor($pubMillis / 1000));
                $pubAt = $dt->format('d/m H:i');
            }

            return [
                'id' => (int)$r['id'],
                'type' => $r['type'],
                'subtype' => $r['subtype'],
                'city' => $r['city'],
                'street' => $r['street'],
                'pubMillis' => $pubMillis,
                'pubAt' => $pubAt,
            ];
        }, $recentAlertsRaw);

        // Recent jams (ultimos 10) — formata pubMillis como data
        $recentJamsRaw = $this->jamRepo->createQueryBuilder('j')
            ->select('j.id, j.street, j.city, j.level, j.length, j.delay, j.speed, j.type, j.turnType, j.pubMillis')
            ->orderBy('j.pubMillis', 'DESC')
            ->setMaxResults(10)
            ->getQuery()
            ->getArrayResult();

        $recentJams = array_map(function (array $r) {
            $pubMillis = (int)($r['pubMillis'] ?? 0);
            $pubAt = null;
            if ($pubMillis > 0) {
                $dt = (new \DateTimeImmutable())->setTimestamp((int)floor($pubMillis / 1000));
                $pubAt = $dt->format('d/m H:i');
            }

            return [
                'id'        => (int)$r['id'],
                'street'    => $r['street'],
                'city'      => $r['city'],
                'level'     => (int)($r['level'] ?? 0),
                'length'    => (int)($r['length'] ?? 0),
                'delay'     => (int)($r['delay'] ?? 0),
                'speed'     => (int)($r['speed'] ?? 0),
                'type'      => $r['type'],
                'turnType'  => $r['turnType'],
                'pubMillis' => $pubMillis,
                'pubAt'     => $pubAt,
            ];
        }, $recentJamsRaw);

        return $this->render('dashboard/index.html.twig', [
            'partnerLabel' => $partnerLabel,
            'partnerStats' => $partnerStats,
            'tvtRoutesCount' => $tvtRoutesCountForCard,
            'monitoredLinksCount' => $monitoredLinksCountForCard,
            'irregularities' => $irregularities,
            'cifsEvents' => $cifsEvents,
            'alertTypes' => $alertTypes,
            'recentAlerts' => $recentAlerts,
            'recentJams' => $recentJams,
        ]);
    }
}
