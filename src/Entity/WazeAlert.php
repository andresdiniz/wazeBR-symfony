<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WazeAlertRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Alerta coletado do feed PartnerHub do Waze (format=1).
 *
 * Estrutura do JSON (alerts[]):
 * {
 *   "uuid": "string",
 *   "type": "ACCIDENT|JAM|WEATHERHAZARD|HAZARD|MISC|CONSTRUCTION|ROAD_CLOSED",
 *   "subtype": "string|null",
 *   "street": "string|null",
 *   "city": "string|null",
 *   "country": "string",
 *   "location": {"x": float, "y": float},
 *   "pubMillis": int,
 *   "reliability": int,
 *   "confidence": int,
 *   "reportRating": int,
 *   "nThumbsUp": int,
 *   "nComments": int,
 *   "reportDescription": "string|null",
 *   "magvar": int,
 *   "roadType": int,
 *   "additionalInfo": "string|null",
 *   "comments": [],
 *   "jamUuid": "string|null",
 *   "inscale": bool,
 *   "isJamUnifiedAlert": bool,
 *   "reportByMunicipalityUser": bool
 * }
 */
#[ORM\Entity(repositoryClass: WazeAlertRepository::class)]
#[ORM\Table(name: 'waze_alerts')]
#[ORM\UniqueConstraint(name: 'uq_waze_alert_uuid', columns: ['waze_id'])]
#[ORM\Index(name: 'idx_type_city', columns: ['type', 'city'])]
#[ORM\Index(name: 'idx_pubmillis', columns: ['pub_millis'])]
#[ORM\Index(name: 'idx_partner_pub', columns: ['partner_id', 'pub_millis'])]
#[ORM\HasLifecycleCallbacks]
class WazeAlert
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Partner::class, inversedBy: 'alerts')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Partner $partner = null;

    /** Link de feed de onde este alerta foi coletado */
    #[ORM\ManyToOne(targetEntity: MonitoredLink::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?MonitoredLink $sourceLink = null;

    /** UUID único do Waze — chave de deduplicação (campo "uuid" no JSON) */
    #[ORM\Column(length: 80, unique: true)]
    private string $wazeId = '';

    /** ACCIDENT, JAM, WEATHERHAZARD, HAZARD, MISC, CONSTRUCTION, ROAD_CLOSED */
    #[ORM\Column(length: 60)]
    private string $type = '';

    /** Ex: ACCIDENT_MINOR, HAZARD_ON_ROAD_POT_HOLE, ROAD_CLOSED_CONSTRUCTION */
    #[ORM\Column(length: 60, nullable: true)]
    private ?string $subtype = null;

    /**
     * Latitude — campo "y" do objeto location.
     * Armazenado como DECIMAL(10,7); Doctrine retorna string do DBAL.
     */
    #[ORM\Column(type: 'decimal', precision: 10, scale: 7)]
    private string $latitude = '0.0000000';

    /**
     * Longitude — campo "x" do objeto location.
     * Armazenado como DECIMAL(10,7); Doctrine retorna string do DBAL.
     */
    #[ORM\Column(type: 'decimal', precision: 10, scale: 7)]
    private string $longitude = '0.0000000';

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $street = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $country = null;

    #[ORM\Column(nullable: true)]
    private ?int $reliability = null;

    #[ORM\Column(nullable: true)]
    private ?int $confidence = null;

    #[ORM\Column(nullable: true)]
    private ?int $reportRating = null;

    /** Número de thumbs up recebidos */
    #[ORM\Column(nullable: true)]
    private ?int $nThumbsUp = null;

    /** Número de comentários no alerta */
    #[ORM\Column(nullable: true)]
    private ?int $nComments = null;

    /** Descrição livre do relato */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $reportDescription = null;

    /** Direção magnética do veículo (0-359) */
    #[ORM\Column(nullable: true)]
    private ?int $magvar = null;

    /** Tipo de via (código Waze: 1=Street, 2=Primary, 3=Freeway...) */
    #[ORM\Column(nullable: true)]
    private ?int $roadType = null;

    /** Informações adicionais do feed */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $additionalInfo = null;

    /** Comentários dos usuários (array JSON) */
    #[ORM\Column(type: 'json')]
    private array $comments = [];

    /** UUID do jam associado a este alerta (campo "jamUuid") */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $jamUuid = null;

    /** Alerta visível na escala atual do mapa (campo "inscale") */
    #[ORM\Column(nullable: true)]
    private ?bool $inscale = null;

    /** Alerta unificado de jam (campo "isJamUnifiedAlert") */
    #[ORM\Column(nullable: true)]
    private ?bool $isJamUnifiedAlert = null;

    /** Relato feito por usuário municipal (campo "reportByMunicipalityUser") */
    #[ORM\Column(nullable: true)]
    private ?bool $reportByMunicipalityUser = null;

    /** Timestamp de publicação no Waze (milissegundos) */
    #[ORM\Column(type: 'bigint')]
    private int $pubMillis = 0;

    /** startTimeMillis do feed onde este alerta foi coletado */
    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $feedStartMillis = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\PrePersist]
    public function onPrePersist(): void { $this->createdAt = new \DateTimeImmutable(); }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void { $this->updatedAt = new \DateTimeImmutable(); }

    // ── Getters & Setters ──────────────────────────────────────────────────────

    public function getId(): ?int { return $this->id; }

    public function getPartner(): ?Partner { return $this->partner; }
    public function setPartner(?Partner $p): static { $this->partner = $p; return $this; }

    public function getSourceLink(): ?MonitoredLink { return $this->sourceLink; }
    public function setSourceLink(?MonitoredLink $l): static { $this->sourceLink = $l; return $this; }

    public function getWazeId(): string { return $this->wazeId; }
    public function setWazeId(string $v): static { $this->wazeId = $v; return $this; }

    public function getType(): string { return $this->type; }
    public function setType(string $v): static { $this->type = $v; return $this; }

    public function getSubtype(): ?string { return $this->subtype; }
    public function setSubtype(?string $v): static { $this->subtype = $v; return $this; }

    /** Retorna latitude como float (faz cast da string DBAL) */
    public function getLatitude(): float { return (float) $this->latitude; }
    public function setLatitude(float|string $v): static { $this->latitude = (string) $v; return $this; }

    /** Retorna longitude como float (faz cast da string DBAL) */
    public function getLongitude(): float { return (float) $this->longitude; }
    public function setLongitude(float|string $v): static { $this->longitude = (string) $v; return $this; }

    public function getStreet(): ?string { return $this->street; }
    public function setStreet(?string $v): static { $this->street = $v; return $this; }

    public function getCity(): ?string { return $this->city; }
    public function setCity(?string $v): static { $this->city = $v; return $this; }

    public function getCountry(): ?string { return $this->country; }
    public function setCountry(?string $v): static { $this->country = $v; return $this; }

    public function getReliability(): ?int { return $this->reliability; }
    public function setReliability(?int $v): static { $this->reliability = $v; return $this; }

    public function getConfidence(): ?int { return $this->confidence; }
    public function setConfidence(?int $v): static { $this->confidence = $v; return $this; }

    public function getReportRating(): ?int { return $this->reportRating; }
    public function setReportRating(?int $v): static { $this->reportRating = $v; return $this; }

    public function getNThumbsUp(): ?int { return $this->nThumbsUp; }
    public function setNThumbsUp(?int $v): static { $this->nThumbsUp = $v; return $this; }

    public function getNComments(): ?int { return $this->nComments; }
    public function setNComments(?int $v): static { $this->nComments = $v; return $this; }

    public function getReportDescription(): ?string { return $this->reportDescription; }
    public function setReportDescription(?string $v): static { $this->reportDescription = $v; return $this; }

    public function getMagvar(): ?int { return $this->magvar; }
    public function setMagvar(?int $v): static { $this->magvar = $v; return $this; }

    public function getRoadType(): ?int { return $this->roadType; }
    public function setRoadType(?int $v): static { $this->roadType = $v; return $this; }

    public function getAdditionalInfo(): ?string { return $this->additionalInfo; }
    public function setAdditionalInfo(?string $v): static { $this->additionalInfo = $v; return $this; }

    public function getComments(): array { return $this->comments; }
    public function setComments(array $v): static { $this->comments = $v; return $this; }

    public function getJamUuid(): ?string { return $this->jamUuid; }
    public function setJamUuid(?string $v): static { $this->jamUuid = $v; return $this; }

    public function getInscale(): ?bool { return $this->inscale; }
    public function setInscale(?bool $v): static { $this->inscale = $v; return $this; }

    public function getIsJamUnifiedAlert(): ?bool { return $this->isJamUnifiedAlert; }
    public function setIsJamUnifiedAlert(?bool $v): static { $this->isJamUnifiedAlert = $v; return $this; }

    public function getReportByMunicipalityUser(): ?bool { return $this->reportByMunicipalityUser; }
    public function setReportByMunicipalityUser(?bool $v): static { $this->reportByMunicipalityUser = $v; return $this; }

    public function getPubMillis(): int { return $this->pubMillis; }
    public function setPubMillis(int $v): static { $this->pubMillis = $v; return $this; }

    public function getFeedStartMillis(): ?int { return $this->feedStartMillis; }
    public function setFeedStartMillis(?int $v): static { $this->feedStartMillis = $v; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }

    /** Converte pubMillis em DateTimeImmutable (UTC) */
    public function getPubDate(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('@' . intdiv($this->pubMillis, 1000));
    }
}
