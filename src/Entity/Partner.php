<?php

namespace App\Entity;

use App\Repository\PartnerRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PartnerRepository::class)]
#[ORM\Table(name: 'partner')]
class Partner
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $code = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $slug = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $bbox = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $cemadenStates = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $apiToken = null;

    #[ORM\Column(nullable: true)]
    private ?bool $active = null;

    #[ORM\Column(nullable: true)]
    private ?int $refreshIntervalMinutes = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $updatedAt = null;

    #[ORM\OneToMany(mappedBy: 'partner', targetEntity: CemadenData::class)]
    private Collection $cemadenData;

    #[ORM\OneToMany(mappedBy: 'partner', targetEntity: MonitoredCity::class)]
    private Collection $cities;

    #[ORM\OneToMany(mappedBy: 'partner', targetEntity: MonitoredLink::class)]
    private Collection $links;

    #[ORM\OneToMany(mappedBy: 'partner', targetEntity: User::class)]
    private Collection $users;

    #[ORM\OneToMany(mappedBy: 'partner', targetEntity: WazeAlert::class)]
    private Collection $alerts;

    #[ORM\OneToMany(mappedBy: 'partner', targetEntity: WazeCount::class)]
    private Collection $wazeCounts;

    #[ORM\OneToMany(mappedBy: 'partner', targetEntity: WazeRoute::class)]
    private Collection $routes;

    #[ORM\OneToMany(mappedBy: 'partner', targetEntity: WazeTrafficJam::class)]
    private Collection $trafficJams;

    #[ORM\OneToMany(mappedBy: 'partner', targetEntity: WazeTvtRoute::class)]
    private Collection $wazeTvtRoutes;

    #[ORM\OneToMany(mappedBy: 'partner', targetEntity: WazeFeed::class)]
    private Collection $wazeFeeds;

    #[ORM\OneToMany(mappedBy: 'partner', targetEntity: PartnerApiLink::class, orphanRemoval: true)]
    private Collection $apiLinks;

    public function __construct()
    {
        $this->cemadenData = new ArrayCollection();
        $this->cities = new ArrayCollection();
        $this->links = new ArrayCollection();
        $this->users = new ArrayCollection();
        $this->alerts = new ArrayCollection();
        $this->wazeCounts = new ArrayCollection();
        $this->routes = new ArrayCollection();
        $this->trafficJams = new ArrayCollection();
        $this->wazeTvtRoutes = new ArrayCollection();
        $this->wazeFeeds = new ArrayCollection();
        $this->apiLinks = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getName(): ?string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }
    public function getCode(): ?string { return $this->code; }
    public function setCode(?string $code): static { $this->code = $code; return $this; }
    public function getSlug(): ?string { return $this->slug; }
    public function setSlug(?string $slug): static { $this->slug = $slug; return $this; }
    public function getEmail(): ?string { return $this->email; }
    public function setEmail(?string $email): static { $this->email = $email; return $this; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): static { $this->description = $description; return $this; }
    public function getBbox(): ?string { return $this->bbox; }
    public function setBbox(?string $bbox): static { $this->bbox = $bbox; return $this; }
    public function getCemadenStates(): ?string { return $this->cemadenStates; }
    public function setCemadenStates(?string $cemadenStates): static { $this->cemadenStates = $cemadenStates; return $this; }
    public function getApiToken(): ?string { return $this->apiToken; }
    public function setApiToken(?string $apiToken): static { $this->apiToken = $apiToken; return $this; }
    public function isActive(): ?bool { return $this->active; }
    public function setActive(?bool $active): static { $this->active = $active; return $this; }
    public function getRefreshIntervalMinutes(): ?int { return $this->refreshIntervalMinutes; }
    public function setRefreshIntervalMinutes(?int $refreshIntervalMinutes): static { $this->refreshIntervalMinutes = $refreshIntervalMinutes; return $this; }
    public function getCreatedAt(): ?\DateTimeInterface { return $this->createdAt; }
    public function setCreatedAt(\DateTimeInterface $createdAt): static { $this->createdAt = $createdAt; return $this; }
    public function getUpdatedAt(): ?\DateTimeInterface { return $this->updatedAt; }
    public function setUpdatedAt(?\DateTimeInterface $updatedAt): static { $this->updatedAt = $updatedAt; return $this; }

    public function getCemadenData(): Collection { return $this->cemadenData; }
    public function getCities(): Collection { return $this->cities; }
    public function getLinks(): Collection { return $this->links; }
    public function getUsers(): Collection { return $this->users; }
    public function getAlerts(): Collection { return $this->alerts; }
    public function getWazeCounts(): Collection { return $this->wazeCounts; }
    public function getRoutes(): Collection { return $this->routes; }
    public function getTrafficJams(): Collection { return $this->trafficJams; }
    public function getWazeTvtRoutes(): Collection { return $this->wazeTvtRoutes; }
    public function getWazeFeeds(): Collection { return $this->wazeFeeds; }

    /** @return Collection<int, PartnerApiLink> */
    public function getApiLinks(): Collection { return $this->apiLinks; }

    public function addApiLink(PartnerApiLink $apiLink): static
    {
        if (!$this->apiLinks->contains($apiLink)) {
            $this->apiLinks->add($apiLink);
            $apiLink->setPartner($this);
        }
        return $this;
    }

    public function removeApiLink(PartnerApiLink $apiLink): static
    {
        if ($this->apiLinks->removeElement($apiLink) && $apiLink->getPartner() === $this) {
            $apiLink->setPartner(null);
        }
        return $this;
    }
}
