<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WazeRouteSnapshotLightRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WazeRouteSnapshotLightRepository::class)]
#[ORM\Table(name: 'waze_route_snapshot_light')]
#[ORM\Index(columns: ['route_id', 'recorded_at'])]
class WazeRouteSnapshotLight
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(fetch: 'LAZY')]
    #[ORM\JoinColumn(nullable: false)]
    private WazeRoute $route;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $recordedAt;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $speed = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $length = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $delay = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $trafficLevel = null;

    public function __construct()
    {
        $this->recordedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRoute(): WazeRoute
    {
        return $this->route;
    }

    public function setRoute(WazeRoute $route): static
    {
        $this->route = $route;
        return $this;
    }

    public function getRecordedAt(): \DateTimeImmutable
    {
        return $this->recordedAt;
    }

    public function setRecordedAt(\DateTimeImmutable $recordedAt): static
    {
        $this->recordedAt = $recordedAt;
        return $this;
    }

    public function getSpeed(): ?float
    {
        return $this->speed;
    }

    public function setSpeed(?float $speed): static
    {
        $this->speed = $speed;
        return $this;
    }

    public function getLength(): ?float
    {
        return $this->length;
    }

    public function setLength(?float $length): static
    {
        $this->length = $length;
        return $this;
    }

    public function getDelay(): ?float
    {
        return $this->delay;
    }

    public function setDelay(?float $delay): static
    {
        $this->delay = $delay;
        return $this;
    }

    public function getTrafficLevel(): ?int
    {
        return $this->trafficLevel;
    }

    public function setTrafficLevel(?int $trafficLevel): static
    {
        $this->trafficLevel = $trafficLevel;
        return $this;
    }
}
