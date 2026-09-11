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

    #[ORM\OneToMany(mappedBy: 'partner', targetEntity: WazeTvtRoute::class)]
    private Collection $wazeTvtRoutes;

    public function __construct()
    {
        $this->wazeTvtRoutes = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getWazeTvtRoutes(): Collection
    {
        return $this->wazeTvtRoutes;
    }

    public function addWazeTvtRoute(WazeTvtRoute $wazeTvtRoute): static
    {
        if (!$this->wazeTvtRoutes->contains($wazeTvtRoute)) {
            $this->wazeTvtRoutes->add($wazeTvtRoute);
            $wazeTvtRoute->setPartner($this);
        }

        return $this;
    }

    public function removeWazeTvtRoute(WazeTvtRoute $wazeTvtRoute): static
    {
        if ($this->wazeTvtRoutes->removeElement($wazeTvtRoute) && $wazeTvtRoute->getPartner() === $this) {
            $wazeTvtRoute->setPartner(null);
        }

        return $this;
    }
}
