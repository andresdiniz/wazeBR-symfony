<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\WazeAlert;
use App\Entity\WazeTrafficJam;
use App\Entity\CemadenData;
use App\Entity\Notification;
use Doctrine\ORM\EntityManagerInterface;

class DashboardService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function getSummary(): array
    {
        $now   = new \DateTimeImmutable();
        $today = $now->setTime(0, 0, 0);

        return [
            'alerts_today'         => $this->entityManager->getRepository(WazeAlert::class)->countByDate($today),
            'jams_today'           => $this->entityManager->getRepository(WazeTrafficJam::class)->countByDate($today),
            'cemaden_active'       => $this->entityManager->getRepository(CemadenData::class)->countActiveAlerts(),
            'unread_notifications' => $this->entityManager->getRepository(Notification::class)->countUnread(),
            'alerts_by_type'       => $this->entityManager->getRepository(WazeAlert::class)->countGroupedByType($today),
            'jams_by_level'        => $this->entityManager->getRepository(WazeTrafficJam::class)->countGroupedByLevel($today),
            'last_collection'      => $this->entityManager->getRepository(WazeAlert::class)->findLastCreatedAt(),
        ];
    }

    public function getAlertsForMap(int $hoursBack = 2): array
    {
        return $this->entityManager->getRepository(WazeAlert::class)->findRecentAlerts($hoursBack);
    }

    public function getTrafficForMap(int $hoursBack = 2): array
    {
        return $this->entityManager->getRepository(WazeTrafficJam::class)->findRecentJams($hoursBack);
    }

    public function getCemadenForMap(): array
    {
        return $this->entityManager->getRepository(CemadenData::class)->findActiveAlerts();
    }
}
