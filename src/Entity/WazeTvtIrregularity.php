<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WazeTvtIrregularityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WazeTvtIrregularityRepository::class)]
#[ORM\Table(name: 'waze_tvt_irregularity')]
#[ORM\Index(
    name: 'idx_tvt_irregularity_partner_active',
    columns: ['partner_id', 'is_active'],
)]
#[ORM\UniqueConstraint(
    name: 'uniq_tvt_irregularity_hash',
    columns: ['partner_id', 'route_id', 'sub_route_id', 'content_hash'],
)]
class WazeTvtIrregularity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(
        targetEntity: Partner::class,
        inversedBy: 'wazeTvtIrregularities',
    )]
    #[ORM\JoinColumn(
        name: 'partner_id',
        referencedColumnName: 'id',
        nullable: false,
        onDelete: 'CASCADE',
    )]
    private ?Partner $partner = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(
        name: 'route_id',
        referencedColumnName: 'id',
        nullable: false,
        onDelete: 'CASCADE',
    )]
    private ?WazeTvtRoute $route = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(
        name: 'sub_route_id',
        referencedColumnName: 'id',
        nullable: true,
        onDelete: 'CASCADE',
    )]
    private ?WazeTvtSubRoute $subRoute = null;

    #[ORM\Column(name: 'waze_route_id', length: 100)]
    private ?string $wazeRouteId = null;

    #[ORM\Column(name: 'waze_sub_route_id', length: 100, nullable: true)]
    private ?string $wazeSubRouteId = null;

    #[ORM\Column(name: 'content_hash', length: 64)]
    private ?string $contentHash = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $type = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $subtype = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $severity = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $reportedTime = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $street = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(length: 2, nullable: true)]
    private ?string $state = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $latitude = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $longitude = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $payload = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $recordedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $lastSeenAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deactivatedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $now = new \DateTimeImmutable();

        $this->recordedAt = $now;
        $this->lastSeenAt = $now;
        $this->updatedAt = $now;
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

    public function getSubRoute(): ?WazeTvtSubRoute
    {
        return $this->subRoute;
    }

    public function setSubRoute(?WazeTvtSubRoute $subRoute): static
    {
        $this->subRoute = $subRoute;

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

    public function getWazeSubRouteId(): ?string
    {
        return $this->wazeSubRouteId;
    }

    public function setWazeSubRouteId(?string $wazeSubRouteId): static
    {
        $this->wazeSubRouteId = $wazeSubRouteId;

        return $this;
    }

    public function getContentHash(): ?string
    {
        return $this->contentHash;
    }

    public function setContentHash(string $contentHash): static
    {
        $this->contentHash = $contentHash;

        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(?string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getSubtype(): ?string
    {
        return $this->subtype;
    }

    public function setSubtype(?string $subtype): static
    {
        $this->subtype = $subtype;

        return $this;
    }

    public function getSeverity(): ?string
    {
        return $this->severity;
    }

    public function setSeverity(?string $severity): static
    {
        $this->severity = $severity;

        return $this;
    }

    public function getReportedTime(): ?\DateTimeImmutable
    {
        return $this->reportedTime;
    }

    public function setReportedTime(
        ?\DateTimeImmutable $reportedTime,
    ): static {
        $this->reportedTime = $reportedTime;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

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

    public function getLatitude(): ?float
    {
        return $this->latitude;
    }

    public function setLatitude(?float $latitude): static
    {
        $this->latitude = $latitude;

        return $this;
    }

    public function getLongitude(): ?float
    {
        return $this->longitude;
    }

    public function setLongitude(?float $longitude): static
    {
        $this->longitude = $longitude;

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

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(
        \DateTimeImmutable $updatedAt,
    ): static {
        $this->updatedAt = $updatedAt;

        return $this;
    }
}
