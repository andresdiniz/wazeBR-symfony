<?php

namespace App\Entity;

use App\Repository\WazeFeedRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WazeFeedRepository::class)]
class WazeFeed
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToMany(mappedBy: 'wazeFeed', targetEntity: WazeAlert::class)]
    private Collection $wazeAlerts;

    #[ORM\OneToMany(mappedBy: 'wazeFeed', targetEntity: WazeTrafficJam::class)]
    private Collection $wazeTrafficJams;

    #[ORM\OneToMany(mappedBy: 'wazeFeed', targetEntity: WazeTvtRoute::class)]
    private Collection $wazeTvtRoutes;

    #[ORM\OneToMany(mappedBy: 'wazeFeed', targetEntity: WazeFeedCollection::class)]
    private Collection $collections;

    public function __construct()
    {
        $this->wazeAlerts = new ArrayCollection();
        $this->wazeTrafficJams = new ArrayCollection();
        $this->wazeTvtRoutes = new ArrayCollection();
        $this->collections = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getWazeAlerts(): Collection { return $this->wazeAlerts; }
    public function getWazeTrafficJams(): Collection { return $this->wazeTrafficJams; }
    public function getWazeTvtRoutes(): Collection { return $this->wazeTvtRoutes; }
    public function getCollections(): Collection { return $this->collections; }

    public function addWazeAlert(WazeAlert $item): static { return $this->addTo($this->wazeAlerts, $item, $this); }
    public function removeWazeAlert(WazeAlert $item): static { return $this->removeFrom($this->wazeAlerts, $item, $this); }
    public function addWazeTrafficJam(WazeTrafficJam $item): static { return $this->addTo($this->wazeTrafficJams, $item, $this); }
    public function removeWazeTrafficJam(WazeTrafficJam $item): static { return $this->removeFrom($this->wazeTrafficJams, $item, $this); }
    public function addWazeTvtRoute(WazeTvtRoute $item): static { return $this->addTo($this->wazeTvtRoutes, $item, $this); }
    public function removeWazeTvtRoute(WazeTvtRoute $item): static { return $this->removeFrom($this->wazeTvtRoutes, $item, $this); }
    public function addCollection(WazeFeedCollection $item): static { return $this->addTo($this->collections, $item, $this); }
    public function removeCollection(WazeFeedCollection $item): static { return $this->removeFrom($this->collections, $item, $this); }

    private function addTo(Collection $collection, object $item, self $feed): static
    {
        if (!$collection->contains($item)) {
            $collection->add($item);
            $item->setWazeFeed($feed);
        }
        return $this;
    }

    private function removeFrom(Collection $collection, object $item, self $feed): static
    {
        if ($collection->removeElement($item) && $item->getWazeFeed() === $feed) {
            $item->setWazeFeed(null);
        }
        return $this;
    }
}
