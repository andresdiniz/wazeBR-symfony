<?php

namespace App\Entity;

use App\Repository\WazeAlertRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: WazeAlertRepository::class)]
#[ORM\Table(name: 'waze_alert')]
#[ORM\Index(columns: ['partner_id'])]
#[ORM\Index(columns: ['type'])]
#[ORM\Index(columns: ['city'])]
#[ORM\Index(columns: ['pub_millis'])]
class WazeAlert
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Partner that owns this alert (via partner_api_link).
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    private ?Partner $partner = null;

    /**
     * Unique alert UUID from Waze.
     */
    #[ORM\Column(length: 64, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 64)]
    private ?string $uuid = null;

    /**
     * Alert type (e.g. ROAD_CLOSED, HAZARD, ACCIDENT, WEATHERHAZARD, JAM).
     */
    #[ORM\Column(length: 50)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 50)]
    private ?string $type = null;

    /**
     * Alert subtype (e.g. HAZARD_ON_ROAD_POT_HOLE, etc.).
     */
    #[ORM\Column(length: 100, nullable: true)]
    #[Assert\Length(max: 100)]
    private ?string $subtype = null;

    /**
     * Publication time in milliseconds since epoch.
     */
    #[ORM\Column(type: Types::BIGINT)]
    #[Assert\NotBlank]
    private ?string $pubMillis = null;

    /**
     * Whether reported by municipality user (string "true"/"false" in API).
     */
    #[ORM\Column(length: 10, nullable: true)]
    #[Assert\Length(max: 10)]
    private ?string $reportByMunicipalityUser = null;

    /**
     * Report rating (1-5 typically).
     */
    #[ORM\Column(nullable: true)]
    #[Assert\Range(min: 1, max: 10)]
    private ?int $reportRating = null;

    /**
     * Confidence level (0-5 typically).
     */
    #[ORM\Column(nullable: true)]
    #[Assert\Range(min: 0, max: 10)]
    private ?int $confidence = null;

    /**
     * Reliability score.
     */
    #[ORM\Column(nullable: true)]
    #[Assert\Range(min: 0, max: 10)]
    private ?int $reliability = null;

    /**
     * Location latitude (y in Waze API).
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 6, nullable: true)]
    private ?string $locationY = null;

    /**
     * Location longitude (x in Waze API).
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 6, nullable: true)]
    private ?string $locationX = null;

    /**
     * Street name.
     */
    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $street = null;

    /**
     * City name.
     */
    #[ORM\Column(length: 150, nullable: true)]
    #[Assert\Length(max: 150)]
    private ?string $city = null;

    /**
     * Country code (e.g. BR).
     */
    #[ORM\Column(length: 3, nullable: true)]
    #[Assert\Length(max: 3)]
    private ?string $country = null;

    /**
     * Road type (numeric code from Waze).
     */
    #[ORM\Column(nullable: true)]
    private ?int $roadType = null;

    /**
     * Number of thumbs up on the report.
     */
    #[ORM\Column(nullable: true)]
    private ?int $nThumbsUp = null;

    /**
     * Magnetic variation (magvar) in degrees.
     */
    #[ORM\Column(nullable: true)]
    private ?int $magvar = null;

    /**
     * Local creation timestamp.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    /**
     * Local update timestamp.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

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

    public function setUuid(?string $uuid): static
    {
        $this->uuid = $uuid;
        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(?string $type): static
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

    public function getPubMillis(): ?string
    {
        return $this->pubMillis;
    }

    public function setPubMillis(?string $pubMillis): static
    {
        $this->pubMillis = $pubMillis;
        return $this;
    }

    public function getReportByMunicipalityUser(): ?string
    {
        return $this->reportByMunicipalityUser;
    }

    public function setReportByMunicipalityUser(?string $reportByMunicipalityUser): static
    {
        $this->reportByMunicipalityUser = $reportByMunicipalityUser;
        return $this;
    }

    public function getReportRating(): ?int
    {
        return $this->reportRating;
    }

    public function setReportRating(?int $reportRating): static
    {
        $this->reportRating = $reportRating;
        return $this;
    }

    public function getConfidence(): ?int
    {
        return $this->confidence;
    }

    public function setConfidence(?int $confidence): static
    {
        $this->confidence = $confidence;
        return $this;
    }

    public function getReliability(): ?int
    {
        return $this->reliability;
    }

    public function setReliability(?int $reliability): static
    {
        $this->reliability = $reliability;
        return $this;
    }

    public function getLocationY(): ?string
    {
        return $this->locationY;
    }

    public function setLocationY(?string $locationY): static
    {
        $this->locationY = $locationY;
        return $this;
    }

    public function getLocationX(): ?string
    {
        return $this->locationX;
    }

    public function setLocationX(?string $locationX): static
    {
        $this->locationX = $locationX;
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

    public function setCountry(?string $country): static
    {
        $this->country = $country;
        return $this;
    }

    public function getRoadType(): ?int
    {
        return $this->roadType;
    }

    public function setRoadType(?int $roadType): static
    {
        $this->roadType = $roadType;
        return $this;
    }

    public function getNThumbsUp(): ?int
    {
        return $this->nThumbsUp;
    }

    public function setNThumbsUp(?int $nThumbsUp): static
    {
        $this->nThumbsUp = $nThumbsUp;
        return $this;
    }

    public function getMagvar(): ?int
    {
        return $this->magvar;
    }

    public function setMagvar(?int $magvar): static
    {
        $this->magvar = $magvar;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
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

    /**
     * Return latitude as float (Y coordinate).
     */
    public function getLatitude(): ?float
    {
        return $this->locationY !== null ? (float) $this->locationY : null;
    }

    /**
     * Return longitude as float (X coordinate).
     */
    public function getLongitude(): ?float
    {
        return $this->locationX !== null ? (float) $this->locationX : null;
    }

    /**
     * Convert pubMillis (string) to DateTimeImmutable (UTC).
     */
    public function getPubDateTime(): ?\DateTimeImmutable
    {
        if ($this->pubMillis === null) {
            return null;
        }
        $seconds = (int) (((int) $this->pubMillis) / 1000);
        return \DateTimeImmutable::createFromFormat('U', (string) $seconds, new \DateTimeZone('UTC'));
    }

    public function __toString(): string
    {
        return sprintf(
            'WazeAlert %s (%s) - %s in %s, %s',
            $this->uuid ?? 'no-uuid',
            $this->type ?? 'unknown',
            $this->street ?? 'unknown street',
            $this->city ?? 'unknown city',
            $this->getPartner()?->getName() ?? 'no partner'
        );
    }
}
