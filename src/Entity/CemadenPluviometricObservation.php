<?php

namespace App\Entity;

use App\Repository\CemadenPluviometricObservationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: CemadenPluviometricObservationRepository::class)]
#[ORM\Table(name: 'cemaden_pluviometric_observation')]
#[ORM\UniqueConstraint(name: 'uniq_cemaden_pluviometric_observation', columns: ['cemaden_station_link_id', 'observed_at'])]
#[ORM\Index(columns: ['partner_id', 'observed_at'])]
#[ORM\Index(columns: ['cemaden_station_link_id', 'observed_at'])]
class CemadenPluviometricObservation
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
    private ?CemadenStationLink $cemadenStationLink = null;

    #[ORM\Column(name: 'reference_date', type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull]
    private \DateTimeImmutable $referenceDate;

    #[ORM\Column(name: 'hour_slot', type: Types::SMALLINT)]
    #[Assert\Range(min: 0, max: 23)]
    private ?int $hourSlot = null;

    #[ORM\Column(name: 'accumulated_rainfall', type: Types::DECIMAL, precision: 8, scale: 3, nullable: true)]
    #[Assert\PositiveOrZero]
    private ?string $accumulatedRainfall = null;

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
        $this->referenceDate = $now->setTime(0, 0);
        $this->observedAt = $now;
        $this->createdAt = $now;
    }

    public function getId(): ?int { return $this->id; }
    public function getPartner(): ?Partner { return $this->partner; }
    public function setPartner(?Partner $partner): static { $this->partner = $partner; return $this; }
    public function getCemadenStationLink(): ?CemadenStationLink { return $this->cemadenStationLink; }
    public function setCemadenStationLink(?CemadenStationLink $cemadenStationLink): static { $this->cemadenStationLink = $cemadenStationLink; return $this; }
    public function getReferenceDate(): \DateTimeImmutable { return $this->referenceDate; }
    public function setReferenceDate(\DateTimeImmutable $referenceDate): static { $this->referenceDate = $referenceDate; return $this; }
    public function getHourSlot(): ?int { return $this->hourSlot; }
    public function setHourSlot(int $hourSlot): static { $this->hourSlot = $hourSlot; return $this; }
    public function getAccumulatedRainfall(): ?string { return $this->accumulatedRainfall; }
    public function setAccumulatedRainfall(?string $accumulatedRainfall): static { $this->accumulatedRainfall = $accumulatedRainfall; return $this; }
    public function getObservedAt(): \DateTimeImmutable { return $this->observedAt; }
    public function setObservedAt(\DateTimeImmutable $observedAt): static { $this->observedAt = $observedAt; return $this; }
    public function getSourcePayload(): array { return $this->sourcePayload; }
    public function setSourcePayload(array $sourcePayload): static { $this->sourcePayload = $sourcePayload; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $createdAt): static { $this->createdAt = $createdAt; return $this; }
}
