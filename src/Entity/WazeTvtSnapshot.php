<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WazeTvtSnapshotRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Snapshot completo de uma coleta do feed TVT do Waze.
 *
 * Estrutura real do JSON (feed-tvt):
 * {
 *   "updateTime": 1782350463272,
 *   "name": "Managed Area",
 *   "areaName": "Managed Area",
 *   "broadcasterId": "",
 *   "isMetric": true,
 *   "bbox": {"minX":..., "maxX":..., "minY":..., "maxY":...},
 *   "usersOnJams": [{"jamLevel": 0, "wazersCount": 79}, ...],
 *   "lengthOfJams": [{"jamLevel": 1, "jamLength": 0}, ...],
 *   "irregularities": [...],
 *   "routes": [{...}, ...]
 * }
 */
#[ORM\Entity(repositoryClass: WazeTvtSnapshotRepository::class)]
#[ORM\Table(name: 'waze_tvt_snapshots')]
#[ORM\Index(columns: ['collected_at'], name: 'idx_tvt_collected_at')]
#[ORM\Index(columns: ['partner_id'], name: 'idx_tvt_partner')]
#[ORM\Index(columns: ['source_link_id'], name: 'idx_tvt_link')]
class WazeTvtSnapshot
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Parceiro dono do feed */
    #[ORM\ManyToOne(targetEntity: Partner::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Partner $partner;

    /** Link monitorado que originou esta coleta */
    #[ORM\ManyToOne(targetEntity: MonitoredLink::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private MonitoredLink $sourceLink;

    /** updateTime do JSON (milissegundos Unix) */
    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?string $updateTime = null;

    /** name / areaName do feed */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $feedName = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $areaName = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $broadcasterId = null;

    #[ORM\Column]
    private bool $isMetric = true;

    /** bbox: {minX, maxX, minY, maxY} */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $bbox = null;

    /**
     * usersOnJams: [{jamLevel: 0, wazersCount: 79}, ...]
     * Resumo de quantos usuários estão em cada nível de jam.
     */
    #[ORM\Column(type: 'json')]
    private array $usersOnJams = [];

    /**
     * lengthOfJams: [{jamLevel: 1, jamLength: 0}, ...]
     * Comprimento total de congestionamento por nível.
     */
    #[ORM\Column(type: 'json')]
    private array $lengthOfJams = [];

    /**
     * irregularities: array de objetos de irregularidade
     * (geralmente vazio neste feed)
     */
    #[ORM\Column(type: 'json')]
    private array $irregularities = [];

    /** Total de rotas neste snapshot */
    #[ORM\Column]
    private int $routeCount = 0;

    /** Quando esta coleta foi realizada */
    #[ORM\Column]
    private \DateTimeImmutable $collectedAt;

    /** Rotas normalizadas deste snapshot */
    #[ORM\OneToMany(
        targetEntity: WazeTvtRoute::class,
        mappedBy: 'snapshot',
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    private Collection $routes;

    public function __construct()
    {
        $this->collectedAt = new \DateTimeImmutable();
        $this->routes      = new ArrayCollection();
    }

    // --- Getters / Setters ---

    public function getId(): ?int { return $this->id; }

    public function getPartner(): Partner { return $this->partner; }
    public function setPartner(Partner $p): static { $this->partner = $p; return $this; }

    public function getSourceLink(): MonitoredLink { return $this->sourceLink; }
    public function setSourceLink(MonitoredLink $l): static { $this->sourceLink = $l; return $this; }

    public function getUpdateTime(): ?string { return $this->updateTime; }
    public function setUpdateTime(?int $t): static { $this->updateTime = $t !== null ? (string) $t : null; return $this; }

    public function getFeedName(): ?string { return $this->feedName; }
    public function setFeedName(?string $n): static { $this->feedName = $n ? mb_substr($n, 0, 120) : null; return $this; }

    public function getAreaName(): ?string { return $this->areaName; }
    public function setAreaName(?string $n): static { $this->areaName = $n ? mb_substr($n, 0, 120) : null; return $this; }

    public function getBroadcasterId(): ?string { return $this->broadcasterId; }
    public function setBroadcasterId(?string $v): static { $this->broadcasterId = $v ? mb_substr($v, 0, 80) : null; return $this; }

    public function isMetric(): bool { return $this->isMetric; }
    public function setIsMetric(bool $v): static { $this->isMetric = $v; return $this; }

    public function getBbox(): ?array { return $this->bbox; }
    public function setBbox(?array $b): static { $this->bbox = $b; return $this; }

    public function getUsersOnJams(): array { return $this->usersOnJams; }
    public function setUsersOnJams(array $v): static { $this->usersOnJams = $v; return $this; }

    public function getLengthOfJams(): array { return $this->lengthOfJams; }
    public function setLengthOfJams(array $v): static { $this->lengthOfJams = $v; return $this; }

    public function getIrregularities(): array { return $this->irregularities; }
    public function setIrregularities(array $v): static { $this->irregularities = $v; return $this; }

    public function getRouteCount(): int { return $this->routeCount; }
    public function setRouteCount(int $v): static { $this->routeCount = $v; return $this; }

    public function getCollectedAt(): \DateTimeImmutable { return $this->collectedAt; }

    public function getRoutes(): Collection { return $this->routes; }

    public function addRoute(WazeTvtRoute $route): static
    {
        if (!$this->routes->contains($route)) {
            $this->routes->add($route);
            $route->setSnapshot($this);
        }
        return $this;
    }

    /**
     * Retorna o total de usuários em jams (soma de todos os níveis).
     * Cast explícito para int — array_sum() retorna float quando o array é vazio.
     */
    public function getTotalUsersOnJams(): int
    {
        return (int) array_sum(array_column($this->usersOnJams, 'wazersCount'));
    }

    /**
     * Retorna comprimento total de congestionamento em metros.
     * Cast explícito para int — array_sum() retorna float quando o array é vazio.
     */
    public function getTotalJamLength(): int
    {
        return (int) array_sum(array_column($this->lengthOfJams, 'jamLength'));
    }
}
