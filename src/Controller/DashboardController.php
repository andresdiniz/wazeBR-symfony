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

        // Label amigavel do parceiro (pode ser ajustado depois se tiver "displayName")
        $partnerLabel = $partner->getName() ?: $partner->getId();

        // TODO: aqui entram os outros agregados do dashboard (mantidos como estao no repo original)

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

        return $this->render('dashboard/index.html.twig', [
            'partnerLabel' => $partnerLabel,
            'recentAlerts' => $recentAlerts,
        ]);
    }
}
