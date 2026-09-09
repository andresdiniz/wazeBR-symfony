<?php

namespace App\Entity;

use App\Repository\WazeFeedRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: WazeFeedRepository::class)]
#[ORM\Table(name: 'waze_feed')]
#[ORM\HasLifecycleCallbacks]
#[ORM\Index(columns: ['feed_type', 'is_active'], name: 'IDX_WAZE_FEED_TYPE_ACTIVE')]
#[ORM\UniqueConstraint(name: 'UQ_WAZE_FEED_UNIQUE', columns: ['partner_id', 'feed_type', 'feed_uuid', 'external_route_id'])]
class WazeFeed
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Partner::class, inversedBy: 'wazeFeeds')]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    private ?Partner $partner = null;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(['EVENTS', 'TVT'])]
    private string $feedType = 'EVENTS';

    #[ORM\Column(length: 30)]
    private string $provider = 'WAZE';

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $externalPartnerId = null;

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank]
    private string $feedUuid = '';

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $externalRouteId = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    private string $endpointUrl = '';

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $label = null;

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $lastSuccessAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $lastErrorAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $lastErrorMessage = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $updatedAt;

    #[ORM\OneToMany(targetEntity: WazeAlert::class, mappedBy: 'wazeFeed')]
    private Collection $wazeAlerts;

    #[ORM\OneToMany(targetEntity: WazeTrafficJam::class, mappedBy: 'wazeFeed')]
    private Collection $wazeTrafficJams;

    #[ORM\OneToMany(targetEntity: WazeTvtRoute::class, mappedBy: 'wazeFeed')]
    private Collection $wazeTvtRoutes;

    #[ORM\OneToMany(targetEntity: WazeFeedCollection::class, mappedBy: 'wazeFeed')]
    private Collection $collections;

    public function __construct()
    {
        $this->wazeAlerts = new ArrayCollection();
        $this->wazeTrafficJams = new ArrayCollection();
        $this->wazeTvtRoutes = new ArrayCollection();
        $this->collections = new ArrayCollection();
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTime();
    }

    public function getUniqueKey(): string
    {
        return sprintf(
            '%d:%s:%s:%s',
            $this->partner?->getId() ?? 0,
            $this->feedType,
            $this->feedUuid,
            $this->externalRouteId ?? ''
        );
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

    public function getFeedType(): string
    {
        return $this->feedType;
    }

    public function setFeedType(string $feedType): static
    {
        $this->feedType = $feedType;
        return $this;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function setProvider(string $provider): static
    {
        $this->provider = $provider;
        return $this;
    }

    public function getExternalPartnerId(): ?string
    {
        return $this->externalPartnerId;
    }

    public function setExternalPartnerId(?string $externalPartnerId): static
    {
        $this->externalPartnerId = $externalPartnerId;
        return $this;
    }

    public function getFeedUuid(): string
    {
        return $this->feedUuid;
    }

    public function setFeedUuid(string $feedUuid): static
    {
        $this->feedUuid = $feedUuid;
        return $this;
    }

    public function getExternalRouteId(): ?string
    {
        return $this->externalRouteId;
    }

    public function setExternalRouteId(?string $externalRouteId): static
    {
        $this->externalRouteId = $externalRouteId;
        return $this;
    }

    public function getEndpointUrl(): string
    {
        return $this->endpointUrl;
    }

    public function setEndpointUrl(string $endpointUrl): static
    {
        $this->endpointUrl = $endpointUrl;
        return $this;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): static
    {
        $this->label = $label;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;
        return $this;
    }

    public function getLastSuccessAt(): ?\DateTimeInterface
    {
        return $this->lastSuccessAt;
    }

    public function setLastSuccessAt(?\DateTimeInterface $lastSuccessAt): static
    {
        $this->lastSuccessAt = $lastSuccessAt;
        return $this;
    }

    public function getLastErrorAt(): ?\DateTimeInterface
    {
        return $this->lastErrorAt;
    }

    public function setLastErrorAt(?\DateTimeInterface $lastErrorAt): static
    {
        $this->lastErrorAt = $lastErrorAt;
        return $this;
    }

    public function getLastErrorMessage(): ?string
    {
        return $this->lastErrorMessage;
    }

    public function setLastErrorMessage(?string $lastErrorMessage): static
    {
        $this->lastErrorMessage = $lastErrorMessage;
        return $this;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function getWazeAlerts(): Collection
    {
        return $this->wazeAlerts;
    }

    public function getWazeTrafficJams(): Collection
    {
        return $this->wazeTrafficJams;
    }

    public function getWazeTvtRoutes(): Collection
    {
        return $this->wazeTvtRoutes;
    }

    public function getCollections(): Collection
    {
        return $this->collections;
    }
}
