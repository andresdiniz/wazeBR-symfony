<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WazeTvtRouteHistoryRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Table;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\OrderBy;

#[Entity(repositoryClass: WazeTvtRouteHistoryRepository::class)]
#[Table(name: 'waze_tvt_route_history')]
class WazeTvtRouteHistory
{
    #[Id]
    #[GeneratedValue]
    #[Column(type: 'bigint')]
    private ?int $id = null;

    #[ManyToOne(inversedBy: 'history')]
    #[JoinColumn(name: 'route_id', referencedColumnName: 'id', nullable: false)]
    private ?WazeTvtRoute $route = null;

    #[Column(type: 'integer', nullable: true)]
    private ?int $jamLevel = null;

    #[Column(type: 'integer', nullable: true)]
    private ?int $lengthMeters = null;

    #[Column(type: 'integer', nullable: true)]
    private ?int $delaySeconds = null;

    #[Column(type: 'decimal', precision: 5, scale: 2, nullable: true)]
    private ?string $speedKmh = null;

    #[Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $collectedAt = null;

    #[Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $createdAt = null;

    #[OneToMany(
        targetEntity: WazeTvtRouteHistoryCoords::class,
        mappedBy: 'history',
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    #[OrderBy(['orderIndex' => 'ASC'])]
    private Collection $coords;

    public function __construct()
    {
        $this->coords = new ArrayCollection();
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?int { return $this->id; }
    public function getRoute(): ?WazeTvtRoute { return $this->route; }
    public function setRoute(?WazeTvtRoute $route): static { $this->route = $route; return $this; }
    public function getJamLevel(): ?int { return $this->jamLevel; }
    public function setJamLevel(?int $jamLevel): static { $this->jamLevel = $jamLevel; return $this; }
    public function getLengthMeters(): ?int { return $this->lengthMeters; }
    public function setLengthMeters(?int $lengthMeters): static { $this->lengthMeters = $lengthMeters; return $this; }
    public function getDelaySeconds(): ?int { return $this->delaySeconds; }
    public function setDelaySeconds(?int $delaySeconds): static { $this->delaySeconds = $delaySeconds; return $this; }
    public function getSpeedKmh(): ?string { return $this->speedKmh; }
    public function setSpeedKmh(?string $speedKmh): static { $this->speedKmh = $speedKmh; return $this; }
    public function getCollectedAt(): ?\DateTimeInterface { return $this->collectedAt; }
    public function setCollectedAt(?\DateTimeInterface $collectedAt): static { $this->collectedAt = $collectedAt; return $this; }
    public function getCreatedAt(): ?\DateTimeInterface { return $this->createdAt; }
    public function setCreatedAt(?\DateTimeInterface $createdAt): static { $this->createdAt = $createdAt; return $this; }
    public function getCoords(): Collection { return $this->coords; }
    public function addCoord(WazeTvtRouteHistoryCoords $coord): static {
        if (!$this->coords->contains($coord)) {
            $this->coords->add($coord);
            $coord->setHistory($this);
        }
        return $this;
    }
    public function removeCoord(WazeTvtRouteHistoryCoords $coord): static {
        if ($this->coords->removeElement($coord)) {
            if ($coord->getHistory() === $this) {
                $coord->setHistory(null);
            }
        }
        return $this;
    }
}
