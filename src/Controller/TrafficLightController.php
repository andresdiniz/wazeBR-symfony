<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\TrafficLight\TrafficLightCommand;
use App\Entity\Partner;
use App\Entity\TrafficLight;
use App\Repository\TrafficLightRepository;
use App\Repository\TrafficLightSnapshotRepository;
use App\Repository\PartnerRepository;
use App\Service\TrafficLight\Exception\TrafficLightException;
use App\Service\TrafficLight\TrafficLightManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/partner-feed/{code}/traffic-lights', name: 'partner_feed_traffic_light_')]
final class TrafficLightController extends AbstractController
{
    public function __construct(
        private readonly PartnerRepository $partnerRepository,
        private readonly TrafficLightRepository $lightRepository,
        private readonly TrafficLightSnapshotRepository $snapshotRepository,
        private readonly TrafficLightManager $manager,
    ) {}

    #[Route('/', name: 'list', methods: ['GET'])]
    public function list(string $code): Response
    {
        $partner = $this->getPartnerOr404($code);

        return $this->render('partner_feed/traffic_lights/list.html.twig', [
            'partner' => $partner,
            'lights'  => $this->lightRepository->findByPartner($partner),
        ]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(string $code, int $id): Response
    {
        $partner = $this->getPartnerOr404($code);
        $light   = $this->getLightOr404($id, $partner);

        return $this->render('partner_feed/traffic_lights/show.html.twig', [
            'partner'  => $partner,
            'light'    => $light,
            'latest'   => $this->snapshotRepository->findLatestForLight($light),
            'history'  => $this->snapshotRepository->findHistoryForLight($light, 50),
        ]);
    }

    #[Route('/{id}/read', name: 'read', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function readNow(string $code, int $id): JsonResponse
    {
        $partner = $this->getPartnerOr404($code);
        $light   = $this->getLightOr404($id, $partner);

        try {
            $state = $this->manager->readNow($light);
            return $this->json(['ok' => true, 'state' => $state->toArray()]);
        } catch (TrafficLightException $e) {
            return $this->json(['ok' => false, 'error' => $e->getMessage()], 502);
        }
    }

    #[Route('/{id}/command', name: 'command', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_TRAFFIC_OPERATOR')]
    public function sendCommand(string $code, int $id, Request $request): JsonResponse
    {
        $partner = $this->getPartnerOr404($code);
        $light   = $this->getLightOr404($id, $partner);

        $payload = json_decode((string) $request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $type    = (string) ($payload['type'] ?? '');
        $data    = (array) ($payload['payload'] ?? []);
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

    private function getPartnerOr404(string $code): Partner
    {
        $partner = $this->partnerRepository->findOneBy(['code' => $code, 'isActive' => true]);
        if ($partner === null) {
            throw $this->createNotFoundException(sprintf("Parceiro '%s' não encontrado.", $code));
        }
        return $partner;
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
