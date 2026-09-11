<?php

namespace App\Entity;

use App\Repository\WazeTvtRouteRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WazeTvtRouteRepository::class)]
class WazeTvtRoute
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Partner::class)]
    #[ORM\JoinColumn(name: 'partner_id', referencedColumnName: 'id', nullable: false)]
    private ?Partner $partner = null;

    #[ORM\ManyToOne(targetEntity: WazeFeed::class, inversedBy: 'wazeTvtRoutes')]
    #[ORM\JoinColumn(name: 'waze_feed_id', referencedColumnName: 'id', nullable: false)]
    private ?WazeFeed $wazeFeed = null;

    public function getId(): ?int { return $this->id; }
    public function getPartner(): ?Partner { return $this->partner; }
    public function setPartner(?Partner $partner): static { $this->partner = $partner; return $this; }
    public function getWazeFeed(): ?WazeFeed { return $this->wazeFeed; }
    public function setWazeFeed(?WazeFeed $wazeFeed): static { $this->wazeFeed = $wazeFeed; return $this; }
}
