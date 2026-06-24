<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\TenantAwareTrait;
use App\Repository\MonitoredCityRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MonitoredCityRepository::class)]
#[ORM\Table(name: 'monitored_cities')]
#[ORM\UniqueConstraint(columns: ['partner_id', 'city', 'state'])]
class MonitoredCity
{
    use TenantAwareTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 80)]
    private string $city = '';

    #[ORM\Column(length: 2)]
    private string $state = '';

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $country = 'BR';

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct() { $this->createdAt = new \DateTimeImmutable(); }

    public function getId(): ?int { return $this->id; }
    public function getCity(): string { return $this->city; }
    public function setCity(string $c): static { $this->city = $c; return $this; }
    public function getState(): string { return $this->state; }
    public function setState(string $s): static { $this->state = $s; return $this; }
    public function getCountry(): ?string { return $this->country; }
    public function setCountry(?string $c): static { $this->country = $c; return $this; }
    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $v): static { $this->isActive = $v; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
