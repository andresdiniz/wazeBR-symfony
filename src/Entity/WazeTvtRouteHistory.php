<?php

namespace App\Entity;

use App\Repository\WazeTvtRouteHistoryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\Table;

#[Entity(repositoryClass: WazeTvtRouteHistoryRepository::class)]
#[Table(name: 'waze_tvt_route_history')]
class WazeTvtRouteHistory
{
    #[Id]
    #[GeneratedValue]
    #[Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ManyToOne(targetEntity: WazeTvtRoute::class, inversedBy: 'histories')]
    #[JoinColumn(name: 'route_id', referencedColumnName: 'id', nullable: false)]
    private ?WazeTvtRoute $route = null;

    #[Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $capturedAt = null;

    #[Column(type: Types::DECIMAL, precision: 10, scale: 6, nullable: true)]
    private ?float $bboxMinLat = null;

    #[Column(type: Types::DECIMAL, precision: 10, scale: 6, nullable: true)]
    private ?float $bboxMinLng = null;

    #[Column(type: Types::DECIMAL, precision: 10, scale: 6, nullable: true)]
    private ?float $bboxMaxLat = null;

    #[Column(type: Types::DECIMAL, precision: 10, scale: 6, nullable: true)]
    private ?float $bboxMaxLng = null;

    #[Column(type: Types::DECIMAL, precision: 10, scale: 6, nullable: true)]
    private ?float $coordStartLat = null;

    #[Column(type: Types::DECIMAL, precision: 10, scale: 6, nullable: true)]
    private ?float $coordStartLng = null;

    #[Column(type: Types::DECIMAL, precision: 10, scale: 6, nullable: true)]
    private ?float $coordMidLat = null;

    #[Column(type: Types::DECIMAL, precision: 10, scale: 6, nullable: true)]
    private ?float $coordMidLng = null;

    #[Column(type: Types::DECIMAL, precision: 10, scale: 6, nullable: true)]
    private ?float $coordEndLat = null;

    #[Column(type: Types::DECIMAL, precision: 10, scale: 6, nullable: true)]
    private ?float $coordEndLng = null;

    #[Column(type: Types::INTEGER, nullable: true)]
    private ?int $originalPointCount = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRoute(): ?WazeTvtRoute
    {
        return $this->route;
    }

    public function setRoute(?WazeTvtRoute $route): static
    {
        $this->route = $route;
        return $this;
    }

    public function getCapturedAt(): ?\DateTimeImmutable
    {
        return $this->capturedAt;
    }

    public function setCapturedAt(\DateTimeImmutable $capturedAt): static
    {
        $this->capturedAt = $capturedAt;
        return $this;
    }

    public function getBboxMinLat(): ?float
    {
        return $this->bboxMinLat;
    }

    public function setBboxMinLat(?float $bboxMinLat): static
    {
        $this->bboxMinLat = $bboxMinLat;
        return $this;
    }

    public function getBboxMinLng(): ?float
    {
        return $this->bboxMinLng;
    }

    public function setBboxMinLng(?float $bboxMinLng): static
    {
        $this->bboxMinLng = $bboxMinLng;
        return $this;
    }

    public function getBboxMaxLat(): ?float
    {
        return $this->bboxMaxLat;
    }

    public function setBboxMaxLat(?float $bboxMaxLat): static
    {
        $this->bboxMaxLat = $bboxMaxLat;
        return $this;
    }

    public function getBboxMaxLng(): ?float
    {
        return $this->bboxMaxLng;
    }

    public function setBboxMaxLng(?float $bboxMaxLng): static
    {
        $this->bboxMaxLng = $bboxMaxLng;
        return $this;
    }

    public function getCoordStartLat(): ?float
    {
        return $this->coordStartLat;
    }

    public function setCoordStartLat(?float $coordStartLat): static
    {
        $this->coordStartLat = $coordStartLat;
        return $this;
    }

    public function getCoordStartLng(): ?float
    {
        return $this->coordStartLng;
    }

    public function setCoordStartLng(?float $coordStartLng): static
    {
        $this->coordStartLng = $coordStartLng;
        return $this;
    }

    public function getCoordMidLat(): ?float
    {
        return $this->coordMidLat;
    }

    public function setCoordMidLat(?float $coordMidLat): static
    {
        $this->coordMidLat = $coordMidLat;
        return $this;
    }

    public function getCoordMidLng(): ?float
    {
        return $this->coordMidLng;
    }

    public function setCoordMidLng(?float $coordMidLng): static
    {
        $this->coordMidLng = $coordMidLng;
        return $this;
    }

    public function getCoordEndLat(): ?float
    {
        return $this->coordEndLat;
    }

    public function setCoordEndLat(?float $coordEndLat): static
    {
        $this->coordEndLat = $coordEndLat;
        return $this;
    }

    public function getCoordEndLng(): ?float
    {
        return $this->coordEndLng;
    }

    public function setCoordEndLng(?float $coordEndLng): static
    {
        $this->coordEndLng = $coordEndLng;
        return $this;
    }

    public function getOriginalPointCount(): ?int
    {
        return $this->originalPointCount;
    }

    public function setOriginalPointCount(?int $originalPointCount): static
    {
        $this->originalPointCount = $originalPointCount;
        return $this;
    }
}
