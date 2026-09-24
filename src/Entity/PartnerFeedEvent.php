<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PartnerFeedEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PartnerFeedEventRepository::class)]
#[ORM\Table(name: 'partner_feed_event')]
#[ORM\Index(columns: ['partner_id'], name: 'idx_pfe_partner')]
#[ORM\Index(columns: ['status'], name: 'idx_pfe_status')]
#[ORM\Index(columns: ['starttime'], name: 'idx_pfe_starttime')]
class PartnerFeedEvent
{
    // ── CIFS types ──────────────────────────────────────────────────────────
    public const TYPE_ROAD_CLOSED = 'ROAD_CLOSED';
    public const TYPE_ACCIDENT    = 'ACCIDENT';
    public const TYPE_HAZARD      = 'HAZARD';
    public const TYPE_POLICE      = 'POLICE';
    public const TYPE_JAM         = 'JAM';
    public const TYPE_CHIT_CHAT   = 'CHIT_CHAT';

    public const TYPES = [
        self::TYPE_ROAD_CLOSED,
        self::TYPE_ACCIDENT,
        self::TYPE_HAZARD,
        self::TYPE_POLICE,
        self::TYPE_JAM,
        self::TYPE_CHIT_CHAT,
    ];

    // ── CIFS subtypes ────────────────────────────────────────────────────────
    public const SUBTYPES = [
        'ROAD_CLOSED' => [
            'ROAD_CLOSED_HAZARD',
            'ROAD_CLOSED_CONSTRUCTION',
            'ROAD_CLOSED_EVENT',
        ],
        'ACCIDENT' => [
            'ACCIDENT_MINOR',
            'ACCIDENT_MAJOR',
        ],
        'HAZARD' => [
            'HAZARD_ON_ROAD',
            'HAZARD_ON_ROAD_CAR_STOPPED',
            'HAZARD_ON_ROAD_CONSTRUCTION',
            'HAZARD_ON_ROAD_EMERGENCY_VEHICLE',
            'HAZARD_ON_ROAD_ICE',
            'HAZARD_ON_ROAD_LANE_CLOSED',
            'HAZARD_ON_ROAD_OBJECT',
            'HAZARD_ON_ROAD_OIL',
            'HAZARD_ON_ROAD_POT_HOLE',
            'HAZARD_ON_ROAD_ROAD_KILL',
            'HAZARD_ON_ROAD_TRAFFIC_LIGHT_FAULT',
            'HAZARD_ON_SHOULDER',
            'HAZARD_ON_SHOULDER_ANIMALS',
            'HAZARD_ON_SHOULDER_CAR_STOPPED',
            'HAZARD_ON_SHOULDER_MISSING_SIGN',
            'HAZARD_WEATHER',
            'HAZARD_WEATHER_FLOOD',
            'HAZARD_WEATHER_FOG',
            'HAZARD_WEATHER_FREEZING_RAIN',
            'HAZARD_WEATHER_HAIL',
            'HAZARD_WEATHER_HEAT_WAVE',
            'HAZARD_WEATHER_HEAVY_RAIN',
            'HAZARD_WEATHER_HEAVY_SNOW',
            'HAZARD_WEATHER_HURRICANE',
            'HAZARD_WEATHER_MONSOON',
            'HAZARD_WEATHER_TORNADO',
        ],
        'JAM' => [
            'JAM_LIGHT_TRAFFIC',
            'JAM_MODERATE_TRAFFIC',
            'JAM_HEAVY_TRAFFIC',
            'JAM_STAND_STILL_TRAFFIC',
        ],
        'POLICE' => [
            'POLICE_VISIBLE',
            'POLICE_HIDING',
            'POLICE_WITH_MOBILE_CAMERA',
        ],
    ];

    public const DIRECTION_BOTH = 'BOTH_DIRECTIONS';
    public const DIRECTION_ONE  = 'ONE_DIRECTION';

    public const STATUS_ACTIVE   = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_DRAFT    = 'draft';

    // ── Campos ───────────────────────────────────────────────────────────────

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Relação com o Partner existente */
    #[ORM\ManyToOne(targetEntity: Partner::class)]
    #[ORM\JoinColumn(name: 'partner_id', referencedColumnName: 'id', nullable: false)]
    private Partner $partner;

    /**
     * ID estável do incidente no feed CIFS.
     * Único globalmente — prefixado com o code do parceiro para evitar colisão.
     */
    #[ORM\Column(length: 120, unique: true)]
    private string $incidentId;

    #[ORM\Column(length: 30)]
    private string $type;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $subtype = null;

    /** Polyline CIFS: "lat lon lat lon ..." */
    #[ORM\Column(type: Types::TEXT)]
    private string $polyline;

    /** Nome da rua validado via Reverse Geocoding */
    #[ORM\Column(length: 255)]
    private string $street;

    #[ORM\Column(length: 20, options: ['default' => 'ONE_DIRECTION'])]
    private string $direction = self::DIRECTION_ONE;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $starttime;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endtime = null;

    /** Máx. 40 caracteres — Waze trunca além disso no app */
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 20, options: ['default' => 'active'])]
    private string $status = self::STATUS_ACTIVE;

    /** Latitude do ponto de referência para geocodificação reversa */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 7, nullable: true)]
    private ?string $latitude = null;

    /** Longitude do ponto de referência para geocodificação reversa */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 7, nullable: true)]
    private ?string $longitude = null;

    /** JSON bruto retornado pela Reverse Geocoding API do Waze */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $geocodingResult = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    // ── Construtor ───────────────────────────────────────────────────────────

    public function __construct()
    {
        $this->createdAt  = new \DateTimeImmutable();
        $this->incidentId = uniqid('ev-', true);
    }

    // ── Getters / Setters ────────────────────────────────────────────────────

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPartner(): Partner
    {
        return $this->partner;
    }

    public function setPartner(Partner $partner): static
    {
        $this->partner = $partner;

        // Prefixo estável com o code do parceiro
        if (!isset($this->incidentId) || str_starts_with($this->incidentId, 'ev-')) {
            $prefix           = $partner->getCode() ?? 'p';
            $this->incidentId = $prefix . '-' . uniqid('', true);
        }

        return $this;
    }

    public function getIncidentId(): string
    {
        return $this->incidentId;
    }

    public function setIncidentId(string $incidentId): static
    {
        $this->incidentId = $incidentId;

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getSubtype(): ?string
    {
        return $this->subtype;
    }

    public function setSubtype(?string $subtype): static
    {
        $this->subtype = $subtype;

        return $this;
    }

    public function getPolyline(): string
    {
        return $this->polyline;
    }

    public function setPolyline(string $polyline): static
    {
        $this->polyline = $polyline;

        return $this;
    }

    public function getStreet(): string
    {
        return $this->street;
    }

    public function setStreet(string $street): static
    {
        $this->street = $street;

        return $this;
    }

    public function getDirection(): string
    {
        return $this->direction;
    }

    public function setDirection(string $direction): static
    {
        $this->direction = $direction;

        return $this;
    }

    public function getStarttime(): \DateTimeImmutable
    {
        return $this->starttime;
    }

    public function setStarttime(\DateTimeImmutable $starttime): static
    {
        $this->starttime = $starttime;

        return $this;
    }

    public function getEndtime(): ?\DateTimeImmutable
    {
        return $this->endtime;
    }

    public function setEndtime(?\DateTimeImmutable $endtime): static
    {
        $this->endtime = $endtime;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description !== null
            ? mb_substr($description, 0, 40)
            : null;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getLatitude(): ?string
    {
        return $this->latitude;
    }

    public function setLatitude(?string $latitude): static
    {
        $this->latitude = $latitude;

        return $this;
    }

    public function getLongitude(): ?string
    {
        return $this->longitude;
    }

    public function setLongitude(?string $longitude): static
    {
        $this->longitude = $longitude;

        return $this;
    }

    public function getGeocodingResult(): ?array
    {
        return $this->geocodingResult;
    }

    public function setGeocodingResult(?array $geocodingResult): static
    {
        $this->geocodingResult = $geocodingResult;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Extrai lat/lon do primeiro par da polyline CIFS.
     *
     * @return array{lat: float, lon: float}|null
     */
    public function getFirstPointFromPolyline(): ?array
    {
        $parts = preg_split('/\s+/', trim($this->polyline));

        if (
            $parts !== false
            && count($parts) >= 2
            && is_numeric($parts[0])
            && is_numeric($parts[1])
        ) {
            return ['lat' => (float) $parts[0], 'lon' => (float) $parts[1]];
        }

        return null;
    }
}
