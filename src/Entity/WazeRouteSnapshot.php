<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WazeRouteSnapshotRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Histórico de velocidade/tempo de uma rota de tráfego.
 * Equivale à tabela historic_routes do wazejobtraficc.php.
 */
#[ORM\Entity(repositoryClass: WazeRouteSnapshotRepository::class)]
#[ORM\Table(name: 'waze_route_snapshots')]
#[ORM\Index(columns: ['collected_at'], name: 'idx_snapshot_collected')]
#[ORM\Index(columns: ['route_id'], name: 'idx_snapshot_route')]
class WazeRouteSnapshot
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: WazeRoute::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private WazeRoute $route;

    /** Velocidade média calculada (km/h) */
    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $avgSpeed = null;

    /** Tempo de percurso em segundos */
    #[ORM\Column(nullable: true)]
    private ?int $avgTime = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $collectedAt;

    public function __construct()
    {
        $this->collectedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getRoute(): WazeRoute { return $this->route; }
    public function setRoute(WazeRoute $r): static { $this->route = $r; return $this; }

    public function getAvgSpeed(): ?float { return $this->avgSpeed; }
    public function setAvgSpeed(?float $v): static { $this->avgSpeed = $v; return $this; }

    public function getAvgTime(): ?int { return $this->avgTime; }
    public function setAvgTime(?int $v): static { $this->avgTime = $v; return $this; }

    public function getCollectedAt(): \DateTimeImmutable { return $this->collectedAt; }
    public function setCollectedAt(\DateTimeImmutable $v): static { $this->collectedAt = $v; return $this; }
}
