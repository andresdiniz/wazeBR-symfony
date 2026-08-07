<?php

namespace App\Entity;

use App\Repository\PartnerRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: PartnerRepository::class)]
#[ORM\Table(name: 'partners')]
class Partner
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 80, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 80)]
    private ?string $slug = null;

    #[ORM\Column(type: 'string', length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private ?string $name = null;

    #[ORM\Column(type: 'string', length: 180, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Email]
    private ?string $email = null;

    #[ORM\Column(type: 'string', length: 64, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 64, max: 64)]
    private ?string $apiToken = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $bbox = null;

    #[ORM\Column(type: 'text', nullable: false)]
    private string $cemadenStates = '[]';

    #[ORM\Column(type: 'boolean')]
    private bool $isActive = true;

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $refreshIntervalMinutes = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;
        return $this;
    }

    public function getApiToken(): ?string
    {
        return $this->apiToken;
    }

    public function setApiToken(?string $apiToken): static
    {
        $this->apiToken = $apiToken;
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

    public function getCemadenStates(): string
    {
        return $this->cemadenStates;
    }

    public function setCemadenStates(string $cemadenStates): static
    {
        $this->cemadenStates = $cemadenStates;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;
        return $this;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getRefreshIntervalMinutes(): ?int
    {
        return $this->refreshIntervalMinutes;
    }

    public function setRefreshIntervalMinutes(?int $refreshIntervalMinutes): static
    {
        $this->refreshIntervalMinutes = $refreshIntervalMinutes;
        return $this;
    }
}
