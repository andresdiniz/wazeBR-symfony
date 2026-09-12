<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PartnerRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
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

    #[ORM\Column(length: 50, unique: true)]
    private ?string $code = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(length: 2, nullable: true)]
    private ?string $state = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $apiKey = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $apiSecret = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $apiToken = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $fetchFrequency = 5;

    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private ?string $fetchFrequencyUnit = 'minutes';

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastFetchAt = null;

    #[ORM\OneToMany(
        targetEntity: User::class,
        mappedBy: 'partner',
    )]
    private Collection $users;

    #[ORM\OneToMany(
        targetEntity: WazeAlert::class,
        mappedBy: 'partner',
    )]
    private Collection $wazeAlerts;

    #[ORM\OneToMany(
        targetEntity: WazeJam::class,
        mappedBy: 'partner',
    )]
    private Collection $wazeJams;

    #[ORM\OneToMany(
        targetEntity: WeatherLocation::class,
        mappedBy: 'partner',
    )]
    private Collection $weatherLocations;

    #[ORM\OneToMany(
        targetEntity: PartnerApiLink::class,
        mappedBy: 'partner',
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    private Collection $apiLinks;

    #[ORM\OneToMany(
        targetEntity: WazeTvtRoute::class,
        mappedBy: 'partner',
    )]
    private Collection $wazeTvtRoutes;

    #[ORM\OneToMany(
        targetEntity: WazeTvtSubRoute::class,
        mappedBy: 'partner',
    )]
    private Collection $wazeTvtSubRoutes;

    #[ORM\OneToMany(
        targetEntity: WazeTvtIrregularity::class,
        mappedBy: 'partner',
    )]
    private Collection $wazeTvtIrregularities;

    #[ORM\OneToMany(
        targetEntity: WazeTvtRouteSnapshot::class,
        mappedBy: 'partner',
    )]
    private Collection $wazeTvtRouteSnapshots;

    #[ORM\OneToMany(
        targetEntity: WazeTvtUserOnJam::class,
        mappedBy: 'partner',
    )]
    private Collection $wazeTvtUsersOnJam;

    public function __construct()
    {
        $this->users = new ArrayCollection();
        $this->wazeAlerts = new ArrayCollection();
        $this->wazeJams = new ArrayCollection();
        $this->weatherLocations = new ArrayCollection();
        $this->apiLinks = new ArrayCollection();

        $this->wazeTvtRoutes = new ArrayCollection();
        $this->wazeTvtSubRoutes = new ArrayCollection();
        $this->wazeTvtIrregularities = new ArrayCollection();
        $this->wazeTvtRouteSnapshots = new ArrayCollection();
        $this->wazeTvtUsersOnJam = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(?string $code): static
    {
        $this->code = $code;

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

    public function getApiKey(): ?string
    {
        return $this->apiKey;
    }

    public function setApiKey(?string $apiKey): static
    {
        $this->apiKey = $apiKey;

        return $this;
    }

    public function getApiSecret(): ?string
    {
        return $this->apiSecret;
    }

    public function setApiSecret(?string $apiSecret): static
    {
        $this->apiSecret = $apiSecret;

        return $this;
    }

    public function getApiToken(): ?string
    {
        return $this->apiToken;
    }

    public function setApiToken(?string $apiToken): static
    {
        $this->apiToken = $apiToken;

        return $this;
    }

    public function getFetchFrequency(): ?int
    {
        return $this->fetchFrequency;
    }

    public function setFetchFrequency(?int $fetchFrequency): static
    {
        $this->fetchFrequency = $fetchFrequency;

        return $this;
    }

    public function getFetchFrequencyUnit(): ?string
    {
        return $this->fetchFrequencyUnit;
    }

    public function setFetchFrequencyUnit(
        ?string $fetchFrequencyUnit,
    ): static {
        $this->fetchFrequencyUnit = $fetchFrequencyUnit;

        return $this;
    }

    public function getLastFetchAt(): ?\DateTimeImmutable
    {
        return $this->lastFetchAt;
    }

    public function setLastFetchAt(
        ?\DateTimeImmutable $lastFetchAt,
    ): static {
        $this->lastFetchAt = $lastFetchAt;

        return $this;
    }

    public function getUsers(): Collection
    {
        return $this->users;
    }

    public function addUser(User $user): static
    {
        if (!$this->users->contains($user)) {
            $this->users->add($user);
            $user->setPartner($this);
        }

        return $this;
    }

    public function removeUser(User $user): static
    {
        if ($this->users->removeElement($user)) {
            if ($user->getPartner() === $this) {
                $user->setPartner(null);
            }
        }

        return $this;
    }

    public function getWazeAlerts(): Collection
    {
        return $this->wazeAlerts;
    }

    public function addWazeAlert(WazeAlert $wazeAlert): static
    {
        if (!$this->wazeAlerts->contains($wazeAlert)) {
            $this->wazeAlerts->add($wazeAlert);
            $wazeAlert->setPartner($this);
        }

        return $this;
    }

    public function removeWazeAlert(WazeAlert $wazeAlert): static
    {
        if ($this->wazeAlerts->removeElement($wazeAlert)) {
            if ($wazeAlert->getPartner() === $this) {
                $wazeAlert->setPartner(null);
            }
        }

        return $this;
    }

    public function getWazeJams(): Collection
    {
        return $this->wazeJams;
    }

    public function addWazeJam(WazeJam $wazeJam): static
    {
        if (!$this->wazeJams->contains($wazeJam)) {
            $this->wazeJams->add($wazeJam);
            $wazeJam->setPartner($this);
        }

        return $this;
    }

    public function removeWazeJam(WazeJam $wazeJam): static
    {
        if ($this->wazeJams->removeElement($wazeJam)) {
            if ($wazeJam->getPartner() === $this) {
                $wazeJam->setPartner(null);
            }
        }

        return $this;
    }

    public function getWeatherLocations(): Collection
    {
        return $this->weatherLocations;
    }

    public function addWeatherLocation(
        WeatherLocation $weatherLocation,
    ): static {
        if (!$this->weatherLocations->contains($weatherLocation)) {
            $this->weatherLocations->add($weatherLocation);
            $weatherLocation->setPartner($this);
        }

        return $this;
    }

    public function removeWeatherLocation(
        WeatherLocation $weatherLocation,
    ): static {
        if ($this->weatherLocations->removeElement($weatherLocation)) {
            if ($weatherLocation->getPartner() === $this) {
                $weatherLocation->setPartner(null);
            }
        }

        return $this;
    }

    public function getApiLinks(): Collection
    {
        return $this->apiLinks;
    }

    public function addApiLink(
        PartnerApiLink $apiLink,
    ): static {
        if (!$this->apiLinks->contains($apiLink)) {
            $this->apiLinks->add($apiLink);
            $apiLink->setPartner($this);
        }

        return $this;
    }

    public function removeApiLink(
        PartnerApiLink $apiLink,
    ): static {
        if ($this->apiLinks->removeElement($apiLink)) {
            if ($apiLink->getPartner() === $this) {
                $apiLink->setPartner(null);
            }
        }

        return $this;
    }

    public function getWazeTvtRoutes(): Collection
    {
        return $this->wazeTvtRoutes;
    }

    public function addWazeTvtRoute(
        WazeTvtRoute $route,
    ): static {
        if (!$this->wazeTvtRoutes->contains($route)) {
            $this->wazeTvtRoutes->add($route);
            $route->setPartner($this);
        }

        return $this;
    }

    public function removeWazeTvtRoute(
        WazeTvtRoute $route,
    ): static {
        if ($this->wazeTvtRoutes->removeElement($route)) {
            if ($route->getPartner() === $this) {
                $route->setPartner(null);
            }
        }

        return $this;
    }

    public function getWazeTvtSubRoutes(): Collection
    {
        return $this->wazeTvtSubRoutes;
    }

    public function addWazeTvtSubRoute(
        WazeTvtSubRoute $subRoute,
    ): static {
        if (!$this->wazeTvtSubRoutes->contains($subRoute)) {
            $this->wazeTvtSubRoutes->add($subRoute);
            $subRoute->setPartner($this);
        }

        return $this;
    }

    public function removeWazeTvtSubRoute(
        WazeTvtSubRoute $subRoute,
    ): static {
        if ($this->wazeTvtSubRoutes->removeElement($subRoute)) {
            if ($subRoute->getPartner() === $this) {
                $subRoute->setPartner(null);
            }
        }

        return $this;
    }

    public function getWazeTvtIrregularities(): Collection
    {
        return $this->wazeTvtIrregularities;
    }

    public function addWazeTvtIrregularity(
        WazeTvtIrregularity $irregularity,
    ): static {
        if (!$this->wazeTvtIrregularities->contains($irregularity)) {
            $this->wazeTvtIrregularities->add($irregularity);
            $irregularity->setPartner($this);
        }

        return $this;
    }

    public function removeWazeTvtIrregularity(
        WazeTvtIrregularity $irregularity,
    ): static {
        if ($this->wazeTvtIrregularities->removeElement($irregularity)) {
            if ($irregularity->getPartner() === $this) {
                $irregularity->setPartner(null);
            }
        }

        return $this;
    }

    public function getWazeTvtRouteSnapshots(): Collection
    {
        return $this->wazeTvtRouteSnapshots;
    }

    public function addWazeTvtRouteSnapshot(
        WazeTvtRouteSnapshot $snapshot,
    ): static {
        if (!$this->wazeTvtRouteSnapshots->contains($snapshot)) {
            $this->wazeTvtRouteSnapshots->add($snapshot);
            $snapshot->setPartner($this);
        }

        return $this;
    }

    public function removeWazeTvtRouteSnapshot(
        WazeTvtRouteSnapshot $snapshot,
    ): static {
        if (
            $this->wazeTvtRouteSnapshots
                ->removeElement($snapshot)
        ) {
            if ($snapshot->getPartner() === $this) {
                $snapshot->setPartner(null);
            }
        }

        return $this;
    }

    public function getWazeTvtUsersOnJam(): Collection
    {
        return $this->wazeTvtUsersOnJam;
    }

    public function addWazeTvtUserOnJam(
        WazeTvtUserOnJam $userOnJam,
    ): static {
        if (!$this->wazeTvtUsersOnJam->contains($userOnJam)) {
            $this->wazeTvtUsersOnJam->add($userOnJam);
            $userOnJam->setPartner($this);
        }

        return $this;
    }

    public function removeWazeTvtUserOnJam(
        WazeTvtUserOnJam $userOnJam,
    ): static {
        if (
            $this->wazeTvtUsersOnJam
                ->removeElement($userOnJam)
        ) {
            if ($userOnJam->getPartner() === $this) {
                $userOnJam->setPartner(null);
            }
        }

        return $this;
    }
}
