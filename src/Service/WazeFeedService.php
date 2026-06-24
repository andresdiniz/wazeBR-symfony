<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\MonitoredLink;
use App\Entity\WazeAlert;
use App\Repository\WazeAlertRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Coleta alertas Waze de um MonitoredLink (type=feed) e persiste com upsert.
 */
class WazeFeedService
{
    public function __construct(
        private readonly HttpClientInterface    $httpClient,
        private readonly EntityManagerInterface $em,
        private readonly WazeAlertRepository   $alertRepo,
        private readonly LoggerInterface        $logger,
    ) {}

    /**
     * Busca e salva alertas de um único feed.
     *
     * @return int Número de alertas novos/atualizados
     * @throws \Throwable em caso de erro HTTP ou JSON inválido
     */
    public function fetchAndPersist(MonitoredLink $link): int
    {
        $response = $this->httpClient->request('GET', $link->getUrl(), [
            'timeout' => 15,
            'headers' => ['Accept' => 'application/json'],
        ]);

        $data = $response->toArray();

        $alerts = $data['alerts'] ?? [];

        if (!is_array($alerts)) {
            throw new \UnexpectedValueException('Campo "alerts" ausente ou inválido no feed.');
        }

        $feedStartMillis = isset($data['startTimeMillis'])
            ? (int) $data['startTimeMillis']
            : null;

        $partner  = $link->getPartner();
        $count    = 0;
        $batch    = 0;

        foreach ($alerts as $raw) {
            $uuid = $raw['uuid'] ?? null;

            if (!$uuid) {
                continue;
            }

            // Upsert: busca existente pelo wazeId único
            $alert = $this->alertRepo->findOneBy(['wazeId' => $uuid]);
            $isNew = $alert === null;

            if ($isNew) {
                $alert = new WazeAlert();
                $alert->setWazeId($uuid);
            }

            $loc = $raw['location'] ?? [];

            $alert
                ->setPartner($partner)
                ->setSourceLink($link)
                ->setType($raw['type'] ?? 'UNKNOWN')
                ->setSubtype($raw['subtype'] ?? null)
                ->setLatitude((float) ($loc['y'] ?? 0))
                ->setLongitude((float) ($loc['x'] ?? 0))
                ->setStreet($this->truncate($raw['street'] ?? null, 120))
                ->setCity($this->truncate($raw['city'] ?? null, 80))
                ->setCountry($this->truncate($raw['country'] ?? null, 10))
                ->setReliability(isset($raw['reliability']) ? (int) $raw['reliability'] : null)
                ->setConfidence(isset($raw['confidence']) ? (int) $raw['confidence'] : null)
                ->setReportRating(isset($raw['reportRating']) ? (int) $raw['reportRating'] : null)
                ->setNThumbsUp(isset($raw['nThumbsUp']) ? (int) $raw['nThumbsUp'] : null)
                ->setReportDescription($raw['reportDescription'] ?? null)
                ->setMagvar(isset($raw['magvar']) ? (int) $raw['magvar'] : null)
                ->setRoadType(isset($raw['roadType']) ? (int) $raw['roadType'] : null)
                ->setAdditionalInfo($raw['additionalInfo'] ?? null)
                ->setComments($raw['comments'] ?? [])
                ->setPubMillis((int) ($raw['pubMillis'] ?? 0))
                ->setFeedStartMillis($feedStartMillis);

            if ($isNew) {
                $this->em->persist($alert);
            }

            $count++;
            $batch++;

            // Flush em lotes de 50 para não explodir a memória
            if ($batch >= 50) {
                $this->em->flush();
                $this->em->clear(WazeAlert::class);
                $batch = 0;
            }
        }

        $this->em->flush();

        $this->logger->info('[WazeFeed] Feed coletado', [
            'link'    => $link->getName(),
            'url'     => $link->getUrl(),
            'partner' => $partner?->getSlug(),
            'alerts'  => $count,
        ]);

        return $count;
    }

    private function truncate(?string $value, int $max): ?string
    {
        if ($value === null) return null;
        return mb_strlen($value) > $max ? mb_substr($value, 0, $max) : $value;
    }
}
