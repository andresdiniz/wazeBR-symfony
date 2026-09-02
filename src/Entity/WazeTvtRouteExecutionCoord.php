<?php

namespace App\Entity;

use App\Repository\WazeTvtRouteExecutionCoordRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WazeTvtRouteExecutionCoordRepository::class)]
#[ORM\Table(name: 'waze_tvt_route_execution_coord')]
class WazeTvtRouteExecutionCoord
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'coordsDetails')]
    #[ORM\JoinColumn(nullable: false)]
    private ?WazeTvtRouteExecution $execution = null;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column(type: 'float')]
    private float $lat = 0.0;

    #[ORM\Column(type: 'float')]
    private float $lng = 0.0;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $speed = null;

    #[ORM\Column(nullable: true)]
    private ?int $level = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getExecution(): ?WazeTvtRouteExecution
    {
        return $this->execution;
    }

    public function setExecution(?WazeTvtRouteExecution $execution): static
    {
        $this->execution = $execution;
        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;
        return $this;
    }

    public function getLat(): float
    {
        return $this->lat;
    }

    public function setLat(float $lat): static
    {
        $this->lat = $lat;
        return $this;
    }

    public function getLng(): float
    {
        return $this->lng;
    }

    public function setLng(float $lng): static
    {
        $this->lng = $lng;
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

    public function getLevel(): ?int
    {
        return $this->level;
    }

    public function setLevel(?int $level): static
    {
        $this->level = $level;
        return $this;
    }
}
