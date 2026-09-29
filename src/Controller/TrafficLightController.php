<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\TrafficLight\TrafficLightCommand;
use App\Entity\Partner;
use App\Entity\TrafficLight;
use App\Form\TrafficLightType;
use App\Repository\TrafficLightRepository;
use App\Repository\TrafficLightSnapshotRepository;
use App\Service\TrafficLight\Exception\TrafficLightException;
use App\Service\TrafficLight\TrafficLightManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[Route('/traffic-lights', name: 'traffic_light_')]
final class TrafficLightController extends AbstractController
{
    public function __construct(
        private readonly TrafficLightRepository $lightRepository,
        private readonly TrafficLightSnapshotRepository $snapshotRepository,
        private readonly TrafficLightManager $manager,
        private readonly EntityManagerInterface $em,
        private readonly HttpClientInterface $httpClient,
    ) {}

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(): Response
    {
        $partner = $this->getPartnerFromUser();

        return $this->render('traffic_lights/list.html.twig', [
            'partner' => $partner,
            'lights'  => $this->lightRepository->findByPartner($partner),
        ]);
    }

    #[Route('/dashboard', name: 'dashboard', methods: ['GET'])]
    public function dashboard(): Response
    {
        return $this->render('traffic_lights/dashboard.html.twig', [
            'partner' => $this->getPartnerFromUser(),
        ]);
    }

    #[Route('/status.json', name: 'status_json', methods: ['GET'])]
    public function statusJson(): JsonResponse
    {
        $partner = $this->getPartnerFromUser();
        $lights  = $this->lightRepository->findByPartner($partner);

        return $this->json(array_map(static fn (TrafficLight $l): array => [
            'id'         => $l->getId(),
            'code'       => $l->getCode(),
            'name'       => $l->getName(),
            'protocol'   => $l->getProtocol(),
            'status'     => $l->getLastReadStatus() ?? 'PENDING',
            'error'      => $l->getLastReadError(),
            'lastReadAt' => $l->getLastReadAt()?->format(\DateTimeInterface::ATOM),
            'latitude'   => $l->getLatitude(),
            'longitude'  => $l->getLongitude(),
        ], $lights));
    }

    // ── Descoberta de controladores SensorThings (Hamburg) ──────────────
    #[Route('/discover/sensorthings', name: 'discover_sensorthings', methods: ['GET'])]
    #[IsGranted(new Expression("is_granted('ROLE_ADMIN') or is_granted('ROLE_TRAFFIC_OPERATOR')"))]
    public function discoverSensorThings(Request $request): JsonResponse
    {
        $base      = rtrim((string) $request->query->get('base', 'https://tld.iot.hamburg.de/v1.1'), '/');
        $service   = (string) $request->query->get('service', 'HH_STA_traffic_lights');
        $layerName = (string) $request->query->get('layer', 'primary_signal');
        $limit     = min(50, max(1, (int) $request->query->get('limit', 10)));

        $filter = sprintf(
            "properties/serviceName eq '%s' and properties/layerName eq '%s'",
            $service,
            $layerName,
        );

        $url = $base . '/Datastreams'
             . '?$filter=' . rawurlencode($filter)
             . '&$top='   . $limit
             . '&$expand=Thing($expand=Locations)'
             . '&$orderby=@iot.id%20asc';

        try {
            $res  = $this->httpClient->request('GET', $url, ['timeout' => 15]);
            $data = $res->toArray(false);
        } catch (\Throwable $e) {
            return $this->json(['ok' => false, 'error' => $e->getMessage()], 502);
        }

        $items = [];
        foreach ($data['value'] ?? [] as $ds) {
            $thing  = $ds['Thing'] ?? [];
            $coords = $this->extractCentroid(
                $thing['Locations'][0]['location']['geometry']['coordinates'] ?? null,
            );

            $items[] = [
                'datastreamId'    => $ds['@iot.id'] ?? null,
                'datastreamName'  => $ds['name'] ?? null,
                'datastreamUrl'   => $ds['@iot.selfLink'] ?? null,
                'description'     => $ds['description'] ?? null,
                'unit'            => $ds['unitOfMeasurement']['symbol'] ?? null,
                'unitDefinition'  => $ds['unitOfMeasurement']['definition'] ?? null,
                'observedArea'    => $ds['observedArea'] ?? null,

                'thingId'         => $thing['@iot.id'] ?? null,
                'thingName'       => $thing['name'] ?? null,
                'thingUrl'        => $thing['@iot.selfLink'] ?? null,

                'latitude'        => $coords['lat'] ?? null,
                'longitude'       => $coords['lng'] ?? null,

                // Metadados úteis extraídos do Thing.properties
                'signalGroupId'   => $ds['properties']['signalGroupID'] ?? null,
                'trafficLightsId' => $thing['properties']['trafficLightsID'] ?? null,
                'connectionId'    => $thing['properties']['connectionID'] ?? null,
                'laneType'        => $thing['properties']['laneType'] ?? null,
                'keywords'        => $thing['properties']['keywords'] ?? [],

                'serviceName'     => $ds['properties']['serviceName'] ?? null,
                'layerName'       => $ds['properties']['layerName'] ?? null,
            ];
        }

        return $this->json([
            'ok'      => true,
            'base'    => $base,
            'service' => $service,
            'layer'   => $layerName,
            'count'   => count($items),
            'items'   => $items,
        ]);
    }

    /**
     * Extrai um ponto representativo (centroide) de uma geometria GeoJSON.
     * Suporta Point, LineString e MultiLineString — o resultado é sempre { lat, lng }.
     *
     * Lembrete: em GeoJSON, a ordem é [longitude, latitude].
     *
     * @param mixed $coordinates
     * @return array{lat:?float,lng:?float}
     */
    private function extractCentroid(mixed $coordinates): array
    {
        $points = [];

        $walk = function (array $node) use (&$walk, &$points): void {
            // Se o primeiro elemento é numérico, é um par [lng, lat]
            if (isset($node[0]) && is_numeric($node[0]) && isset($node[1]) && is_numeric($node[1])) {
                $points[] = [(float) $node[0], (float) $node[1]];
                return;
            }
            // Senão, aprofunda
            foreach ($node as $child) {
                if (is_array($child)) {
                    $walk($child);
                }
            }
        };

        if (is_array($coordinates)) {
            $walk($coordinates);
        }

        if ($points === []) {
            return ['lat' => null, 'lng' => null];
        }

        $sumLng = 0.0;
        $sumLat = 0.0;
        foreach ($points as [$lng, $lat]) {
            $sumLng += $lng;
            $sumLat += $lat;
        }
        $n = count($points);

        return [
            'lat' => round($sumLat / $n, 7),
            'lng' => round($sumLng / $n, 7),
        ];
    }
    
    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function new(Request $request): Response
    {
        $partner = $this->getPartnerFromUser();

        $light = new TrafficLight();
        $light->setPartner($partner);

        $form = $this->createForm(TrafficLightType::class, $light);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (!$this->applyOptionsJson($form->get('optionsJson')->getData(), $light)) {
                $this->addFlash('error', 'JSON inválido no campo de opções.');
            } else {
                $this->em->persist($light);
                $this->em->flush();

                $this->addFlash('success', sprintf('Controlador "%s" criado.', $light->getCode()));
                return $this->redirectToRoute('traffic_light_list');
            }
        }

        return $this->render('traffic_lights/form.html.twig', [
            'partner' => $partner,
            'light'   => $light,
            'form'    => $form,
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function edit(int $id, Request $request): Response
    {
        $partner = $this->getPartnerFromUser();
        $light   = $this->getLightOr404($id, $partner);

        $form = $this->createForm(TrafficLightType::class, $light, [
            'optionsJson' => json_encode(
                $light->getOptions(),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ) ?: '{}',
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (!$this->applyOptionsJson($form->get('optionsJson')->getData(), $light)) {
                $this->addFlash('error', 'JSON inválido no campo de opções.');
            } else {
                $this->em->flush();
                $this->addFlash('success', 'Controlador atualizado.');
                return $this->redirectToRoute('traffic_light_list');
            }
        }

        return $this->render('traffic_lights/form.html.twig', [
            'partner' => $partner,
            'light'   => $light,
            'form'    => $form,
        ]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(int $id, Request $request): Response
    {
        $partner = $this->getPartnerFromUser();
        $light   = $this->getLightOr404($id, $partner);

        if ($this->isCsrfTokenValid('delete_tl_' . $id, (string) $request->request->get('_token'))) {
            $this->em->remove($light);
            $this->em->flush();
            $this->addFlash('success', 'Controlador removido.');
        }

        return $this->redirectToRoute('traffic_light_list');
    }

    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): Response
    {
        $partner = $this->getPartnerFromUser();
        $light   = $this->getLightOr404($id, $partner);

        return $this->render('traffic_lights/show.html.twig', [
            'partner' => $partner,
            'light'   => $light,
            'latest'  => $this->snapshotRepository->findLatestForLight($light),
            'history' => $this->snapshotRepository->findHistoryForLight($light, 50),
        ]);
    }

    #[Route('/{id}/read', name: 'read', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function readNow(int $id): JsonResponse
    {
        $partner = $this->getPartnerFromUser();
        $light   = $this->getLightOr404($id, $partner);

        try {
            $state = $this->manager->readNow($light);
            return $this->json(['ok' => true, 'state' => $state->toArray()]);
        } catch (TrafficLightException $e) {
            return $this->json(['ok' => false, 'error' => $e->getMessage()], 502);
        }
    }

    #[Route('/{id}/command', name: 'command', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(new Expression("is_granted('ROLE_ADMIN') or is_granted('ROLE_TRAFFIC_OPERATOR')"))]
    public function sendCommand(int $id, Request $request): JsonResponse
    {
        $partner = $this->getPartnerFromUser();
        $light   = $this->getLightOr404($id, $partner);

        $payload = json_decode((string) $request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $type    = (string) ($payload['type'] ?? '');
        $data    = (array)  ($payload['payload'] ?? []);
        $reason  = isset($payload['reason']) ? (string) $payload['reason'] : null;

        try {
            $command = new TrafficLightCommand($type, $data, $reason);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['ok' => false, 'error' => $e->getMessage()], 400);
        }

        try {
            $user = $this->getUser();
            $userId = $user !== null && method_exists($user, 'getId') ? (int) $user->getId() : null;
            $ok = $this->manager->write($light, $command, $userId);
            return $this->json(['ok' => $ok]);
        } catch (TrafficLightException $e) {
            return $this->json(['ok' => false, 'error' => $e->getMessage()], 502);
        }
    }

    #[Route('/{id}/control', name: 'control', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted(new Expression("is_granted('ROLE_ADMIN') or is_granted('ROLE_TRAFFIC_OPERATOR')"))]
    public function control(int $id): Response
    {
        $partner = $this->getPartnerFromUser();
        $light   = $this->getLightOr404($id, $partner);

        $latest = $this->snapshotRepository->findLatestForLight($light);

        $commands = [];
        foreach ($this->snapshotRepository->findHistoryForLight($light, 20) as $snap) {
            $state = $snap->getState();
            if (isset($state['command'])) {
                $commands[] = [
                    'at'      => $snap->getReadAt(),
                    'success' => $snap->isSuccess(),
                    'type'    => (string) $state['command'],
                    'payload' => (array) ($state['payload'] ?? []),
                    'reason'  => (string) ($state['reason'] ?? ''),
                ];
            }
        }

        return $this->render('traffic_lights/control.html.twig', [
            'partner'  => $partner,
            'light'    => $light,
            'latest'   => $latest,
            'commands' => $commands,
            'presets'  => [
                'flash'    => ['type' => 'FLASH_YELLOW', 'label' => 'Piscante amarelo', 'icon' => '⚠'],
                'all_red'  => ['type' => 'ALL_RED',      'label' => 'Tudo vermelho',    'icon' => '⛔'],
                'all_dark' => ['type' => 'ALL_DARK',     'label' => 'Apagar tudo',      'icon' => '⏻'],
                'auto'     => ['type' => 'SET_MODE',     'label' => 'Modo automático',  'icon' => '▶', 'payload' => ['mode' => 'AUTOMATIC']],
                'manual'   => ['type' => 'SET_MODE',     'label' => 'Modo manual',      'icon' => '✋', 'payload' => ['mode' => 'MANUAL']],
            ],
        ]);
    }

    #[Route('/{id}/control', name: 'control_send', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(new Expression("is_granted('ROLE_ADMIN') or is_granted('ROLE_TRAFFIC_OPERATOR')"))]
    public function controlSend(int $id, Request $request): JsonResponse
    {
        $partner = $this->getPartnerFromUser();
        $light   = $this->getLightOr404($id, $partner);

        $payload = json_decode((string) $request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $type    = (string) ($payload['type'] ?? '');
        $data    = (array)  ($payload['payload'] ?? []);
        $reason  = isset($payload['reason']) && $payload['reason'] !== ''
            ? (string) $payload['reason']
            : null;

        try {
            $command = new TrafficLightCommand($type, $data, $reason);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['ok' => false, 'error' => $e->getMessage()], 400);
        }

        try {
            $user = $this->getUser();
            $userId = $user !== null && method_exists($user, 'getId') ? (int) $user->getId() : null;
            $ok = $this->manager->write($light, $command, $userId);

            return $this->json([
                'ok'      => $ok,
                'command' => $command->type,
                'payload' => $command->payload,
            ]);
        } catch (TrafficLightException $e) {
            return $this->json(['ok' => false, 'error' => $e->getMessage()], 502);
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function getPartnerFromUser(): Partner
    {
        $user = $this->getUser();
        if ($user === null) {
            throw $this->createAccessDeniedException('Usuário não autenticado.');
        }

        if (!method_exists($user, 'getPartner')) {
            throw $this->createAccessDeniedException('Usuário não possui parceiro vinculado.');
        }

        /** @var Partner|null $partner */
        $partner = $user->getPartner();
        if ($partner === null) {
            throw $this->createAccessDeniedException('Usuário não está vinculado a um parceiro.');
        }

        return $partner;
    }

    private function applyOptionsJson(mixed $json, TrafficLight $light): bool
    {
        if ($json === null || $json === '') {
            $light->setOptions([]);
            return true;
        }

        try {
            $decoded = json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                return false;
            }
            $light->setOptions($decoded);
            return true;
        } catch (\JsonException) {
            return false;
        }
    }

    private function getLightOr404(int $id, Partner $partner): TrafficLight
    {
        $light = $this->lightRepository->find($id);
        if ($light === null || $light->getPartner() !== $partner) {
            throw $this->createNotFoundException('Controlador não encontrado.');
        }
        return $light;
    }
}
