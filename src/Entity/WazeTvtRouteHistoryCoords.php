<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Table;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\JoinColumn;

#[Entity]
#[Table(name: 'waze_tvt_route_history_coords')]
class WazeTvtRouteHistoryCoords
{
    #[Id]
    #[GeneratedValue]
    #[Column(type: 'bigint')]
    private ?int $id = null;

    #[ManyToOne(inversedBy: 'coords')]
    #[JoinColumn(name: 'history_id', referencedColumnName: 'id', nullable: false)]
    private ?WazeTvtRouteHistory $history = null;

    #[Column(type: 'decimal', precision: 10, scale: 8, nullable: true)]
    private ?string $latitude = null;

    #[Column(type: 'decimal', precision: 11, scale: 8, nullable: true)]
    private ?string $longitude = null;

    #[Column(type: 'integer', nullable: true)]
    private ?int $orderIndex = null;

    public function getId(): ?int { return $this->id; }
    public function getHistory(): ?WazeTvtRouteHistory { return $this->history; }
    public function setHistory(?WazeTvtRouteHistory $history): static { $this->history = $history; return $this; }
    public function getLatitude(): ?string { return $this->latitude; }
    public function setLatitude(?string $latitude): static { $this->latitude = $latitude; return $this; }
    public function getLongitude(): ?string { return $this->longitude; }
    public function setLongitude(?string $longitude): static { $this->longitude = $longitude; return $this; }
    public function getOrderIndex(): ?int { return $this->orderIndex; }
    public function setOrderIndex(?int $orderIndex): static { $this->orderIndex = $orderIndex; return $this; }
}
