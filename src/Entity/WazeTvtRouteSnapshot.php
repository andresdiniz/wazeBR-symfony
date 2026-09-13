<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WazeTvtRouteSnapshotRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WazeTvtRouteSnapshotRepository::class)]
#[ORM\Table(name: 'waze_tvt_route_snapshot')]
#[ORM\Index(
    name: 'idx_tvt_snapshot_partner_recorded',
    columns: ['partner_id', 'recorded_at'],
)]
#[ORM\Index(
    name: 'idx_tvt_snapshot_route_recorded',
    columns: ['route_id', 'recorded_at'],
)]
class WazeTvtRouteSnapshot
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(
        targetEntity: Partner::class,
        inversedBy: 'wazeTvtRouteSnapshots',
    )]
    #[ORM\JoinColumn(
        name: 'partner_id',
        referencedColumnName: 'id',
        nullable: false,
        onDelete: 'CASCADE',
    )]
    private ?Partner $partner = null;

    #[ORM\ManyToOne(
        targetEntity: WazeTvtRoute::class,
    )]
    #[ORM\JoinColumn(
        name: 'route_id',
        referencedColumnName: 'id',
        nullable: false,
        onDelete: 'CASCADE',
    )]
    private ?WazeTvtRoute $route = null;

    #[ORM\Column(name: 'waze_route_id', length: 100)]
    private ?string $wazeRouteId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(length: 2, nullable: true)]
    private ?string $state = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $time = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $historicTime = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $jamLevel = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $payload = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $recordedAt = null;

    public function __construct()
    {
        $this->recordedAt = new \DateTimeImmutable();
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

    public function getRoute(): ?WazeTvtRoute
    {
        return $this->route;
    }

    public function setRoute(?WazeTvtRoute $route): static
    {
        $this->route = $route;

        return $this;
    }

    public function getWazeRouteId(): ?string
    {
        return $this->wazeRouteId;
    }

    public function setWazeRouteId(string $wazeRouteId): static
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

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function setCity(?string $city): static
    {
        $this->city = $city;

        return $this;
    }

    public function getState(): ?string
    {
        return $this->state;
    }

    public function setState(?string $state): static
    {
        $this->state = $state;

        return $this;
    }

    public function getTime(): ?int
    {
        return $this->time;
    }

    public function setTime(?int $time): static
    {
        $this->time = $time;

        return $this;
    }

    public function getHistoricTime(): ?int
    {
        return $this->historicTime;
    }

    public function setHistoricTime(?int $historicTime): static
    {
        $this->historicTime = $historicTime;

        return $this;
    }

    public function getJamLevel(): ?int
    {
        return $this->jamLevel;
    }

    public function setJamLevel(?int $jamLevel): static
    {
        $this->jamLevel = $jamLevel;

        return $this;
    }

    public function getPayload(): ?array
    {
        return $this->payload;
    }

    public function setPayload(?array $payload): static
    {
        $this->payload = $payload;

        return $this;
    }

    public function getRecordedAt(): ?\DateTimeImmutable
    {
        return $this->recordedAt;
    }

    public function setRecordedAt(
        \DateTimeImmutable $recordedAt,
    ): static {
        $this->recordedAt = $recordedAt;

        return $this;
    }
}
