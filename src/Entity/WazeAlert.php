<?php

namespace App\Entity;

use App\Repository\WazeAlertRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WazeAlertRepository::class)]
#[ORM\Table(name: 'waze_alert')]
#[ORM\Index(columns: ['partner_id', 'waze_feed_id', 'external_uuid'], name: 'IDX_WAZE_ALERT_EXTERNAL')]
#[ORM\Index(columns: ['partner_id', 'dedup_key'], name: 'IDX_WAZE_ALERT_DEDUP')]
#[ORM\Index(columns: ['partner_id', 'is_active', 'last_seen_at'], name: 'IDX_WAZE_ALERT_ACTIVE_SEEN')]
class WazeAlert
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    // ── Vínculos ────────────────────────────────────────────────────────────

    #[ORM\ManyToOne(targetEntity: Partner::class, inversedBy: 'alerts')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Partner $partner = null;

    #[ORM\ManyToOne(targetEntity: WazeFeed::class, inversedBy: 'wazeAlerts')]
    #[ORM\JoinColumn(nullable: false)]
    private ?WazeFeed $wazeFeed = null;

    #[ORM\ManyToOne(targetEntity: WazeFeedCollection::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?WazeFeedCollection $lastSeenCollection = null;

    // ── Identificadores ─────────────────────────────────────────────────────

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $externalUuid = null;

    #[ORM\Column(length: 64)]
    private string $dedupKey = '';

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $semanticClusterKey = null;

    // ── Tipo ────────────────────────────────────────────────────────────────

    #[ORM\Column(length: 50)]
    private string $type = '';

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $subtype = null;

    // ── Localização ─────────────────────────────────────────────────────────

    #[ORM\Column(type: 'decimal', precision: 10, scale: 7)]
    private string $latitude = '0.0000000';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 7)]
    private string $longitude = '0.0000000';

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

    // ── Métricas ────────────────────────────────────────────────────────────

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

    // ── Timestamps ──────────────────────────────────────────────────────────

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $reportedAt = null;

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

    public function getExternalUuid(): ?string { return $this->externalUuid; }
    public function setExternalUuid(?string $externalUuid): static { $this->externalUuid = $externalUuid; return $this; }

    public function getDedupKey(): string { return $this->dedupKey; }
    public function setDedupKey(string $dedupKey): static { $this->dedupKey = $dedupKey; return $this; }

    public function getSemanticClusterKey(): ?string { return $this->semanticClusterKey; }
    public function setSemanticClusterKey(?string $k): static { $this->semanticClusterKey = $k; return $this; }

    public function getType(): string { return $this->type; }
    public function setType(string $type): static { $this->type = $type; return $this; }

    public function getSubtype(): ?string { return $this->subtype; }
    public function setSubtype(?string $subtype): static { $this->subtype = $subtype; return $this; }

    public function getLatitude(): string { return $this->latitude; }
    public function setLatitude(string|float $latitude): static { $this->latitude = (string)$latitude; return $this; }

    public function getLongitude(): string { return $this->longitude; }
    public function setLongitude(string|float $longitude): static { $this->longitude = (string)$longitude; return $this; }

    public function getGeohash(): string { return $this->geohash; }
    public function setGeohash(string $geohash): static { $this->geohash = $geohash; return $this; }

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

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): static { $this->description = $description; return $this; }

    public function getConfidence(): ?int { return $this->confidence; }
    public function setConfidence(?int $confidence): static { $this->confidence = $confidence; return $this; }

    public function getReliability(): ?int { return $this->reliability; }
    public function setReliability(?int $reliability): static { $this->reliability = $reliability; return $this; }

    public function getReportRating(): ?int { return $this->reportRating; }
    public function setReportRating(?int $reportRating): static { $this->reportRating = $reportRating; return $this; }

    public function getThumbsUp(): ?int { return $this->thumbsUp; }
    public function setThumbsUp(?int $thumbsUp): static { $this->thumbsUp = $thumbsUp; return $this; }

    public function getMagvar(): ?int { return $this->magvar; }
    public function setMagvar(?int $magvar): static { $this->magvar = $magvar; return $this; }

    public function getReportedAt(): ?\DateTimeInterface { return $this->reportedAt; }
    public function setReportedAt(?\DateTimeInterface $reportedAt): static { $this->reportedAt = $reportedAt; return $this; }

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
