<?php

namespace App\Entity;

use App\Repository\CemadenHidroObservationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: CemadenHidroObservationRepository::class)]
#[ORM\Table(name: 'cemaden_hidro_observation')]
#[ORM\UniqueConstraint(name: 'uniq_cemaden_hidro_observation', columns: ['cemaden_hidro_station_link_id', 'observed_at'])]
#[ORM\Index(columns: ['partner_id', 'observed_at'])]
#[ORM\Index(columns: ['cemaden_hidro_station_link_id', 'observed_at'])]
class CemadenHidroObservation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    private ?Partner $partner = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    private ?CemadenHidroStationLink $cemadenHidroStationLink = null;

    #[ORM\Column(name: 'station_code', length: 100, nullable: true)]
    private ?string $stationCode = null;

    #[ORM\Column(name: 'station_name', length: 255, nullable: true)]
    private ?string $stationName = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(length: 2, nullable: true)]
    #[Assert\Length(exactly: 2)]
    private ?string $state = null;

    #[ORM\Column(name: 'river_level', type: Types::DECIMAL, precision: 10, scale: 3, nullable: true)]
    private ?string $riverLevel = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 3, nullable: true)]
    private ?string $offset = null;

    #[ORM\Column(name: 'observed_at', type: Types::DATETIME_IMMUTABLE)]
    #[Assert\NotNull]
    private \DateTimeImmutable $observedAt;

    #[ORM\Column(name: 'source_payload', type: Types::JSON)]
    private array $sourcePayload = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $now = new \DateTimeImmutable();
        $this->observedAt = $now;
        $this->createdAt = $now;
    }

    public function getId(): ?int { return $this->id; }
    public function getPartner(): ?Partner { return $this->partner; }
    public function setPartner(?Partner $partner): static { $this->partner = $partner; return $this; }
    public function getCemadenHidroStationLink(): ?CemadenHidroStationLink { return $this->cemadenHidroStationLink; }
    public function setCemadenHidroStationLink(?CemadenHidroStationLink $cemadenHidroStationLink): static { $this->cemadenHidroStationLink = $cemadenHidroStationLink; return $this; }
    public function getStationCode(): ?string { return $this->stationCode; }
    public function setStationCode(?string $stationCode): static { $this->stationCode = $stationCode; return $this; }
    public function getStationName(): ?string { return $this->stationName; }
    public function setStationName(?string $stationName): static { $this->stationName = $stationName; return $this; }
    public function getCity(): ?string { return $this->city; }
    public function setCity(?string $city): static { $this->city = $city; return $this; }
    public function getState(): ?string { return $this->state; }
    public function setState(?string $state): static { $this->state = $state; return $this; }
    public function getRiverLevel(): ?string { return $this->riverLevel; }
    public function setRiverLevel(?string $riverLevel): static { $this->riverLevel = $riverLevel; return $this; }
    public function getOffset(): ?string { return $this->offset; }
    public function setOffset(?string $offset): static { $this->offset = $offset; return $this; }
    public function getObservedAt(): \DateTimeImmutable { return $this->observedAt; }
    public function setObservedAt(\DateTimeImmutable $observedAt): static { $this->observedAt = $observedAt; return $this; }
    public function getSourcePayload(): array { return $this->sourcePayload; }
    public function setSourcePayload(array $sourcePayload): static { $this->sourcePayload = $sourcePayload; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $createdAt): static { $this->createdAt = $createdAt; return $this; }
}
