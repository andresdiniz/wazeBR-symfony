<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WazeFeedRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Timestampable\Traits\TimestampableEntity;

#[ORM\Entity(repositoryClass: WazeFeedRepository::class)]
#[ORM\Table(name: 'waze_feed')]
class WazeFeed
{
    use TimestampableEntity;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'wazeFeeds')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Partner $partner = null;

    #[ORM\Column(length: 36, unique: true)]
    private ?string $feedUuid = null;

    #[ORM\Column(name: 'feed_id', type: Types::INTEGER, nullable: true, options: ['comment' => 'Waze numeric feed ID'])]
    private ?int $feedId = null;

    #[ORM\Column(name: 'endpoint_url', type: Types::STRING, length: 500, nullable: true, options: ['comment' => 'Full Waze API endpoint URL'])]
    private ?string $endpointUrl = null;

    #[ORM\Column(length: 50)]
    private ?string $type = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $active = true;

    #[ORM\OneToMany(mappedBy: 'wazeFeed', targetEntity: WazeFeedCollection::class, cascade: ['remove'])]
    private Collection $collections;

    public function __construct()
    {
        $this->collections = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPartner(): ?Partner
    {
        return $this->partner;
    }

    public function setPartner(?Partner $partner): static
    {
        $this->partner = $partner;
        return $this;
    }

    public function getFeedUuid(): ?string
    {
        return $this->feedUuid;
    }

    public function setFeedUuid(?string $feedUuid): static
    {
        $this->feedUuid = $feedUuid;
        return $this;
    }

    public function getFeedId(): ?int
    {
        return $this->feedId;
    }

    public function setFeedId(?int $feedId): static
    {
        $this->feedId = $feedId;
        return $this;
    }

    public function getEndpointUrl(): ?string
    {
        return $this->endpointUrl;
    }

    public function setEndpointUrl(?string $endpointUrl): static
    {
        $this->endpointUrl = $endpointUrl;
        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(?string $type): static
    {
        $this->type = $type;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;
        return $this;
    }

    /**
     * @return Collection<int, WazeFeedCollection>
     */
    public function getCollections(): Collection
    {
        return $this->collections;
    }

    public function addCollection(WazeFeedCollection $collection): static
    {
        if (!$this->collections->contains($collection)) {
            $this->collections->add($collection);
            $collection->setWazeFeed($this);
        }

        return $this;
    }

    public function removeCollection(WazeFeedCollection $collection): static
    {
        if ($this->collections->removeElement($collection)) {
            if ($collection->getWazeFeed() === $this) {
                $collection->setWazeFeed(null);
            }
        }

        return $this;
    }
}
