<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Partner;
use App\Enum\CifsDirectionEnum;
use App\Enum\CifsTypeEnum;
use App\Repository\PartnerFeedEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Evento enviado pelo parceiro para o feed CIFS consumido pelo Waze.
 *
 * IMPORTANTE: após aplicar este arquivo, rode:
 *   php bin/console make:migration
 *   php bin/console doctrine:migrations:migrate
 */
#[ORM\Entity(repositoryClass: PartnerFeedEventRepository::class)]
#[ORM\Table(name: 'partner_feed_event')]
#[ORM\HasLifecycleCallbacks]
#[ORM\Index(name: 'idx_pfe_partner_active', columns: ['partner_id', 'is_active'])]
#[ORM\Index(name: 'idx_pfe_uuid', columns: ['uuid'])]
class PartnerFeedEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    /**
     * Identificador CIFS estável do evento.
     * Gere uma vez e NUNCA mude durante o ciclo de vida do evento.
     */
    #[ORM\Column(type: 'string', length: 64, unique: true)]
    #[Assert\NotBlank]
    private ?string $uuid = null;

    #[ORM\ManyToOne(targetEntity: Partner::class, inversedBy: 'feedEvents')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Partner $partner = null;

    /** Tipo CIFS principal: ACCIDENT, JAM, HAZARD, POLICE, CHIT_CHAT, ROAD_CLOSED. */
    #[ORM\Column(type: 'string', length: 20, enumType: CifsTypeEnum::class)]
    #[Assert\NotNull]
    private ?CifsTypeEnum $cifsType = CifsTypeEnum::ROAD_CLOSED;

    /** Subtipo CIFS do evento, ex: ROAD_CLOSED_CONSTRUCTION. */
    #[ORM\Column(type: 'string', length: 60, nullable: true)]
    private ?string $cifsSubtype = null;

    /** Rua onde o evento ocorre (obrigatória no CIFS). */
    #[ORM\Column(type: 'string', length: 120)]
    #[Assert\NotBlank]
    private ?string $street = null;

    /** Referência textual livre (bairro, km, etc.) */
    #[ORM\Column(type: 'string', length: 160, nullable: true)]
    private ?string $reference = null;

    /** Descrição do evento, ideal <= 40 chars para síntese por TTS. */
    #[ORM\Column(type: 'string', length: 180, nullable: true)]
    private ?string $description = null;

    /** Cidade usada no display do mapa (opcional) */
    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $city = null;

    /**
     * Polyline do evento: array de pontos [ [lat, lng], [lat, lng], ... ]
     * SRID 4326, com 6+ casas decimais, no sentido do tráfego afetado.
     */
    #[ORM\Column(type: Types::JSON)]
    private array $polyline = [];

    /** Direção do evento conforme CIFS. */
    #[ORM\Column(type: 'string', length: 20, enumType: CifsDirectionEnum::class)]
    private CifsDirectionEnum $direction = CifsDirectionEnum::ONE_DIRECTION;

    /** Início do evento (timezone gravado junto). */
    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    #[Assert\NotNull]
    private ?\DateTimeInterface $startTime = null;

    /** Fim do evento; se null, o Waze assume fallback de 14 dias do CIFS. */
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $endTime = null;

    /** Momento em que o evento foi criado no sistema. */
    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $creationTime = null;

    /** Última atualização do evento. */
    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $updateTime = null;

    /** Se false, o evento some do feed CIFS. */
    #[ORM\Column(type: 'boolean')]
    private bool $isActive = true;

    /** Motivo interno da desativação (ex: 'expired') — opcional. */
    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $deactivatedReason = null;

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $now = new \DateTimeImmutable();
        if ($this->uuid === null) {
            $this->uuid = Uuid::v4()->toRfc4122();
        }
        if ($this->creationTime === null) {
            $this->creationTime = $now;
        }
        $this->updateTime = $now;
        if ($this->startTime === null) {
            $this->startTime = $now;
        }
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updateTime = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getUuid(): ?string { return $this->uuid; }
    public function setUuid(string $uuid): self { $this->uuid = $uuid; return $this; }

    public function getPartner(): ?Partner { return $this->partner; }
    public function setPartner(?Partner $partner): self { $this->partner = $partner; return $this; }

    public function getCifsType(): ?CifsTypeEnum { return $this->cifsType; }
    public function setCifsType(CifsTypeEnum $cifsType): self { $this->cifsType = $cifsType; return $this; }

    public function getCifsSubtype(): ?string { return $this->cifsSubtype; }
    public function setCifsSubtype(?string $cifsSubtype): self
    {
        if ($cifsSubtype !== null && $this->cifsType !== null && !$this->cifsType->isValidSubtype($cifsSubtype)) {
            throw new \InvalidArgumentException("Subtype '{$cifsSubtype}' não é válido para {$this->cifsType->value}");
        }
        $this->cifsSubtype = $cifsSubtype;
        return $this;
    }

    public function getStreet(): ?string { return $this->street; }
    public function setStreet(string $street): self { $this->street = $street; return $this; }

    public function getReference(): ?string { return $this->reference; }
    public function setReference(?string $reference): self { $this->reference = $reference; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): self { $this->description = $description; return $this; }

    public function getCity(): ?string { return $this->city; }
    public function setCity(?string $city): self { $this->city = $city; return $this; }

    /** @return array<int, array{0: float, 1: float}> */
    public function getPolyline(): array { return $this->polyline; }
    public function setPolyline(array $polyline): self { $this->polyline = $polyline; return $this; }

    public function getDirection(): CifsDirectionEnum { return $this->direction; }
    public function setDirection(CifsDirectionEnum $direction): self { $this->direction = $direction; return $this; }

    public function getStartTime(): ?\DateTimeInterface { return $this->startTime; }
    public function setStartTime(?\DateTimeInterface $startTime): self { $this->startTime = $startTime; return $this; }

    public function getEndTime(): ?\DateTimeInterface { return $this->endTime; }
    public function setEndTime(?\DateTimeInterface $endTime): self { $this->endTime = $endTime; return $this; }

    public function getCreationTime(): ?\DateTimeInterface { return $this->creationTime; }
    public function setCreationTime(?\DateTimeInterface $creationTime): self { $this->creationTime = $creationTime; return $this; }

    public function getUpdateTime(): ?\DateTimeInterface { return $this->updateTime; }
    public function setUpdateTime(?\DateTimeInterface $updateTime): self { $this->updateTime = $updateTime; return $this; }

    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $isActive): self { $this->isActive = $isActive; return $this; }

    public function getDeactivatedReason(): ?string { return $this->deactivatedReason; }
    public function setDeactivatedReason(?string $deactivatedReason): self { $this->deactivatedReason = $deactivatedReason; return $this; }

    private function formatCifsDate(?\DateTimeInterface $dt): ?string
    {
        return $dt?->format('Y-m-d\TH:i:sP');
    }

    /** Formata a polyline como "lat lng lat lng ..." conforme CIFS. */
    public function toCifsPolyline(): string
    {
        $parts = [];
        foreach ($this->polyline as $point) {
            if (!is_array($point) || !isset($point[0], $point[1])) {
                continue;
            }
            $parts[] = sprintf('%.6f %.6f', (float) $point[0], (float) $point[1]);
        }
        return implode(' ', $parts);
    }

    /** Representação do evento no formato CIFS. */
    public function toCifsArray(): array
    {
        return [
            'id'           => $this->uuid,
            'creationtime' => $this->formatCifsDate($this->creationTime),
            'updatetime'   => $this->formatCifsDate($this->updateTime),
            'type'         => $this->cifsType?->value,
            'subtype'      => $this->cifsSubtype,
            'street'       => $this->street,
            'description'  => $this->description,
            'city'         => $this->city,
            'polyline'     => $this->toCifsPolyline(),
            'direction'    => $this->direction->value,
            'starttime'    => $this->formatCifsDate($this->startTime),
            'endtime'      => $this->formatCifsDate($this->endTime),
            'reference'    => $this->reference,
        ];
    }
}