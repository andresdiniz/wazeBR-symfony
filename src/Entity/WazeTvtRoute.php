<?php

namespace App\Entity;

use App\Repository\WazeTvtRouteRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\OneToMany;

#[Entity(repositoryClass: WazeTvtRouteRepository::class)]
class WazeTvtRoute
{
    #[Id]
    #[GeneratedValue]
    #[Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ManyToOne(targetEntity: Partner::class, inversedBy: 'wazeTvtRoutes')]
    #[JoinColumn(name: 'partner_id', referencedColumnName: 'id', nullable: false)]
    private ?Partner $partner = null;

    #[OneToMany(targetEntity: WazeTvtRouteExecution::class, mappedBy: 'tvtRoute')]
    private Collection $executions;

    public function __construct()
    {
        $this->executions = new ArrayCollection();
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

    public function getExecutions(): Collection
    {
        return $this->executions;
    }
}
