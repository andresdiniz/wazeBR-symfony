<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WazeRouteSnapshotRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Histórico de cada busca de dados de uma WazeRoute.
 * Cada vez que o sistema consulta o Waze para uma rota, um snapshot é gerado.
 */
#[ORM\Entity(repositoryClass: WazeRouteSnapshotRepository::class)]
#[ORM\Table(name: 'waze_route_snapshots')]
#[ORM\Index(columns: ['route_id', 'collected_at'], name: 'idx_route_snapshot_route_time')]
class WazeRouteSnapshot
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: WazeRoute::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private WazeRoute $route;

    /** Tempo de percurso atual em segundos no momento da busca */
    #[ORM\Column(nullable: true)]
    private ?int $time = null;

    /** Tempo histórico em segundos no momento da busca */
    #[ORM\Column(nullable: true)]
    private ?int $historicTime = null;

    /** Comprimento em metros */
    #[ORM\Column(nullable: true)]
    private ?int $length = null;

    /** Nível de congestionamento (0–4) */
    #[ORM\Column(nullable: true)]
    private ?int $jamLevel = null;

    /** Timestamp da busca (UTC) */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $collectedAt;

    /**
     * Sub-rotas (trechos) coletadas neste snapshot.
     */
    #[ORM\OneToMany(targetEntity: WazeSubRoute::class, mappedBy: 'routeSnapshot', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $subRoutes;

    public function __construct()
    {
        $this->collectedAt = new \DateTimeImmutable();
        $this->subRoutes   = new ArrayCollection();
    }

    // ─── Getters / Setters ────────────────────────────────────────────────────

    public function getId(): ?int { return $this->id; }

    public function getRoute(): WazeRoute { return $this->route; }
    public function setRoute(WazeRoute $route): static { $this->route = $route; return $this; }

    public function getTime(): ?int { return $this->time; }
    public function setTime(?int $time): static { $this->time = $time; return $this; }

    public function getHistoricTime(): ?int { return $this->historicTime; }
    public function setHistoricTime(?int $historicTime): static { $this->historicTime = $historicTime; return $this; }

    public function getLength(): ?int { return $this->length; }
    public function setLength(?int $length): static { $this->length = $length; return $this; }

    public function getJamLevel(): ?int { return $this->jamLevel; }
    public function setJamLevel(?int $jamLevel): static { $this->jamLevel = $jamLevel; return $this; }

    public function getCollectedAt(): \DateTimeImmutable { return $this->collectedAt; }
    public function setCollectedAt(\DateTimeImmutable $collectedAt): static { $this->collectedAt = $collectedAt; return $this; }

    /** @return Collection<int, WazeSubRoute> */
    public function getSubRoutes(): Collection { return $this->subRoutes; }

    public function addSubRoute(WazeSubRoute $subRoute): static
    {
        if (!$this->subRoutes->contains($subRoute)) {
            $this->subRoutes->add($subRoute);
            $subRoute->setRouteSnapshot($this);
        }
        return $this;
    }

    public function removeSubRoute(WazeSubRoute $subRoute): static
    {
        if ($this->subRoutes->removeElement($subRoute)) {
            if ($subRoute->getRouteSnapshot() === $this) {
                $subRoute->setRouteSnapshot(null);
            }
        }
        return $this;
    }
}
