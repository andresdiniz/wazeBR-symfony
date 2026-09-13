<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Partner;
use Doctrine\ORM\EntityManagerInterface;

final class PartnerFeedSynchronizer
{
    public function __construct(
        private readonly WazeFeedSynchronizer $wazeFeedSynchronizer,
        private readonly WazeTvtSynchronizer $wazeTvtSynchronizer,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array<string, int>
     */
    public function synchronize(
        Partner $partner,
        bool $dryRun = false,
    ): array {
        $result = [
            'alertsCreated' => 0,
            'alertsUpdated' => 0,
            'alertsReactivated' => 0,
            'alertsDeactivated' => 0,
            'jamsCreated' => 0,
            'jamsUpdated' => 0,
            'jamsReactivated' => 0,
            'jamsDeactivated' => 0,
            'routesCreated' => 0,
            'routesReactivated' => 0,
            'routesDeactivated' => 0,
            'subRoutesCreated' => 0,
            'subRoutesReactivated' => 0,
            'subRoutesDeactivated' => 0,
            'irregularitiesCreated' => 0,
            'irregularitiesReactivated' => 0,
            'irregularitiesDeactivated' => 0,
            'snapshotsCreated' => 0,
            'usersOnJamCreated' => 0,
        ];

        $alertsResult = $this->wazeFeedSynchronizer
            ->synchronize($partner, $dryRun);

        foreach ($alertsResult as $key => $value) {
            $result[$key] += $value;
        }

        $tvtResult = $this->wazeTvtSynchronizer
            ->synchronize($partner, $dryRun);

        foreach ($tvtResult as $key => $value) {
            $result[$key] += $value;
        }

        /*
         * Atualiza last_fetch_at somente depois de Alerts e TVT
         * concluírem sem exceção.
         */
        if (!$dryRun) {
            $partner->setLastFetchAt(
                new \DateTimeImmutable(),
            );

            $this->entityManager->persist($partner);
            $this->entityManager->flush();
        }

        return $result;
    }
}
