<?php

namespace App\Entity;

use App\Repository\WazeTvtRouteRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\Table;

#[Entity(repositoryClass: WazeTvtRouteRepository::class)]
#[Table(name: 'waze_tvt_route')]
class WazeTvtRoute
{
    #[Id]
    #[GeneratedValue]
    #[Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ManyToOne(targetEntity: Partner::class, inversedBy: 'wazeTvtRoutes')]
    #[JoinColumn(name: 'partner_id', referencedColumnName: 'id', nullable: false)]
    private ?Partner $partner = null;

    #[Column(type: Types::STRING, length: 36, unique: true)]
    private ?string $wazeRouteId = null;

    #[Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $name = null;

    #[Column(type: Types::STRING, length: 50, nullable: true)]
    private ?string $from = null;

    #[Column(type: Types::STRING, length: 50, nullable: true)]
    private ?string $to = null;

    #[Column(type: Types::DECIMAL, precision: 10, scale: 6, nullable: true)]
    private ?float $startLat = null;

    #[Column(type: Types::DECIMAL, precision: 10, scale: 6, nullable: true)]
    private ?float $startLng = null;

    #[Column(type: Types::DECIMAL, precision: 10, scale: 6, nullable: true)]
    private ?float $endLat = null;

    #[Column(type: Types::DECIMAL, precision: 10, scale: 6, nullable: true)]
    private ?float $endLng = null;

    #[Column(type: Types::STRING, length: 20, nullable: true)]
    private ?string $direction = null;

    #[OneToMany(targetEntity: WazeTvtRouteExecution::class, mappedBy: 'tvtRoute')]
    private Collection $executions;

    #[OneToMany(targetEntity: WazeTvtRouteHistory::class, mappedBy: 'route', cascade: ['persist', 'remove'])]
    private Collection $histories;

    public function __construct()
    {
        $this->executions = new ArrayCollection();
        $this->histories = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPartner(): ?Partner
    {
        return $this->partner;
    }

    public function setPartner(?Partner $partner): static
    {
        $this->partner = $partner;
        return $this;
    }

    public function getWazeRouteId(): ?string
    {
        return $this->wazeRouteId;
    }

    public function setWazeRouteId(?string $wazeRouteId): static
    {
        $this->wazeRouteId = $wazeRouteId;
        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getFrom(): ?string
    {
        return $this->from;
    }

    public function setFrom(?string $from): static
    {
        $this->from = $from;
        return $this;
    }

    public function getTo(): ?string
    {
        return $this->to;
    }

    public function setTo(?string $to): static
    {
        $this->to = $to;
        return $this;
    }

    public function getStartLat(): ?float
    {
        return $this->startLat;
    }

    public function setStartLat(?float $startLat): static
    {
        $this->startLat = $startLat;
        return $this;
    }

    public function getStartLng(): ?float
    {
        return $this->startLng;
    }

    public function setStartLng(?float $startLng): static
    {
        $this->startLng = $startLng;
        return $this;
    }

    public function getEndLat(): ?float
    {
        return $this->endLat;
    }

    public function setEndLat(?float $endLat): static
    {
        $this->endLat = $endLat;
        return $this;
    }

    public function getEndLng(): ?float
    {
        return $this->endLng;
    }

    public function setEndLng(?float $endLng): static
    {
        $this->endLng = $endLng;
        return $this;
    }

    public function getDirection(): ?string
    {
        return $this->direction;
    }

    public function setDirection(?string $direction): static
    {
        $this->direction = $direction;
        return $this;
    }

    public function getExecutions(): Collection
    {
        return $this->executions;
    }

    public function getHistories(): Collection
    {
        return $this->histories;
    }
}
