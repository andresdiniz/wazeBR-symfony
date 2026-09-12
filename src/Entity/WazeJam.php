<?php

namespace App\Entity;

use App\Repository\WazeJamRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: WazeJamRepository::class)]
#[ORM\Table(name: 'waze_jam')]
#[ORM\Index(columns: ['partner_id'])]
#[ORM\Index(columns: ['city'])]
#[ORM\Index(columns: ['road_type'])]
#[ORM\Index(columns: ['pub_millis'])]
class WazeJam
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Partner that owns this jam (via partner_api_link).
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    private ?Partner $partner = null;

    /**
     * Unique jam UUID from Waze.
     */
    #[ORM\Column(length: 64, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 64)]
    private ?string $uuid = null;

    /**
     * Jam level (severity, e.g. 1-5).
     */
    #[ORM\Column(nullable: true)]
    #[Assert\Range(min: 0, max: 10)]
    private ?int $level = null;

    /**
     * Jam length in meters.
     */
    #[ORM\Column(nullable: true)]
    private ?int $length = null;

    /**
     * Delay in seconds (negative values may indicate closed/blocked).
     */
    #[ORM\Column(nullable: true)]
    private ?int $delay = null;

    /**
     * Speed (raw value from API).
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $speed = null;

    /**
     * Speed in km/h.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $speedKMH = null;

    /**
     * Street name.
     */
    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $street = null;

    /**
     * City name.
     */
    #[ORM\Column(length: 150, nullable: true)]
    #[Assert\Length(max: 150)]
    private ?string $city = null;

    /**
     * Country code (e.g. BR).
     */
    #[ORM\Column(length: 3, nullable: true)]
    #[Assert\Length(max: 3)]
    private ?string $country = null;

    /**
     * Road type (numeric code from Waze).
     */
    #[ORM\Column(nullable: true)]
    private ?int $roadType = null;

    /**
     * Publication time in milliseconds since epoch.
     */
    #[ORM\Column(type: Types::BIGINT)]
    #[Assert\NotBlank]
    private ?string $pubMillis = null;

    /**
     * Turn type (e.g. NONE, LEFT, RIGHT).
     */
    #[ORM\Column(length: 20, nullable: true)]
    #[Assert\Length(max: 20)]
    private ?string $turnType = null;

    /**
     * UUID of blocking alert (if this jam is caused by an alert).
     */
    #[ORM\Column(length: 64, nullable: true)]
    #[Assert\Length(max: 64)]
    private ?string $blockingAlertUuid = null;

    /**
     * Line geometry: JSON array of points [{x: float, y: float}, ...].
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $line = null;

    /**
     * Local creation timestamp.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    /**
     * Local update timestamp.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

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

    public function setUuid(?string $uuid): static
    {
        $this->uuid = $uuid;
        return $this;
    }

    public function getLevel(): ?int
    {
        return $this->level;
    }

    public function setLevel(?int $level): static
    {
        $this->level = $level;
        return $this;
    }

    public function getLength(): ?int
    {
        return $this->length;
    }

    public function setLength(?int $length): static
    {
        $this->length = $length;
        return $this;
    }

    public function getDelay(): ?int
    {
        return $this->delay;
    }

    public function setDelay(?int $delay): static
    {
        $this->delay = $delay;
        return $this;
    }

    public function getSpeed(): ?string
    {
        return $this->speed;
    }

    public function setSpeed(?string $speed): static
    {
        $this->speed = $speed;
        return $this;
    }

    public function getSpeedKMH(): ?string
    {
        return $this->speedKMH;
    }

    public function setSpeedKMH(?string $speedKMH): static
    {
        $this->speedKMH = $speedKMH;
        return $this;
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

    public function setCountry(?string $country): static
    {
        $this->country = $country;
        return $this;
    }

    public function getRoadType(): ?int
    {
        return $this->roadType;
    }

    public function setRoadType(?int $roadType): static
    {
        $this->roadType = $roadType;
        return $this;
    }

    public function getPubMillis(): ?string
    {
        return $this->pubMillis;
    }

    public function setPubMillis(?string $pubMillis): static
    {
        $this->pubMillis = $pubMillis;
        return $this;
    }

    public function getTurnType(): ?string
    {
        return $this->turnType;
    }

    public function setTurnType(?string $turnType): static
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

    public function getLine(): ?array
    {
        return $this->line;
    }

    public function setLine(?array $line): static
    {
        $this->line = $line;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    /**
     * Convert pubMillis (string) to DateTimeImmutable (UTC).
     */
    public function getPubDateTime(): ?\DateTimeImmutable
    {
        if ($this->pubMillis === null) {
            return null;
        }
        $seconds = (int) (((int) $this->pubMillis) / 1000);
        return \DateTimeImmutable::createFromFormat('U', (string) $seconds, new \DateTimeZone('UTC'));
    }

    /**
     * Return line as array of [lon, lat] pairs for easier use in maps.
     * Example: [[-43.788786, -20.658154], [-43.788488, -20.658277], ...]
     */
    public function getLineLonLat(): array
    {
        if (empty($this->line)) {
            return [];
        }
        $result = [];
        foreach ($this->line as $point) {
            if (is_array($point) && isset($point['x'], $point['y'])) {
                $result[] = [(float) $point['x'], (float) $point['y']];
            }
        }
        return $result;
    }

    public function __toString(): string
    {
        return sprintf(
            'WazeJam %s (level %d, length %dm) on %s in %s, %s',
            $this->uuid ?? 'no-uuid',
            $this->level ?? 0,
            $this->length ?? 0,
            $this->street ?? 'unknown street',
            $this->city ?? 'unknown city',
            $this->getPartner()?->getName() ?? 'no partner'
        );
    }
}
