<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WazeTrafficJamRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WazeTrafficJamRepository::class)]
#[ORM\Table(name: 'waze_traffic_jams')]
#[ORM\Index(name: 'idx_city_level', columns: ['city', 'level'])]
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

    #[ORM\Column(length: 50)]
    private string $wazeId;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $street = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(nullable: true)]
    private ?int $level = null;

    #[ORM\Column(type: 'decimal', precision: 8, scale: 2, nullable: true)]
    private ?float $speedKmh = null;

    #[ORM\Column(type: 'decimal', precision: 8, scale: 2, nullable: true)]
    private ?float $length = null;

    #[ORM\Column(nullable: true)]
    private ?int $delay = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $line = null;

    #[ORM\Column(type: 'bigint')]
    private int $pubMillis;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\PrePersist]
    public function onPrePersist(): void { $this->createdAt = new \DateTimeImmutable(); }

    public function getId(): ?int { return $this->id; }
    public function getPartner(): ?Partner { return $this->partner; }
    public function setPartner(?Partner $partner): static { $this->partner = $partner; return $this; }
    public function getWazeId(): string { return $this->wazeId; }
    public function setWazeId(string $wazeId): static { $this->wazeId = $wazeId; return $this; }
    public function getStreet(): ?string { return $this->street; }
    public function setStreet(?string $street): static { $this->street = $street; return $this; }
    public function getCity(): ?string { return $this->city; }
    public function setCity(?string $city): static { $this->city = $city; return $this; }
    public function getLevel(): ?int { return $this->level; }
    public function setLevel(?int $level): static { $this->level = $level; return $this; }
    public function getSpeedKmh(): ?float { return $this->speedKmh; }
    public function setSpeedKmh(?float $speedKmh): static { $this->speedKmh = $speedKmh; return $this; }
    public function getLength(): ?float { return $this->length; }
    public function setLength(?float $length): static { $this->length = $length; return $this; }
    public function getDelay(): ?int { return $this->delay; }
    public function setDelay(?int $delay): static { $this->delay = $delay; return $this; }
    public function getLine(): ?array { return $this->line; }
    public function setLine(?array $line): static { $this->line = $line; return $this; }
    public function getPubMillis(): int { return $this->pubMillis; }
    public function setPubMillis(int $pubMillis): static { $this->pubMillis = $pubMillis; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
