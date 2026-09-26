<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TrafficLightRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TrafficLightRepository::class)]
#[ORM\Table(name: 'traffic_light')]
#[ORM\UniqueConstraint(name: 'uniq_traffic_light_partner_code', columns: ['partner_id', 'code'])]
#[ORM\HasLifecycleCallbacks]
class TrafficLight
{
    public const PROTOCOL_NTCIP      = 'NTCIP';
    public const PROTOCOL_MODBUS_TCP = 'MODBUS_TCP';
    public const PROTOCOL_HTTP_REST  = 'HTTP_REST';
    public const PROTOCOL_FAKE       = 'FAKE';

    public const STATUS_OK    = 'OK';
    public const STATUS_ERROR = 'ERROR';

    public const PROTOCOLS = [
        self::PROTOCOL_NTCIP      => 'NTCIP (SNMP)',
        self::PROTOCOL_MODBUS_TCP => 'Modbus TCP',
        self::PROTOCOL_HTTP_REST  => 'HTTP REST (gateway SCATS/SCOOT)',
        self::PROTOCOL_FAKE       => 'Fake (desenvolvimento)',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Partner::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Partner $partner = null;

    /** Identificador curto dentro do parceiro (ex.: 'CRZ-001'). */
    #[ORM\Column(length: 100)]
    private ?string $code = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 32)]
    private ?string $protocol = null;

    /** host:porta para NTCIP/Modbus, URL base para HTTP REST. */
    #[ORM\Column(length: 255)]
    private ?string $endpoint = null;

    /** @var array<string,mixed> Opções específicas do protocolo (community, unitId, oids, headers, etc.) */
    #[ORM\Column(type: 'json')]
    private array $options = [];

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $latitude = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $longitude = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastReadAt = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $lastReadStatus = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lastReadError = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    /** @var Collection<int,TrafficLightSnapshot> */
    #[ORM\OneToMany(
        targetEntity: TrafficLightSnapshot::class,
        mappedBy: 'trafficLight',
        cascade: ['remove'],
        orphanRemoval: true,
    )]
    private Collection $snapshots;

    public function __construct()
    {
        $this->snapshots = new ArrayCollection();
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    // ─────────────────────────────────────────────────────────────────
    // Identificação
    // ─────────────────────────────────────────────────────────────────

    public function getId(): ?int { return $this->id; }

    public function getPartner(): ?Partner { return $this->partner; }

    public function setPartner(?Partner $partner): static
    {
        $this->partner = $partner;
        return $this;
    }

    public function getCode(): ?string { return $this->code; }

    public function setCode(string $code): static
    {
        $this->code = $code;
        return $this;
    }

    public function getName(): ?string { return $this->name; }

    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getProtocol(): ?string { return $this->protocol; }

    public function setProtocol(string $protocol): static
    {
        if (!isset(self::PROTOCOLS[$protocol])) {
            throw new \InvalidArgumentException(sprintf('Protocolo inválido: %s', $protocol));
        }
        $this->protocol = $protocol;
        return $this;
    }

    public function getEndpoint(): ?string { return $this->endpoint; }

    public function setEndpoint(string $endpoint): static
    {
        $this->endpoint = $endpoint;
        return $this;
    }

    /** @return array<string,mixed> */
    public function getOptions(): array { return $this->options; }

    /** @param array<string,mixed> $options */
    public function setOptions(array $options): static
    {
        $this->options = $options;
        return $this;
    }

    public function getLatitude(): ?float { return $this->latitude; }

    public function setLatitude(?float $latitude): static
    {
        $this->latitude = $latitude;
        return $this;
    }

    public function getLongitude(): ?float { return $this->longitude; }

    public function setLongitude(?float $longitude): static
    {
        $this->longitude = $longitude;
        return $this;
    }

    public function isActive(): bool { return $this->isActive; }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;
        return $this;
    }

    // ─────────────────────────────────────────────────────────────────
    // Última leitura
    // ─────────────────────────────────────────────────────────────────

    public function getLastReadAt(): ?\DateTimeImmutable { return $this->lastReadAt; }
    public function getLastReadStatus(): ?string { return $this->lastReadStatus; }
    public function getLastReadError(): ?string { return $this->lastReadError; }

    public function markReadOk(\DateTimeImmutable $at): static
    {
        $this->lastReadAt     = $at;
        $this->lastReadStatus = self::STATUS_OK;
        $this->lastReadError  = null;
        return $this;
    }

    public function markReadError(\DateTimeImmutable $at, string $error): static
    {
        $this->lastReadAt     = $at;
        $this->lastReadStatus = self::STATUS_ERROR;
        $this->lastReadError  = mb_substr($error, 0, 2000);
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }

    // ─────────────────────────────────────────────────────────────────
    // Snapshots
    // ─────────────────────────────────────────────────────────────────

    /** @return Collection<int,TrafficLightSnapshot> */
    public function getSnapshots(): Collection { return $this->snapshots; }

    public function addSnapshot(TrafficLightSnapshot $snapshot): static
    {
        if (!$this->snapshots->contains($snapshot)) {
            $this->snapshots->add($snapshot);
            $snapshot->setTrafficLight($this);
        }
        return $this;
    }

    public function removeSnapshot(TrafficLightSnapshot $snapshot): static
    {
        if ($this->snapshots->removeElement($snapshot)) {
            if ($snapshot->getTrafficLight() === $this) {
                $snapshot->setTrafficLight(null);
            }
        }
        return $this;
    }
}
