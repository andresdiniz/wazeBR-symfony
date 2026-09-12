<?php

namespace App\Entity;

use App\Repository\CemadenHidroStationLinkRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: CemadenHidroStationLinkRepository::class)]
#[ORM\Table(name: 'cemaden_hidro_station_link')]
#[ORM\UniqueConstraint(name: 'uniq_cemaden_hidro_link_partner_transaction', columns: ['partner_id', 'cemaden_transaction_id'])]
#[ORM\Index(columns: ['partner_id', 'active'])]
class CemadenHidroStationLink
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    private ?Partner $partner = null;

    #[ORM\Column(name: 'cemaden_transaction_id', type: Types::INTEGER)]
    #[Assert\Positive]
    private ?int $cemadenTransactionId = null;

    #[ORM\Column(name: 'station_code', length: 100, nullable: true)]
    private ?string $stationCode = null;

    #[ORM\Column(name: 'station_name', length: 255, nullable: true)]
    private ?string $stationName = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(length: 2, nullable: true)]
    #[Assert\Length(exactly: 2)]
    private ?string $state = null;

    #[ORM\Column(name: 'base_url', type: Types::TEXT)]
    #[Assert\Url]
    private string $baseUrl = 'https://resources.cemaden.gov.br/graficos/cemaden/hidro/resources/json/AcumuladoResource.php';

    #[ORM\Column(name: 'records_to_fetch', type: Types::SMALLINT, options: ['default' => 24])]
    #[Assert\Range(min: 1, max: 720)]
    private int $recordsToFetch = 24;

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
    public function getCemadenTransactionId(): ?int { return $this->cemadenTransactionId; }
    public function setCemadenTransactionId(int $cemadenTransactionId): static { $this->cemadenTransactionId = $cemadenTransactionId; return $this; }
    public function getStationCode(): ?string { return $this->stationCode; }
    public function setStationCode(?string $stationCode): static { $this->stationCode = $stationCode; return $this; }
    public function getStationName(): ?string { return $this->stationName; }
    public function setStationName(?string $stationName): static { $this->stationName = $stationName; return $this; }
    public function getCity(): ?string { return $this->city; }
    public function setCity(?string $city): static { $this->city = $city; return $this; }
    public function getState(): ?string { return $this->state; }
    public function setState(?string $state): static { $this->state = $state; return $this; }
    public function getBaseUrl(): string { return $this->baseUrl; }
    public function setBaseUrl(string $baseUrl): static { $this->baseUrl = $baseUrl; return $this; }
    public function getRecordsToFetch(): int { return $this->recordsToFetch; }
    public function setRecordsToFetch(int $recordsToFetch): static { $this->recordsToFetch = $recordsToFetch; return $this; }
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
        return sprintf('%s?est=%d&pag=%d', $this->baseUrl, $this->cemadenTransactionId, $this->recordsToFetch);
    }
}
