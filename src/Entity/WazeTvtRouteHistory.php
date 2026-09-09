<?php

namespace App\Entity;

use App\Repository\WazeTvtRouteHistoryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WazeTvtRouteHistoryRepository::class)]
#[ORM\Table(name: 'waze_tvt_route_history')]
class WazeTvtRouteHistory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'route_id', type: Types::STRING, length: 255)]
    private string $routeId = '';

    #[ORM\Column(name: 'observed_at', type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $observedAt;

    #[ORM\Column(name: 'travel_time_seconds', type: Types::INTEGER, nullable: true)]
    private ?int $travelTimeSeconds = null;

    #[ORM\Column(name: 'speed_kmh', type: Types::DECIMAL, precision: 8, scale: 2, nullable: true)]
    private ?string $speedKmh = null;

    #[ORM\Column(name: 'delay_seconds', type: Types::INTEGER, nullable: true)]
    private ?int $delaySeconds = null;

    #[ORM\Column(name: 'length_meters', type: Types::INTEGER, nullable: true)]
    private ?int $lengthMeters = null;

    #[ORM\Column(name: 'status', type: Types::STRING, length: 40, nullable: true)]
    private ?string $status = null;

    #[ORM\Column(name: 'raw_metrics', type: Types::JSON, nullable: true)]
    private ?array $rawMetrics = null;

    public function __construct()
    {
        $this->observedAt = new \DateTime();
    }

    public function getId(): ?int { return $this->id; }

    public function getRouteId(): string { return $this->routeId; }
    public function setRouteId(string $routeId): static { $this->routeId = $routeId; return $this; }

    public function getObservedAt(): \DateTimeInterface { return $this->observedAt; }
    public function setObservedAt(\DateTimeInterface $observedAt): static { $this->observedAt = $observedAt; return $this; }

    public function getTravelTimeSeconds(): ?int { return $this->travelTimeSeconds; }
    public function setTravelTimeSeconds(?int $travelTimeSeconds): static { $this->travelTimeSeconds = $travelTimeSeconds; return $this; }

    public function getSpeedKmh(): ?string { return $this->speedKmh; }
    public function setSpeedKmh(?string $speedKmh): static { $this->speedKmh = $speedKmh; return $this; }

    public function getDelaySeconds(): ?int { return $this->delaySeconds; }
    public function setDelaySeconds(?int $delaySeconds): static { $this->delaySeconds = $delaySeconds; return $this; }

    public function getLengthMeters(): ?int { return $this->lengthMeters; }
    public function setLengthMeters(?int $lengthMeters): static { $this->lengthMeters = $lengthMeters; return $this; }

    public function getStatus(): ?string { return $this->status; }
    public function setStatus(?string $status): static { $this->status = $status; return $this; }

    public function getRawMetrics(): ?array { return $this->rawMetrics; }
    public function setRawMetrics(?array $rawMetrics): static { $this->rawMetrics = $rawMetrics; return $this; }
}
