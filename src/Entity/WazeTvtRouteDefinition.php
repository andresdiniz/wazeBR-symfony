<?php

namespace App\Entity;

use App\Repository\WazeTvtRouteDefinitionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WazeTvtRouteDefinitionRepository::class)]
class WazeTvtRouteDefinition
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: WazeTvtRoute::class, inversedBy: 'definitions')]
    #[ORM\JoinColumn(name: 'waze_tvt_route_id', referencedColumnName: 'id', nullable: false)]
    private ?WazeTvtRoute $wazeTvtRoute = null;

    public function getId(): ?int { return $this->id; }
    public function getWazeTvtRoute(): ?WazeTvtRoute { return $this->wazeTvtRoute; }
    public function setWazeTvtRoute(?WazeTvtRoute $wazeTvtRoute): static { $this->wazeTvtRoute = $wazeTvtRoute; return $this; }
}
