<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CemadenDataRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CemadenDataRepository::class)]
#[ORM\Table(name: 'cemaden_data')]
#[ORM\HasLifecycleCallbacks]
class CemadenData
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 80)]
    private string $stationCode;

    #[ORM\Column(length: 120)]
    private string $stationName;

    #[ORM\Column(length: 80)]
    private string $municipality;

    #[ORM\Column(length: 2)]
    private string $state;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 7)]
    private float $latitude;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 7)]
    private float $longitude;

    #[ORM\Column(type: 'decimal', precision: 8, scale: 2, nullable: true)]
    private ?float $accumulatedRain = null;

    #[ORM\Column(type: 'decimal', precision: 8, scale: 2, nullable: true)]
    private ?float $waterLevel = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $alertLevel = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $measuredAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\PrePersist]
    public function onPrePersist(): void { $this->createdAt = new \DateTimeImmutable(); }

    public function getId(): ?int { return $this->id; }
    public function getStationCode(): string { return $this->stationCode; }
    public function setStationCode(string $stationCode): static { $this->stationCode = $stationCode; return $this; }
    public function getStationName(): string { return $this->stationName; }
    public function setStationName(string $stationName): static { $this->stationName = $stationName; return $this; }
    public function getMunicipality(): string { return $this->municipality; }
    public function setMunicipality(string $municipality): static { $this->municipality = $municipality; return $this; }
    public function getState(): string { return $this->state; }
    public function setState(string $state): static { $this->state = $state; return $this; }
    public function getLatitude(): float { return $this->latitude; }
    public function setLatitude(float $latitude): static { $this->latitude = $latitude; return $this; }
    public function getLongitude(): float { return $this->longitude; }
    public function setLongitude(float $longitude): static { $this->longitude = $longitude; return $this; }
    public function getAccumulatedRain(): ?float { return $this->accumulatedRain; }
    public function setAccumulatedRain(?float $rain): static { $this->accumulatedRain = $rain; return $this; }
    public function getWaterLevel(): ?float { return $this->waterLevel; }
    public function setWaterLevel(?float $level): static { $this->waterLevel = $level; return $this; }
    public function getAlertLevel(): ?string { return $this->alertLevel; }
    public function setAlertLevel(?string $alertLevel): static { $this->alertLevel = $alertLevel; return $this; }
    public function getMeasuredAt(): \DateTimeImmutable { return $this->measuredAt; }
    public function setMeasuredAt(\DateTimeImmutable $measuredAt): static { $this->measuredAt = $measuredAt; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
