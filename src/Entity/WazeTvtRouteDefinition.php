<?php

namespace App\Entity;

use App\Repository\WazeTvtRouteDefinitionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WazeTvtRouteDefinitionRepository::class)]
#[ORM\Table(name: 'waze_tvt_route_definition')]
class WazeTvtRouteDefinition
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'route_id', type: Types::STRING, length: 255)]
    private string $routeId = '';

    #[ORM\Column(name: 'name', type: Types::STRING, length: 255, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(name: 'bbox', type: Types::TEXT, nullable: true)]
    private ?string $bbox = null;

    #[ORM\Column(name: 'line', type: Types::TEXT, nullable: true)]
    private ?string $line = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
    }

    public function getId(): ?int { return $this->id; }

    public function getRouteId(): string { return $this->routeId; }
    public function setRouteId(string $routeId): static { $this->routeId = $routeId; return $this; }

    public function getName(): ?string { return $this->name; }
    public function setName(?string $name): static { $this->name = $name; return $this; }

    public function getBbox(): ?string { return $this->bbox; }
    public function setBbox(?string $bbox): static { $this->bbox = $bbox; return $this; }

    public function getLine(): ?string { return $this->line; }
    public function setLine(?string $line): static { $this->line = $line; return $this; }

    public function getCreatedAt(): \DateTimeInterface { return $this->createdAt; }
    public function setCreatedAt(\DateTimeInterface $createdAt): static { $this->createdAt = $createdAt; return $this; }

    public function getUpdatedAt(): \DateTimeInterface { return $this->updatedAt; }
    public function setUpdatedAt(\DateTimeInterface $updatedAt): static { $this->updatedAt = $updatedAt; return $this; }
}
