<?php

namespace App\Entity;

use App\Repository\WazeAlertRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: WazeAlertRepository::class)]
#[ORM\Table(name: 'waze_alert')]
#[ORM\Index(columns: ['partner_id', 'pub_millis'])]
class WazeAlert
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    private ?Partner $partner = null;

    #[ORM\Column(name: 'alert_id', length: 100, unique: true)]
    #[Assert\NotBlank]
    private ?string $alertId = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $street = null;

    #[ORM\Column(name: 'city', length: 150, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(name: 'country', length: 50, nullable: true)]
    private ?string $country = null;

    #[ORM\Column(name: 'alert_type', length: 50, nullable: true)]
    private ?string $alertType = null;

    #[ORM\Column(name: 'alert_subtype', length: 100, nullable: true)]
    private ?string $alertSubtype = null;

    #[ORM\Column(name: 'reliability', type: Types::SMALLINT, nullable: true)]
    private ?int $reliability = null;

    #[ORM\Column(name: 'report_description', type: Types::TEXT, nullable: true)]
    private ?string $reportDescription = null;

    #[ORM\Column(name: 'report_rating', type: Types::SMALLINT, nullable: true)]
    private ?int $reportRating = null;

    #[ORM\Column(name: 'confidence', type: Types::SMALLINT, nullable: true)]
    private ?int $confidence = null;

    #[ORM\Column(name: 'pub_millis', type: Types::BIGINT)]
    #[Assert\Positive]
    private ?int $pubMillis = null;

    #[ORM\Column(name: 'pub_utc_date', type: Types::DATETIME_IMMUTABLE)]
    #[Assert\NotNull]
    private \DateTimeImmutable $pubUtcDate;

    #[ORM\Column(name: 'location_latitude', type: Types::DECIMAL, precision: 10, scale: 7)]
    #[Assert\Range(min: -90, max: 90)]
    private ?string $locationLatitude = null;

    #[ORM\Column(name: 'location_longitude', type: Types::DECIMAL, precision: 10, scale: 7)]
    #[Assert\Range(min: -180, max: 180)]
    private ?string $locationLongitude = null;

    #[ORM\Column(name: 'magvar', type: Types::DECIMAL, precision: 10, scale: 7, nullable: true)]
    private ?string $magvar = null;

    #[ORM\Column(name: 'num_thumbs_up', type: Types::SMALLINT, nullable: true)]
    #[Assert\PositiveOrZero]
    private ?int $numThumbsUp = null;

    #[ORM\Column(name: 'num_comments', type: Types::SMALLINT, nullable: true)]
    #[Assert\PositiveOrZero]
    private ?int $numComments = null;

    #[ORM\Column(name: 'report_by', length: 100, nullable: true)]
    private ?string $reportBy = null;

    #[ORM\Column(name: 'source_payload', type: Types::JSON)]
    private array $sourcePayload = [];

    #[ORM\Column(name: 'is_active', options: ['default' => 1])]
    private int $isActive = 1;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int { return $this->id; }
    public function getPartner(): ?Partner { return $this->partner; }
    public function setPartner(?Partner $partner): static { $this->partner = $partner; return $this; }
    public function getAlertId(): ?string { return $this->alertId; }
    public function setAlertId(string $alertId): static { $this->alertId = $alertId; return $this; }
    public function getStreet(): ?string { return $this->street; }
    public function setStreet(?string $street): static { $this->street = $street; return $this; }
    public function getCity(): ?string { return $this->city; }
    public function setCity(?string $city): static { $this->city = $city; return $this; }
    public function getCountry(): ?string { return $this->country; }
    public function setCountry(?string $country): static { $this->country = $country; return $this; }
    public function getAlertType(): ?string { return $this->alertType; }
    public function setAlertType(?string $alertType): static { $this->alertType = $alertType; return $this; }
    public function getAlertSubtype(): ?string { return $this->alertSubtype; }
    public function setAlertSubtype(?string $alertSubtype): static { $this->alertSubtype = $alertSubtype; return $this; }
    public function getReliability(): ?int { return $this->reliability; }
    public function setReliability(?int $reliability): static { $this->reliability = $reliability; return $this; }
    public function getReportDescription(): ?string { return $this->reportDescription; }
    public function setReportDescription(?string $reportDescription): static { $this->reportDescription = $reportDescription; return $this; }
    public function getReportRating(): ?int { return $this->reportRating; }
    public function setReportRating(?int $reportRating): static { $this->reportRating = $reportRating; return $this; }
    public function getConfidence(): ?int { return $this->confidence; }
    public function setConfidence(?int $confidence): static { $this->confidence = $confidence; return $this; }
    public function getPubMillis(): ?int { return $this->pubMillis; }
    public function setPubMillis(int $pubMillis): static { $this->pubMillis = $pubMillis; return $this; }
    public function getPubUtcDate(): \DateTimeImmutable { return $this->pubUtcDate; }
    public function setPubUtcDate(\DateTimeImmutable $pubUtcDate): static { $this->pubUtcDate = $pubUtcDate; return $this; }
    public function getLocationLatitude(): ?string { return $this->locationLatitude; }
    public function setLocationLatitude(string $locationLatitude): static { $this->locationLatitude = $locationLatitude; return $this; }
    public function getLocationLongitude(): ?string { return $this->locationLongitude; }
    public function setLocationLongitude(string $locationLongitude): static { $this->locationLongitude = $locationLongitude; return $this; }
    public function getMagvar(): ?string { return $this->magvar; }
    public function setMagvar(?string $magvar): static { $this->magvar = $magvar; return $this; }
    public function getNumThumbsUp(): ?int { return $this->numThumbsUp; }
    public function setNumThumbsUp(?int $numThumbsUp): static { $this->numThumbsUp = $numThumbsUp; return $this; }
    public function getNumComments(): ?int { return $this->numComments; }
    public function setNumComments(?int $numComments): static { $this->numComments = $numComments; return $this; }
    public function getReportBy(): ?string { return $this->reportBy; }
    public function setReportBy(?string $reportBy): static { $this->reportBy = $reportBy; return $this; }
    public function getSourcePayload(): array { return $this->sourcePayload; }
    public function setSourcePayload(array $sourcePayload): static { $this->sourcePayload = $sourcePayload; return $this; }
    public function getIsActive(): int { return $this->isActive; }
    public function setIsActive(int $isActive): static { $this->isActive = $isActive; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $createdAt): static { $this->createdAt = $createdAt; return $this; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static { $this->updatedAt = $updatedAt; return $this; }
}
