<?php

namespace App\Service;

use App\Entity\WazeFeed;
use App\Entity\WazeFeedCollection;
use App\Repository\WazeAlertRepository;
use App\Repository\WazeTrafficJamRepository;
use Doctrine\ORM\EntityManagerInterface;

class WazeEventLifecycleService
{
    // Tolerâncias: se um alerta/jam não aparece por mais que isso, é desativado
    private const ALERT_MISSING_TOLERANCE_MINUTES = 15;
    private const JAM_MISSING_TOLERANCE_MINUTES   = 10;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WazeAlertRepository $alertRepository,
        private readonly WazeTrafficJamRepository $jamRepository
    ) {}

    public function markMissingAndDeactivateExpired(WazeFeed $feed, WazeFeedCollection $collection): void
    {
        $now = new \DateTime();
        $feedId = $feed->getId();

        $alertCutoff = (clone $now)->modify(sprintf('-%d minutes', self::ALERT_MISSING_TOLERANCE_MINUTES));
        $jamCutoff   = (clone $now)->modify(sprintf('-%d minutes', self::JAM_MISSING_TOLERANCE_MINUTES));

        $this->processAlerts($feedId, $now, $alertCutoff);
        $this->processJams($feedId, $now, $jamCutoff);

        $this->em->flush();
    }

    private function processAlerts(int $feedId, \DateTime $now, \DateTime $cutoff): void
    {
        // Marcar como missing (primeira vez que somem)
        foreach ($this->alertRepository->findMissingSince($feedId, $cutoff) as $alert) {
            $alert->setMissingSinceAt($now);
        }

        // Desativar os que já estão missing há tempo suficiente
        foreach ($this->alertRepository->findExpired($feedId, $cutoff) as $alert) {
            $alert->setIsActive(false);
            $alert->setDeactivatedAt($now);
        }
    }

    private function processJams(int $feedId, \DateTime $now, \DateTime $cutoff): void
    {
        foreach ($this->jamRepository->findMissingSince($feedId, $cutoff) as $jam) {
            $jam->setMissingSinceAt($now);
        }

        foreach ($this->jamRepository->findExpired($feedId, $cutoff) as $jam) {
            $jam->setIsActive(false);
            $jam->setDeactivatedAt($now);
        }
    }
}
