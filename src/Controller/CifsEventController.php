<?php

namespace App\Controller;

use App\Entity\CifsEvent;
use App\Enum\CifsDirectionEnum;
use App\Enum\CifsTypeEnum;
use App\Repository\CifsEventRepository;
use App\Repository\CifsEventTypeRepository;
use App\Repository\PartnerRepository;
use App\Service\CifsFeedService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/cifs', name: 'cifs_')]
class CifsEventController extends AbstractController
{
    public function __construct(
        private readonly CifsEventRepository     $eventRepo,
        private readonly CifsEventTypeRepository $typeRepo,
        private readonly CifsFeedService         $feedService,
        private readonly EntityManagerInterface  $em,
    ) {}

    // ── Página principal (PWA) ─────────────────────────────────

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $locale   = $request->getLocale() ?: 'pt';
        $grouped  = $this->typeRepo->getGroupedByType($locale);
        $typesMap = $this->typeRepo->getTypesMap($locale);
        $events   = $this->eventRepo->findFiltered(onlyActive: false, limit: 50);

        return $this->render('cifs/index.html.twig', [
            'grouped'    => $grouped,
            'typesMap'   => $typesMap,
            'events'     => $events,
            'directions' => CifsDirectionEnum::cases(),
            'types'      => CifsTypeEnum::cases(),
            'roadsides'  => \App\Enum\CifsRoadsideEnum::cases(),
            'weekdays'   => [
                'monday' => 'Segunda', 'tuesday' => 'Terça', 'wednesday' => 'Quarta',
                'thursday' => 'Quinta', 'friday' => 'Sexta', 'saturday' => 'Sábado', 'sunday' => 'Domingo',
            ],
        ]);
    }

    // ── API: tipos e subtipos (JSON) — usada pelo JS da página ──

    #[Route('/api/types', name: 'api_types', methods: ['GET'])]
    public function apiTypes(Request $request): JsonResponse
    {
        $locale  = $request->query->get('locale', 'pt');
        $grouped = $this->typeRepo->getGroupedByType($locale);
        return $this->json($grouped);
    }

    // ── API: salvar evento (POST JSON) ─────────────────────────

    #[Route('/api/event', name: 'api_save', methods: ['POST'])]
    public function apiSave(Request $request, PartnerRepository $partnerRepo): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!$data || empty($data['type']) || empty($data['polyline']) || empty($data['street'])) {
            return $this->json(['error' => 'Campos obrigatórios ausentes: type, polyline, street'], 400);
        }

        $type = CifsTypeEnum::tryFrom($data['type']);
        if (!$type) {
            return $this->json(['error' => 'Tipo inválido'], 400);
        }

        // Valida subtipo se informado
        $subtype = $data['subtype'] ?? null;
        if ($subtype && !in_array($subtype, $type->allowedSubtypes(), true)) {
            return $this->json(['error' => 'Subtipo inválido para o tipo ' . $type->value], 400);
        }

        $event = new CifsEvent();
        $event->setType($type);
        $event->setSubtype($subtype);
        $event->setPolyline(trim($data['polyline']));
        $event->setStreet($data['street']);

        $direction = null;
        if (!empty($data['direction'])) {
            $direction = CifsDirectionEnum::tryFrom($data['direction']);
            if ($direction) $event->setDirection($direction);
        }

        if (!empty($data['description'])) {
            $event->setDescription(mb_substr($data['description'], 0, 40));
        }

        // schedule: mapa {dayname: "hh:mm-hh:mm,hh:mm-hh:mm"} — tag opcional da spec CIFS
        if (!empty($data['schedule']) && is_array($data['schedule'])) {
            $validDays = ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'];
            $schedule = [];
            foreach ($data['schedule'] as $day => $ranges) {
                if (!in_array($day, $validDays, true) || empty($ranges)) continue;
                if (!preg_match('/^\d{2}:\d{2}-\d{2}:\d{2}(,\d{2}:\d{2}-\d{2}:\d{2})*$/', $ranges)) {
                    return $this->json(['error' => "Horário inválido para $day. Use HH:MM-HH:MM."], 400);
                }
                $schedule[$day] = $ranges;
            }
            if ($schedule) $event->setSchedule($schedule);
        }

        // lane_impact (formato parcial): só se não for fechamento total e a via afetada for um único sentido
        if (isset($data['lane_impact']['total_closed_lanes']) && $data['lane_impact']['total_closed_lanes'] !== '') {
            if ($type === CifsTypeEnum::ROAD_CLOSED) {
                return $this->json(['error' => 'lane_impact não se aplica a ROAD_CLOSED (fechamento total).'], 400);
            }
            if ($direction !== CifsDirectionEnum::ONE_DIRECTION) {
                return $this->json(['error' => 'lane_impact exige direction = ONE_DIRECTION.'], 400);
            }
            $event->setLaneImpactClosedLanes((int) $data['lane_impact']['total_closed_lanes']);
            if (!empty($data['lane_impact']['roadside'])) {
                $roadside = \App\Enum\CifsRoadsideEnum::tryFrom($data['lane_impact']['roadside']);
                if ($roadside) $event->setLaneImpactRoadside($roadside);
            }
        }

        try {
            $event->setStartTime(new \DateTimeImmutable($data['starttime'] ?? 'now'));
        } catch (\Exception) {
            $event->setStartTime(new \DateTimeImmutable());
        }

        if (!empty($data['endtime'])) {
            try {
                $event->setEndTime(new \DateTimeImmutable($data['endtime']));
            } catch (\Exception) {}
        }

        if (!empty($data['partner_id'])) {
            $partner = $partnerRepo->find($data['partner_id']);
            if ($partner) $event->setPartner($partner);
        }

        $this->em->persist($event);
        $this->em->flush();

        return $this->json([
            'id'         => $event->getId(),
            'externalId' => $event->getExternalId(),
            'message'    => 'Evento criado com sucesso',
        ], 201);
    }

    // ── API: desativar evento ──────────────────────────────────

    #[Route('/api/event/{id}/deactivate', name: 'api_deactivate', methods: ['POST'])]
    public function apiDeactivate(CifsEvent $event): JsonResponse
    {
        $event->setActive(false);
        $this->em->flush();
        return $this->json(['message' => 'Evento desativado']);
    }

    // ── Feed JSON (para o Waze consumir) ───────────────────────

    #[Route('/feed.json', name: 'feed_json', methods: ['GET'])]
    public function feedJson(): JsonResponse
    {
        return $this->json($this->feedService->buildJsonFeed());
    }

    // ── Feed XML (para o Waze consumir) ───────────────────────

    #[Route('/feed.xml', name: 'feed_xml', methods: ['GET'])]
    public function feedXml(): Response
    {
        $xml = $this->feedService->buildXmlFeed();
        return new Response($xml, 200, ['Content-Type' => 'application/xml; charset=utf-8']);
    }
}
