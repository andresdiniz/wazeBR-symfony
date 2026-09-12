<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CemadenHidroObservationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CemadenHidroObservationRepository::class)]
#[ORM\Table(name: 'cemaden_hidro_observation')]
class CemadenHidroObservation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $stationCode = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stationName = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(length: 2, nullable: true)]
    private ?string $state = null;

    #[ORM\Column(name: 'offset_value', type: 'float', nullable: true)]
    private ?float $offset = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $observedAt = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $sourcePayload = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $waterLevel = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $flow = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $rain = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStationCode(): ?string
    {
        return $this->stationCode;
    }

    public function setStationCode(?string $stationCode): static
    {
        $this->stationCode = $stationCode;
        return $this;
    }

    public function getStationName(): ?string
    {
        return $this->stationName;
    }

    public function setStationName(?string $stationName): static
    {
        $this->stationName = $stationName;
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

    public function getState(): ?string
    {
        return $this->state;
    }

    public function setState(?string $state): static
    {
        $this->state = $state;
        return $this;
    }

    public function getOffset(): ?float
    {
        return $this->offset;
    }

    public function setOffset(?float $offset): static
    {
        $this->offset = $offset;
        return $this;
    }

    public function getObservedAt(): ?\DateTimeInterface
    {
        return $this->observedAt;
    }

    public function setObservedAt(?\DateTimeInterface $observedAt): static
    {
        $this->observedAt = $observedAt;
        return $this;
    }

    public function getSourcePayload(): ?string
    {
        return $this->sourcePayload;
    }

    public function setSourcePayload(?string $sourcePayload): static
    {
        $this->sourcePayload = $sourcePayload;
        return $this;
    }

    public function getWaterLevel(): ?float
    {
        return $this->waterLevel;
    }

    public function setWaterLevel(?float $waterLevel): static
    {
        $this->waterLevel = $waterLevel;
        return $this;
    }

    public function getFlow(): ?float
    {
        return $this->flow;
    }

    public function setFlow(?float $flow): static
    {
        $this->flow = $flow;
        return $this;
    }

    public function getRain(): ?float
    {
        return $this->rain;
    }

    public function setRain(?float $rain): static
    {
        $this->rain = $rain;
        return $this;
    }
}
