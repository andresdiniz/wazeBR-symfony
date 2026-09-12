<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WazeJamRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WazeJamRepository::class)]
#[ORM\Table(
    name: 'waze_jams',
    indexes: [
        new ORM\Index(
            name: 'idx_waze_jam_uuid',
            columns: ['uuid'],
        ),
        new ORM\Index(
            name: 'idx_waze_jam_partner_active',
            columns: ['partner_id', 'is_active'],
        ),
        new ORM\Index(
            name: 'idx_waze_jam_city_level',
            columns: ['city', 'level'],
        ),
        new ORM\Index(
            name: 'idx_waze_jam_pub_millis',
            columns: ['pub_millis'],
        ),
        new ORM\Index(
            name: 'idx_waze_jam_collected_at',
            columns: ['collected_at'],
        ),
        new ORM\Index(
            name: 'idx_waze_jam_last_seen_at',
            columns: ['last_seen_at'],
        ),
        new ORM\Index(
            name: 'idx_waze_jam_blocking_alert',
            columns: ['blocking_alert_uuid'],
        ),
    ],
)]
#[ORM\UniqueConstraint(
    name: 'uniq_waze_jam_partner_uuid',
    columns: ['partner_id', 'uuid'],
)]
class WazeJam
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'wazeJams')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Partner $partner = null;

    #[ORM\Column(length: 100)]
    private ?string $uuid = null;

    #[ORM\Column(type: Types::BIGINT)]
    private int $jamId = 0;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $line = null;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $linePoints = 0;

    #[ORM\Column(
        type: Types::DECIMAL,
        precision: 10,
        scale: 3,
    )]
    private ?string $speed = '0';

    #[ORM\Column(
        type: Types::DECIMAL,
        precision: 10,
        scale: 3,
    )]
    private ?string $speedKmh = '0';

    #[ORM\Column(type: Types::INTEGER)]
    private int $length = 0;

    #[ORM\Column(type: Types::INTEGER)]
    private int $delay = -1;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $level = 0;

    #[ORM\Column(type: Types::BIGINT)]
    private ?int $pubMillis = null;

    #[ORM\Column(length: 20)]
    private ?string $turnType = 'NONE';

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $blockingAlertUuid = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $segments = null;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $segmentCount = 0;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $street = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(length: 2)]
    private ?string $country = 'BR';

    #[ORM\Column(type: Types::SMALLINT)]
    private int $roadType = 0;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $endNode = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $collectedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $lastSeenAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deactivatedAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPartner(): ?Partner
    {
        return $this->partner;
    }

    public function setPartner(?Partner $partner): static
    {
        $this->partner = $partner;

        return $this;
    }

    public function getUuid(): ?string
    {
        return $this->uuid;
    }

    public function setUuid(string $uuid): static
    {
        $this->uuid = $uuid;

        return $this;
    }

    public function getJamId(): int
    {
        return $this->jamId;
    }

    public function setJamId(int $jamId): static
    {
        $this->jamId = $jamId;

        return $this;
    }

    public function getLine(): ?array
    {
        return $this->line;
    }

    public function setLine(?array $line): static
    {
        $this->line = $line;
        $this->linePoints = count($line ?? []);

        return $this;
    }

    public function getLinePoints(): int
    {
        return $this->linePoints;
    }

    public function getSpeed(): ?string
    {
        return $this->speed;
    }

    public function setSpeed(float|string $speed): static
    {
        $this->speed = (string) $speed;

        return $this;
    }

    public function getSpeedKmh(): ?string
    {
        return $this->speedKmh;
    }

    public function setSpeedKmh(float|string $speedKmh): static
    {
        $this->speedKmh = (string) $speedKmh;

        return $this;
    }

    public function getLength(): int
    {
        return $this->length;
    }

    public function setLength(int $length): static
    {
        $this->length = $length;

        return $this;
    }

    public function getDelay(): int
    {
        return $this->delay;
    }

    public function setDelay(int $delay): static
    {
        $this->delay = $delay;

        return $this;
    }

    public function getLevel(): int
    {
        return $this->level;
    }

    public function setLevel(int $level): static
    {
        $this->level = $level;

        return $this;
    }

    public function getPubMillis(): ?int
    {
        return $this->pubMillis;
    }

    public function setPubMillis(int $pubMillis): static
    {
        $this->pubMillis = $pubMillis;

        return $this;
    }

    public function getTurnType(): ?string
    {
        return $this->turnType;
    }

    public function setTurnType(string $turnType): static
    {
        $this->turnType = $turnType;

        return $this;
    }

    public function getBlockingAlertUuid(): ?string
    {
        return $this->blockingAlertUuid;
    }

    public function setBlockingAlertUuid(?string $blockingAlertUuid): static
    {
        $this->blockingAlertUuid = $blockingAlertUuid;

        return $this;
    }

    public function getSegments(): ?array
    {
        return $this->segments;
    }

    public function setSegments(?array $segments): static
    {
        $this->segments = $segments;
        $this->segmentCount = count($segments ?? []);

        return $this;
    }

    public function getSegmentCount(): int
    {
        return $this->segmentCount;
    }

    public function getStreet(): ?string
    {
        return $this->street;
    }

    public function setStreet(?string $street): static
    {
        $this->street = $street;

        return $this;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function setCity(?string $city): static
    {
        $this->city = $city;

        return $this;
    }

    public function getCountry(): ?string
    {
        return $this->country;
    }

    public function setCountry(string $country): static
    {
        $this->country = $country;

        return $this;
    }

    public function getRoadType(): int
    {
        return $this->roadType;
    }

    public function setRoadType(int $roadType): static
    {
        $this->roadType = $roadType;

        return $this;
    }

    public function getEndNode(): ?string
    {
        return $this->endNode;
    }

    public function setEndNode(?string $endNode): static
    {
        $this->endNode = $endNode;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;

        if ($isActive) {
            $this->deactivatedAt = null;
        }

        return $this;
    }

    public function getCollectedAt(): ?\DateTimeImmutable
    {
        return $this->collectedAt;
    }

    public function setCollectedAt(\DateTimeImmutable $collectedAt): static
    {
        $this->collectedAt = $collectedAt;

        return $this;
    }

    public function getLastSeenAt(): ?\DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function setLastSeenAt(\DateTimeImmutable $lastSeenAt): static
    {
        $this->lastSeenAt = $lastSeenAt;

        return $this;
    }

    public function getDeactivatedAt(): ?\DateTimeImmutable
    {
        return $this->deactivatedAt;
    }

    public function setDeactivatedAt(
        ?\DateTimeImmutable $deactivatedAt,
    ): static {
        $this->deactivatedAt = $deactivatedAt;

        return $this;
    }

    public function deactivate(
        \DateTimeImmutable $deactivatedAt,
    ): static {
        $this->isActive = false;
        $this->deactivatedAt = $deactivatedAt;

        return $this;
    }

    public function getPubDateTime(): ?\DateTimeImmutable
    {
        if ($this->pubMillis === null || $this->pubMillis <= 0) {
            return null;
        }

        return (new \DateTimeImmutable())->setTimestamp(
            (int) floor($this->pubMillis / 1000),
        );
    }

    public function getLevelLabel(): string
    {
        $labels = [
            1 => 'Baixo',
            2 => 'Moderado',
            3 => 'Alto',
            4 => 'Muito alto',
            5 => 'Parado',
        ];

        return $labels[$this->level] ?? sprintf(
            'Nível %d',
            $this->level,
        );
    }

    public function getFirstCoordinate(): ?array
    {
        if ($this->line === null || $this->line === []) {
            return null;
        }

        return $this->line[0] ?? null;
    }

    public function getLastCoordinate(): ?array
    {
        if ($this->line === null || $this->line === []) {
            return null;
        }

        $lastIndex = count($this->line) - 1;

        return $this->line[$lastIndex] ?? null;
    }
}
