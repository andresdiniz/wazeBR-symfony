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
    private ?string $apiToken = null;

    #[ORM\OneToMany(targetEntity: User::class, mappedBy: 'partner')]
    private Collection $users;

    #[ORM\OneToMany(targetEntity: WazeAlert::class, mappedBy: 'partner')]
    private Collection $wazeAlerts;

    #[ORM\OneToMany(targetEntity: WazeJam::class, mappedBy: 'partner')]
    private Collection $wazeJams;

    #[ORM\OneToMany(targetEntity: WeatherLocation::class, mappedBy: 'partner')]
    private Collection $weatherLocations;

    public function __construct()
    {
        $this->users = new ArrayCollection();
        $this->wazeAlerts = new ArrayCollection();
        $this->wazeJams = new ArrayCollection();
        $this->weatherLocations = new ArrayCollection();
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

    public function getApiToken(): ?string
    {
        return $this->apiToken;
    }

    public function setApiToken(?string $apiToken): static
    {
        $this->apiToken = $apiToken;
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

    public function addWeatherLocation(WeatherLocation $weatherLocation): static
    {
        if (!$this->weatherLocations->contains($weatherLocation)) {
            $this->weatherLocations->add($weatherLocation);
            $weatherLocation->setPartner($this);
        }
        return $this;
    }

    public function removeWeatherLocation(WeatherLocation $weatherLocation): static
    {
        if ($this->weatherLocations->removeElement($weatherLocation)) {
            if ($weatherLocation->getPartner() === $this) {
                $weatherLocation->setPartner(null);
            }
        }
        return $this;
    }
}
