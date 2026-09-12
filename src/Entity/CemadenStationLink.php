<?php

namespace App\Entity;

use App\Repository\CemadenStationLinkRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: CemadenStationLinkRepository::class)]
#[ORM\Table(name: 'cemaden_station_link')]
#[ORM\UniqueConstraint(name: 'uniq_cemaden_station_link_partner_station', columns: ['partner_id', 'cemaden_station_id'])]
#[ORM\Index(columns: ['partner_id', 'active'])]
class CemadenStationLink
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    private ?Partner $partner = null;

    #[ORM\Column(name: 'cemaden_station_id', type: Types::INTEGER)]
    #[Assert\Positive]
    private ?int $cemadenStationId = null;

    #[ORM\Column(name: 'station_code', length: 100, nullable: true)]
    private ?string $stationCode = null;

    #[ORM\Column(name: 'station_name', length: 255)]
    #[Assert\NotBlank]
    private ?string $stationName = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 7)]
    #[Assert\Range(min: -90, max: 90)]
    private ?string $latitude = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 7)]
    #[Assert\Range(min: -180, max: 180)]
    private ?string $longitude = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $status = null;

    #[ORM\Column(name: 'station_type', length: 100, nullable: true)]
    private ?string $stationType = null;

    #[ORM\Column(name: 'municipality_id', type: Types::INTEGER, nullable: true)]
    private ?int $municipalityId = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(length: 2, nullable: true)]
    #[Assert\Length(exactly: 2)]
    private ?string $state = null;

    #[ORM\Column(name: 'ibge_code', length: 20, nullable: true)]
    private ?string $ibgeCode = null;

    #[ORM\Column(name: 'network_id', type: Types::INTEGER, nullable: true)]
    private ?int $networkId = null;

    #[ORM\Column(name: 'network_name', length: 255, nullable: true)]
    private ?string $networkName = null;

    #[ORM\Column(name: 'network_acronym', length: 50, nullable: true)]
    private ?string $networkAcronym = null;

    #[ORM\Column(name: 'base_url', type: Types::TEXT)]
    #[Assert\Url]
    private string $baseUrl = 'https://mapservices.cemaden.gov.br/MapaInterativoWS/resources/horario';

    #[ORM\Column(name: 'hours_to_fetch', type: Types::SMALLINT, options: ['default' => 24])]
    #[Assert\Range(min: 1, max: 168)]
    private int $hoursToFetch = 24;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column(name: 'last_fetched_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastFetchedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int { return $this->id; }
    public function getPartner(): ?Partner { return $this->partner; }
    public function setPartner(?Partner $partner): static { $this->partner = $partner; return $this; }
    public function getCemadenStationId(): ?int { return $this->cemadenStationId; }
    public function setCemadenStationId(int $cemadenStationId): static { $this->cemadenStationId = $cemadenStationId; return $this; }
    public function getStationCode(): ?string { return $this->stationCode; }
    public function setStationCode(?string $stationCode): static { $this->stationCode = $stationCode; return $this; }
    public function getStationName(): ?string { return $this->stationName; }
    public function setStationName(string $stationName): static { $this->stationName = $stationName; return $this; }
    public function getLatitude(): ?string { return $this->latitude; }
    public function setLatitude(string $latitude): static { $this->latitude = $latitude; return $this; }
    public function getLongitude(): ?string { return $this->longitude; }
    public function setLongitude(string $longitude): static { $this->longitude = $longitude; return $this; }
    public function getStatus(): ?string { return $this->status; }
    public function setStatus(?string $status): static { $this->status = $status; return $this; }
    public function getStationType(): ?string { return $this->stationType; }
    public function setStationType(?string $stationType): static { $this->stationType = $stationType; return $this; }
    public function getMunicipalityId(): ?int { return $this->municipalityId; }
    public function setMunicipalityId(?int $municipalityId): static { $this->municipalityId = $municipalityId; return $this; }
    public function getCity(): ?string { return $this->city; }
    public function setCity(?string $city): static { $this->city = $city; return $this; }
    public function getState(): ?string { return $this->state; }
    public function setState(?string $state): static { $this->state = $state; return $this; }
    public function getIbgeCode(): ?string { return $this->ibgeCode; }
    public function setIbgeCode(?string $ibgeCode): static { $this->ibgeCode = $ibgeCode; return $this; }
    public function getNetworkId(): ?int { return $this->networkId; }
    public function setNetworkId(?int $networkId): static { $this->networkId = $networkId; return $this; }
    public function getNetworkName(): ?string { return $this->networkName; }
    public function setNetworkName(?string $networkName): static { $this->networkName = $networkName; return $this; }
    public function getNetworkAcronym(): ?string { return $this->networkAcronym; }
    public function setNetworkAcronym(?string $networkAcronym): static { $this->networkAcronym = $networkAcronym; return $this; }
    public function getBaseUrl(): string { return $this->baseUrl; }
    public function setBaseUrl(string $baseUrl): static { $this->baseUrl = rtrim($baseUrl, '/'); return $this; }
    public function getHoursToFetch(): int { return $this->hoursToFetch; }
    public function setHoursToFetch(int $hoursToFetch): static { $this->hoursToFetch = $hoursToFetch; return $this; }
    public function isActive(): bool { return $this->active; }
    public function setActive(bool $active): static { $this->active = $active; return $this; }
    public function getLastFetchedAt(): ?\DateTimeImmutable { return $this->lastFetchedAt; }
    public function setLastFetchedAt(?\DateTimeImmutable $lastFetchedAt): static { $this->lastFetchedAt = $lastFetchedAt; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $createdAt): static { $this->createdAt = $createdAt; return $this; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static { $this->updatedAt = $updatedAt; return $this; }

    public function getRequestUrl(): string
    {
        return sprintf('%s/%d/%d', $this->baseUrl, $this->cemadenStationId, $this->hoursToFetch);
    }
}
