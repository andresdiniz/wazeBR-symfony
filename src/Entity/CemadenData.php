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

    #[ORM\ManyToOne(targetEntity: Partner::class, inversedBy: 'cemadenData')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Partner $partner = null;

    #[ORM\Column(length: 80)]
    private string $stationCode;

    #[ORM\Column(length: 120)]
    private string $stationName;

    #[ORM\Column(length: 80)]
    private string $municipality;

    #[ORM\Column(length: 2)]
    private string $state;

    /**
     * DECIMAL(10,7) — Doctrine retorna string do DBAL; armazenamos como string.
     * Use getLatitude() para obter float.
     */
    #[ORM\Column(type: 'decimal', precision: 10, scale: 7)]
    private string $latitude = '0.0000000';

    /**
     * DECIMAL(10,7) — Doctrine retorna string do DBAL; armazenamos como string.
     * Use getLongitude() para obter float.
     */
    #[ORM\Column(type: 'decimal', precision: 10, scale: 7)]
    private string $longitude = '0.0000000';

    /**
     * DECIMAL(8,2) — Doctrine retorna string do DBAL; armazenamos como string.
     * Use getAccumulatedRain() para obter float|null.
     */
    #[ORM\Column(type: 'decimal', precision: 8, scale: 2, nullable: true)]
    private ?string $accumulatedRain = null;

    /**
     * DECIMAL(8,2) — Doctrine retorna string do DBAL; armazenamos como string.
     * Use getWaterLevel() para obter float|null.
     */
    #[ORM\Column(type: 'decimal', precision: 8, scale: 2, nullable: true)]
    private ?string $waterLevel = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $alertLevel = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $measuredAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\PrePersist]
    public function onPrePersist(): void { $this->createdAt = new \DateTimeImmutable(); }

    public function getId(): ?int { return $this->id; }
    public function getPartner(): ?Partner { return $this->partner; }
    public function setPartner(?Partner $partner): static { $this->partner = $partner; return $this; }
    public function getStationCode(): string { return $this->stationCode; }
    public function setStationCode(string $stationCode): static { $this->stationCode = $stationCode; return $this; }
    public function getStationName(): string { return $this->stationName; }
    public function setStationName(string $stationName): static { $this->stationName = $stationName; return $this; }
    public function getMunicipality(): string { return $this->municipality; }
    public function setMunicipality(string $municipality): static { $this->municipality = $municipality; return $this; }
    public function getState(): string { return $this->state; }
    public function setState(string $state): static { $this->state = $state; return $this; }

    public function getLatitude(): float { return (float) $this->latitude; }
    public function setLatitude(float|string $latitude): static { $this->latitude = (string) $latitude; return $this; }

    public function getLongitude(): float { return (float) $this->longitude; }
    public function setLongitude(float|string $longitude): static { $this->longitude = (string) $longitude; return $this; }

    public function getAccumulatedRain(): ?float { return $this->accumulatedRain !== null ? (float) $this->accumulatedRain : null; }
    public function setAccumulatedRain(float|string|null $rain): static { $this->accumulatedRain = $rain !== null ? (string) $rain : null; return $this; }

    public function getWaterLevel(): ?float { return $this->waterLevel !== null ? (float) $this->waterLevel : null; }
    public function setWaterLevel(float|string|null $level): static { $this->waterLevel = $level !== null ? (string) $level : null; return $this; }

    public function getAlertLevel(): ?string { return $this->alertLevel; }
    public function setAlertLevel(?string $alertLevel): static { $this->alertLevel = $alertLevel; return $this; }
    public function getMeasuredAt(): \DateTimeImmutable { return $this->measuredAt; }
    public function setMeasuredAt(\DateTimeImmutable $measuredAt): static { $this->measuredAt = $measuredAt; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
