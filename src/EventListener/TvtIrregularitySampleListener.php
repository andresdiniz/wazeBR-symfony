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
    public function __construct(private readonly LoggerInterface $logger) {}

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
        if (!$entity instanceof WazeTvtIrregularity || !$em instanceof EntityManagerInterface || !$entity->isActive() || $entity->getId() === null || $entity->getPartner()?->getId() === null || $entity->getLastSeenAt() === null) return;
        $db = $em->getConnection();
        if (microtime(true) - $this->checkedAt >= 120) {
            $this->available = $db->createSchemaManager()->tablesExist(['waze_tvt_irregularity_sample']);
            $this->checkedAt = microtime(true);
            if (!$this->available) $this->logger->warning('tvt.irregularity.history_schema_missing', ['migration' => 'Version20261112223000']);
        }
        if (!$this->available) return;
        $data = $entity->getPayload() ?? [];
        $observed = self::positive($data['time'] ?? null);
        $historic = self::positive($data['historicTime'] ?? null);
        $delay = $observed !== null && $historic !== null ? max(0, $observed - $historic) : null;
        $level = isset($data['jamLevel']) && in_array((string) $data['jamLevel'], ['0','1','2','3','4','5'], true) ? (int) $data['jamLevel'] : null;
        $db->executeStatement('INSERT INTO waze_tvt_irregularity_sample (irregularity_id, partner_id, observed_at, observed_seconds, historic_seconds, delay_seconds, jam_level) VALUES (:id, :partner, :at, :observed, :historic, :delay, :level) ON DUPLICATE KEY UPDATE observed_seconds = VALUES(observed_seconds), historic_seconds = VALUES(historic_seconds), delay_seconds = VALUES(delay_seconds), jam_level = VALUES(jam_level)', [
            'id' => $entity->getId(), 'partner' => $entity->getPartner()->getId(),
            'at' => $entity->getLastSeenAt()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'observed' => $observed, 'historic' => $historic, 'delay' => $delay, 'level' => $level,
        ]);
    }
    private static function positive(mixed $value): ?float
    {
        if (!is_numeric($value)) return null;
        $number = (float) $value;
        return is_finite($number) && $number > 0 ? $number : null;
    }
}
