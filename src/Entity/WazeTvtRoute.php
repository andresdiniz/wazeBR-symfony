<?php

namespace App\Entity;

use App\Repository\WazeTvtRouteRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WazeTvtRouteRepository::class)]
#[ORM\Table(name: 'waze_tvt_route')]
#[ORM\Index(columns: ['partner_id', 'waze_feed_id', 'external_route_id'], name: 'IDX_WAZE_TVT_ROUTE_EXTERNAL')]
#[ORM\UniqueConstraint(name: 'UQ_WAZE_TVT_ROUTE', columns: ['waze_feed_id', 'external_route_id'])]
class WazeTvtRoute
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Partner::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Partner $partner = null;

    #[ORM\ManyToOne(targetEntity: WazeFeed::class, inversedBy: 'wazeTvtRoutes')]
    #[ORM\JoinColumn(nullable: false)]
    private ?WazeFeed $wazeFeed = null;

    #[ORM\Column(length: 80)]
    private string $externalRouteId = '';

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $externalUuid = null;

    #[ORM\Column(length: 200, nullable: true)]
    private ?string $label = null;

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\ManyToOne(targetEntity: WazeTvtRouteDefinition::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?WazeTvtRouteDefinition $currentDefinition = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $firstSeenAt;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $lastSeenAt;

    #[ORM\OneToMany(targetEntity: WazeTvtRouteDefinition::class, mappedBy: 'wazeTvtRoute')]
    private Collection $definitions;

    #[ORM\OneToMany(targetEntity: WazeTvtRouteHistory::class, mappedBy: 'wazeTvtRoute')]
    private Collection $history;

    public function __construct()
    {
        $this->definitions = new ArrayCollection();
        $this->history = new ArrayCollection();
        $this->firstSeenAt = new \DateTime();
        $this->lastSeenAt = new \DateTime();
    }

    public function getId(): ?int { return $this->id; }

    public function getPartner(): ?Partner { return $this->partner; }
    public function setPartner(?Partner $partner): static { $this->partner = $partner; return $this; }

    public function getWazeFeed(): ?WazeFeed { return $this->wazeFeed; }
    public function setWazeFeed(?WazeFeed $wazeFeed): static { $this->wazeFeed = $wazeFeed; return $this; }

    public function getExternalRouteId(): string { return $this->externalRouteId; }
    public function setExternalRouteId(string $externalRouteId): static { $this->externalRouteId = $externalRouteId; return $this; }

    public function getExternalUuid(): ?string { return $this->externalUuid; }
    public function setExternalUuid(?string $externalUuid): static { $this->externalUuid = $externalUuid; return $this; }

    public function getLabel(): ?string { return $this->label; }
    public function setLabel(?string $label): static { $this->label = $label; return $this; }

    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $isActive): static { $this->isActive = $isActive; return $this; }

    public function getCurrentDefinition(): ?WazeTvtRouteDefinition { return $this->currentDefinition; }
    public function setCurrentDefinition(?WazeTvtRouteDefinition $currentDefinition): static { $this->currentDefinition = $currentDefinition; return $this; }

    public function getFirstSeenAt(): \DateTimeInterface { return $this->firstSeenAt; }
    public function setFirstSeenAt(\DateTimeInterface $firstSeenAt): static { $this->firstSeenAt = $firstSeenAt; return $this; }

    public function getLastSeenAt(): \DateTimeInterface { return $this->lastSeenAt; }
    public function setLastSeenAt(\DateTimeInterface $lastSeenAt): static { $this->lastSeenAt = $lastSeenAt; return $this; }

    public function getDefinitions(): Collection { return $this->definitions; }
    public function getHistory(): Collection { return $this->history; }
}
