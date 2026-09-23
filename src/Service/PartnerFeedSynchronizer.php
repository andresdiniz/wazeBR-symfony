<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Partner;
use App\Entity\PartnerApiLink;
use App\Entity\WazeAlert;
use App\Service\Tv\TvNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class PartnerFeedSynchronizer
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly HttpClientInterface $httpClient,
        private readonly TvNotifier $tvNotifier,
    ) {
    }

    /** @return array{processed:int,inserted:int,updated:int,skipped:int,errors:int} */
    public function synchronize(Partner $partner): array
    {
        $counters = $this->emptyCounters();
        $partnerCity = $this->normalizeCity($partner->getCity());

        if ($partnerCity === null) {
            return $counters;
        }

        $links = $this->entityManager
            ->getRepository(PartnerApiLink::class)
            ->findBy(['partner' => $partner, 'active' => true], ['id' => 'ASC']);

        if ($links === []) {
            return $counters;
        }

        foreach ($links as $link) {
            try {
                $response = $this->httpClient->request('GET', $link->getUrl());
                $payload = $response->toArray(false);
                $items = $this->extractItems($payload);

                foreach ($items as $item) {
                    if (is_array($item)) {
                        $this->processFeedItem($item, $partner, $counters);
                    }
                }
            } catch (\Throwable) {
                $counters['errors']++;
            }
        }

        $this->entityManager->flush();

        // ▼ Notifica a TV somente se houve escrita real
        if ($counters['inserted'] > 0 || $counters['updated'] > 0) {
            $this->tvNotifier->notify($partner);
        }

        return $counters;
    }

    /** @param array<string,mixed> $item */
    private function processFeedItem(array $item, Partner $partner, array &$counters): void
    {
        $counters['processed']++;

        $city = $this->normalizeCity($item['city'] ?? null)
            ?? $this->normalizeCity($item['municipality'] ?? null)
            ?? $this->normalizeCity($partner->getCity());

        if ($city === null) {
            $counters['skipped']++;
            return;
        }

        $uuid = $this->stringValue($item['uuid'] ?? $item['id'] ?? null);
        if ($uuid === null) {
            $counters['skipped']++;
            return;
        }

        $repository = $this->entityManager->getRepository(WazeAlert::class);
        $alert = $repository->findOneBy(['partner' => $partner, 'uuid' => $uuid]);

        if ($alert === null) {
            $alert = new WazeAlert();
            $alert->setPartner($partner);
            $counters['inserted']++;
        } else {
            $counters['updated']++;
        }

        $alert->setUuid($uuid);
        $alert->setCity($city);
        $alert->setLatitude($this->floatValue($item['latitude'] ?? null));
        $alert->setLongitude($this->floatValue($item['longitude'] ?? null));
        $alert->setStreet($this->stringValue($item['street'] ?? null));
        $alert->setCountry($this->stringValue($item['country'] ?? null));
        $alert->setType($this->stringValue($item['type'] ?? null));
        $alert->setSubtype($this->stringValue($item['subtype'] ?? null));

        $this->entityManager->persist($alert);
    }

    /** @return list<array<string,mixed>> */
    private function extractItems(mixed $payload): array
    {
        if (!is_array($payload)) {
            return [];
        }

        foreach (['alerts', 'jams', 'items', 'data', 'features'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                return array_values(array_filter($payload[$key], 'is_array'));
            }
        }

        return array_is_list($payload) ? array_values(array_filter($payload, 'is_array')) : [];
    }

    private function normalizeCity(mixed $city): ?string
    {
        if (!is_string($city) && !is_scalar($city)) {
            return null;
        }

        $city = trim((string) $city);

        return $city === '' ? null : $city;
    }

    private function stringValue(mixed $value): ?string
    {
        if (!is_string($value) && !is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function floatValue(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /** @return array{processed:int,inserted:int,updated:int,skipped:int,errors:int} */
    private function emptyCounters(): array
    {
        return [
            'processed' => 0,
            'inserted' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];
    }
}
