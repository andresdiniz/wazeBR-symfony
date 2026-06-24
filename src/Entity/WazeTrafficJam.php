<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WazeTrafficJamRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Congestionamento coletado do feed TVT do Waze.
 *
 * Estrutura do JSON (feeds-tvt):
 * {
 *   "jams": [{
 *     "uuid": "...",
 *     "street": "...",
 *     "city": "...",
 *     "country": "BR",
 *     "level": 3,
 *     "speedKMH": 12.5,
 *     "length": 450,
 *     "delay": 120,
 *     "type": "NONE",
 *     "turnType": "NONE",
 *     "roadType": 2,
 *     "segments": [],
 *     "line": [{"x":-43.9,"y":-19.9}, ...],
 *     "startNode": "Rua X",
 *     "endNode": "Rua Y",
 *     "causedBy": "uuid-do-alerta",
 *     "pubMillis": 1700000060000
 *   }]
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

    /** Link de feed TVT de onde este jam foi coletado */
    #[ORM\ManyToOne(targetEntity: MonitoredLink::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?MonitoredLink $sourceLink = null;

    /** UUID único Waze — chave de deduplicação */
    #[ORM\Column(length: 80, unique: true)]
    private string $wazeId = '';

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $street = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $country = null;

    /** Nível de congestionamento (0-5) */
    #[ORM\Column(nullable: true)]
    private ?int $level = null;

    /** Velocidade média em km/h */
    #[ORM\Column(type: 'decimal', precision: 8, scale: 2, nullable: true)]
    private ?float $speedKmh = null;

    /** Comprimento do jam em metros */
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?float $length = null;

    /** Atraso estimado em segundos */
    #[ORM\Column(nullable: true)]
    private ?int $delay = null;

    /** Tipo do jam (NONE, JAM, SMALL_JAM...) */
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

    /** UUID do alerta que causou o jam (causedBy) */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $causedBy = null;

    /** Linha geográfica do jam — array de {x, y} */
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

    public function getLength(): ?float { return $this->length; }
    public function setLength(?float $v): static { $this->length = $v; return $this; }

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
