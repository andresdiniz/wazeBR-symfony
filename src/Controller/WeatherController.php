<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Partner;
use App\Entity\User;
use App\Repository\WeatherRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/weather', name: 'weather_')]
final class WeatherController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(WeatherRepository $repository): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $partner = $this->resolvePartnerScope();
        $payload = $repository->getIndex($partner);

        return $this->render('weather/index.html.twig', [
            'kpis'     => $payload['kpis'],
            'stations' => $payload['stations'],
            'partner'  => $partner,
        ]);
    }

    /**
     * Precisa vir ANTES do /{id} — senão "analysis" casa como id.
     */
    #[Route('/analysis', name: 'analysis', methods: ['GET'])]
    public function analysis(Request $request, WeatherRepository $repository): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $partner = $this->resolvePartnerScope();

        $params = [
            'locationId' => (int) $request->query->get('station', 0),
            'variable'   => (string) $request->query->get('variable', 'temperature'),
            'metric'     => (string) $request->query->get('metric', 'route_delay'),
            'days'       => (int) $request->query->get('days', 30),
            'routeId'    => $request->query->get('route') ? (int) $request->query->get('route') : null,
        ];

        // Lista de estações para os selects
        $indexPayload = $repository->getIndex($partner);

        // Se não escolheu estação, mostra a primeira
        if ($params['locationId'] <= 0 && $indexPayload['stations'] !== []) {
            $params['locationId'] = (int) $indexPayload['stations'][0]['id'];
        }

        $analysis = $params['locationId'] > 0
            ? $repository->getAnalysis($partner, $params)
            : null;

        return $this->render('weather/analysis.html.twig', [
            'stations' => $indexPayload['stations'],
            'analysis' => $analysis,
            'params'   => $params,
            'partner'  => $partner,
        ]);
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id, WeatherRepository $repository): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $partner = $this->resolvePartnerScope();
        $detail  = $repository->getDetail($partner, $id);

        if ($detail === null) {
            throw $this->createNotFoundException('Estação não encontrada.');
        }

        return $this->render('weather/show.html.twig', [
            'location'   => $detail['location'],
            'current'    => $detail['current'],
            'stats'      => $detail['stats'],
            'timeline'   => $detail['timeline'],
            'heatmap'    => $detail['heatmap'],
            'byHour'     => $detail['byHour'],
            'byDow'      => $detail['byDow'],
            'rainHourly' => $detail['rainHourly'],
            'recent'     => $detail['recent'],
            'partner'    => $partner,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────

    private function resolvePartnerScope(): ?Partner
    {
        /** @var User|null $user */
        $user = $this->getUser();
        if (!$user instanceof User) return null;
        if ($user->isGlobalAdmin()) return null;
        return $user->getPartner();
    }
}
