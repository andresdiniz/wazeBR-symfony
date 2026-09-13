<?php

namespace App\Service;

use App\Entity\Partner;
use App\Entity\WazeAlert;
use Doctrine\ORM\EntityManagerInterface;

final class PartnerFeedSynchronizer
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /** @return array{processed:int,inserted:int,updated:int,skipped:int,errors:int} */
    public function synchronize(Partner $partner): array
    {
        $counters = [
            'processed' => 0,
            'inserted' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];

        $partnerCity = $this->normalizeCity($partner->getCity());

        if ($partnerCity === null) {
            return $counters;
        }

        // O parser do feed deve fornecer os itens para processFeedItem().
        // Nenhum alerta é persistido sem passar pela validação de cidade.
        return $counters;
    }

    /** @param array<string,mixed> $item */
    public function processFeedItem(array $item, Partner $partner, array &$counters): void
    {
        $counters['processed']++;

        $city = $this->normalizeCity($item['city'] ?? null)
            ?? $this->normalizeCity($item['municipality'] ?? null)
            ?? $this->normalizeCity($partner->getCity());

        if ($city === null) {
            $counters['skipped']++;
            return;
        }

        try {
            $alert = new WazeAlert();
            $alert->setCity($city);
            $alert->setPartner($partner);

            $this->entityManager->persist($alert);
            $counters['inserted']++;
        } catch (\Throwable) {
            $counters['errors']++;
        }
    }

    private function normalizeCity(mixed $city): ?string
    {
        if (!is_string($city) && !is_scalar($city)) {
            return null;
        }

        $city = trim((string) $city);

        return $city === '' ? null : $city;
    }
}
