<?php

namespace App\Entity;

use App\Repository\PartnerRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PartnerRepository::class)]
class Partner
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

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
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCemadenData(): Collection { return $this->cemadenData; }
    public function getCities(): Collection { return $this->cities; }
    public function getLinks(): Collection { return $this->links; }
    public function getUsers(): Collection { return $this->users; }
    public function getAlerts(): Collection { return $this->alerts; }
    public function getWazeCounts(): Collection { return $this->wazeCounts; }
    public function getRoutes(): Collection { return $this->routes; }
    public function getTrafficJams(): Collection { return $this->trafficJams; }
    public function getWazeTvtRoutes(): Collection { return $this->wazeTvtRoutes; }

    public function addCemadenData(CemadenData $item): static { return $this->addTo($this->cemadenData, $item, $this); }
    public function removeCemadenData(CemadenData $item): static { return $this->removeFrom($this->cemadenData, $item, $this); }
    public function addCity(MonitoredCity $item): static { return $this->addTo($this->cities, $item, $this); }
    public function removeCity(MonitoredCity $item): static { return $this->removeFrom($this->cities, $item, $this); }
    public function addLink(MonitoredLink $item): static { return $this->addTo($this->links, $item, $this); }
    public function removeLink(MonitoredLink $item): static { return $this->removeFrom($this->links, $item, $this); }
    public function addUser(User $item): static { return $this->addTo($this->users, $item, $this); }
    public function removeUser(User $item): static { return $this->removeFrom($this->users, $item, $this); }
    public function addAlert(WazeAlert $item): static { return $this->addTo($this->alerts, $item, $this); }
    public function removeAlert(WazeAlert $item): static { return $this->removeFrom($this->alerts, $item, $this); }
    public function addWazeCount(WazeCount $item): static { return $this->addTo($this->wazeCounts, $item, $this); }
    public function removeWazeCount(WazeCount $item): static { return $this->removeFrom($this->wazeCounts, $item, $this); }
    public function addRoute(WazeRoute $item): static { return $this->addTo($this->routes, $item, $this); }
    public function removeRoute(WazeRoute $item): static { return $this->removeFrom($this->routes, $item, $this); }
    public function addTrafficJam(WazeTrafficJam $item): static { return $this->addTo($this->trafficJams, $item, $this); }
    public function removeTrafficJam(WazeTrafficJam $item): static { return $this->removeFrom($this->trafficJams, $item, $this); }
    public function addWazeTvtRoute(WazeTvtRoute $item): static { return $this->addTo($this->wazeTvtRoutes, $item, $this); }
    public function removeWazeTvtRoute(WazeTvtRoute $item): static { return $this->removeFrom($this->wazeTvtRoutes, $item, $this); }

    private function addTo(Collection $collection, object $item, self $partner): static
    {
        if (!$collection->contains($item)) {
            $collection->add($item);
            $item->setPartner($partner);
        }
        return $this;
    }

    private function removeFrom(Collection $collection, object $item, self $partner): static
    {
        if ($collection->removeElement($item) && $item->getPartner() === $partner) {
            $item->setPartner(null);
        }
        return $this;
    }
}
