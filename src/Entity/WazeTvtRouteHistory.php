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

    #[ORM\ManyToOne(targetEntity: WazeTvtRoute::class, inversedBy: 'histories')]
    #[ORM\JoinColumn(nullable: false)]
    private ?WazeTvtRoute $wazeTvtRoute = null;

    #[ORM\ManyToOne(targetEntity: WazeTvtRouteDefinition::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?WazeTvtRouteDefinition $wazeTvtRouteDefinition = null;

    #[ORM\ManyToOne(targetEntity: WazeFeedCollection::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?WazeFeedCollection $wazeFeedCollection = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $observedAt;

    #[ORM\Column(nullable: true)]
    private ?int $travelTimeSeconds = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $travelTimeMinutes = null;

    #[ORM\Column(type: 'decimal', precision: 8, scale: 2, nullable: true)]
    private ?string $speedKmh = null;

    #[ORM\Column(nullable: true)]
    private ?int $delaySeconds = null;

    #[ORM\Column(nullable: true)]
    private ?int $lengthMeters = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $status = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $rawMetrics = null;

    public function __construct()
    {
        $this->observedAt = new \DateTime();
    }

    public function getId(): ?int { return $this->id; }

    public function getWazeTvtRoute(): ?WazeTvtRoute { return $this->wazeTvtRoute; }
    public function setWazeTvtRoute(?WazeTvtRoute $wazeTvtRoute): static { $this->wazeTvtRoute = $wazeTvtRoute; return $this; }

    public function getWazeTvtRouteDefinition(): ?WazeTvtRouteDefinition { return $this->wazeTvtRouteDefinition; }
    public function setWazeTvtRouteDefinition(?WazeTvtRouteDefinition $wazeTvtRouteDefinition): static { $this->wazeTvtRouteDefinition = $wazeTvtRouteDefinition; return $this; }

    public function getWazeFeedCollection(): ?WazeFeedCollection { return $this->wazeFeedCollection; }
    public function setWazeFeedCollection(?WazeFeedCollection $wazeFeedCollection): static { $this->wazeFeedCollection = $wazeFeedCollection; return $this; }

    public function getObservedAt(): \DateTimeInterface { return $this->observedAt; }
    public function setObservedAt(\DateTimeInterface $observedAt): static { $this->observedAt = $observedAt; return $this; }

    public function getTravelTimeSeconds(): ?int { return $this->travelTimeSeconds; }
    public function setTravelTimeSeconds(?int $travelTimeSeconds): static { $this->travelTimeSeconds = $travelTimeSeconds; return $this; }

    public function getTravelTimeMinutes(): ?string { return $this->travelTimeMinutes; }
    public function setTravelTimeMinutes(?string $travelTimeMinutes): static { $this->travelTimeMinutes = $travelTimeMinutes; return $this; }

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
