<?php

namespace App\Entity;

use App\Repository\PartnerRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PartnerRepository::class)]
#[ORM\Table(name: 'partners')]
class Partner
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 100, unique: true)]
    private ?string $name = null;

    #[ORM\Column(type: 'string', length: 50, unique: true)]
    private ?string $slug = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(type: 'string', length: 2, nullable: true)]
    private ?string $state = null;

    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private ?string $cnpj = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $logoUrl = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $primaryColor = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $secondaryColor = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\OneToMany(targetEntity: User::class, mappedBy: 'partner', orphanRemoval: true)]
    private Collection $users;

    #[ORM\OneToMany(targetEntity: WazeAlert::class, mappedBy: 'partner', orphanRemoval: true)]
    private Collection $alerts;

    #[ORM\OneToMany(targetEntity: WazeTrafficJam::class, mappedBy: 'partner', orphanRemoval: true)]
    private Collection $trafficJams;

    #[ORM\OneToMany(targetEntity: CemadenData::class, mappedBy: 'partner', orphanRemoval: true)]
    private Collection $cemadenData;

    #[ORM\OneToMany(targetEntity: WazeTvtSnapshot::class, mappedBy: 'partner', orphanRemoval: true)]
    private Collection $tvtSnapshots;

    #[ORM\OneToMany(targetEntity: WazeIrregularity::class, mappedBy: 'partner', orphanRemoval: true)]
    private Collection $irregularities;

    #[ORM\OneToMany(targetEntity: WazeCount::class, mappedBy: 'partner', orphanRemoval: true)]
    private Collection $wazeCounts;

    #[ORM\OneToMany(targetEntity: CifsEvent::class, mappedBy: 'partner', orphanRemoval: true)]
    private Collection $cifsEvents;

    public function __construct()
    {
        $this->users = new ArrayCollection();
        $this->alerts = new ArrayCollection();
        $this->trafficJams = new ArrayCollection();
        $this->cemadenData = new ArrayCollection();
        $this->tvtSnapshots = new ArrayCollection();
        $this->irregularities = new ArrayCollection();
        $this->wazeCounts = new ArrayCollection();
        $this->cifsEvents = new ArrayCollection();
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

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(?string $slug): static
    {
        $this->slug = $slug;
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

    public function getCnpj(): ?string
    {
        return $this->cnpj;
    }

    public function setCnpj(?string $cnpj): static
    {
        $this->cnpj = $cnpj;
        return $this;
    }

    public function getLogoUrl(): ?string
    {
        return $this->logoUrl;
    }

    public function setLogoUrl(?string $logoUrl): static
    {
        $this->logoUrl = $logoUrl;
        return $this;
    }

    public function getPrimaryColor(): ?string
    {
        return $this->primaryColor;
    }

    public function setPrimaryColor(?string $primaryColor): static
    {
        $this->primaryColor = $primaryColor;
        return $this;
    }

    public function getSecondaryColor(): ?string
    {
        return $this->secondaryColor;
    }

    public function setSecondaryColor(?string $secondaryColor): static
    {
        $this->secondaryColor = $secondaryColor;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(?\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
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

    public function getAlerts(): Collection
    {
        return $this->alerts;
    }

    public function getTrafficJams(): Collection
    {
        return $this->trafficJams;
    }

    public function getCemadenData(): Collection
    {
        return $this->cemadenData;
    }

    public function getTvtSnapshots(): Collection
    {
        return $this->tvtSnapshots;
    }

    public function getIrregularities(): Collection
    {
        return $this->irregularities;
    }

    public function getWazeCounts(): Collection
    {
        return $this->wazeCounts;
    }

    public function getCifsEvents(): Collection
    {
        return $this->cifsEvents;
    }

    public function __toString(): string
    {
        return (string) $this->name;
    }
}
