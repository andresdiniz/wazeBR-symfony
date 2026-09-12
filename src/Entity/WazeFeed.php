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
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Partner::class, inversedBy: 'wazeFeeds')]
    #[ORM\JoinColumn(name: 'partner_id', referencedColumnName: 'id', nullable: false)]
    private ?Partner $partner = null;

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
    public function getPartner(): ?Partner { return $this->partner; }
    public function setPartner(?Partner $partner): static { $this->partner = $partner; return $this; }
    public function getWazeAlerts(): Collection { return $this->wazeAlerts; }
    public function getWazeTrafficJams(): Collection { return $this->wazeTrafficJams; }
    public function getWazeTvtRoutes(): Collection { return $this->wazeTvtRoutes; }
    public function getCollections(): Collection { return $this->collections; }
}
