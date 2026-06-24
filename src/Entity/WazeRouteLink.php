<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WazeRouteLinkRepository;
use Doctrine\ORM\Mapping as ORM;

/** Sub-rota / trecho vinculado a uma rota principal */
#[ORM\Entity(repositoryClass: WazeRouteLinkRepository::class)]
#[ORM\Table(name: 'waze_route_links')]
class WazeRouteLink
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: WazeRoute::class, inversedBy: 'routeLinks')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private WazeRoute $route;

    #[ORM\Column(length: 80)]
    private string $name = '';

    #[ORM\Column(type: 'json')]
    private array $coordinates = [];

    #[ORM\Column(nullable: true)]
    private ?int $sortOrder = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct() { $this->createdAt = new \DateTimeImmutable(); }

    public function getId(): ?int { return $this->id; }
    public function getRoute(): WazeRoute { return $this->route; }
    public function setRoute(WazeRoute $r): static { $this->route = $r; return $this; }
    public function getName(): string { return $this->name; }
    public function setName(string $n): static { $this->name = $n; return $this; }
    public function getCoordinates(): array { return $this->coordinates; }
    public function setCoordinates(array $c): static { $this->coordinates = $c; return $this; }
    public function getSortOrder(): ?int { return $this->sortOrder; }
    public function setSortOrder(?int $o): static { $this->sortOrder = $o; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
