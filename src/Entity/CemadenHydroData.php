<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CemadenHydroDataRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Leitura hidrológica de uma estação CEMADEN (station_type = hydrological).
 *
 * Campos vindos da API:
 *   https://resources.cemaden.gov.br/graficos/cemaden/hidro/resources/json/MedidaResource.php?est=XXX&sen=20&pag=24
 *
 * JSON de exemplo:
 *   {"codigo":"311830410H","estacao":"Rio Bananeiras","cidade":"CONSELHEIRO LAFAIETE",
 *    "uf":"MG","datahora":"2026-07-04 22:00:00","valor":"7.47",
 *    "qualificacao":"0000","offset":"7.714",
 *    "cota_atencao":"2.55","cota_alerta":"3.40","cota_transbordamento":"4.25"}
 *
 * Índice único: (station_code, measured_at) — evita duplicatas em re-runs.
 */
#[ORM\Entity(repositoryClass: CemadenHydroDataRepository::class)]
#[ORM\Table(name: 'cemaden_hydro_data')]
#[ORM\UniqueConstraint(name: 'uniq_hydro_station_time', columns: ['station_code', 'measured_at'])]
#[ORM\HasLifecycleCallbacks]
class CemadenHydroData
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Código da estação (ex: "311830410H") */
    #[ORM\Column(length: 30)]
    private string $stationCode;

    /** Nome da estação (ex: "Rio Bananeiras") */
    #[ORM\Column(length: 120)]
    private string $stationName;

    /** Município (ex: "CONSELHEIRO LAFAIETE") */
    #[ORM\Column(length: 120)]
    private string $municipality;

    /** UF (ex: "MG") */
    #[ORM\Column(length: 2)]
    private string $state;

    /**
     * Nível do rio em metros (campo "valor").
     * DECIMAL(8,3) armazenado como string pelo Doctrine DBAL.
     */
    #[ORM\Column(type: 'decimal', precision: 8, scale: 3, nullable: true)]
    private ?string $waterLevel = null;

    /**
     * Offset de calibração (campo "offset").
     * DECIMAL(8,3).
     */
    #[ORM\Column(type: 'decimal', precision: 8, scale: 3, nullable: true)]
    private ?string $offsetValue = null;

    /**
     * Qualificação da medida (campo "qualificacao", ex: "0000").
     */
    #[ORM\Column(length: 10, nullable: true)]
    private ?string $qualificacao = null;

    /**
     * Cota de atenção em metros (campo "cota_atencao").
     * DECIMAL(6,2).
     */
    #[ORM\Column(type: 'decimal', precision: 6, scale: 2, nullable: true)]
    private ?string $cotaAtencao = null;

    /**
     * Cota de alerta em metros (campo "cota_alerta").
     * DECIMAL(6,2).
     */
    #[ORM\Column(type: 'decimal', precision: 6, scale: 2, nullable: true)]
    private ?string $cotaAlerta = null;

    /**
     * Cota de transbordamento em metros (campo "cota_transbordamento").
     * DECIMAL(6,2).
     */
    #[ORM\Column(type: 'decimal', precision: 6, scale: 2, nullable: true)]
    private ?string $cotaTransbordamento = null;

    /** Nível de alerta calculado: normal | atencao | alerta | transbordamento */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $alertLevel = null;

    /** Parceiro associado à estação (herdado de cemaden_stations.partner_slug) */
    #[ORM\ManyToOne(targetEntity: Partner::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Partner $partner = null;

    /** Data/hora da medição (campo "datahora") */
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $measuredAt;

    /** Data/hora de inserção no banco */
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    // ──────────────────────────────────────────────
    // Getters & Setters
    // ──────────────────────────────────────────────

    public function getId(): ?int { return $this->id; }

    public function getStationCode(): string { return $this->stationCode; }
    public function setStationCode(string $v): static { $this->stationCode = $v; return $this; }

    public function getStationName(): string { return $this->stationName; }
    public function setStationName(string $v): static { $this->stationName = $v; return $this; }

    public function getMunicipality(): string { return $this->municipality; }
    public function setMunicipality(string $v): static { $this->municipality = $v; return $this; }

    public function getState(): string { return $this->state; }
    public function setState(string $v): static { $this->state = $v; return $this; }

    public function getWaterLevel(): ?float
    {
        return $this->waterLevel !== null ? (float) $this->waterLevel : null;
    }
    public function setWaterLevel(float|string|null $v): static
    {
        $this->waterLevel = $v !== null ? (string) $v : null;
        return $this;
    }

    public function getOffsetValue(): ?float
    {
        return $this->offsetValue !== null ? (float) $this->offsetValue : null;
    }
    public function setOffsetValue(float|string|null $v): static
    {
        $this->offsetValue = $v !== null ? (string) $v : null;
        return $this;
    }

    public function getQualificacao(): ?string { return $this->qualificacao; }
    public function setQualificacao(?string $v): static { $this->qualificacao = $v; return $this; }

    public function getCotaAtencao(): ?float
    {
        return $this->cotaAtencao !== null ? (float) $this->cotaAtencao : null;
    }
    public function setCotaAtencao(float|string|null $v): static
    {
        $this->cotaAtencao = $v !== null ? (string) $v : null;
        return $this;
    }

    public function getCotaAlerta(): ?float
    {
        return $this->cotaAlerta !== null ? (float) $this->cotaAlerta : null;
    }
    public function setCotaAlerta(float|string|null $v): static
    {
        $this->cotaAlerta = $v !== null ? (string) $v : null;
        return $this;
    }

    public function getCotaTransbordamento(): ?float
    {
        return $this->cotaTransbordamento !== null ? (float) $this->cotaTransbordamento : null;
    }
    public function setCotaTransbordamento(float|string|null $v): static
    {
        $this->cotaTransbordamento = $v !== null ? (string) $v : null;
        return $this;
    }

    public function getAlertLevel(): ?string { return $this->alertLevel; }
    public function setAlertLevel(?string $v): static { $this->alertLevel = $v; return $this; }

    public function getPartner(): ?Partner { return $this->partner; }
    public function setPartner(?Partner $v): static { $this->partner = $v; return $this; }

    public function getMeasuredAt(): \DateTimeImmutable { return $this->measuredAt; }
    public function setMeasuredAt(\DateTimeImmutable $v): static { $this->measuredAt = $v; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
