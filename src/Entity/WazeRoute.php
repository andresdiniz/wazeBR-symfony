<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\TenantAwareTrait;
use App\Repository\WazeRouteRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WazeRouteRepository::class)]
#[ORM\Table(name: 'waze_routes')]
class WazeRoute
{
    use TenantAwareTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 80)]
    private string $name = '';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'json')]
    private array $coordinates = [];

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\OneToMany(targetEntity: WazeRouteLink::class, mappedBy: 'route', cascade: ['persist', 'remove'])]
    #[ORM\OrderBy(['sortOrder' => 'ASC'])]
    private Collection $routeLinks;

    public function __construct()
    {
        $this->createdAt  = new \DateTimeImmutable();
        $this->routeLinks = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $n): static { $this->name = $n; return $this; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $d): static { $this->description = $d; return $this; }
    public function getCoordinates(): array { return $this->coordinates; }
    public function setCoordinates(array $c): static { $this->coordinates = $c; return $this; }
    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $v): static { $this->isActive = $v; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getRouteLinks(): Collection { return $this->routeLinks; }
}
