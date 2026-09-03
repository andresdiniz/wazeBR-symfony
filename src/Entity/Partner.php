<?php

declare(strict_types=1);

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

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(nullable: true)]
    private ?bool $active = null;

    #[ORM\OneToMany(mappedBy: 'partner', targetEntity: User::class)]
    private Collection $users;

    #[ORM\OneToMany(mappedBy: 'partner', targetEntity: CemadenData::class)]
    private Collection $cemadenData;

    #[ORM\OneToMany(mappedBy: 'partner', targetEntity: MonitoredCity::class)]
    private Collection $cities;

    #[ORM\OneToMany(mappedBy: 'partner', targetEntity: MonitoredLink::class)]
    private Collection $links;

    #[ORM\OneToMany(mappedBy: 'partner', targetEntity: WazeAlert::class)]
    private Collection $alerts;

    #[ORM\OneToMany(mappedBy: 'partner', targetEntity: WazeCount::class)]
    private Collection $wazeCounts;

    #[ORM\OneToMany(mappedBy: 'partner', targetEntity: WazeRoute::class)]
    private Collection $routes;

    #[ORM\OneToMany(mappedBy: 'partner', targetEntity: WazeTrafficJam::class)]
    private Collection $trafficJams;

    public function __construct()
    {
        $this->users = new ArrayCollection();
        $this->cemadenData = new ArrayCollection();
        $this->cities = new ArrayCollection();
        $this->links = new ArrayCollection();
        $this->alerts = new ArrayCollection();
        $this->wazeCounts = new ArrayCollection();
        $this->routes = new ArrayCollection();
        $this->trafficJams = new ArrayCollection();
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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function isActive(): ?bool
    {
        return $this->active;
    }

    public function setActive(?bool $active): static
    {
        $this->active = $active;
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

    public function getCemadenData(): Collection
    {
        return $this->cemadenData;
    }

    public function getCities(): Collection
    {
        return $this->cities;
    }

    public function getLinks(): Collection
    {
        return $this->links;
    }

    public function getAlerts(): Collection
    {
        return $this->alerts;
    }

    public function getWazeCounts(): Collection
    {
        return $this->wazeCounts;
    }

    public function getRoutes(): Collection
    {
        return $this->routes;
    }

    public function getTrafficJams(): Collection
    {
        return $this->trafficJams;
    }
}
