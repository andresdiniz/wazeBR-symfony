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
private readonly CifsEventRepository $eventRepo,
private readonly CifsEventTypeRepository $typeRepo,
private readonly CifsFeedService $feedService,
private readonly EntityManagerInterface $em,
) {}

#[Route('', name: 'index', methods: ['GET'])]
public function index(Request $request): Response
{
$locale = $request->getLocale() ?: 'pt';
$grouped = $this->typeRepo->getGroupedByType($locale);
$typesMap = $this->typeRepo->getTypesMap($locale);
$events = $this->eventRepo->findFiltered(onlyActive: false, limit: 100);

return $this->render('cifs/index.html.twig', [
'grouped' => $grouped,
'typesMap' => $typesMap,
'events' => $events,
'directions' => CifsDirectionEnum::cases(),
'types' => CifsTypeEnum::cases(),
'roadsides' => \App\Enum\CifsRoadsideEnum::cases(),
'weekdays' => [
'monday' => 'Segunda', 'tuesday' => 'Terça', 'wednesday' => 'Quarta',
'thursday' => 'Quinta', 'friday' => 'Sexta', 'saturday' => 'Sábado', 'sunday' => 'Domingo',
],
]);
}

#[Route('/api/types', name: 'api_types', methods: ['GET'])]
public function apiTypes(Request $request): JsonResponse
{
$locale = $request->query->get('locale', 'pt');
$grouped = $this->typeRepo->getGroupedByType($locale);
return $this->json($grouped);
}

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

// parse and basic normalize polyline: expect "lat lon lat lon ..." or "lon lat,lon lat" etc.
$polyline = trim((string)$data['polyline']);
if ($polyline === '') {
return $this->json(['error' => 'Polyline inválida'], 400);
}
// Normalização mínima: accept space-separated pairs (lat lon) or comma pairs (lon,lat)
// For stricter validation you may implement encoded polyline support.
$pairsSpace = preg_split('/\s+/', $polyline);
if (count($pairsSpace) < 2) {
// Try comma-separated lon,lat list
$coords = array_filter(array_map('trim', preg_split('/,/', $polyline)));
if (count($coords) < 2) {
return $this->json(['error' => 'Polyline contém poucos pontos (mínimo 1 ponto requerido, preferível 2+)'], 400);
}
}

// validate start/end time parsing; ensure timezone-aware ISO
try {
$start = !empty($data['starttime']) ? new \DateTimeImmutable($data['starttime']) : new \DateTimeImmutable('now');
} catch (\Exception) {
return $this->json(['error' => 'starttime inválido; use ISO8601 com timezone'], 400);
}

$end = null;
if (!empty($data['endtime'])) {
try {
$end = new \DateTimeImmutable($data['endtime']);
} catch (\Exception) {
return $this->json(['error' => 'endtime inválido; use ISO8601 com timezone'], 400);
}
}

// For ROAD_CLOSED, enforce starttime + direction and require polyline with >=2 points when possible
$direction = null;
if (!empty($data['direction'])) {
$direction = CifsDirectionEnum::tryFrom($data['direction']);
if (!$direction) {
return $this->json(['error' => 'direction inválida'], 400);
}
}

if ($type === CifsTypeEnum::ROAD_CLOSED) {
// starttime must be explicit (not now) according to stricter policy — require user provide starttime
if (empty($data['starttime'])) {
return $this->json(['error' => 'starttime obrigatório para ROAD_CLOSED (fechamento total)'], 400);
}
if (!$direction) {
return $this->json(['error' => 'direction (ONE_DIRECTION|BOTH_DIRECTIONS) recomendado para ROAD_CLOSED'], 400);
}
}

// description length: allow up to 64 chars per CIFS common recommendations
$description = null;
if (!empty($data['description'])) {
$description = mb_substr((string)$data['description'], 0, 64);
}

// schedule validation (HH:MM-HH:MM[,...])
$schedule = null;
if (!empty($data['schedule']) && is_array($data['schedule'])) {
$validDays = ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'];
$sched = [];
foreach ($data['schedule'] as $day => $ranges) {
if (!in_array($day, $validDays, true) || empty($ranges)) continue;
if (!preg_match('/^\d{2}:\d{2}-\d{2}:\d{2}(,\d{2}:\d{2}-\d{2}:\d{2})*$/', $ranges)) {
return $this->json(['error' => "Horário inválido para $day. Use HH:MM-HH:MM."], 400);
}
$sched[$day] = $ranges;
}
if ($sched) $schedule = $sched;
}

// lane_impact checks
$laneImpact = null;
if (!empty($data['lane_impact']) && is_array($data['lane_impact'])) {
$li = $data['lane_impact'];
if (isset($li['total_closed_lanes']) && $li['total_closed_lanes'] !== '') {
if ($type === CifsTypeEnum::ROAD_CLOSED) {
// disallow lane_impact for full closure
return $this->json(['error' => 'lane_impact não se aplica a ROAD_CLOSED (fechamento total).'], 400);
}
if ($direction !== CifsDirectionEnum::ONE_DIRECTION) {
return $this->json(['error' => 'lane_impact exige direction = ONE_DIRECTION.'], 400);
}
$laneImpact = [
'total_closed_lanes' => (int)$li['total_closed_lanes'],
];
if (!empty($li['roadside'])) {
$laneImpact['roadside'] = $li['roadside'];
}
}
}

// partner assignment if provided
$partner = null;
if (!empty($data['partner_id'])) {
$partner = $partnerRepo->find($data['partner_id']);
}

// create event
$event = new CifsEvent();
$event->setType($type);
$event->setSubtype($data['subtype'] ?? null);
$event->setPolyline($polyline);
$event->setStreet($data['street']);
if ($direction) $event->setDirection($direction);
if ($description) $event->setDescription($description);
$event->setStartTime($start);
if ($end) $event->setEndTime($end);
if ($schedule) $event->setSchedule($schedule);
if ($laneImpact !== null) {
$event->setLaneImpactClosedLanes($laneImpact['total_closed_lanes']);
if (!empty($laneImpact['roadside'])) {
// Attempt to map roadside enum if exists
$event->setLaneImpactRoadside(\App\Enum\CifsRoadsideEnum::tryFrom($laneImpact['roadside']) ?? null);
}
}
if ($partner) $event->setPartner($partner);

// externalId generation: prefer provided externalId, otherwise create stable unique id
if (!empty($data['externalId'])) {
$event->setExternalId(substr(preg_replace('/[^A-Za-z0-9-_]/', '-', $data['externalId']), 0, 80));
} else {
$event->setExternalId('cifs-' . bin2hex(random_bytes(6)));
}

$this->em->persist($event);
$this->em->flush();

return $this->json([
'id' => $event->getId(),
'externalId' => $event->getExternalId(),
'message' => 'Evento criado com sucesso',
], 201);
}

#[Route('/api/event/{id}/deactivate', name: 'api_deactivate', methods: ['POST'])]
public function apiDeactivate(CifsEvent $event): JsonResponse
{
$event->setActive(false);
$this->em->flush();
return $this->json(['message' => 'Evento desativado']);
}

#[Route('/feed.json', name: 'feed_json', methods: ['GET'])]
public function feedJson(): JsonResponse
{
// Return JSON feed ready to be consumed by Waze; service handles shape
return $this->json($this->feedService->buildJsonFeed());
}

#[Route('/feed.xml', name: 'feed_xml', methods: ['GET'])]
public function feedXml(): Response
{
$xml = $this->feedService->buildXmlFeed();
return new Response($xml, 200, ['Content-Type' => 'application/xml; charset=utf-8']);
}
}
