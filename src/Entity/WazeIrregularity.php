<?php

namespace App\Entity;

use App\Repository\WazeIrregularityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Irregularidade de tráfego reportada pelo feed TVT do Waze.
 *
 * Equivale a irregularities[] do JSON:
 * {
 *   "id": "...",
 *   "name": "...",
 *   "fromName": "...",
 *   "toName": "...",
 *   "length": 100,
 *   "time": 60,
 *   "historicTime": 45,
 *   "jamLevel": 1,
 *   "bbox": {"minX":..., "minY":..., "maxX":..., "maxY":...},
 *   "line": [{"x":..., "y":...}, ...],
 *   "leadAlert": {"id":"...", "type":"...", ...}
 * }
 */
#[ORM\Entity(repositoryClass: WazeIrregularityRepository::class)]
#[ORM\Table(name: 'waze_irregularities')]
#[ORM\UniqueConstraint(name: 'uq_irr_waze_link', columns: ['waze_id', 'source_link_id'])]
class WazeIrregularity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** ID string vindo do Waze */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $wazeId = null;

    #[ORM\ManyToOne(targetEntity: Partner::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Partner $partner = null;

    #[ORM\ManyToOne(targetEntity: MonitoredLink::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?MonitoredLink $sourceLink = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $fromName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $toName = null;

    /** Comprimento em metros */
    #[ORM\Column(nullable: true)]
    private ?int $length = null;

    /** Tempo atual em segundos */
    #[ORM\Column(nullable: true)]
    private ?int $time = null;

    /** Tempo histórico em segundos */
    #[ORM\Column(nullable: true)]
    private ?int $historicTime = null;

    /** 0 = livre, 4 = parado */
    #[ORM\Column(nullable: true)]
    private ?int $jamLevel = null;

    /** Velocidade média calculada (km/h) */
    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $avgSpeed = null;

    /** Velocidade histórica calculada (km/h) */
    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $historicSpeed = null;

    /** Bounding box {minX, minY, maxX, maxY} */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $bbox = null;

    /** Geometria [{x, y}] */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $line = null;

    /** Ativa no último feed? */
    #[ORM\Column(nullable: true)]
    private ?bool $isActive = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $collectedAt = null;

    // ── Lead Alert ──────────────────────────────────────────────────────
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $leadAlertId = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $leadAlertType = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $leadAlertSubType = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $leadAlertPosition = null;

    #[ORM\Column(nullable: true)]
    private ?int $leadAlertNumComments = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $leadAlertCity = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $leadAlertExternalImageId = null;

    #[ORM\Column(nullable: true)]
    private ?int $leadAlertNumThumbsUp = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $leadAlertStreet = null;

    #[ORM\Column(nullable: true)]
    private ?int $leadAlertNumNotThereReports = null;

    // ── Getters / Setters ────────────────────────────────────────────────

    public function getId(): ?int { return $this->id; }

    public function getWazeId(): ?string { return $this->wazeId; }
    public function setWazeId(?string $v): static { $this->wazeId = $v; return $this; }

    public function getPartner(): ?Partner { return $this->partner; }
    public function setPartner(?Partner $v): static { $this->partner = $v; return $this; }

    public function getSourceLink(): ?MonitoredLink { return $this->sourceLink; }
    public function setSourceLink(?MonitoredLink $v): static { $this->sourceLink = $v; return $this; }

    public function getName(): ?string { return $this->name; }
    public function setName(?string $v): static { $this->name = $v; return $this; }

    public function getFromName(): ?string { return $this->fromName; }
    public function setFromName(?string $v): static { $this->fromName = $v; return $this; }

    public function getToName(): ?string { return $this->toName; }
    public function setToName(?string $v): static { $this->toName = $v; return $this; }

    public function getLength(): ?int { return $this->length; }
    public function setLength(?int $v): static { $this->length = $v; return $this; }

    public function getTime(): ?int { return $this->time; }
    public function setTime(?int $v): static { $this->time = $v; return $this; }

    public function getHistoricTime(): ?int { return $this->historicTime; }
    public function setHistoricTime(?int $v): static { $this->historicTime = $v; return $this; }

    public function getJamLevel(): ?int { return $this->jamLevel; }
    public function setJamLevel(?int $v): static { $this->jamLevel = $v; return $this; }

    public function getAvgSpeed(): ?float { return $this->avgSpeed; }
    public function setAvgSpeed(?float $v): static { $this->avgSpeed = $v; return $this; }

    public function getHistoricSpeed(): ?float { return $this->historicSpeed; }
    public function setHistoricSpeed(?float $v): static { $this->historicSpeed = $v; return $this; }

    public function getBbox(): ?array { return $this->bbox; }
    public function setBbox(?array $v): static { $this->bbox = $v; return $this; }

    public function getLine(): ?array { return $this->line; }
    public function setLine(?array $v): static { $this->line = $v; return $this; }

    public function isActive(): ?bool { return $this->isActive; }
    public function setIsActive(?bool $v): static { $this->isActive = $v; return $this; }

    public function getCollectedAt(): ?\DateTimeImmutable { return $this->collectedAt; }
    public function setCollectedAt(?\DateTimeImmutable $v): static { $this->collectedAt = $v; return $this; }

    public function getLeadAlertId(): ?string { return $this->leadAlertId; }
    public function setLeadAlertId(?string $v): static { $this->leadAlertId = $v; return $this; }

    public function getLeadAlertType(): ?string { return $this->leadAlertType; }
    public function setLeadAlertType(?string $v): static { $this->leadAlertType = $v; return $this; }

    public function getLeadAlertSubType(): ?string { return $this->leadAlertSubType; }
    public function setLeadAlertSubType(?string $v): static { $this->leadAlertSubType = $v; return $this; }

    public function getLeadAlertPosition(): ?array { return $this->leadAlertPosition; }
    public function setLeadAlertPosition(?array $v): static { $this->leadAlertPosition = $v; return $this; }

    public function getLeadAlertNumComments(): ?int { return $this->leadAlertNumComments; }
    public function setLeadAlertNumComments(?int $v): static { $this->leadAlertNumComments = $v; return $this; }

    public function getLeadAlertCity(): ?string { return $this->leadAlertCity; }
    public function setLeadAlertCity(?string $v): static { $this->leadAlertCity = $v; return $this; }

    public function getLeadAlertExternalImageId(): ?string { return $this->leadAlertExternalImageId; }
    public function setLeadAlertExternalImageId(?string $v): static { $this->leadAlertExternalImageId = $v; return $this; }

    public function getLeadAlertNumThumbsUp(): ?int { return $this->leadAlertNumThumbsUp; }
    public function setLeadAlertNumThumbsUp(?int $v): static { $this->leadAlertNumThumbsUp = $v; return $this; }

    public function getLeadAlertStreet(): ?string { return $this->leadAlertStreet; }
    public function setLeadAlertStreet(?string $v): static { $this->leadAlertStreet = $v; return $this; }

    public function getLeadAlertNumNotThereReports(): ?int { return $this->leadAlertNumNotThereReports; }
    public function setLeadAlertNumNotThereReports(?int $v): static { $this->leadAlertNumNotThereReports = $v; return $this; }
}
