<?php

namespace App\Entity;

use App\Repository\WazeTvtRouteDefinitionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: WazeTvtRouteDefinitionRepository::class)]
#[ORM\Table(name: 'waze_tvt_route_definition')]
#[UniqueEntity(fields: ['routeId'], message: 'This route ID already exists.')]
class WazeTvtRouteDefinition
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private ?string $routeId = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $name = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $bbox = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $line = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\OneToMany(mappedBy: 'routeDefinition', targetEntity: WazeTvtRouteExecution::class, cascade: ['remove'])]
    private Collection $executions;

    public function __construct()
    {
        $this->executions = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRouteId(): ?string
    {
        return $this->routeId;
    }

    public function setRouteId(string $routeId): static
    {
        $this->routeId = $routeId;
        return $this;
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

    public function getBbox(): ?string
    {
        return $this->bbox;
    }

    public function setBbox(?string $bbox): static
    {
        $this->bbox = $bbox;
        return $this;
    }

    public function getLine(): ?string
    {
        return $this->line;
    }

    public function setLine(?string $line): static
    {
        $this->line = $line;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function getExecutions(): Collection
    {
        return $this->executions;
    }

    public function addExecution(WazeTvtRouteExecution $execution): static
    {
        if (!$this->executions->contains($execution)) {
            $this->executions->add($execution);
            $execution->setRouteDefinition($this);
        }
        return $this;
    }

    public function removeExecution(WazeTvtRouteExecution $execution): static
    {
        if ($this->executions->removeElement($execution)) {
            if ($execution->getRouteDefinition() === $this) {
                $execution->setRouteDefinition(null);
            }
        }
        return $this;
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
