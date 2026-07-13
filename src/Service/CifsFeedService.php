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
     */
    public function buildJsonFeed(): array
    {
        $events = $this->getActiveEvents();
        $incidents = [];

        foreach ($events as $event) {
            $incident = [
                'id'           => $event->getExternalId(),
                'type'         => $event->getType()->value,
                'polyline'     => $event->getPolyline(),
                'street'       => $event->getStreet(),
                'starttime'    => $event->getStartTime()->format(\DateTimeInterface::ATOM),
                'creationtime' => $event->getCreationTime()->format(\DateTimeInterface::ATOM),
            ];

            if ($event->getSubtype())    $incident['subtype']     = $event->getSubtype();
            if ($event->getDirection())  $incident['direction']   = $event->getDirection()->value;
            if ($event->getDescription()) $incident['description'] = $event->getDescription();
            if ($event->getEndTime())    $incident['endtime']     = $event->getEndTime()->format(\DateTimeInterface::ATOM);
            if ($event->getUpdateTime()) $incident['updatetime']  = $event->getUpdateTime()->format(\DateTimeInterface::ATOM);

            $incidents[] = $incident;
        }

        return ['incidents' => $incidents];
    }

    /**
     * Gera o feed em XML conforme especificação CIFS v2.
     */
    public function buildXmlFeed(): string
    {
        $events = $this->getActiveEvents();

        $xml = new \SimpleXMLElement(
            '<?xml version="1.0" encoding="UTF-8"?>' .
            '<incidents xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" ' .
            'xsi:noNamespaceSchemaLocation="http://www.gstatic.com/road-incidents/cifsv2.xsd"/>'
        );

        foreach ($events as $event) {
            $inc = $xml->addChild('incident');
            $inc->addAttribute('id', $event->getExternalId());
            $inc->addChild('creationtime', $event->getCreationTime()->format(\DateTimeInterface::ATOM));
            if ($event->getUpdateTime()) {
                $inc->addChild('updatetime', $event->getUpdateTime()->format(\DateTimeInterface::ATOM));
            }
            $inc->addChild('type',      $event->getType()->value);
            if ($event->getSubtype()) {
                $inc->addChild('subtype', $event->getSubtype());
            }
            $inc->addChild('polyline',  $event->getPolyline());
            $inc->addChild('street',    $event->getStreet());
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
        }

        $dom = dom_import_simplexml($xml)->ownerDocument;
        $dom->formatOutput = true;
        return $dom->saveXML();
    }
}
