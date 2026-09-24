<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Partner;
use App\Entity\PartnerFeedEvent;
use App\Repository\PartnerFeedEventRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Constrói o payload CIFS do parceiro (feed outbound que o Waze consome).
 *
 * Comportamento:
 *   - Inclui apenas eventos ativos e dentro do intervalo de validade
 *   - Se 'endtime' for null, o Waze aplica o fallback oficial de 14 dias
 */
final class CifsFeedBuilder
{
    public function __construct(
        private readonly PartnerFeedEventRepository $eventRepository,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return PartnerFeedEvent[] */
    public function collectActiveEvents(Partner $partner): array
    {
        $now = new \DateTimeImmutable();

        return $this->eventRepository->createQueryBuilder('e')
            ->andWhere('e.partner = :partner')
            ->andWhere('e.isActive = true')
            ->andWhere('(e.startTime IS NULL OR e.startTime <= :now)')
            ->andWhere('(e.endTime IS NULL OR e.endTime >= :now)')
            ->setParameter('partner', $partner)
            ->setParameter('now', $now)
            ->orderBy('e.creationTime', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Payload JSON CIFS. */
    public function buildJsonPayload(Partner $partner): array
    {
        $events = $this->collectActiveEvents($partner);
        $now = new \DateTimeImmutable();

        $incidents = array_map(
            static fn (PartnerFeedEvent $event) => $event->toCifsArray(),
            $events,
        );

        return [
            'incidents'    => $incidents,
            'creationtime' => $now->format('Y-m-d\TH:i:sP'),
            'updatetime'   => $now->format('Y-m-d\TH:i:sP'),
        ];
    }

    /** Payload XML CIFS (o Waze aceita XML ou JSON). */
    public function buildXmlPayload(Partner $partner): string
    {
        $data = $this->buildJsonPayload($partner);

        $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><feed></feed>');
        $xml->addAttribute('creationtime', $data['creationtime']);
        $xml->addAttribute('updatetime', $data['updatetime']);

        $incidentsNode = $xml->addChild('incidents');

        foreach ($data['incidents'] as $incident) {
            $node = $incidentsNode->addChild('incident');
            foreach ($incident as $key => $value) {
                if ($value === null) {
                    continue;
                }
                $node->addChild($key, htmlspecialchars((string) $value, ENT_XML1));
            }
        }

        return (string) $xml->asXML();
    }

    /**
     * Expira eventos antigos: marca isActive=false quando o endTime já passou.
     *
     * @return int Quantidade de eventos desativados
     */
    public function expireOldEvents(): int
    {
        $now = new \DateTimeImmutable();

        $qb = $this->em->createQueryBuilder();
        $qb->update(PartnerFeedEvent::class, 'e')
            ->set('e.isActive', ':false')
            ->set('e.deactivatedReason', ':reason')
            ->set('e.updateTime', ':now')
            ->where('e.isActive = true')
            ->andWhere('e.endTime IS NOT NULL')
            ->andWhere('e.endTime < :now')
            ->setParameter('false', false)
            ->setParameter('reason', 'expired')
            ->setParameter('now', $now);

        $count = (int) $qb->getQuery()->execute();
        $this->logger->info(sprintf('CIFS: %d eventos expirados.', $count));

        return $count;
    }
}