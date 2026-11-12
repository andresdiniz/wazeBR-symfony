<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\WazeTvtIrregularity;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;

#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postUpdate)]
final class TvtIrregularitySampleListener
{
    private float $checkedAt = 0;
    private bool $available = false;

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function postPersist(PostPersistEventArgs $event): void
    {
        $this->record($event);
    }

    public function postUpdate(PostUpdateEventArgs $event): void
    {
        $this->record($event);
    }

    public function record(PostPersistEventArgs|PostUpdateEventArgs $event): void
    {
        $entity = $event->getObject();
        $em = $event->getObjectManager();

        if (!$entity instanceof WazeTvtIrregularity
            || !$em instanceof EntityManagerInterface
            || !$entity->isActive()
            || $entity->getId() === null
            || $entity->getPartner()?->getId() === null
            || $entity->getLastSeenAt() === null
        ) {
            return;
        }

        $db = $em->getConnection();

        if (microtime(true) - $this->checkedAt >= 120) {
            $this->available = $db->createSchemaManager()->tablesExist(['waze_tvt_irregularity_sample']);
            $this->checkedAt = microtime(true);

            if (!$this->available) {
                $this->logger->warning('tvt.irregularity.history_schema_missing', [
                    'migration' => 'Version20261112223000',
                ]);
            }
        }

        if (!$this->available) {
            return;
        }

        $data = $entity->getPayload() ?? [];
        $observed = $this->positive($data['time'] ?? null);
        $historic = $this->positive($data['historicTime'] ?? null);
        $delay = $observed !== null && $historic !== null ? max(0, $observed - $historic) : null;
        $level = isset($data['jamLevel']) && in_array((string) $data['jamLevel'], ['0', '1', '2', '3', '4', '5'], true)
            ? (int) $data['jamLevel']
            : null;

        $snapshot = json_encode([
            'time' => $data['time'] ?? null,
            'historicTime' => $data['historicTime'] ?? null,
            'jamLevel' => $data['jamLevel'] ?? null,
            'length' => $data['length'] ?? null,
            'type' => $data['type'] ?? null,
            'name' => $data['name'] ?? null,
            'fromName' => $data['fromName'] ?? null,
            'toName' => $data['toName'] ?? null,
            'street' => $data['street'] ?? null,
            'city' => $data['city'] ?? null,
            'state' => $data['state'] ?? null,
            'leadAlert' => $data['leadAlert'] ?? null,
            'bbox' => $data['bbox'] ?? null,
            'line' => $data['line'] ?? null,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $db->executeStatement(
            'INSERT INTO waze_tvt_irregularity_sample
                (irregularity_id, partner_id, observed_at, observed_seconds,
                 historic_seconds, delay_seconds, jam_level, snapshot)
             VALUES (:id, :partner, :at, :observed, :historic, :delay, :level, :snapshot)
             ON DUPLICATE KEY UPDATE
                observed_seconds = VALUES(observed_seconds),
                historic_seconds = VALUES(historic_seconds),
                delay_seconds = VALUES(delay_seconds),
                jam_level = VALUES(jam_level),
                snapshot = VALUES(snapshot)',
            [
                'id' => $entity->getId(),
                'partner' => $entity->getPartner()->getId(),
                'at' => $entity->getLastSeenAt()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                'observed' => $observed,
                'historic' => $historic,
                'delay' => $delay,
                'level' => $level,
                'snapshot' => $snapshot,
            ],
        );
    }

    private function positive(mixed $value): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }

        $number = (float) $value;

        return is_finite($number) && $number > 0 ? $number : null;
    }
}
