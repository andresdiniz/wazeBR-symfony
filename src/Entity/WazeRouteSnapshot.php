<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WazeRouteSnapshotRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Histórico de velocidade/tempo de uma rota de tráfego.
 * Equivale à tabela historic_routes do wazejobtraficc.php.
 *
 * Campos brutos (time, historicTime, length, jamLevel) espelham
 * exatamente o payload da API Waze para reprodutibilidade.
 * Os campos calculados (avgSpeed) são derivados deles.
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

    /** Tempo de percurso atual em segundos (payload: time) */
    #[ORM\Column(nullable: true)]
    private ?int $time = null;

    /** Tempo histórico em segundos (payload: historicTime) */
    #[ORM\Column(nullable: true)]
    private ?int $historicTime = null;

    /** Comprimento em metros (payload: length) */
    #[ORM\Column(nullable: true)]
    private ?int $length = null;

    /** Nível de congestionamento 0-5 (payload: jamLevel) */
    #[ORM\Column(nullable: true)]
    private ?int $jamLevel = null;

    /** Velocidade média calculada em km/h */
    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $avgSpeed = null;

    /** Velocidade histórica calculada em km/h */
    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $historicSpeed = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $collectedAt;

    public function __construct()
    {
        $this->collectedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getRoute(): WazeRoute { return $this->route; }
    public function setRoute(WazeRoute $r): static { $this->route = $r; return $this; }

    public function getTime(): ?int { return $this->time; }
    public function setTime(?int $v): static { $this->time = $v; return $this; }

    public function getHistoricTime(): ?int { return $this->historicTime; }
    public function setHistoricTime(?int $v): static { $this->historicTime = $v; return $this; }

    public function getLength(): ?int { return $this->length; }
    public function setLength(?int $v): static { $this->length = $v; return $this; }

    public function getJamLevel(): ?int { return $this->jamLevel; }
    public function setJamLevel(?int $v): static { $this->jamLevel = $v; return $this; }

    public function getAvgSpeed(): ?float { return $this->avgSpeed; }
    public function setAvgSpeed(?float $v): static { $this->avgSpeed = $v; return $this; }

    public function getHistoricSpeed(): ?float { return $this->historicSpeed; }
    public function setHistoricSpeed(?float $v): static { $this->historicSpeed = $v; return $this; }

    public function getCollectedAt(): \DateTimeImmutable { return $this->collectedAt; }
    public function setCollectedAt(\DateTimeImmutable $v): static { $this->collectedAt = $v; return $this; }
}
