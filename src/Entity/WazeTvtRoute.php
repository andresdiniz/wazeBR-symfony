<?php

namespace App\Entity;

use App\Repository\WazeTvtRouteRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WazeTvtRouteRepository::class)]
class WazeTvtRoute
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Partner::class, inversedBy: 'wazeTvtRoutes')]
    #[ORM\JoinColumn(name: 'partner_id', referencedColumnName: 'id', nullable: false)]
    private ?Partner $partner = null;

    #[ORM\ManyToOne(targetEntity: WazeFeed::class, inversedBy: 'wazeTvtRoutes')]
    #[ORM\JoinColumn(name: 'waze_feed_id', referencedColumnName: 'id', nullable: false)]
    private ?WazeFeed $wazeFeed = null;

    #[ORM\OneToMany(mappedBy: 'wazeTvtRoute', targetEntity: WazeTvtRouteDefinition::class)]
    private Collection $definitions;

    #[ORM\OneToMany(mappedBy: 'wazeTvtRoute', targetEntity: WazeTvtRouteHistory::class)]
    private Collection $histories;

    public function __construct()
    {
        $this->definitions = new ArrayCollection();
        $this->histories = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getPartner(): ?Partner { return $this->partner; }
    public function setPartner(?Partner $partner): static { $this->partner = $partner; return $this; }
    public function getWazeFeed(): ?WazeFeed { return $this->wazeFeed; }
    public function setWazeFeed(?WazeFeed $wazeFeed): static { $this->wazeFeed = $wazeFeed; return $this; }
    public function getDefinitions(): Collection { return $this->definitions; }
    public function getHistories(): Collection { return $this->histories; }
}
