<?php

namespace App\Entity;

use App\Repository\WazeSubRouteRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WazeSubRouteRepository::class)]
#[ORM\Table(name: 'wazesubroutes')]
class WazeSubRoute
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: WazeRouteSnapshot::class, inversedBy: 'subRoutes')]
    #[ORM\JoinColumn(nullable: false)]
    private ?WazeRouteSnapshot $routeSnapshot = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $fromName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $toName = null;

    /**
     * Tempo atual de percurso do trecho em segundos
     */
    #[ORM\Column(nullable: true)]
    private ?int $time = null;

    /**
     * Tempo histórico do trecho em segundos
     */
    #[ORM\Column(nullable: true)]
    private ?int $historicTime = null;

    /**
     * Comprimento do trecho em metros
     */
    #[ORM\Column(nullable: true)]
    private ?int $length = null;

    /**
     * Nível de congestionamento do trecho (0 = livre, 4 = parado)
     */
    #[ORM\Column(nullable: true)]
    private ?int $jamLevel = null;

    /**
     * Geometria do trecho como JSON (array de {x, y})
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $line = null;

    /**
     * Bounding box do trecho {minY, minX, maxY, maxX}
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $bbox = null;

    /**
     * Posição ordinal do subRoute dentro da rota pai (0-based)
     */
    #[ORM\Column(nullable: true)]
    private ?int $sortOrder = null;

    public function getId(): ?int { return $this->id; }

    public function getRouteSnapshot(): ?WazeRouteSnapshot { return $this->routeSnapshot; }
    public function setRouteSnapshot(?WazeRouteSnapshot $routeSnapshot): static { $this->routeSnapshot = $routeSnapshot; return $this; }

    public function getFromName(): ?string { return $this->fromName; }
    public function setFromName(?string $fromName): static { $this->fromName = $fromName; return $this; }

    public function getToName(): ?string { return $this->toName; }
    public function setToName(?string $toName): static { $this->toName = $toName; return $this; }

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

    public function getSortOrder(): ?int { return $this->sortOrder; }
    public function setSortOrder(?int $sortOrder): static { $this->sortOrder = $sortOrder; return $this; }
}
