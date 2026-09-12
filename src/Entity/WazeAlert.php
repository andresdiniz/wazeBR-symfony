<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WazeAlertRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WazeAlertRepository::class)]
#[ORM\Table(name: 'waze_alerts', indexes: [
    new ORM\Index(name: 'idx_waze_alert_uuid', columns: ['uuid']),
    new ORM\Index(name: 'idx_waze_alert_partner_active', columns: ['partner_id', 'is_active']),
    new ORM\Index(name: 'idx_waze_alert_city_type', columns: ['city', 'type']),
    new ORM\Index(name: 'idx_waze_alert_pub_millis', columns: ['pub_millis']),
    new ORM\Index(name: 'idx_waze_alert_collected_at', columns: ['collected_at']),
    new ORM\Index(name: 'idx_waze_alert_last_seen_at', columns: ['last_seen_at']),
    new ORM\Index(name: 'idx_waze_alert_location', columns: ['longitude', 'latitude']),
])]
#[ORM\UniqueConstraint(name: 'uniq_waze_alert_partner_uuid', columns: ['partner_id', 'uuid'])]
class WazeAlert
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'wazeAlerts')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Partner $partner = null;

    #[ORM\Column(length: 100)]
    private ?string $uuid = null;

    #[ORM\Column(length: 50)]
    private ?string $type = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $subtype = null;

    #[ORM\Column(type: Types::BIGINT)]
    private ?int $pubMillis = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $reportByMunicipalityUser = false;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $reportRating = 0;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $confidence = 0;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $reliability = 0;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 7)]
    private ?string $longitude = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 7)]
    private ?string $latitude = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $street = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(length: 2)]
    private ?string $country = 'BR';

    #[ORM\Column(type: Types::SMALLINT)]
    private int $roadType = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $reportDescription = null;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $nThumbsUp = 0;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $magvar = 0;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $collectedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $lastSeenAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deactivatedAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPartner(): ?Partner
    {
        return $this->partner;
    }

    public function setPartner(?Partner $partner): static
    {
        $this->partner = $partner;

        return $this;
    }

    public function getUuid(): ?string
    {
        return $this->uuid;
    }

    public function setUuid(string $uuid): static
    {
        $this->uuid = $uuid;

        return $this;
    }

    public function getType(): ?string
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

    public function getPubMillis(): ?int
    {
        return $this->pubMillis;
    }

    public function setPubMillis(int $pubMillis): static
    {
        $this->pubMillis = $pubMillis;

        return $this;
    }

    public function isReportByMunicipalityUser(): bool
    {
        return $this->reportByMunicipalityUser;
    }

    public function setReportByMunicipalityUser(bool $reportByMunicipalityUser): static
    {
        $this->reportByMunicipalityUser = $reportByMunicipalityUser;

        return $this;
    }

    public function getReportRating(): int
    {
        return $this->reportRating;
    }

    public function setReportRating(int $reportRating): static
    {
        $this->reportRating = $reportRating;

        return $this;
    }

    public function getConfidence(): int
    {
        return $this->confidence;
    }

    public function setConfidence(int $confidence): static
    {
        $this->confidence = $confidence;

        return $this;
    }

    public function getReliability(): int
    {
        return $this->reliability;
    }

    public function setReliability(int $reliability): static
    {
        $this->reliability = $reliability;

        return $this;
    }

    public function getLongitude(): ?string
    {
        return $this->longitude;
    }

    public function setLongitude(float|string $longitude): static
    {
        $this->longitude = (string) $longitude;

        return $this;
    }

    public function getLatitude(): ?string
    {
        return $this->latitude;
    }

    public function setLatitude(float|string $latitude): static
    {
        $this->latitude = (string) $latitude;

        return $this;
    }

    public function getStreet(): ?string
    {
        return $this->street;
    }

    public function setStreet(?string $street): static
    {
        $this->street = $street;

        return $this;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function setCity(?string $city): static
    {
        $this->city = $city;

        return $this;
    }

    public function getCountry(): ?string
    {
        return $this->country;
    }

    public function setCountry(string $country): static
    {
        $this->country = $country;

        return $this;
    }

    public function getRoadType(): int
    {
        return $this->roadType;
    }

    public function setRoadType(int $roadType): static
    {
        $this->roadType = $roadType;

        return $this;
    }

    public function getReportDescription(): ?string
    {
        return $this->reportDescription;
    }

    public function setReportDescription(?string $reportDescription): static
    {
        $this->reportDescription = $reportDescription;

        return $this;
    }

    public function getNThumbsUp(): int
    {
        return $this->nThumbsUp;
    }

    public function setNThumbsUp(int $nThumbsUp): static
    {
        $this->nThumbsUp = $nThumbsUp;

        return $this;
    }

    public function getMagvar(): int
    {
        return $this->magvar;
    }

    public function setMagvar(int $magvar): static
    {
        $this->magvar = $magvar;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;

        if ($isActive) {
            $this->deactivatedAt = null;
        }

        return $this;
    }

    public function getCollectedAt(): ?\DateTimeImmutable
    {
        return $this->collectedAt;
    }

    public function setCollectedAt(\DateTimeImmutable $collectedAt): static
    {
        $this->collectedAt = $collectedAt;

        return $this;
    }

    public function getLastSeenAt(): ?\DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function setLastSeenAt(\DateTimeImmutable $lastSeenAt): static
    {
        $this->lastSeenAt = $lastSeenAt;

        return $this;
    }

    public function getDeactivatedAt(): ?\DateTimeImmutable
    {
        return $this->deactivatedAt;
    }

    public function deactivate(\DateTimeImmutable $date): static
    {
        $this->isActive = false;
        $this->deactivatedAt = $date;

        return $this;
    }

    public function getPubDateTime(): ?\DateTimeImmutable
    {
        if ($this->pubMillis === null || $this->pubMillis <= 0) {
            return null;
        }

        return (new \DateTimeImmutable())->setTimestamp((int) floor($this->pubMillis / 1000));
    }

    public function getTypeLabel(): string
    {
        $labels = [
            'HAZARD' => 'Perigo',
            'ROAD_CLOSED' => 'Via fechada',
            'ACCIDENT' => 'Acidente',
            'JAM' => 'Congestionamento',
            'POLICE' => 'Polícia',
            'WEATHERHAZARD' => 'Perigo climático',
        ];

        return $labels[$this->type ?? ''] ?? ($this->type ?? 'Não informado');
    }

    public function getSubtypeLabel(): string
    {
        $labels = [
            'HAZARD_ON_ROAD_POT_HOLE' => 'Buraco na pista',
            'HAZARD_ON_ROAD_CONSTRUCTION' => 'Obras na via',
            'HAZARD_ON_ROAD_OBJECT' => 'Objeto na pista',
            'HAZARD_WEATHER_FLOOD' => 'Alagamento',
            'HAZARD_WEATHER_FOG' => 'Neblina',
        ];

        return $labels[$this->subtype ?? ''] ?? ($this->subtype ?? '');
    }
}
