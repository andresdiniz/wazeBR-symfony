<?php

namespace App\Entity;

use App\Repository\WazeRouteRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WazeRouteRepository::class)]
#[ORM\Table(name: 'waze_routes')]
class WazeRoute
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * ID string da rota vindo do Waze (ex: "1762938283029")
     */
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $wazeId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $fromName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $toName = null;

    /**
     * Tipo da rota (ex: STATIC)
     */
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $type = null;

    /**
     * Tempo atual de percurso em segundos
     */
    #[ORM\Column(nullable: true)]
    private ?int $time = null;

    /**
     * Tempo histórico de percurso em segundos
     */
    #[ORM\Column(nullable: true)]
    private ?int $historicTime = null;

    /**
     * Comprimento total em metros
     */
    #[ORM\Column(nullable: true)]
    private ?int $length = null;

    /**
     * Nível de congestionamento (0 = livre, 4 = parado)
     */
    #[ORM\Column(nullable: true)]
    private ?int $jamLevel = null;

    /**
     * Geometria da rota principal como JSON (array de {x, y})
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $line = null;

    /**
     * Bounding box {minY, minX, maxY, maxX}
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $bbox = null;

    /**
     * Rota alternativa embutida (alternateRoute) como JSON completo
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $alternateRoute = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $collectedAt = null;

    /**
     * @var Collection<int, WazeSubRoute>
     */
    #[ORM\OneToMany(targetEntity: WazeSubRoute::class, mappedBy: 'route', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $subRoutes;

    public function __construct()
    {
        $this->subRoutes = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getWazeId(): ?string { return $this->wazeId; }
    public function setWazeId(?string $wazeId): static { $this->wazeId = $wazeId; return $this; }

    public function getName(): ?string { return $this->name; }
    public function setName(?string $name): static { $this->name = $name; return $this; }

    public function getFromName(): ?string { return $this->fromName; }
    public function setFromName(?string $fromName): static { $this->fromName = $fromName; return $this; }

    public function getToName(): ?string { return $this->toName; }
    public function setToName(?string $toName): static { $this->toName = $toName; return $this; }

    public function getType(): ?string { return $this->type; }
    public function setType(?string $type): static { $this->type = $type; return $this; }

    public function getTime(): ?int { return $this->time; }
    public function setTime(?int $time): static { $this->time = $time; return $this; }

    public function getHistoricTime(): ?int { return $this->historicTime; }
    public function setHistoricTime(?int $historicTime): static { $this->historicTime = $historicTime; return $this; }

    public function getLength(): ?int { return $this->length; }
    public function setLength(?int $length): static { $this->length = $length; return $this; }

    public function getJamLevel(): ?int { return $this->jamLevel; }
    public function setJamLevel(?int $jamLevel): static { $this->jamLevel = $jamLevel; return $this; }

    public function getLine(): ?array { return $this->line; }
    public function setLine(?array $line): static { $this->line = $line; return $this; }

    public function getBbox(): ?array { return $this->bbox; }
    public function setBbox(?array $bbox): static { $this->bbox = $bbox; return $this; }

    public function getAlternateRoute(): ?array { return $this->alternateRoute; }
    public function setAlternateRoute(?array $alternateRoute): static { $this->alternateRoute = $alternateRoute; return $this; }

    public function getCollectedAt(): ?\DateTimeInterface { return $this->collectedAt; }
    public function setCollectedAt(?\DateTimeInterface $collectedAt): static { $this->collectedAt = $collectedAt; return $this; }

    /**
     * @return Collection<int, WazeSubRoute>
     */
    public function getSubRoutes(): Collection { return $this->subRoutes; }

    public function addSubRoute(WazeSubRoute $subRoute): static
    {
        if (!$this->subRoutes->contains($subRoute)) {
            $this->subRoutes->add($subRoute);
            $subRoute->setRoute($this);
        }
        return $this;
    }

    public function removeSubRoute(WazeSubRoute $subRoute): static
    {
        if ($this->subRoutes->removeElement($subRoute)) {
            if ($subRoute->getRoute() === $this) {
                $subRoute->setRoute(null);
            }
        }
        return $this;
    }
}
