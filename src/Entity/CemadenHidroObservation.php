<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CemadenHidroObservationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CemadenHidroObservationRepository::class)]
#[ORM\Table(name: 'cemaden_hidro_observation')]
#[ORM\UniqueConstraint(
    name: 'uniq_cemaden_hidro_observation',
    columns: ['cemaden_hidro_station_link_id', 'observed_at', 'observation_type'],
)]
#[ORM\Index(columns: ['partner_id', 'observed_at'])]
#[ORM\Index(columns: ['cemaden_hidro_station_link_id', 'observed_at'])]
class CemadenHidroObservation
{
    public const TYPE_LEVEL = 'level';
    public const TYPE_RAIN  = 'rain';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Partner $partner = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?CemadenHidroStationLink $cemadenHidroStationLink = null;

    /** level | rain */
    #[ORM\Column(name: 'observation_type', length: 10)]
    private string $observationType = self::TYPE_LEVEL;

    #[ORM\Column(name: 'station_code', length: 50, nullable: true)]
    private ?string $stationCode = null;

    #[ORM\Column(name: 'station_name', length: 255, nullable: true)]
    private ?string $stationName = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(length: 2, nullable: true)]
    private ?string $state = null;

    /** Offset do sensor (distância sensor→leito), em metros. */
    #[ORM\Column(name: 'offset_value', type: Types::FLOAT, nullable: true)]
    private ?float $offset = null;

    /** Leitura bruta do sensor (nível do rio). */
    #[ORM\Column(name: 'raw_value', type: Types::FLOAT, nullable: true)]
    private ?float $rawValue = null;

    /** Nível do rio calculado (offset − valor), em metros. */
    #[ORM\Column(name: 'water_level', type: Types::FLOAT, nullable: true)]
    private ?float $waterLevel = null;

    #[ORM\Column(name: 'cota_atencao', type: Types::FLOAT, nullable: true)]
    private ?float $cotaAtencao = null;

    #[ORM\Column(name: 'cota_alerta', type: Types::FLOAT, nullable: true)]
    private ?float $cotaAlerta = null;

    #[ORM\Column(name: 'cota_transbordamento', type: Types::FLOAT, nullable: true)]
    private ?float $cotaTransbordamento = null;

    /** Chuva acumulada no período (mm). */
    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $rain = null;

    /** Vazão (m³/s) — raramente preenchido. */
    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $flow = null;

    #[ORM\Column(name: 'observed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $observedAt = null;

    #[ORM\Column(name: 'source_payload', type: Types::JSON, nullable: true)]
    private ?array $sourcePayload = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int { return $this->id; }

    public function getPartner(): ?Partner { return $this->partner; }
    public function setPartner(?Partner $partner): static { $this->partner = $partner; return $this; }

    public function getCemadenHidroStationLink(): ?CemadenHidroStationLink { return $this->cemadenHidroStationLink; }
    public function setCemadenHidroStationLink(?CemadenHidroStationLink $link): static { $this->cemadenHidroStationLink = $link; return $this; }

    public function getObservationType(): string { return $this->observationType; }
    public function setObservationType(string $type): static { $this->observationType = $type; return $this; }

    public function getStationCode(): ?string { return $this->stationCode; }
    public function setStationCode(?string $stationCode): static { $this->stationCode = $stationCode; return $this; }

    public function getStationName(): ?string { return $this->stationName; }
    public function setStationName(?string $stationName): static { $this->stationName = $stationName; return $this; }

    public function getCity(): ?string { return $this->city; }
    public function setCity(?string $city): static { $this->city = $city; return $this; }

    public function getState(): ?string { return $this->state; }
    public function setState(?string $state): static { $this->state = $state; return $this; }

    public function getOffset(): ?float { return $this->offset; }
    public function setOffset(?float $offset): static { $this->offset = $offset; return $this; }

    public function getRawValue(): ?float { return $this->rawValue; }
    public function setRawValue(?float $rawValue): static { $this->rawValue = $rawValue; return $this; }

    public function getWaterLevel(): ?float { return $this->waterLevel; }
    public function setWaterLevel(?float $waterLevel): static { $this->waterLevel = $waterLevel; return $this; }

    public function getRiverLevel(): ?float { return $this->waterLevel; }
    public function setRiverLevel(?float $value): static { return $this->setWaterLevel($value); }

    public function getCotaAtencao(): ?float { return $this->cotaAtencao; }
    public function setCotaAtencao(?float $v): static { $this->cotaAtencao = $v; return $this; }

    public function getCotaAlerta(): ?float { return $this->cotaAlerta; }
    public function setCotaAlerta(?float $v): static { $this->cotaAlerta = $v; return $this; }

    public function getCotaTransbordamento(): ?float { return $this->cotaTransbordamento; }
    public function setCotaTransbordamento(?float $v): static { $this->cotaTransbordamento = $v; return $this; }

    public function getRain(): ?float { return $this->rain; }
    public function setRain(?float $rain): static { $this->rain = $rain; return $this; }

    public function getFlow(): ?float { return $this->flow; }
    public function setFlow(?float $flow): static { $this->flow = $flow; return $this; }

    public function getObservedAt(): ?\DateTimeImmutable { return $this->observedAt; }
    public function setObservedAt(?\DateTimeImmutable $observedAt): static { $this->observedAt = $observedAt; return $this; }

    public function getSourcePayload(): ?array { return $this->sourcePayload; }
    public function setSourcePayload(?array $sourcePayload): static { $this->sourcePayload = $sourcePayload; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $createdAt): static { $this->createdAt = $createdAt; return $this; }

    public function getRiskLevel(): string
    {
        $w = $this->waterLevel;
        if ($w === null) return 'unknown';
        if ($this->cotaTransbordamento !== null && $w >= $this->cotaTransbordamento) return 'overflow';
        if ($this->cotaAlerta !== null && $w >= $this->cotaAlerta) return 'alert';
        if ($this->cotaAtencao !== null && $w >= $this->cotaAtencao) return 'attention';
        return 'normal';
    }
}
