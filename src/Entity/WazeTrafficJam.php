<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WazeTrafficJamRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Congestionamento coletado do feed PartnerHub do Waze (format=1).
 *
 * Estrutura do JSON (jams[]):
 * {
 *   "uuid": "string",
 *   "street": "string|null",
 *   "city": "string|null",
 *   "country": "string",
 *   "level": int,          // 0-5
 *   "speedKMH": float,     // velocidade em km/h
 *   "speed": float,        // velocidade em m/s
 *   "length": int|float,   // comprimento em metros (API pode retornar float ex: 450.0)
 *   "delay": int,          // atraso em segundos
 *   "type": "string",      // NONE, JAM, SMALL_JAM...
 *   "turnType": "string",
 *   "roadType": int,
 *   "startNode": "string|null",
 *   "endNode": "string|null",
 *   "causedBy": "string|null",
 *   "blocking": bool,
 *   "severity": int,
 *   "id": int,
 *   "line": [{"x": float, "y": float}],
 *   "segments": [],
 *   "pubMillis": int
 * }
 */
#[ORM\Entity(repositoryClass: WazeTrafficJamRepository::class)]
#[ORM\Table(name: 'waze_traffic_jams')]
#[ORM\UniqueConstraint(name: 'uq_waze_jam_uuid', columns: ['waze_id'])]
#[ORM\Index(name: 'idx_city_level', columns: ['city', 'level'])]
#[ORM\Index(name: 'idx_jam_pubmillis', columns: ['pub_millis'])]
#[ORM\Index(name: 'idx_jam_partner_pub', columns: ['partner_id', 'pub_millis'])]
#[ORM\HasLifecycleCallbacks]
class WazeTrafficJam
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Partner::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Partner $partner = null;

    /** Link de feed de onde este jam foi coletado */
    #[ORM\ManyToOne(targetEntity: MonitoredLink::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?MonitoredLink $sourceLink = null;

    /** UUID único Waze — chave de deduplicação (campo "uuid" no JSON) */
    #[ORM\Column(length: 80, unique: true)]
    private string $wazeId = '';

    /** ID numérico interno do Waze (campo "id" no JSON) */
    #[ORM\Column(nullable: true)]
    private ?int $wazeNumericId = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $street = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $country = null;

    /** Nível de congestionamento (0-5) */
    #[ORM\Column(nullable: true)]
    private ?int $level = null;

    /** Velocidade média em km/h (campo "speedKMH") */
    #[ORM\Column(type: 'decimal', precision: 8, scale: 2, nullable: true)]
    private ?float $speedKmh = null;

    /** Velocidade em m/s (campo "speed") */
    #[ORM\Column(type: 'decimal', precision: 8, scale: 3, nullable: true)]
    private ?float $speed = null;

    /** Comprimento do jam em metros (campo "length") — armazenado como int */
    #[ORM\Column(nullable: true)]
    private ?int $length = null;

    /** Atraso estimado em segundos (campo "delay") */
    #[ORM\Column(nullable: true)]
    private ?int $delay = null;

    /** Tipo do jam (NONE, JAM, SMALL_JAM, LARGE_JAM, HUGE_JAM) */
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $type = null;

    /** Tipo de curva/conversão */
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $turnType = null;

    /** Código de tipo de via Waze */
    #[ORM\Column(nullable: true)]
    private ?int $roadType = null;

    /** Nó de início do segmento */
    #[ORM\Column(length: 200, nullable: true)]
    private ?string $startNode = null;

    /** Nó de fim do segmento */
    #[ORM\Column(length: 200, nullable: true)]
    private ?string $endNode = null;

    /** UUID do alerta que causou o jam (campo "causedBy") */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $causedBy = null;

    /** Jam bloqueia a via completamente (campo "blocking") */
    #[ORM\Column(nullable: true)]
    private ?bool $blocking = null;

    /** Severidade do jam (campo "severity") */
    #[ORM\Column(nullable: true)]
    private ?int $severity = null;

    /** Linha geográfica do jam — array de {x: lon, y: lat} */
    #[ORM\Column(type: 'json')]
    private array $line = [];

    /** Segmentos de via do jam */
    #[ORM\Column(type: 'json')]
    private array $segments = [];

    /** Timestamp de publicação no Waze (milissegundos) */
    #[ORM\Column(type: 'bigint')]
    private int $pubMillis = 0;

    /** startTimeMillis do feed onde este jam foi coletado */
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

    // ── Getters & Setters ─────────────────────────────────────────

    public function getId(): ?int { return $this->id; }

    public function getPartner(): ?Partner { return $this->partner; }
    public function setPartner(?Partner $p): static { $this->partner = $p; return $this; }

    public function getSourceLink(): ?MonitoredLink { return $this->sourceLink; }
    public function setSourceLink(?MonitoredLink $l): static { $this->sourceLink = $l; return $this; }

    public function getWazeId(): string { return $this->wazeId; }
    public function setWazeId(string $v): static { $this->wazeId = $v; return $this; }

    public function getWazeNumericId(): ?int { return $this->wazeNumericId; }
    public function setWazeNumericId(?int $v): static { $this->wazeNumericId = $v; return $this; }

    public function getStreet(): ?string { return $this->street; }
    public function setStreet(?string $v): static { $this->street = $v; return $this; }

    public function getCity(): ?string { return $this->city; }
    public function setCity(?string $v): static { $this->city = $v; return $this; }

    public function getCountry(): ?string { return $this->country; }
    public function setCountry(?string $v): static { $this->country = $v; return $this; }

    public function getLevel(): ?int { return $this->level; }
    public function setLevel(?int $v): static { $this->level = $v; return $this; }

    public function getSpeedKmh(): ?float { return $this->speedKmh; }
    public function setSpeedKmh(?float $v): static { $this->speedKmh = $v; return $this; }

    public function getSpeed(): ?float { return $this->speed; }
    public function setSpeed(?float $v): static { $this->speed = $v; return $this; }

    public function getLength(): ?int { return $this->length; }

    /**
     * A API do Waze pode retornar length como float (ex: 450.0).
     * Aceitamos int|float|null e armazenamos sempre como int.
     */
    public function setLength(int|float|null $v): static
    {
        $this->length = $v !== null ? (int) $v : null;
        return $this;
    }

    public function getDelay(): ?int { return $this->delay; }
    public function setDelay(?int $v): static { $this->delay = $v; return $this; }

    public function getType(): ?string { return $this->type; }
    public function setType(?string $v): static { $this->type = $v; return $this; }

    public function getTurnType(): ?string { return $this->turnType; }
    public function setTurnType(?string $v): static { $this->turnType = $v; return $this; }

    public function getRoadType(): ?int { return $this->roadType; }
    public function setRoadType(?int $v): static { $this->roadType = $v; return $this; }

    public function getStartNode(): ?string { return $this->startNode; }
    public function setStartNode(?string $v): static { $this->startNode = $v; return $this; }

    public function getEndNode(): ?string { return $this->endNode; }
    public function setEndNode(?string $v): static { $this->endNode = $v; return $this; }

    public function getCausedBy(): ?string { return $this->causedBy; }
    public function setCausedBy(?string $v): static { $this->causedBy = $v; return $this; }

    public function getBlocking(): ?bool { return $this->blocking; }
    public function setBlocking(?bool $v): static { $this->blocking = $v; return $this; }

    public function getSeverity(): ?int { return $this->severity; }
    public function setSeverity(?int $v): static { $this->severity = $v; return $this; }

    public function getLine(): array { return $this->line; }
    public function setLine(array $v): static { $this->line = $v; return $this; }

    public function getSegments(): array { return $this->segments; }
    public function setSegments(array $v): static { $this->segments = $v; return $this; }

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
