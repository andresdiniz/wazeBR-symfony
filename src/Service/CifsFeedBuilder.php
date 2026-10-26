<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Partner;
use App\Entity\PartnerFeedEvent;
use App\Repository\PartnerFeedEventRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Constrói o feed CIFS do parceiro (outbound, consumido pelo Waze).
 * É a ÚNICA fonte de payload: /partner-feed/{code}.json, /feeds/waze/{code}.json|xml
 * e o comando app:partner-feed:update-json produzem exatamente o mesmo conteúdo.
 *
 * Regras (https://developers.google.com/waze/data-feed/cifs-specification):
 *   - Só campos da spec; campos vazios são omitidos (nada de "null").
 *   - Datas ISO8601 com segundos e offset (America/Sao_Paulo).
 *   - Um valor inválido em tag obrigatória invalida o feed inteiro, então
 *     eventos que não passam no CifsEventValidator NÃO são publicados
 *     (ficam só no log) em vez de derrubar o feed dos demais.
 *   - Eventos planejados (início em até PLANNED_HORIZON) entram antes do início.
 *   - Sem 'endtime' o Waze assume 14 dias após o início.
 */
final class CifsFeedBuilder
{
    public const TIMEZONE = 'America/Sao_Paulo';
    public const PLANNED_HORIZON = '+7 days';
    public const XSD_URL = 'http://www.gstatic.com/road-incidents/cifsv2.xsd';

    public function __construct(
        private readonly PartnerFeedEventRepository $eventRepository,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly CifsEventValidator $validator,
    ) {
    }

    /** @return PartnerFeedEvent[] Eventos candidatos (ativos, não encerrados, início dentro do horizonte). */
    public function collectActiveEvents(Partner $partner): array
    {
        $now = new \DateTimeImmutable();

        return $this->eventRepository->findPublishableByPartner(
            $partner,
            $now,
            $now->modify(self::PLANNED_HORIZON),
        );
    }

    /**
     * @return list<array<string, string>> Incidentes CIFS válidos, prontos para serializar.
     */
    public function buildIncidents(Partner $partner): array
    {
        $incidents = [];

        foreach ($this->collectActiveEvents($partner) as $event) {
            $errors = $this->validator->validate($event)['errors'];
            if ($errors !== []) {
                $this->logger->warning('CIFS: evento fora da spec não publicado.', [
                    'partner' => $partner->getCode(),
                    'event_id' => $event->getId(),
                    'errors' => $errors,
                ]);
                continue;
            }
            $incidents[] = $event->toCifsArray(self::TIMEZONE);
        }

        return $incidents;
    }

    /** Payload JSON CIFS: {"incidents":[...]} (sem campos extras). */
    public function buildJsonPayload(Partner $partner): array
    {
        return ['incidents' => $this->buildIncidents($partner)];
    }

    /** Payload XML CIFS (o Waze aceita XML ou JSON). */
    public function buildXmlPayload(Partner $partner): string
    {
        return self::renderXml($this->buildIncidents($partner));
    }

    /**
     * Serializa incidentes no XML da spec:
     * <incidents xsi:noNamespaceSchemaLocation=...><incident id="..."> ... </incident></incidents>
     *
     * @param list<array<string, string>> $incidents saída de PartnerFeedEvent::toCifsArray()
     */
    public static function renderXml(array $incidents): string
    {
        $xml = new \XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->setIndentString('  ');
        $xml->startDocument('1.0', 'UTF-8');

        $xml->startElement('incidents');
        $xml->writeAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $xml->writeAttribute('xsi:noNamespaceSchemaLocation', self::XSD_URL);

        foreach ($incidents as $incident) {
            $xml->startElement('incident');
            $xml->writeAttribute('id', (string) ($incident['id'] ?? ''));
            foreach ($incident as $tag => $value) {
                if ($tag === 'id' || $value === '' || $value === null) {
                    continue;
                }
                $xml->writeElement($tag, (string) $value);
            }
            $xml->endElement();
        }

        $xml->endElement();
        $xml->endDocument();

        return $xml->outputMemory();
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
