# ImplementaÃ§Ã£o: Armazenamento Waze por Partner com Feeds

## VisÃ£o geral da arquitetura

```text
Partner (1) ----< WazeFeed (N)
                      |
                      +-- feedType = EVENTS --> WazeAlert, WazeTrafficJam
                      |
                      +-- feedType = TVT ----> WazeTvtRoute
                                                    |
                                                    +-- WazeTvtRouteDefinition (1..N versÃµes)
                                                    |
                                                    +-- WazeTvtRouteHistory (N mÃ©tricas)
```

**Regra de ouro:** Cada URL do Waze Ã‰ um registro em `WazeFeed`, vinculado a um `Partner`.  
NÃ£o armazenar URLs em `.env`, cÃ³digo hard-coded ou configuraÃ§Ã£o global.

---

## 1. Entidades novas

### 1.1 WazeFeed

**Arquivo:** `src/Entity/WazeFeed.php`

```php
<?php

namespace App\Entity;

use App\Repository\WazeFeedRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: WazeFeedRepository::class)]
#[ORM\Table(name: 'waze_feed')]
class WazeFeed
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Partner::class, inversedBy: 'wazeFeeds')]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    private ?Partner $partner = null;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(['EVENTS', 'TVT'])]
    private string $feedType = 'EVENTS';

    #[ORM\Column(length: 30)]
    private string $provider = 'WAZE';

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $externalPartnerId = null;

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank]
    private string $feedUuid = '';

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $externalRouteId = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    private string $endpointUrl = '';

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $label = null;

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $lastSuccessAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $lastErrorAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $lastErrorMessage = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $updatedAt;

    #[ORM\OneToMany(targetEntity: WazeAlert::class, mappedBy: 'wazeFeed')]
    private Collection $wazeAlerts;

    #[ORM\OneToMany(targetEntity: WazeTrafficJam::class, mappedBy: 'wazeFeed')]
    private Collection $wazeTrafficJams;

    #[ORM\OneToMany(targetEntity: WazeTvtRoute::class, mappedBy: 'wazeFeed')]
    private Collection $wazeTvtRoutes;

    #[ORM\OneToMany(targetEntity: WazeFeedCollection::class, mappedBy: 'wazeFeed')]
    private Collection $collections;

    public function __construct()
    {
        $this->wazeAlerts = new ArrayCollection();
        $this->wazeTrafficJams = new ArrayCollection();
        $this->wazeTvtRoutes = new ArrayCollection();
        $this->collections = new ArrayCollection();
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
    }

    // Getters e setters padrÃ£o...

    public function getUniqueKey(): string
    {
        return sprintf(
            '%d:%s:%s:%s',
            $this->partner?->getId() ?? 0,
            $this->feedType,
            $this->feedUuid,
            $this->externalRouteId ?? ''
        );
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTime();
    }
}
```

### 1.2 WazeFeedCollection

**Arquivo:** `src/Entity/WazeFeedCollection.php`

```php
<?php

namespace App\Entity;

use App\Repository\WazeFeedCollectionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WazeFeedCollectionRepository::class)]
#[ORM\Table(name: 'waze_feed_collection')]
class WazeFeedCollection
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: WazeFeed::class, inversedBy: 'collections')]
    #[ORM\JoinColumn(nullable: false)]
    private ?WazeFeed $wazeFeed = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $startedAt;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $finishedAt = null;

    #[ORM\Column(length: 20)]
    private string $status = 'RUNNING'; // RUNNING, SUCCESS, FAILED, PARTIAL

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $httpStatus = null;

    #[ORM\Column]
    private int $alertsReceived = 0;

    #[ORM\Column]
    private int $jamsReceived = 0;

    #[ORM\Column]
    private int $routesReceived = 0;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $payloadHash = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $createdAt;

    public function __construct()
    {
        $this->startedAt = new \DateTime();
        $this->createdAt = new \DateTime();
    }

    // Getters e setters...

    public function succeed(int $alerts, int $jams, int $routes, ?string $payloadHash): void
    {
        $this->status = 'SUCCESS';
        $this->finishedAt = new \DateTime();
        $this->alertsReceived = $alerts;
        $this->jamsReceived = $jams;
        $this->routesReceived = $routes;
        $this->payloadHash = $payloadHash;
    }

    public function fail(\Throwable $e): void
    {
        $this->status = 'FAILED';
        $this->finishedAt = new \DateTime();
        $this->errorMessage = substr($e->getMessage(), 0, 500);
    }
}
```

---

## 2. Entidades existentes - alteraÃ§Ãµes necessÃ¡rias

### 2.1 WazeAlert

**Arquivo:** `src/Entity/WazeAlert.php`

Adicionar campos:

```php
#[ORM\ManyToOne(targetEntity: Partner::class)]
#[ORM\JoinColumn(nullable: false)]
private ?Partner $partner = null;

#[ORM\ManyToOne(targetEntity: WazeFeed::class, inversedBy: 'wazeAlerts')]
#[ORM\JoinColumn(nullable: false)]
private ?WazeFeed $wazeFeed = null;

#[ORM\Column(length: 50, nullable: true)]
private ?string $externalUuid = null;

#[ORM\Column(length: 64)]
private string $dedupKey = '';

#[ORM\Column(length: 64, nullable: true)]
private ?string $semanticClusterKey = null;

#[ORM\Column(precision: 10, scale: 7)]
private float $latitude = 0.0;

#[ORM\Column(precision: 10, scale: 7)]
private float $longitude = 0.0;

#[ORM\Column(length: 12)]
private string $geohash = '';

#[ORM\Column(length: 255, nullable: true)]
private ?string $street = null;

#[ORM\Column(length: 255, nullable: true)]
private ?string $streetNormalized = null;

#[ORM\Column(length: 150, nullable: true)]
private ?string $city = null;

#[ORM\Column(length: 2, nullable: true)]
private ?string $country = null;

#[ORM\Column(type: 'smallint', nullable: true)]
private ?int $roadType = null;

#[ORM\Column(type: Types::TEXT, nullable: true)]
private ?string $description = null;

#[ORM\Column(type: 'smallint', nullable: true)]
private ?int $confidence = null;

#[ORM\Column(type: 'smallint', nullable: true)]
private ?int $reliability = null;

#[ORM\Column(type: 'smallint', nullable: true)]
private ?int $reportRating = null;

#[ORM\Column(nullable: true)]
private ?int $thumbsUp = null;

#[ORM\Column(type: 'smallint', nullable: true)]
private ?int $magvar = null;

#[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
private ?\DateTimeInterface $reportedAt = null;

#[ORM\Column(type: Types::DATETIME_MUTABLE)]
private \DateTimeInterface $firstSeenAt;

#[ORM\Column(type: Types::DATETIME_MUTABLE)]
private \DateTimeInterface $lastSeenAt;

#[ORM\ManyToOne(targetEntity: WazeFeedCollection::class)]
#[ORM\JoinColumn(nullable: true)]
private ?WazeFeedCollection $lastSeenCollection = null;

#[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
private ?\DateTimeInterface $missingSinceAt = null;

#[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
private ?\DateTimeInterface $deactivatedAt = null;

#[ORM\Column]
private bool $isActive = true;

#[ORM\Column(type: Types::JSON, nullable: true)]
private ?array $rawPayload = null;
```

### 2.2 WazeTrafficJam

**Arquivo:** `src/Entity/WazeTrafficJam.php`

Adicionar campos similares a `WazeAlert`, incluindo:

- `partner`, `wazeFeed`, `externalId`, `externalUuid`, `dedupKey`
- `street`, `streetNormalized`, `city`, `country`, `roadType`
- `startLatitude`, `startLongitude`, `endLatitude`, `endLongitude`
- `geometryHash`, `geometry` (JSON)
- `lengthMeters`, `speedKmh`, `speedMps`, `delaySeconds`, `level`
- `turnType`, `blockingAlertUuid`, `publishedAt`
- `firstSeenAt`, `lastSeenAt`, `lastSeenCollection`, `missingSinceAt`, `deactivatedAt`, `isActive`
- `rawPayload`

### 2.3 WazeTvtRoute

**Arquivo:** `src/Entity/WazeTvtRoute.php`

Manter identidade estÃ¡vel:

```php
#[ORM\ManyToOne(targetEntity: Partner::class)]
private ?Partner $partner = null;

#[ORM\ManyToOne(targetEntity: WazeFeed::class, inversedBy: 'wazeTvtRoutes')]
private ?WazeFeed $wazeFeed = null;

#[ORM\Column(length: 80)]
private string $externalRouteId = '';

#[ORM\Column(length: 80, nullable: true)]
private ?string $externalUuid = null;

#[ORM\Column(length: 200, nullable: true)]
private ?string $label = null;

#[ORM\Column]
private bool $isActive = true;

#[ORM\ManyToOne(targetEntity: WazeTvtRouteDefinition::class)]
#[ORM\JoinColumn(nullable: true)]
private ?WazeTvtRouteDefinition $currentDefinition = null;

#[ORM\Column(type: Types::DATETIME_MUTABLE)]
private \DateTimeInterface $firstSeenAt;

#[ORM\Column(type: Types::DATETIME_MUTABLE)]
private \DateTimeInterface $lastSeenAt;
```

### 2.4 WazeTvtRouteDefinition

**Arquivo:** `src/Entity/WazeTvtRouteDefinition.php`

```php
#[ORM\ManyToOne(targetEntity: WazeTvtRoute::class, inversedBy: 'definitions')]
private ?WazeTvtRoute $wazeTvtRoute = null;

#[ORM\Column]
private int $versionNumber = 1;

#[ORM\Column(length: 64)]
private string $definitionHash = '';

#[ORM\Column(length: 255, nullable: true)]
private ?string $name = null;

#[ORM\Column(length: 255, nullable: true)]
private ?string $originName = null;

#[ORM\Column(length: 255, nullable: true)]
private ?string $destinationName = null;

#[ORM\Column(nullable: true)]
private ?int $distanceMeters = null;

#[ORM\Column(type: Types::JSON)]
private array $geometry = [];

#[ORM\Column(length: 64)]
private string $geometryHash = '';

#[ORM\Column(nullable: true)]
private ?int $segmentCount = null;

#[ORM\Column(type: Types::JSON, nullable: true)]
private ?array $metadata = null;

#[ORM\Column]
private bool $isCurrent = true;

#[ORM\Column(type: Types::DATETIME_MUTABLE)]
private \DateTimeInterface $validFrom;

#[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
private ?\DateTimeInterface $validUntil = null;
```

### 2.5 WazeTvtRouteHistory

**Arquivo:** `src/Entity/WazeTvtRouteHistory.php`

Manter SOMENTE mÃ©tricas temporais:

```php
#[ORM\ManyToOne(targetEntity: WazeTvtRoute::class)]
private ?WazeTvtRoute $wazeTvtRoute = null;

#[ORM\ManyToOne(targetEntity: WazeTvtRouteDefinition::class)]
private ?WazeTvtRouteDefinition $wazeTvtRouteDefinition = null;

#[ORM\ManyToOne(targetEntity: WazeFeedCollection::class)]
private ?WazeFeedCollection $wazeFeedCollection = null;

#[ORM\Column(type: Types::DATETIME_MUTABLE)]
private \DateTimeInterface $observedAt;

#[ORM\Column(nullable: true)]
private ?int $travelTimeSeconds = null;

#[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
private ?string $travelTimeMinutes = null;

#[ORM\Column(type: 'decimal', precision: 8, scale: 2, nullable: true)]
private ?string $speedKmh = null;

#[ORM\Column(nullable: true)]
private ?int $delaySeconds = null;

#[ORM\Column(nullable: true)]
private ?int $lengthMeters = null;

#[ORM\Column(length: 40, nullable: true)]
private ?string $status = null;

#[ORM\Column(type: Types::JSON, nullable: true)]
private ?array $rawMetrics = null;
```

---

## 3. Repositories

### 3.1 WazeFeedRepository

**Arquivo:** `src/Repository/WazeFeedRepository.php`

```php
<?php

namespace App\Repository;

use App\Entity\WazeFeed;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class WazeFeedRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WazeFeed::class);
    }

    public function findActiveEventsFeeds(): array
    {
        return $this->createQueryBuilder('f')
            ->where('f.feedType = :type')
            ->andWhere('f.isActive = :active')
            ->setParameter('type', 'EVENTS')
            ->setParameter('active', true)
            ->getQuery()
            ->getResult();
    }

    public function findActiveTvtFeeds(): array
    {
        return $this->createQueryBuilder('f')
            ->where('f.feedType = :type')
            ->andWhere('f.isActive = :active')
            ->setParameter('type', 'TVT')
            ->setParameter('active', true)
            ->getQuery()
            ->getResult();
    }

    public function findOneByUniqueKey(string $uniqueKey): ?WazeFeed
    {
        // Implementar lÃ³gica de busca por partner, feedType, feedUuid, externalRouteId
        // Ou usar critÃ©ria diretamente no command
    }
}
```

### 3.2 WazeFeedCollectionRepository

**Arquivo:** `src/Repository/WazeFeedCollectionRepository.php`

```php
<?php

namespace App\Repository;

use App\Entity\WazeFeedCollection;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class WazeFeedCollectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WazeFeedCollection::class);
    }

    public function createForFeed(\App\Entity\WazeFeed $feed): WazeFeedCollection
    {
        $collection = new WazeFeedCollection();
        $collection->wazeFeed = $feed;
        $this->getEntityManager()->persist($collection);
        $this->getEntityManager()->flush();
        return $collection;
    }
}
```

### 3.3 WazeAlertRepository

Adicionar mÃ©todos:

```php
public function findOneByExternalUuid(int $partnerId, int $feedId, string $externalUuid): ?WazeAlert
public function findOneByDedupKey(int $partnerId, string $dedupKey): ?WazeAlert
public function findActiveByFeed(int $feedId): array
public function findMissingSince(int $feedId, \DateTimeInterface $cutoff): array
```

### 3.4 WazeTrafficJamRepository

Similar ao `WazeAlertRepository`, com mÃ©todos para busca por `externalId`, `dedupKey`, e ativos por feed.

---

## 4. Services

### 4.1 WazeFeedCollectionService

**Arquivo:** `src/Service/WazeFeedCollectionService.php`

```php
<?php

namespace App\Service;

use App\Entity\WazeFeedCollection;
use App\Repository\WazeFeedCollectionRepository;

class WazeFeedCollectionService
{
    public function __construct(
        private WazeFeedCollectionRepository $collectionRepository
    ) {}

    public function start(\App\Entity\WazeFeed $feed): WazeFeedCollection
    {
        return $this->collectionRepository->createForFeed($feed);
    }

    public function succeed(WazeFeedCollection $collection, int $alerts, int $jams, int $routes, ?string $payloadHash): void
    {
        $collection->succeed($alerts, $jams, $routes, $payloadHash);
        $this->collectionRepository->getEntityManager()->flush();

        // Atualizar lastSuccessAt no feed
        $feed = $collection->getWazeFeed();
        $feed->setLastSuccessAt(new \DateTime());
        $feed->setLastErrorMessage(null);
        $this->collectionRepository->getEntityManager()->flush();
    }

    public function fail(WazeFeedCollection $collection, \Throwable $e): void
    {
        $collection->fail($e);
        $this->collectionRepository->getEntityManager()->flush();

        $feed = $collection->getWazeFeed();
        $feed->setLastErrorAt(new \DateTime());
        $feed->setLastErrorMessage($e->getMessage());
        $this->collectionRepository->getEntityManager()->flush();
    }
}
```

### 4.2 WazeAlertSynchronizer

**Arquivo:** `src/Service/WazeAlertSynchronizer.php`

ResponsÃ¡vel por upsert de alertas com deduplicaÃ§Ã£o.

```php
<?php

namespace App\Service;

use App\Entity\WazeAlert;
use App\Entity\WazeFeed;
use App\Entity\WazeFeedCollection;
use Doctrine\ORM\EntityManagerInterface;

class WazeAlertSynchronizer
{
    public function __construct(
        private EntityManagerInterface $em
    ) {}

    public function upsert(WazeFeed $feed, WazeFeedCollection $collection, array $alertData): WazeAlert
    {
        $externalUuid = $alertData['uuid'] ?? null;
        $partner = $feed->getPartner();

        // Regra 1: UUID externo
        if ($externalUuid) {
            $existing = $this->em->getRepository(WazeAlert::class)
                ->findOneBy(['partner' => $partner, 'wazeFeed' => $feed, 'externalUuid' => $externalUuid]);

            if ($existing) {
                $this->updateAlert($existing, $alertData, $collection);
                return $existing;
            }
        }

        // Regra 2: dedupKey
        $dedupKey = $this->calculateDedupKey($feed, $alertData);
        $existing = $this->em->getRepository(WazeAlert::class)
            ->findOneBy(['partner' => $partner, 'dedupKey' => $dedupKey]);

        if ($existing) {
            $this->updateAlert($existing, $alertData, $collection);
            return $existing;
        }

        // Criar novo
        $alert = new WazeAlert();
        $alert->setPartner($partner);
        $alert->setWazeFeed($feed);
        $alert->setExternalUuid($externalUuid);
        $alert->setDedupKey($dedupKey);
        $this->populateAlert($alert, $alertData);
        $alert->setFirstSeenAt(new \DateTime());
        $alert->setLastSeenAt(new \DateTime());
        $alert->setLastSeenCollection($collection);
        $alert->setIsActive(true);

        $this->em->persist($alert);
        $this->em->flush();

        return $alert;
    }

    private function updateAlert(WazeAlert $alert, array $data, WazeFeedCollection $collection): void
    {
        $this->populateAlert($alert, $data);
        $alert->setLastSeenAt(new \DateTime());
        $alert->setLastSeenCollection($collection);
        $alert->setIsActive(true);
        $alert->setMissingSinceAt(null);
        $alert->setDeactivatedAt(null);
        $this->em->flush();
    }

    private function populateAlert(WazeAlert $alert, array $data): void
    {
        // Preencher todos os campos mutÃ¡veis
        $alert->setType($data['type'] ?? '');
        $alert->setSubtype($data['subtype'] ?? null);
        // ... coordenadas, rua, cidade, etc.
    }

    private function calculateDedupKey(WazeFeed $feed, array $data): string
    {
        // SHA-256 de partner + feed + type + subtype + street_normalized + geohash + pubMillis arredondado
        $streetNormalized = $this->normalizeStreet($data['street'] ?? '');
        $geohash = $this->calculateGeohash((float)($data['location']['y'] ?? 0), (float)($data['location']['x'] ?? 0));
        $pubMillisRounded = isset($data['pubMillis']) ? (int)($data['pubMillis'] / 300000) * 300000 : 0;

        $raw = sprintf(
            '%d|%d|%s|%s|%s|%s|%d',
            $feed->getPartner()->getId(),
            $feed->getId(),
            $data['type'] ?? '',
            $data['subtype'] ?? '',
            $streetNormalized,
            $geohash,
            $pubMillisRounded
        );

        return hash('sha256', $raw);
    }

    private function normalizeStreet(?string $street): string
    {
        if (!$street) {
            return '';
        }
        $street = mb_strtoupper($street);
        $street = iconv('UTF-8', 'ASCII//TRANSLIT', $street);
        $street = preg_replace('/[^A-Z0-9 ]/', '', $street);
        $street = preg_replace('/\s+/', ' ', $street);
        return trim($street);
    }

    private function calculateGeohash(float $lat, float $lon, int $precision = 8): string
    {
        // Implementar ou usar biblioteca de geohash
        // Retornar string de 8 caracteres
        return 'geohash_placeholder';
    }
}
```

### 4.3 WazeEventLifecycleService

**Arquivo:** `src/Service/WazeEventLifecycleService.php`

ResponsÃ¡vel por marcar `missingSinceAt` e desativar eventos apÃ³s tolerÃ¢ncia.

```php
<?php

namespace App\Service;

use App\Entity\WazeFeed;
use App\Entity\WazeFeedCollection;
use App\Repository\WazeAlertRepository;
use App\Repository\WazeTrafficJamRepository;
use Doctrine\ORM\EntityManagerInterface;

class WazeEventLifecycleService
{
    public function __construct(
        private EntityManagerInterface $em,
        private WazeAlertRepository $alertRepository,
        private WazeTrafficJamRepository $jamRepository
    ) {}

    public function markMissingAndDeactivateExpired(WazeFeed $feed, WazeFeedCollection $collection): void
    {
        $now = new \DateTime();
        $cutoffAlerts = (clone $now)->modify('-15 minutes');
        $cutoffJams = (clone $now)->modify('-10 minutes');

        // Alertas ausentes
        $missingAlerts = $this->alertRepository->findMissingSince($feed->getId(), $cutoffAlerts);
        foreach ($missingAlerts as $alert) {
            if (!$alert->getMissingSinceAt()) {
                $alert->setMissingSinceAt($now);
            }
        }

        $expiredAlerts = $this->alertRepository->findExpired($feed->getId(), $cutoffAlerts);
        foreach ($expiredAlerts as $alert) {
            $alert->setIsActive(false);
            $alert->setDeactivatedAt($now);
        }

        // Jams ausentes
        $missingJams = $this->jamRepository->findMissingSince($feed->getId(), $cutoffJams);
        foreach ($missingJams as $jam) {
            if (!$jam->getMissingSinceAt()) {
                $jam->setMissingSinceAt($now);
            }
        }

        $expiredJams = $this->jamRepository->findExpired($feed->getId(), $cutoffJams);
        foreach ($expiredJams as $jam) {
            $jam->setIsActive(false);
            $jam->setDeactivatedAt($now);
        }

        $this->em->flush();
    }
}
```

---

## 5. Commands

### 5.1 WazeCollectFeedCommand

**Arquivo:** `src/Command/WazeCollectFeedCommand.php`

Unifica coleta de `alerts` e `jams`.

```php
<?php

namespace App\Command;

use App\Repository\WazeFeedRepository;
use App\Service\WazeFeedCollectionService;
use App\Service\WazeAlertSynchronizer;
use App\Service\WazeJamSynchronizer;
use App\Service\WazeEventLifecycleService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'waze:collect-feed',
    description: 'Coleta alertas e congestionamentos do feed operacional Waze'
)]
class WazeCollectFeedCommand extends Command
{
    public function __construct(
        private WazeFeedRepository $feedRepository,
        private WazeFeedCollectionService $collectionService,
        private WazeAlertSynchronizer $alertSynchronizer,
        private WazeJamSynchronizer $jamSynchronizer,
        private WazeEventLifecycleService $lifecycleService,
        private HttpClientInterface $httpClient
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $feeds = $this->feedRepository->findActiveEventsFeeds();

        foreach ($feeds as $feed) {
            $collection = $this->collectionService->start($feed);

            try {
                $response = $this->httpClient->request('GET', $feed->getEndpointUrl(), [
                    'timeout' => 30,
                    'headers' => ['Accept' => 'application/json']
                ]);

                $payload = $response->toArray();
                $payloadHash = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

                $alertsCount = 0;
                foreach ($payload['alerts'] ?? [] as $alertData) {
                    $this->alertSynchronizer->upsert($feed, $collection, $alertData);
                    $alertsCount++;
                }

                $jamsCount = 0;
                foreach ($payload['jams'] ?? [] as $jamData) {
                    $this->jamSynchronizer->upsert($feed, $collection, $jamData);
                    $jamsCount++;
                }

                $this->collectionService->succeed($collection, $alertsCount, $jamsCount, 0, $payloadHash);
                $this->lifecycleService->markMissingAndDeactivateExpired($feed, $collection);

                $output->writeln(sprintf(
                    '[%s] Feed %d: %d alerts, %d jams',
                    (new \DateTime())->format('Y-m-d H:i:s'),
                    $feed->getId(),
                    $alertsCount,
                    $jamsCount
                ));

            } catch (\Throwable $e) {
                $this->collectionService->fail($collection, $e);
                $output->writeln(sprintf(
                    '[%s] Feed %d FAILED: %s',
                    (new \DateTime())->format('Y-m-d H:i:s'),
                    $feed->getId(),
                    $e->getMessage()
                ));
            }
        }

        return Command::SUCCESS;
    }
}
```

### 5.2 WazeCollectTvtCommand

**Arquivo:** `src/Command/WazeCollectTvtCommand.php`

Similar ao acima, mas focado em TVT.

---

## 6. Migration SQL

**Arquivo:** `migrations/Version20260908220000.php`

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260908220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Refatoracao Waze: feeds por partner, deduplicacao e ciclo de vida';
    }

    public function up(Schema $schema): void
    {
        // 1. Criar tabela waze_feed
        $this->addSql('
            CREATE TABLE waze_feed (
                id BIGINT AUTO_INCREMENT NOT NULL,
                partner_id BIGINT NOT NULL,
                feed_type VARCHAR(20) NOT NULL,
                provider VARCHAR(30) NOT NULL,
                external_partner_id VARCHAR(80) DEFAULT NULL,
                feed_uuid VARCHAR(50) NOT NULL,
                external_route_id VARCHAR(80) DEFAULT NULL,
                endpoint_url TEXT NOT NULL,
                label VARCHAR(150) DEFAULT NULL,
                is_active TINYINT(1) NOT NULL,
                last_success_at DATETIME DEFAULT NULL,
                last_error_at DATETIME DEFAULT NULL,
                last_error_message TEXT DEFAULT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX IDX_WAZE_FEED_PARTNER (partner_id),
                INDEX IDX_WAZE_FEED_TYPE_ACTIVE (feed_type, is_active),
                UNIQUE KEY UQ_WAZE_FEED_UNIQUE (partner_id, feed_type, feed_uuid, external_route_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');

        $this->addSql('ALTER TABLE waze_feed ADD CONSTRAINT FK_WAZE_FEED_PARTNER FOREIGN KEY (partner_id) REFERENCES partner (id)');

        // 2. Criar tabela waze_feed_collection
        $this->addSql('
            CREATE TABLE waze_feed_collection (
                id BIGINT AUTO_INCREMENT NOT NULL,
                waze_feed_id BIGINT NOT NULL,
                started_at DATETIME NOT NULL,
                finished_at DATETIME DEFAULT NULL,
                status VARCHAR(20) NOT NULL,
                http_status SMALLINT DEFAULT NULL,
                alerts_received INT NOT NULL,
                jams_received INT NOT NULL,
                routes_received INT NOT NULL,
                payload_hash VARCHAR(64) DEFAULT NULL,
                error_message TEXT DEFAULT NULL,
                created_at DATETIME NOT NULL,
                INDEX IDX_WAZE_COLLECTION_FEED (waze_feed_id, started_at DESC),
                INDEX IDX_WAZE_COLLECTION_STATUS (status, started_at DESC),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');

        $this->addSql('ALTER TABLE waze_feed_collection ADD CONSTRAINT FK_WAZE_COLLECTION_FEED FOREIGN KEY (waze_feed_id) REFERENCES waze_feed (id)');

        // 3. Adicionar colunas em waze_alert
        $this->addSql('ALTER TABLE waze_alert ADD partner_id BIGINT NOT NULL');
        $this->addSql('ALTER TABLE waze_alert ADD waze_feed_id BIGINT NOT NULL');
        $this->addSql('ALTER TABLE waze_alert ADD external_uuid VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE waze_alert ADD dedup_key VARCHAR(64) NOT NULL');
        $this->addSql('ALTER TABLE waze_alert ADD semantic_cluster_key VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE waze_alert ADD latitude DECIMAL(10, 7) NOT NULL');
        $this->addSql('ALTER TABLE waze_alert ADD longitude DECIMAL(10, 7) NOT NULL');
        $this->addSql('ALTER TABLE waze_alert ADD geohash VARCHAR(12) NOT NULL');
        $this->addSql('ALTER TABLE waze_alert ADD street VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE waze_alert ADD street_normalized VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE waze_alert ADD city VARCHAR(150) DEFAULT NULL');
        $this->addSql('ALTER TABLE waze_alert ADD country VARCHAR(2) DEFAULT NULL');
        $this->addSql('ALTER TABLE waze_alert ADD road_type SMALLINT DEFAULT NULL');
        $this->addSql('ALTER TABLE waze_alert ADD description TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE waze_alert ADD confidence SMALLINT DEFAULT NULL');
        $this->addSql('ALTER TABLE waze_alert ADD reliability SMALLINT DEFAULT NULL');
        $this->addSql('ALTER TABLE waze_alert ADD report_rating SMALLINT DEFAULT NULL');
        $this->addSql('ALTER TABLE waze_alert ADD thumbs_up INT DEFAULT NULL');
        $this->addSql('ALTER TABLE waze_alert ADD magvar SMALLINT DEFAULT NULL');
        $this->addSql('ALTER TABLE waze_alert ADD reported_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE waze_alert ADD first_seen_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE waze_alert ADD last_seen_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE waze_alert ADD last_seen_collection_id BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE waze_alert ADD missing_since_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE waze_alert ADD deactivated_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE waze_alert ADD is_active TINYINT(1) NOT NULL DEFAULT 1');
        $this->addSql('ALTER TABLE waze_alert ADD raw_payload JSON DEFAULT NULL');

        $this->addSql('ALTER TABLE waze_alert ADD CONSTRAINT FK_WAZE_ALERT_PARTNER FOREIGN KEY (partner_id) REFERENCES partner (id)');
        $this->addSql('ALTER TABLE waze_alert ADD CONSTRAINT FK_WAZE_ALERT_FEED FOREIGN KEY (waze_feed_id) REFERENCES waze_feed (id)');
        $this->addSql('ALTER TABLE waze_alert ADD CONSTRAINT FK_WAZE_ALERT_COLLECTION FOREIGN KEY (last_seen_collection_id) REFERENCES waze_feed_collection (id)');

        $this->addSql('CREATE INDEX IDX_WAZE_ALERT_EXTERNAL ON waze_alert (partner_id, waze_feed_id, external_uuid)');
        $this->addSql('CREATE INDEX IDX_WAZE_ALERT_DEDUP ON waze_alert (partner_id, dedup_key)');
        $this->addSql('CREATE INDEX IDX_WAZE_ALERT_ACTIVE_SEEN ON waze_alert (partner_id, is_active, last_seen_at DESC)');

        // 4. Adicionar colunas similares em waze_traffic_jam
        // ... (mesmo padrÃ£o)

        // 5. Adicionar colunas em waze_tvt_route
        $this->addSql('ALTER TABLE waze_tvt_route ADD partner_id BIGINT NOT NULL');
        $this->addSql('ALTER TABLE waze_tvt_route ADD waze_feed_id BIGINT NOT NULL');
        // ...

        $this->addSql('ALTER TABLE waze_tvt_route ADD CONSTRAINT FK_WAZE_TVT_ROUTE_PARTNER FOREIGN KEY (partner_id) REFERENCES partner (id)');
        $this->addSql('ALTER TABLE waze_tvt_route ADD CONSTRAINT FK_WAZE_TVT_ROUTE_FEED FOREIGN KEY (waze_feed_id) REFERENCES waze_feed (id)');

        // 6. Atualizar waze_tvt_route_definition e waze_tvt_route_history conforme especificaÃ§Ã£o
        // ...
    }

    public function down(Schema $schema): void
    {
        // Reverter alteraÃ§Ãµes (opcional, mas recomendado)
        $this->addSql('DROP TABLE waze_feed_collection');
        $this->addSql('DROP TABLE waze_feed');
        // ... remover colunas adicionadas
    }
}
```

---

## 7. Como vincular cada URL ao Partner

### Passo 1: Criar Partner (se nÃ£o existir)

```bash
php bin/console app:create-partner "Prefeitura CL" cl
```

### Passo 2: Criar WazeFeed via command ou admin

**OpÃ§Ã£o A: Command manual**

```bash
php bin/console app:create-waze-feed \
    --partner=1 \
    --type=EVENTS \
    --uuid=9bb3e551-76f2-4fc6-a32e-ad078a285f2e \
    --url="https://www.waze.com/row-partnerhub-api/partners/11682863520/waze-feeds/9bb3e551-76f2-4fc6-a32e-ad078a285f2e?format=1" \
    --label="Feed operacional CL"
```

**OpÃ§Ã£o B: Admin UI**

Criar formulÃ¡rio em `/admin/waze-feed/new`:

- Partner: dropdown com parceiros
- Feed Type: EVENTS ou TVT
- External Partner ID: `11682863520` (opcional)
- Feed UUID: `9bb3e551-76f2-4fc6-a32e-ad078a285f2e`
- External Route ID: vazio para EVENTS, `12699055487` para TVT
- Endpoint URL: URL completa
- Label: nome administrativo

### Passo 3: Configurar cron

```bash
# Feed operacional (alerts + jams)
*/5 * * * * cd /path/to/app && php bin/console waze:collect-feed --env=prod >> /var/log/waze-feed.log 2>&1

# TVT (rotas)
*/5 * * * * cd /path/to/app && php bin/console waze:collect-tvt --env=prod >> /var/log/waze-tvt.log 2>&1
```

---

## 8. Checklist de remoÃ§Ã£o de duplicatas

Antes de apagar qualquer entidade/command:

- [ ] Verificar se hÃ¡ referÃªncias em controllers, templates, services
- [ ] Migrar dados existentes para novas tabelas
- [ ] Atualizar imports e type hints
- [ ] Rodar testes
- [ ] Fazer backup do banco

**Entidades para remover/apenas após migraÃ§Ã£o:**

- `WazeTvtRouteExecution` (substituÃ³do por `WazeFeedCollection` + `WazeTvtRouteHistory`)
- `WazeTvtRouteExecutionCoord` (se existir e nÃ£o for necessÃ¡ria)
- `WazeTvtSnapshot` (se redundante com `WazeTvtRouteHistory`)
- Commands duplicados que nÃ£o usam `WazeFeed`

---

## 9. PrÃ³ximos passos

1. Aplicar migration em ambiente de desenvolvimento
2. Criar feeds de teste via command ou admin
3. Rodar `waze:collect-feed` e `waze:collect-tvt` manualmente
4. Verificar no banco se os dados estÃ£o vinculados corretamente ao Partner
5. Ajustar deduplicaÃ§Ã£o (geohash, normalizaÃ§Ã£o de rua)
6. Implementar UI administrativa para gerenciar feeds
7. Remover classes duplicadas apÃ³s validaÃ§Ã£o

---

## 10. DÃºvidas frequentes

### Q: Posso ter mÃºltiplos feeds para o mesmo Partner?

Sim. Um Partner pode ter vÃ¡rios `WazeFeed`:
- Um para EVENTS (alertas/jams)
- Um ou mais para TVT (rotas diferentes)

### Q: Como evitar duplicaÃ§Ã£o de alertas prÃ³ximos?

Use `semanticClusterKey` e consultas espaciais (geohash ou `ST_Distance_Sphere`) para identificar alertas dentro de um raio (ex.: 30m para buracos, 100m para interdiÃ§Ãµes).

### Q: O que fazer se o feed falhar?

O `WazeFeedCollection` registra o erro. A rotina de desativaÃ§Ã£o sÃ³ roda apÃ³s coletas com `SUCCESS`, evitando desligar alertas por falha temporÃ¡ria.

### Q: Como versionar rotas TVT?

Calcular `definitionHash` com geometria e metadados estÃ¡veis. Se mudar, criar nova `WazeTvtRouteDefinition` e apontar `currentDefinition` na rota.

---

**Fim do documento.**