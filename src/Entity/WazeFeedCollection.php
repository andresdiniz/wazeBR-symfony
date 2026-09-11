<?php

namespace App\Entity;

use App\Repository\WazeFeedCollectionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WazeFeedCollectionRepository::class)]
class WazeFeedCollection
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: WazeFeed::class, inversedBy: 'collections')]
    #[ORM\JoinColumn(name: 'waze_feed_id', referencedColumnName: 'id', nullable: false)]
    private ?WazeFeed $wazeFeed = null;

    public function getId(): ?int { return $this->id; }
    public function getWazeFeed(): ?WazeFeed { return $this->wazeFeed; }
    public function setWazeFeed(?WazeFeed $wazeFeed): static { $this->wazeFeed = $wazeFeed; return $this; }
}
