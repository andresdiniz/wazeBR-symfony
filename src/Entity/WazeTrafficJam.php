<?php

namespace App\Entity;

use App\Repository\WazeTrafficJamRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WazeTrafficJamRepository::class)]
#[ORM\Table(name: 'waze_traffic_jam')]
#[ORM\Index(columns: ['partner_id', 'waze_feed_id', 'external_uuid'], name: 'IDX_WAZE_JAM_EXTERNAL')]
#[ORM\Index(columns: ['partner_id', 'dedup_key'], name: 'IDX_WAZE_JAM_DEDUP')]
#[ORM\Index(columns: ['partner_id', 'is_active', 'last_seen_at'], name: 'IDX_WAZE_JAM_ACTIVE_SEEN')]
class WazeTrafficJam
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    // ── Vínculos ────────────────────────────────────────────────────────────

    #[ORM\ManyToOne(targetEntity: Partner::class, inversedBy: 'trafficJams')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Partner $partner = null;

    #[ORM\ManyToOne(targetEntity: WazeFeed::class, inversedBy: 'wazeTrafficJams')]
    #[ORM\JoinColumn(nullable: false)]
    private ?WazeFeed $wazeFeed = null;

    #[ORM\ManyToOne(targetEntity: WazeFeedCollection::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?WazeFeedCollection $lastSeenCollection = null;

    // ── Identificadores ─────────────────────────────────────────────────────

    #[ORM\Column(nullable: true)]
    private ?int $externalId = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $externalUuid = null;

    #[ORM\Column(length: 64)]
    private string $dedupKey = '';

    // ── Localização ─────────────────────────────────────────────────────────

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

    #[ORM\Column(type: 'decimal', precision: 10, scale: 7, nullable: true)]
    private ?string $startLatitude = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 7, nullable: true)]
    private ?string $startLongitude = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 7, nullable: true)]
    private ?string $endLatitude = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 7, nullable: true)]
    private ?string $endLongitude = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $geometryHash = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $geometry = null;

    // ── Métricas ────────────────────────────────────────────────────────────

    #[ORM\Column(nullable: true)]
    private ?int $lengthMeters = null;

    #[ORM\Column(type: 'decimal', precision: 8, scale: 2, nullable: true)]
    private ?string $speedKmh = null;

    #[ORM\Column(type: 'decimal', precision: 8, scale: 2, nullable: true)]
    private ?string $speedMps = null;

    #[ORM\Column(nullable: true)]
    private ?int $delaySeconds = null;

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $level = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $turnType = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $blockingAlertUuid = null;

    // ── Timestamps ──────────────────────────────────────────────────────────

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $publishedAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $firstSeenAt;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $lastSeenAt;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $missingSinceAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $deactivatedAt = null;

    // ── Estado ──────────────────────────────────────────────────────────────

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $rawPayload = null;

    public function __construct()
    {
        $this->firstSeenAt = new \DateTime();
        $this->lastSeenAt = new \DateTime();
    }

    // ── Getters / Setters ────────────────────────────────────────────────────

    public function getId(): ?int { return $this->id; }

    public function getPartner(): ?Partner { return $this->partner; }
    public function setPartner(?Partner $partner): static { $this->partner = $partner; return $this; }

    public function getWazeFeed(): ?WazeFeed { return $this->wazeFeed; }
    public function setWazeFeed(?WazeFeed $wazeFeed): static { $this->wazeFeed = $wazeFeed; return $this; }

    public function getLastSeenCollection(): ?WazeFeedCollection { return $this->lastSeenCollection; }
    public function setLastSeenCollection(?WazeFeedCollection $c): static { $this->lastSeenCollection = $c; return $this; }

    public function getExternalId(): ?int { return $this->externalId; }
    public function setExternalId(?int $externalId): static { $this->externalId = $externalId; return $this; }

    public function getExternalUuid(): ?string { return $this->externalUuid; }
    public function setExternalUuid(?string $externalUuid): static { $this->externalUuid = $externalUuid; return $this; }

    public function getDedupKey(): string { return $this->dedupKey; }
    public function setDedupKey(string $dedupKey): static { $this->dedupKey = $dedupKey; return $this; }

    public function getStreet(): ?string { return $this->street; }
    public function setStreet(?string $street): static { $this->street = $street; return $this; }

    public function getStreetNormalized(): ?string { return $this->streetNormalized; }
    public function setStreetNormalized(?string $streetNormalized): static { $this->streetNormalized = $streetNormalized; return $this; }

    public function getCity(): ?string { return $this->city; }
    public function setCity(?string $city): static { $this->city = $city; return $this; }

    public function getCountry(): ?string { return $this->country; }
    public function setCountry(?string $country): static { $this->country = $country; return $this; }

    public function getRoadType(): ?int { return $this->roadType; }
    public function setRoadType(?int $roadType): static { $this->roadType = $roadType; return $this; }

    public function getStartLatitude(): ?string { return $this->startLatitude; }
    public function setStartLatitude(null|string|float $startLatitude): static { $this->startLatitude = $startLatitude !== null ? (string)$startLatitude : null; return $this; }

    public function getStartLongitude(): ?string { return $this->startLongitude; }
    public function setStartLongitude(null|string|float $startLongitude): static { $this->startLongitude = $startLongitude !== null ? (string)$startLongitude : null; return $this; }

    public function getEndLatitude(): ?string { return $this->endLatitude; }
    public function setEndLatitude(null|string|float $endLatitude): static { $this->endLatitude = $endLatitude !== null ? (string)$endLatitude : null; return $this; }

    public function getEndLongitude(): ?string { return $this->endLongitude; }
    public function setEndLongitude(null|string|float $endLongitude): static { $this->endLongitude = $endLongitude !== null ? (string)$endLongitude : null; return $this; }

    public function getGeometryHash(): ?string { return $this->geometryHash; }
    public function setGeometryHash(?string $geometryHash): static { $this->geometryHash = $geometryHash; return $this; }

    public function getGeometry(): ?array { return $this->geometry; }
    public function setGeometry(?array $geometry): static { $this->geometry = $geometry; return $this; }

    public function getLengthMeters(): ?int { return $this->lengthMeters; }
    public function setLengthMeters(?int $lengthMeters): static { $this->lengthMeters = $lengthMeters; return $this; }

    public function getSpeedKmh(): ?string { return $this->speedKmh; }
    public function setSpeedKmh(?string $speedKmh): static { $this->speedKmh = $speedKmh; return $this; }

    public function getSpeedMps(): ?string { return $this->speedMps; }
    public function setSpeedMps(?string $speedMps): static { $this->speedMps = $speedMps; return $this; }

    public function getDelaySeconds(): ?int { return $this->delaySeconds; }
    public function setDelaySeconds(?int $delaySeconds): static { $this->delaySeconds = $delaySeconds; return $this; }

    public function getLevel(): ?int { return $this->level; }
    public function setLevel(?int $level): static { $this->level = $level; return $this; }

    public function getTurnType(): ?string { return $this->turnType; }
    public function setTurnType(?string $turnType): static { $this->turnType = $turnType; return $this; }

    public function getBlockingAlertUuid(): ?string { return $this->blockingAlertUuid; }
    public function setBlockingAlertUuid(?string $blockingAlertUuid): static { $this->blockingAlertUuid = $blockingAlertUuid; return $this; }

    public function getPublishedAt(): ?\DateTimeInterface { return $this->publishedAt; }
    public function setPublishedAt(?\DateTimeInterface $publishedAt): static { $this->publishedAt = $publishedAt; return $this; }

    public function getFirstSeenAt(): \DateTimeInterface { return $this->firstSeenAt; }
    public function setFirstSeenAt(\DateTimeInterface $firstSeenAt): static { $this->firstSeenAt = $firstSeenAt; return $this; }

    public function getLastSeenAt(): \DateTimeInterface { return $this->lastSeenAt; }
    public function setLastSeenAt(\DateTimeInterface $lastSeenAt): static { $this->lastSeenAt = $lastSeenAt; return $this; }

    public function getMissingSinceAt(): ?\DateTimeInterface { return $this->missingSinceAt; }
    public function setMissingSinceAt(?\DateTimeInterface $missingSinceAt): static { $this->missingSinceAt = $missingSinceAt; return $this; }

    public function getDeactivatedAt(): ?\DateTimeInterface { return $this->deactivatedAt; }
    public function setDeactivatedAt(?\DateTimeInterface $deactivatedAt): static { $this->deactivatedAt = $deactivatedAt; return $this; }

    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $isActive): static { $this->isActive = $isActive; return $this; }

    public function getRawPayload(): ?array { return $this->rawPayload; }
    public function setRawPayload(?array $rawPayload): static { $this->rawPayload = $rawPayload; return $this; }
}
