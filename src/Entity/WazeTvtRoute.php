<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WazeTvtRouteRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WazeTvtRouteRepository::class)]
#[ORM\Table(name: 'waze_tvt_route')]
#[ORM\Index(
    name: 'idx_tvt_route_partner_active',
    columns: ['partner_id', 'is_active'],
)]
#[ORM\UniqueConstraint(
    name: 'uniq_tvt_route_partner_route',
    columns: ['partner_id', 'route_id'],
)]
class WazeTvtRoute
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(
        targetEntity: Partner::class,
        inversedBy: 'wazeTvtRoutes',
    )]
    #[ORM\JoinColumn(
        name: 'partner_id',
        referencedColumnName: 'id',
        nullable: false,
        onDelete: 'CASCADE',
    )]
    private ?Partner $partner = null;

    #[ORM\Column(length: 100)]
    private ?string $routeId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $fromName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $toName = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $length = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $geometry = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $lastSeenAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deactivatedAt = null;

    #[ORM\OneToMany(
        targetEntity: WazeTvtSubRoute::class,
        mappedBy: 'route',
    )]
    private Collection $subRoutes;

    public function __construct()
    {
        $this->subRoutes = new ArrayCollection();
        $this->lastSeenAt = new \DateTimeImmutable();
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

    public function getRouteId(): ?string
    {
        return $this->routeId;
    }

    public function setRouteId(string $routeId): static
    {
        $this->routeId = $routeId;

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

    public function getFromName(): ?string
    {
        return $this->fromName;
    }

    public function setFromName(?string $fromName): static
    {
        $this->fromName = $fromName;

        return $this;
    }

    public function getToName(): ?string
    {
        return $this->toName;
    }

    public function setToName(?string $toName): static
    {
        $this->toName = $toName;

        return $this;
    }

    public function getLength(): ?int
    {
        return $this->length;
    }

    public function setLength(?int $length): static
    {
        $this->length = $length;

        return $this;
    }

    public function getGeometry(): ?array
    {
        return $this->geometry;
    }

    public function setGeometry(?array $geometry): static
    {
        $this->geometry = $geometry;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;

        if ($isActive) {
            $this->deactivatedAt = null;
        }

        return $this;
    }

    public function getLastSeenAt(): ?\DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function setLastSeenAt(
        \DateTimeImmutable $lastSeenAt,
    ): static {
        $this->lastSeenAt = $lastSeenAt;

        return $this;
    }

    public function getDeactivatedAt(): ?\DateTimeImmutable
    {
        return $this->deactivatedAt;
    }

    public function setDeactivatedAt(
        ?\DateTimeImmutable $deactivatedAt,
    ): static {
        $this->deactivatedAt = $deactivatedAt;

        return $this;
    }

    public function getSubRoutes(): Collection
    {
        return $this->subRoutes;
    }

    public function addSubRoute(
        WazeTvtSubRoute $subRoute,
    ): static {
        if (!$this->subRoutes->contains($subRoute)) {
            $this->subRoutes->add($subRoute);
            $subRoute->setRoute($this);
        }

        return $this;
    }

    public function removeSubRoute(
        WazeTvtSubRoute $subRoute,
    ): static {
        $this->subRoutes->removeElement($subRoute);

        return $this;
    }
}
