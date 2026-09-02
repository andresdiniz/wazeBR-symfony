<?php

namespace App\Entity;

use App\Repository\WazeTvtRouteExecutionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\JoinColumn;

#[Entity(repositoryClass: WazeTvtRouteExecutionRepository::class)]
class WazeTvtRouteExecution
{
    #[Id]
    #[GeneratedValue]
    #[Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ManyToOne(targetEntity: WazeTvtRoute::class, inversedBy: 'executions')]
    #[JoinColumn(name: 'tvt_route_id', referencedColumnName: 'id', nullable: true)]
    private ?WazeTvtRoute $tvtRoute = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTvtRoute(): ?WazeTvtRoute
    {
        return $this->tvtRoute;
    }

    public function setTvtRoute(?WazeTvtRoute $tvtRoute): static
    {
        $this->tvtRoute = $tvtRoute;
        return $this;
    }
}
