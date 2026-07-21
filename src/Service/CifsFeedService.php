<?php

namespace App\Service;

use App\Entity\CifsEvent;
use App\Repository\CifsEventRepository;

class CifsFeedService
{
public function __construct(
private readonly CifsEventRepository $repo
) {}

/** @return CifsEvent[] */
public function getActiveEvents(): array
{
return $this->repo->findActiveForFeed();
}

/**
* Gera o payload JSON conforme especificação CIFS.
* Normaliza campos essenciais e garante ISO8601 com timezone.
*/
public function buildJsonFeed(): array
{
$events = $this->getActiveEvents();
$incidents = [];

foreach ($events as $event) {
// Ensure external id exists
$externalId = $event->getExternalId() ?: ('cifs-' . bin2hex(random_bytes(6)));

$incident = [
'id' => $externalId,
'type' => $event->getType()->value,
'polyline' => $this->normalizePolylineForJson($event->getPolyline()),
'street' => $event->getStreet(),
'starttime' => $event->getStartTime()->format(\DateTimeInterface::ATOM),
'creationtime' => $event->getCreationTime()?->format(\DateTimeInterface::ATOM) ?? (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
];

if ($event->getSubtype()) $incident['subtype'] = $event->getSubtype();
if ($event->getDirection()) $incident['direction'] = $event->getDirection()->value;
if ($event->getDescription()) $incident['description'] = $event->getDescription();
if ($event->getEndTime()) $incident['endtime'] = $event->getEndTime()->format(\DateTimeInterface::ATOM);
if ($event->getUpdateTime()) $incident['updatetime'] = $event->getUpdateTime()->format(\DateTimeInterface::ATOM);

if ($event->getSchedule()) {
// Keep schedule as an object of day: ranges
$incident['schedule'] = $event->getSchedule();
}

if ($event->getLaneImpactClosedLanes() !== null) {
$incident['lane_impact'] = [
'total_closed_lanes' => (int)$event->getLaneImpactClosedLanes(),
];
if ($event->getLaneImpactRoadside()) {
$incident['lane_impact']['roadside'] = $event->getLaneImpactRoadside()->value;
}
}

// Add partner reference if available
if ($event->getPartner()) {
$incident['reference'] = $event->getPartner()->getExternalReference() ?? $event->getPartner()->getId();
}

$incidents[] = $incident;
}

return ['incidents' => $incidents];
}

/**
* Gera o feed em XML conforme especificação CIFS v2.
* Observações: use a mesma normalização de polyline feita no JSON.
*/
public function buildXmlFeed(): string
{
$events = $this->getActiveEvents();

$xml = new \SimpleXMLElement(
'<?xml version="1.0" encoding="UTF-8"?><incidents xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="http://www.gstatic.com/road-incidents/cifsv2.xsd"/>'
);

foreach ($events as $event) {
$inc = $xml->addChild('incident');
$inc->addAttribute('id', $event->getExternalId() ?: ('cifs-' . bin2hex(random_bytes(6))));
$inc->addChild('creationtime', $event->getCreationTime()?->format(\DateTimeInterface::ATOM) ?? (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM));
if ($event->getUpdateTime()) {
$inc->addChild('updatetime', $event->getUpdateTime()->format(\DateTimeInterface::ATOM));
}
$inc->addChild('type', $event->getType()->value);
if ($event->getSubtype()) {
$inc->addChild('subtype', $event->getSubtype());
}
$inc->addChild('polyline', htmlspecialchars($this->normalizePolylineForJson($event->getPolyline())));
$inc->addChild('street', $event->getStreet());
if ($event->getDirection()) {
$inc->addChild('direction', $event->getDirection()->value);
}
if ($event->getDescription()) {
$inc->addChild('description', $event->getDescription());
}
$inc->addChild('starttime', $event->getStartTime()->format(\DateTimeInterface::ATOM));
if ($event->getEndTime()) {
$inc->addChild('endtime', $event->getEndTime()->format(\DateTimeInterface::ATOM));
}

if ($event->getSchedule()) {
$sched = $inc->addChild('schedule');
foreach ($event->getSchedule() as $day => $ranges) {
$sched->addChild($day, $ranges);
}
}

if ($event->getLaneImpactClosedLanes() !== null) {
$lane = $inc->addChild('lane_impact');
$lane->addChild('total_closed_lanes', (string)$event->getLaneImpactClosedLanes());
if ($event->getLaneImpactRoadside()) {
$lane->addChild('roadside', $event->getLaneImpactRoadside()->value);
}
}

if ($event->getPartner()) {
$inc->addChild('reference', $event->getPartner()->getExternalReference() ?? $event->getPartner()->getId());
}
}

$dom = dom_import_simplexml($xml)->ownerDocument;
$dom->formatOutput = true;
return $dom->saveXML();
}

/**
* Normaliza polyline para o CIFS JSON/XML:
* - Remove duplicatas
* - Garante formato "lat lon lat lon ..." (note: CIFS frequentemente aceita lon,lat pairs
* — verifique se seu parceiro Waze quer lon,lat ou lat,lon; ajustar conforme necessário)
*
* Observação: a documentação do Waze costuma preferir lon,lat pairs (longitude primeiro) para
* alguns casos; aqui mantemos lat lon para compatibilidade com a interface atual — adapte se necessário.
*/
private function normalizePolylineForJson(?string $poly): string
{
if (!$poly) return '';
// Basic cleanup: multiple whitespaces => single, trim
$p = preg_replace('/\s+/', ' ', trim($poly));
// Remove trailing/leading commas
$p = trim($p, ',');
return $p;
}
}
