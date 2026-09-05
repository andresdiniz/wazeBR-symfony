<?php

namespace App\Entity;

use App\Repository\WazeTvtRouteExecutionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WazeTvtRouteExecutionRepository::class)]
#[ORM\Table(name: 'waze_tvt_route_execution')]
class WazeTvtRouteExecution
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Relação legada: usada pelo fluxo atual de coleta (WazeCollectTvtCommand)
     * e pela migração (MigrateTvtRoutesCommand). Mantida para não quebrar
     * quem já grava dados por aqui.
     */
    #[ORM\ManyToOne(inversedBy: 'executions')]
    #[ORM\JoinColumn(nullable: true)]
    private ?WazeTvtRouteDefinition $routeDefinition = null;

    /**
     * Nova relação (adicionada para permitir filtrar execuções por parceiro
     * via WazeTvtRoute::partner). Ainda não é preenchida pelo comando de
     * coleta — veja o aviso abaixo.
     */
    #[ORM\ManyToOne(targetEntity: WazeTvtRoute::class, inversedBy: 'executions')]
    #[ORM\JoinColumn(name: 'tvt_route_id', referencedColumnName: 'id', nullable: true)]
    private ?WazeTvtRoute $tvtRoute = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $timestamp = null;

    #[ORM\Column(nullable: true)]
    private ?int $duration = null;

    #[ORM\Column(nullable: true)]
    private ?int $length = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $irregularities = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $trafficJams = 0;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $avgSpeed = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $coords = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\OneToMany(mappedBy: 'execution', targetEntity: WazeTvtRouteExecutionCoord::class, cascade: ['remove'], orphanRemoval: true)]
    private Collection $coordsDetails;

    public function __construct()
    {
        $this->coordsDetails = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRouteDefinition(): ?WazeTvtRouteDefinition
    {
        return $this->routeDefinition;
    }

    public function setRouteDefinition(?WazeTvtRouteDefinition $routeDefinition): static
    {
        $this->routeDefinition = $routeDefinition;
        return $this;
    }

    public function getTvtRoute(): ?WazeTvtRoute
    {
        return $this->tvtRoute;
    }

    public function setTvtRoute(?WazeTvtRoute $tvtRoute): static
    {
        $this->tvtRoute = $tvtRoute;
        return $this;
    }

    public function getTimestamp(): ?\DateTimeImmutable
    {
        return $this->timestamp;
    }

    public function setTimestamp(?\DateTimeImmutable $timestamp): static
    {
        $this->timestamp = $timestamp;
        return $this;
    }

    public function getDuration(): ?int
    {
        return $this->duration;
    }

    public function setDuration(?int $duration): static
    {
        $this->duration = $duration;
        return $this;
    }

    public function getLength(): ?int
    {
        return $this->length;
    }

    public function setLength(?int $length): static
    {
        $this->length = $length;
        return $this;
    }

    public function getIrregularities(): int
    {
        return $this->irregularities;
    }

    public function setIrregularities(int $irregularities): static
    {
        $this->irregularities = $irregularities;
        return $this;
    }

    public function getTrafficJams(): int
    {
        return $this->trafficJams;
    }

    public function setTrafficJams(int $trafficJams): static
    {
        $this->trafficJams = $trafficJams;
        return $this;
    }

    public function getAvgSpeed(): ?float
    {
        return $this->avgSpeed;
    }

    public function setAvgSpeed(?float $avgSpeed): static
    {
        $this->avgSpeed = $avgSpeed;
        return $this;
    }

    public function getCoords(): ?string
    {
        return $this->coords;
    }

    public function setCoords(?string $coords): static
    {
        $this->coords = $coords;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getCoordsDetails(): Collection
    {
        return $this->coordsDetails;
    }

    public function addCoordsDetail(WazeTvtRouteExecutionCoord $detail): static
    {
        if (!$this->coordsDetails->contains($detail)) {
            $this->coordsDetails->add($detail);
            $detail->setExecution($this);
        }
        return $this;
    }

    public function removeCoordsDetail(WazeTvtRouteExecutionCoord $detail): static
    {
        if ($this->coordsDetails->removeElement($detail)) {
            if ($detail->getExecution() === $this) {
                $detail->setExecution(null);
            }
        }
        return $this;
    }
}
