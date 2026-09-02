<?php

namespace App\Entity;

use App\Repository\PartnerRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\Table;

#[Entity(repositoryClass: PartnerRepository::class)]
#[Table(name: 'partners')]
class Partner
{
    #[Id]
    #[GeneratedValue]
    #[Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[Column(type: Types::STRING, length: 255)]
    private ?string $name = null;

    #[OneToMany(targetEntity: User::class, mappedBy: 'partner')]
    private Collection $users;

    #[OneToMany(targetEntity: WazeTvtRoute::class, mappedBy: 'partner')]
    private Collection $wazeTvtRoutes;

    public function __construct()
    {
        $this->users = new ArrayCollection();
        $this->wazeTvtRoutes = new ArrayCollection();
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

    public function getUsers(): Collection
    {
        return $this->users;
    }

    public function getWazeTvtRoutes(): Collection
    {
        return $this->wazeTvtRoutes;
    }
}
