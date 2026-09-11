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

    public function __construct()
    {
        $this->wazeAlerts = new ArrayCollection();
        $this->wazeTrafficJams = new ArrayCollection();
        $this->wazeTvtRoutes = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getWazeAlerts(): Collection
    {
        return $this->wazeAlerts;
    }

    public function addWazeAlert(WazeAlert $wazeAlert): static
    {
        if (!$this->wazeAlerts->contains($wazeAlert)) {
            $this->wazeAlerts->add($wazeAlert);
            $wazeAlert->setWazeFeed($this);
        }

        return $this;
    }

    public function removeWazeAlert(WazeAlert $wazeAlert): static
    {
        if ($this->wazeAlerts->removeElement($wazeAlert) && $wazeAlert->getWazeFeed() === $this) {
            $wazeAlert->setWazeFeed(null);
        }

        return $this;
    }

    public function getWazeTrafficJams(): Collection
    {
        return $this->wazeTrafficJams;
    }

    public function addWazeTrafficJam(WazeTrafficJam $wazeTrafficJam): static
    {
        if (!$this->wazeTrafficJams->contains($wazeTrafficJam)) {
            $this->wazeTrafficJams->add($wazeTrafficJam);
            $wazeTrafficJam->setWazeFeed($this);
        }

        return $this;
    }

    public function removeWazeTrafficJam(WazeTrafficJam $wazeTrafficJam): static
    {
        if ($this->wazeTrafficJams->removeElement($wazeTrafficJam) && $wazeTrafficJam->getWazeFeed() === $this) {
            $wazeTrafficJam->setWazeFeed(null);
        }

        return $this;
    }

    public function getWazeTvtRoutes(): Collection
    {
        return $this->wazeTvtRoutes;
    }

    public function addWazeTvtRoute(WazeTvtRoute $wazeTvtRoute): static
    {
        if (!$this->wazeTvtRoutes->contains($wazeTvtRoute)) {
            $this->wazeTvtRoutes->add($wazeTvtRoute);
            $wazeTvtRoute->setWazeFeed($this);
        }

        return $this;
    }

    public function removeWazeTvtRoute(WazeTvtRoute $wazeTvtRoute): static
    {
        if ($this->wazeTvtRoutes->removeElement($wazeTvtRoute) && $wazeTvtRoute->getWazeFeed() === $this) {
            $wazeTvtRoute->setWazeFeed(null);
        }

        return $this;
    }
}
