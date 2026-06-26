<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MonitoredCityRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MonitoredCityRepository::class)]
#[ORM\Table(name: 'monitored_cities')]
class MonitoredCity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Partner::class, inversedBy: 'cities')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Partner $partner;

    #[ORM\Column(length: 80)]
    private string $name = '';

    #[ORM\Column(length: 2)]
    private string $state = '';

    #[ORM\Column]
    private bool $isActive = true;

    public function getId(): ?int { return $this->id; }
    public function getPartner(): Partner { return $this->partner; }
    public function setPartner(Partner $p): static { $this->partner = $p; return $this; }
    public function getName(): string { return $this->name; }
    public function setName(string $n): static { $this->name = $n; return $this; }
    public function getState(): string { return $this->state; }
    public function setState(string $s): static { $this->state = $s; return $this; }
    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $v): static { $this->isActive = $v; return $this; }
}
