<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WazeRouteSnapshotGeomRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WazeRouteSnapshotGeomRepository::class)]
#[ORM\Table(name: 'waze_route_snapshot_geom')]
#[ORM\Index(columns: ['route_id'])]
class WazeRouteSnapshotGeom
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(fetch: 'LAZY')]
    #[ORM\JoinColumn(nullable: false, unique: true)]
    private WazeRoute $route;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $line = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $bbox = null;

    public function __construct()
    {
        $this->updatedAt = new \DateTimeImmutable();
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

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function getLine(): ?array
    {
        return $this->line;
    }

    public function setLine(?array $line): static
    {
        $this->line = $line;
        return $this;
    }

    public function getBbox(): ?array
    {
        return $this->bbox;
    }

    public function setBbox(?array $bbox): static
    {
        $this->bbox = $bbox;
        return $this;
    }
}
