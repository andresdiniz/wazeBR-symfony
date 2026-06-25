<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WazeTvtRouteRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Uma rota individual dentro de um snapshot TVT do Waze.
 *
 * Estrutura real de routes[] no JSON TVT:
 * {
 *   "id": "1734959326165",      <- string ID gerado pelo Waze
 *   "name": "Cachoeira Centro",
 *   "type": "STATIC",           <- STATIC | DYNAMIC
 *   "fromName": "R. Antônio...",
 *   "toName": "Av. Pref. ...",
 *   "length": 3152,             <- metros
 *   "time": 522,               <- segundos (tempo atual)
 *   "historicTime": 457,       <- segundos (tempo histórico sem tráfego)
 *   "jamLevel": 1,             <- 0=livre, 1-3=lento, 4=tráfego pesado, 5=parado
 *   "line": [{"x": -43.79, "y": -20.64}, ...],
 *   "bbox": {"minX":..., "maxX":..., "minY":..., "maxY":...},
 *   "subRoutes": []            <- subrotas (mesmo schema, recursivo)
 * }
 */
#[ORM\Entity(repositoryClass: WazeTvtRouteRepository::class)]
#[ORM\Table(name: 'waze_tvt_routes')]
#[ORM\Index(columns: ['snapshot_id'], name: 'idx_tvt_route_snapshot')]
#[ORM\Index(columns: ['waze_route_id'], name: 'idx_tvt_route_waze_id')]
#[ORM\Index(columns: ['jam_level'], name: 'idx_tvt_route_jam_level')]
class WazeTvtRoute
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Snapshot ao qual esta rota pertence */
    #[ORM\ManyToOne(targetEntity: WazeTvtSnapshot::class, inversedBy: 'routes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private WazeTvtSnapshot $snapshot;

    /**
     * ID da rota conforme retornado pelo Waze (string numérica).
     * Ex: "1734959326165"
     */
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $wazeRouteId = null;

    /** true = é uma subRota de outra rota (parent_waze_id preenchido) */
    #[ORM\Column]
    private bool $isSubRoute = false;

    /** wazeRouteId da rota pai (se for subRota) */
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $parentWazeId = null;

    /** Nome da rota. Ex: "Cachoeira Centro" */
    #[ORM\Column(length: 160, nullable: true)]
    private ?string $name = null;

    /** Tipo: STATIC | DYNAMIC */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $type = null;

    /** Ponto de origem da rota. Ex: "R. Antônio Aureliano de Rezende" */
    #[ORM\Column(length: 200, nullable: true)]
    private ?string $fromName = null;

    /** Ponto de destino da rota. Ex: "Av. Pref. Mário Rodrigues Pereira" */
    #[ORM\Column(length: 200, nullable: true)]
    private ?string $toName = null;

    /** Comprimento em metros */
    #[ORM\Column(nullable: true)]
    private ?int $length = null;

    /** Tempo de percurso atual em segundos */
    #[ORM\Column(nullable: true)]
    private ?int $time = null;

    /** Tempo histórico sem congestionamento em segundos */
    #[ORM\Column(nullable: true)]
    private ?int $historicTime = null;

    /**
     * Nível de congestionamento:
     *   0 = livre
     *   1 = levemente congestionado
     *   2 = moderado
     *   3 = pesado
     *   4 = muito pesado
     *   5 = parado
     */
    #[ORM\Column(nullable: true)]
    private ?int $jamLevel = null;

    /**
     * Linha geográfica da rota: [{"x": lon, "y": lat}, ...]
     * Armazenada como JSON.
     */
    #[ORM\Column(type: 'json')]
    private array $line = [];

    /** bbox: {minX, maxX, minY, maxY} */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $bbox = null;

    /**
     * subRoutes salvas como JSON bruto.
     * Rotas com subRoutes também são normalizadas como WazeTvtRoute separadas
     * com isSubRoute=true, mas mantemos o raw aqui para referência.
     */
    #[ORM\Column(type: 'json')]
    private array $subRoutesRaw = [];

    // --- Getters / Setters ---

    public function getId(): ?int { return $this->id; }

    public function getSnapshot(): WazeTvtSnapshot { return $this->snapshot; }
    public function setSnapshot(WazeTvtSnapshot $s): static { $this->snapshot = $s; return $this; }

    public function getWazeRouteId(): ?string { return $this->wazeRouteId; }
    public function setWazeRouteId(?string $v): static { $this->wazeRouteId = $v; return $this; }

    public function isSubRoute(): bool { return $this->isSubRoute; }
    public function setIsSubRoute(bool $v): static { $this->isSubRoute = $v; return $this; }

    public function getParentWazeId(): ?string { return $this->parentWazeId; }
    public function setParentWazeId(?string $v): static { $this->parentWazeId = $v; return $this; }

    public function getName(): ?string { return $this->name; }
    public function setName(?string $v): static { $this->name = $v ? mb_substr($v, 0, 160) : null; return $this; }

    public function getType(): ?string { return $this->type; }
    public function setType(?string $v): static { $this->type = $v ? mb_substr($v, 0, 20) : null; return $this; }

    public function getFromName(): ?string { return $this->fromName; }
    public function setFromName(?string $v): static { $this->fromName = $v ? mb_substr($v, 0, 200) : null; return $this; }

    public function getToName(): ?string { return $this->toName; }
    public function setToName(?string $v): static { $this->toName = $v ? mb_substr($v, 0, 200) : null; return $this; }

    public function getLength(): ?int { return $this->length; }
    public function setLength(?int $v): static { $this->length = $v; return $this; }

    public function getTime(): ?int { return $this->time; }
    public function setTime(?int $v): static { $this->time = $v; return $this; }

    public function getHistoricTime(): ?int { return $this->historicTime; }
    public function setHistoricTime(?int $v): static { $this->historicTime = $v; return $this; }

    public function getJamLevel(): ?int { return $this->jamLevel; }
    public function setJamLevel(?int $v): static { $this->jamLevel = $v; return $this; }

    public function getLine(): array { return $this->line; }
    public function setLine(array $v): static { $this->line = $v; return $this; }

    public function getBbox(): ?array { return $this->bbox; }
    public function setBbox(?array $v): static { $this->bbox = $v; return $this; }

    public function getSubRoutesRaw(): array { return $this->subRoutesRaw; }
    public function setSubRoutesRaw(array $v): static { $this->subRoutesRaw = $v; return $this; }

    /**
     * Atraso em segundos em relação ao tempo histórico.
     * Positivo = mais lento que o normal.
     */
    public function getDelaySeconds(): ?int
    {
        if ($this->time === null || $this->historicTime === null) {
            return null;
        }
        return $this->time - $this->historicTime;
    }

    /**
     * Rótulo legível do nível de congestionamento.
     */
    public function getJamLevelLabel(): string
    {
        return match($this->jamLevel) {
            0       => 'Livre',
            1       => 'Lento',
            2       => 'Moderado',
            3       => 'Pesado',
            4       => 'Muito Pesado',
            5       => 'Parado',
            default => 'Desconhecido',
        };
    }
}
