<?php

namespace App\Entity;

use App\Enum\CifsDirectionEnum;
use App\Enum\CifsRoadsideEnum;
use App\Enum\CifsTypeEnum;
use App\Repository\CifsEventRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CifsEventRepository::class)]
#[ORM\Table(name: 'cifs_event')]
#[ORM\HasLifecycleCallbacks]
class CifsEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** UUID estável para o Waze (event_id no feed) */
    #[ORM\Column(length: 64, unique: true)]
    private string $externalId;

    #[ORM\Column(enumType: CifsTypeEnum::class, length: 30)]
    private CifsTypeEnum $type;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $subtype = null;

    /**
     * Polilinha CIFS: "lat lon lat lon …" com pelo menos 6 casas decimais.
     * Armazenamos como texto; gerado pelo frontend via cliques no mapa.
     */
    #[ORM\Column(type: 'text')]
    private string $polyline;

    #[ORM\Column(length: 150)]
    private string $street;

    #[ORM\Column(enumType: CifsDirectionEnum::class, length: 20, nullable: true)]
    private ?CifsDirectionEnum $direction = null;

    /** Máx 40 chars para exibição correta no app Waze */
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $startTime;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $endTime = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $creationTime;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updateTime = null;

    /** Evento ativo / inativo (soft delete) */
    #[ORM\Column]
    private bool $active = true;

    /** Parceiro que criou o evento (opcional) */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?Partner $partner = null;

    /**
     * Agendamento recorrente (tag <schedule> da spec CIFS).
     * Formato: { "monday": "09:00-11:00,17:00-21:00", "thursday": "09:00-11:00", ... }
     * Chaves em inglês minúsculo (monday..sunday), só dias com horário aparecem.
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $schedule = null;

    /**
     * lane_impact (formato parcial da spec CIFS).
     * Só faz sentido quando type != ROAD_CLOSED e direction == ONE_DIRECTION.
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $laneImpactClosedLanes = null;

    #[ORM\Column(enumType: CifsRoadsideEnum::class, length: 10, nullable: true)]
    private ?CifsRoadsideEnum $laneImpactRoadside = null;

    // ── Lifecycle ─────────────────────────────────────────────

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->creationTime = new \DateTimeImmutable();
        if (empty($this->externalId)) {
            $this->externalId = uniqid('cifs_', true);
        }
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updateTime = new \DateTimeImmutable();
    }

    // ── Getters & Setters ─────────────────────────────────────

    public function getId(): ?int { return $this->id; }

    public function getExternalId(): string { return $this->externalId; }
    public function setExternalId(string $externalId): static { $this->externalId = $externalId; return $this; }

    public function getType(): CifsTypeEnum { return $this->type; }
    public function setType(CifsTypeEnum $type): static { $this->type = $type; return $this; }

    public function getSubtype(): ?string { return $this->subtype; }
    public function setSubtype(?string $subtype): static { $this->subtype = $subtype; return $this; }

    public function getPolyline(): string { return $this->polyline; }
    public function setPolyline(string $polyline): static { $this->polyline = $polyline; return $this; }

    public function getStreet(): string { return $this->street; }
    public function setStreet(string $street): static { $this->street = $street; return $this; }

    public function getDirection(): ?CifsDirectionEnum { return $this->direction; }
    public function setDirection(?CifsDirectionEnum $direction): static { $this->direction = $direction; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): static { $this->description = $description; return $this; }

    public function getStartTime(): \DateTimeImmutable { return $this->startTime; }
    public function setStartTime(\DateTimeImmutable $startTime): static { $this->startTime = $startTime; return $this; }

    public function getEndTime(): ?\DateTimeImmutable { return $this->endTime; }
    public function setEndTime(?\DateTimeImmutable $endTime): static { $this->endTime = $endTime; return $this; }

    public function getCreationTime(): \DateTimeImmutable { return $this->creationTime; }

    public function getUpdateTime(): ?\DateTimeImmutable { return $this->updateTime; }

    public function isActive(): bool { return $this->active; }
    public function setActive(bool $active): static { $this->active = $active; return $this; }

    public function getPartner(): ?Partner { return $this->partner; }
    public function setPartner(?Partner $partner): static { $this->partner = $partner; return $this; }

    public function getSchedule(): ?array { return $this->schedule; }
    public function setSchedule(?array $schedule): static { $this->schedule = $schedule; return $this; }

    public function getLaneImpactClosedLanes(): ?int { return $this->laneImpactClosedLanes; }
    public function setLaneImpactClosedLanes(?int $n): static { $this->laneImpactClosedLanes = $n; return $this; }

    public function getLaneImpactRoadside(): ?CifsRoadsideEnum { return $this->laneImpactRoadside; }
    public function setLaneImpactRoadside(?CifsRoadsideEnum $r): static { $this->laneImpactRoadside = $r; return $this; }
}
