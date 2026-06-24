<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\MonitoredLink;
use App\Entity\WazeTrafficJam;
use App\Repository\WazeTrafficJamRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Coleta jams de tr\u00e1fego do feed TVT do Waze e persiste com upsert.
 *
 * Formato da URL: https://www.waze.com/row-partnerhub-api/feeds-tvt/{uuid}?id={partnerId}
 *
 * JSON retornado:
 * {
 *   "startTimeMillis": ...,
 *   "endTimeMillis": ...,
 *   "jams": [
 *     {
 *       "uuid": "...",        <- chave \u00fanica
 *       "street": "...",
 *       "city": "...",
 *       "country": "BR",
 *       "level": 3,           <- 0=livre .. 5=parado
 *       "speedKMH": 12.5,
 *       "length": 450,        <- metros
 *       "delay": 120,         <- segundos
 *       "type": "NONE",
 *       "turnType": "NONE",
 *       "roadType": 2,
 *       "startNode": "...",
 *       "endNode": "...",
 *       "causedBy": "uuid-alert",
 *       "line": [{"x":-43.9,"y":-19.9},...],
 *       "segments": [],
 *       "pubMillis": ...
 *     }
 *   ]
 * }
 */
class WazeTrafficFeedService
{
    public function __construct(
        private readonly HttpClientInterface      $httpClient,
        private readonly EntityManagerInterface  $em,
        private readonly WazeTrafficJamRepository $jamRepo,
        private readonly LoggerInterface          $logger,
    ) {}

    /**
     * Busca e salva jams de um feed TVT.
     *
     * @return int N\u00famero de jams novos/atualizados
     */
    public function fetchAndPersist(MonitoredLink $link): int
    {
        $response = $this->httpClient->request('GET', $link->getUrl(), [
            'timeout' => 15,
            'headers' => ['Accept' => 'application/json'],
        ]);

        $data = $response->toArray();
        $jams = $data['jams'] ?? [];

        if (!is_array($jams)) {
            throw new \UnexpectedValueException('Campo "jams" ausente ou inv\u00e1lido no feed TVT.');
        }

        $feedStartMillis = isset($data['startTimeMillis'])
            ? (int) $data['startTimeMillis']
            : null;

        $partner = $link->getPartner();
        $count   = 0;
        $batch   = 0;

        foreach ($jams as $raw) {
            $uuid = $raw['uuid'] ?? null;
            if (!$uuid) continue;

            $jam   = $this->jamRepo->findOneBy(['wazeId' => $uuid]);
            $isNew = $jam === null;

            if ($isNew) {
                $jam = new WazeTrafficJam();
                $jam->setWazeId($uuid);
            }

            $jam
                ->setPartner($partner)
                ->setSourceLink($link)
                ->setStreet($this->trunc($raw['street'] ?? null, 120))
                ->setCity($this->trunc($raw['city'] ?? null, 80))
                ->setCountry($this->trunc($raw['country'] ?? null, 10))
                ->setLevel(isset($raw['level']) ? (int) $raw['level'] : null)
                ->setSpeedKmh(isset($raw['speedKMH']) ? (float) $raw['speedKMH'] : null)
                ->setLength(isset($raw['length']) ? (float) $raw['length'] : null)
                ->setDelay(isset($raw['delay']) ? (int) $raw['delay'] : null)
                ->setType($raw['type'] ?? null)
                ->setTurnType($raw['turnType'] ?? null)
                ->setRoadType(isset($raw['roadType']) ? (int) $raw['roadType'] : null)
                ->setStartNode($this->trunc($raw['startNode'] ?? null, 200))
                ->setEndNode($this->trunc($raw['endNode'] ?? null, 200))
                ->setCausedBy($this->trunc($raw['causedBy'] ?? null, 80))
                ->setLine($raw['line'] ?? [])
                ->setSegments($raw['segments'] ?? [])
                ->setPubMillis((int) ($raw['pubMillis'] ?? 0))
                ->setFeedStartMillis($feedStartMillis);

            if ($isNew) {
                $this->em->persist($jam);
            }

            $count++;
            $batch++;

            if ($batch >= 50) {
                $this->em->flush();
                $this->em->clear(WazeTrafficJam::class);
                $batch = 0;
            }
        }

        $this->em->flush();

        $this->logger->info('[WazeTraffic] Feed coletado', [
            'link'    => $link->getName(),
            'partner' => $partner?->getSlug(),
            'jams'    => $count,
        ]);

        return $count;
    }

    private function trunc(?string $v, int $max): ?string
    {
        if ($v === null) return null;
        return mb_strlen($v) > $max ? mb_substr($v, 0, $max) : $v;
    }
}
