<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CemadenStationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CemadenStationRepository::class)]
#[ORM\Table(name: 'cemaden_stations')]
#[ORM\UniqueConstraint(name: 'uniq_cod_partner', columns: ['cod_estacao', 'partner_id'])]
#[ORM\HasLifecycleCallbacks]
class CemadenStation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 30)]
    private string $codEstacao;

    #[ORM\Column(length: 120)]
    private string $nome;

    #[ORM\Column(length: 120)]
    private string $municipio;

    #[ORM\Column(length: 2)]
    private string $uf;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 7, nullable: true)]
    private ?string $lat = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 7, nullable: true)]
    private ?string $lng = null;

    #[ORM\Column(type: 'string', length: 20, enumType: StationType::class)]
    private StationType $stationType;

    #[ORM\Column(type: 'boolean')]
    private bool $isActive = true;

    #[ORM\ManyToOne(targetEntity: Partner::class)]
    #[ORM\JoinColumn(name: 'partner_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Partner $partner;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $hydroUrl = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    // Getters & Setters

    public function getId(): ?int { return $this->id; }

    public function getCodEstacao(): string { return $this->codEstacao; }
    public function setCodEstacao(string $codEstacao): static { $this->codEstacao = $codEstacao; return $this; }

    public function getNome(): string { return $this->nome; }
    public function setNome(string $nome): static { $this->nome = $nome; return $this; }

    public function getMunicipio(): string { return $this->municipio; }
    public function setMunicipio(string $municipio): static { $this->municipio = $municipio; return $this; }

    public function getUf(): string { return $this->uf; }
    public function setUf(string $uf): static { $this->uf = $uf; return $this; }

    public function getLat(): ?float { return $this->lat !== null ? (float) $this->lat : null; }
    public function setLat(?float $lat): static { $this->lat = $lat !== null ? (string) $lat : null; return $this; }

    public function getLng(): ?float { return $this->lng !== null ? (float) $this->lng : null; }
    public function setLng(?float $lng): static { $this->lng = $lng !== null ? (string) $lng : null; return $this; }

    public function getStationType(): StationType { return $this->stationType; }
    public function setStationType(StationType $stationType): static { $this->stationType = $stationType; return $this; }

    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $isActive): static { $this->isActive = $isActive; return $this; }

    public function getPartner(): Partner { return $this->partner; }
    public function setPartner(Partner $partner): static { $this->partner = $partner; return $this; }

    public function getHydroUrl(): ?string { return $this->hydroUrl; }
    public function setHydroUrl(?string $hydroUrl): static { $this->hydroUrl = $hydroUrl; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
}
