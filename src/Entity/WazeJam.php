<?php

namespace App\Entity;

use App\Repository\WazeJamRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: WazeJamRepository::class)]
#[ORM\Table(name: 'waze_jam')]
#[ORM\Index(columns: ['partner_id', 'pub_millis'])]
class WazeJam
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    private ?Partner $partner = null;

    #[ORM\Column(name: 'jam_id', length: 100, unique: true)]
    #[Assert\NotBlank]
    private ?string $jamId = null;

    #[ORM\Column(name: 'street', type: Types::TEXT, nullable: true)]
    private ?string $street = null;

    #[ORM\Column(name: 'city', length: 150, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(name: 'country', length: 50, nullable: true)]
    private ?string $country = null;

    #[ORM\Column(name: 'level', type: Types::SMALLINT, nullable: true)]
    #[Assert\Range(min: 1, max: 5)]
    private ?int $level = null;

    #[ORM\Column(name: 'delay', type: Types::INTEGER, nullable: true)]
    #[Assert\PositiveOrZero]
    private ?int $delay = null;

    #[ORM\Column(name: 'length', type: Types::INTEGER, nullable: true)]
    #[Assert\PositiveOrZero]
    private ?int $length = null;

    #[ORM\Column(name: 'turn_type', length: 50, nullable: true)]
    private ?string $turnType = null;

    #[ORM\Column(name: 'type', length: 50, nullable: true)]
    private ?string $type = null;

    #[ORM\Column(name: 'pub_millis', type: Types::BIGINT)]
    #[Assert\Positive]
    private ?int $pubMillis = null;

    #[ORM\Column(name: 'pub_utc_date', type: Types::DATETIME_IMMUTABLE)]
    #[Assert\NotNull]
    private \DateTimeImmutable $pubUtcDate;

    #[ORM\Column(name: 'start_location_latitude', type: Types::DECIMAL, precision: 10, scale: 7)]
    #[Assert\Range(min: -90, max: 90)]
    private ?string $startLocationLatitude = null;

    #[ORM\Column(name: 'start_location_longitude', type: Types::DECIMAL, precision: 10, scale: 7)]
    #[Assert\Range(min: -180, max: 180)]
    private ?string $startLocationLongitude = null;

    #[ORM\Column(name: 'end_location_latitude', type: Types::DECIMAL, precision: 10, scale: 7)]
    #[Assert\Range(min: -90, max: 90)]
    private ?string $endLocationLatitude = null;

    #[ORM\Column(name: 'end_location_longitude', type: Types::DECIMAL, precision: 10, scale: 7)]
    #[Assert\Range(min: -180, max: 180)]
    private ?string $endLocationLongitude = null;

    #[ORM\Column(name: 'line', type: Types::JSON, nullable: true)]
    private ?array $line = null;

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
    public function getJamId(): ?string { return $this->jamId; }
    public function setJamId(string $jamId): static { $this->jamId = $jamId; return $this; }
    public function getStreet(): ?string { return $this->street; }
    public function setStreet(?string $street): static { $this->street = $street; return $this; }
    public function getCity(): ?string { return $this->city; }
    public function setCity(?string $city): static { $this->city = $city; return $this; }
    public function getCountry(): ?string { return $this->country; }
    public function setCountry(?string $country): static { $this->country = $country; return $this; }
    public function getLevel(): ?int { return $this->level; }
    public function setLevel(?int $level): static { $this->level = $level; return $this; }
    public function getDelay(): ?int { return $this->delay; }
    public function setDelay(?int $delay): static { $this->delay = $delay; return $this; }
    public function getLength(): ?int { return $this->length; }
    public function setLength(?int $length): static { $this->length = $length; return $this; }
    public function getTurnType(): ?string { return $this->turnType; }
    public function setTurnType(?string $turnType): static { $this->turnType = $turnType; return $this; }
    public function getType(): ?string { return $this->type; }
    public function setType(?string $type): static { $this->type = $type; return $this; }
    public function getPubMillis(): ?int { return $this->pubMillis; }
    public function setPubMillis(int $pubMillis): static { $this->pubMillis = $pubMillis; return $this; }
    public function getPubUtcDate(): \DateTimeImmutable { return $this->pubUtcDate; }
    public function setPubUtcDate(\DateTimeImmutable $pubUtcDate): static { $this->pubUtcDate = $pubUtcDate; return $this; }
    public function getStartLocationLatitude(): ?string { return $this->startLocationLatitude; }
    public function setStartLocationLatitude(string $startLocationLatitude): static { $this->startLocationLatitude = $startLocationLatitude; return $this; }
    public function getStartLocationLongitude(): ?string { return $this->startLocationLongitude; }
    public function setStartLocationLongitude(string $startLocationLongitude): static { $this->startLocationLongitude = $startLocationLongitude; return $this; }
    public function getEndLocationLatitude(): ?string { return $this->endLocationLatitude; }
    public function setEndLocationLatitude(string $endLocationLatitude): static { $this->endLocationLatitude = $endLocationLatitude; return $this; }
    public function getEndLocationLongitude(): ?string { return $this->endLocationLongitude; }
    public function setEndLocationLongitude(string $endLocationLongitude): static { $this->endLocationLongitude = $endLocationLongitude; return $this; }
    public function getLine(): ?array { return $this->line; }
    public function setLine(?array $line): static { $this->line = $line; return $this; }
    public function getSourcePayload(): array { return $this->sourcePayload; }
    public function setSourcePayload(array $sourcePayload): static { $this->sourcePayload = $sourcePayload; return $this; }
    public function getIsActive(): int { return $this->isActive; }
    public function setIsActive(int $isActive): static { $this->isActive = $isActive; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $createdAt): static { $this->createdAt = $createdAt; return $this; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static { $this->updatedAt = $updatedAt; return $this; }
}
