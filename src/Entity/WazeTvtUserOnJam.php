<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WazeTvtUserOnJamRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WazeTvtUserOnJamRepository::class)]
#[ORM\Table(name: 'waze_tvt_user_on_jam')]
#[ORM\Index(
    name: 'idx_tvt_user_jam_partner_recorded',
    columns: ['partner_id', 'recorded_at'],
)]
class WazeTvtUserOnJam
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(
        targetEntity: Partner::class,
        inversedBy: 'wazeTvtUsersOnJam',
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
        nullable: true,
        onDelete: 'SET NULL',
    )]
    private ?WazeTvtRoute $route = null;

    #[ORM\Column(name: 'waze_route_id', length: 100, nullable: true)]
    private ?string $wazeRouteId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $username = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $jamType = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $street = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(length: 2, nullable: true)]
    private ?string $state = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $wazersCount = null;

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

    public function setWazeRouteId(?string $wazeRouteId): static
    {
        $this->wazeRouteId = $wazeRouteId;

        return $this;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function setUsername(?string $username): static
    {
        $this->username = $username;

        return $this;
    }

    public function getJamType(): ?string
    {
        return $this->jamType;
    }

    public function setJamType(?string $jamType): static
    {
        $this->jamType = $jamType;

        return $this;
    }

    public function getStreet(): ?string
    {
        return $this->street;
    }

    public function setStreet(?string $street): static
    {
        $this->street = $street;

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

    public function getWazersCount(): ?int
    {
        return $this->wazersCount;
    }

    public function setWazersCount(?int $wazersCount): static
    {
        $this->wazersCount = $wazersCount;

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
