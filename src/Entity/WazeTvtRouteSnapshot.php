<?php

namespace App\Entity;

use App\Repository\WazeTvtRouteSnapshotRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: WazeTvtRouteSnapshotRepository::class)]
#[ORM\Table(name: 'waze_tvt_route_snapshot')]
#[ORM\Index(columns: ['partner_id', 'route_id'])]
#[ORM\Index(columns: ['partner_id', 'recorded_at'])]
#[ORM\Index(columns: ['route_id', 'recorded_at'])]
class WazeTvtRouteSnapshot
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    private ?Partner $partner = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    private ?WazeTvtRoute $route = null;

    #[ORM\Column(name: 'route_id', type: Types::BIGINT)]
    #[Assert\NotBlank]
    private ?string $routeId = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $time = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $historicTime = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    #[Assert\Range(min: 0, max: 10)]
    private ?int $jamLevel = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Assert\NotNull]
    private \DateTimeImmutable $recordedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->recordedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getPartner(): ?Partner { return $this->partner; }
    public function setPartner(?Partner $partner): static { $this->partner = $partner; return $this; }
    public function getRoute(): ?WazeTvtRoute { return $this->route; }
    public function setRoute(?WazeTvtRoute $route): static { $this->route = $route; return $this; }
    public function getRouteId(): ?string { return $this->routeId; }
    public function setRouteId(?string $routeId): static { $this->routeId = $routeId; return $this; }
    public function getTime(): ?int { return $this->time; }
    public function setTime(?int $time): static { $this->time = $time; return $this; }
    public function getHistoricTime(): ?int { return $this->historicTime; }
    public function setHistoricTime(?int $historicTime): static { $this->historicTime = $historicTime; return $this; }
    public function getJamLevel(): ?int { return $this->jamLevel; }
    public function setJamLevel(?int $jamLevel): static { $this->jamLevel = $jamLevel; return $this; }
    public function getRecordedAt(): \DateTimeImmutable { return $this->recordedAt; }
    public function setRecordedAt(\DateTimeImmutable $recordedAt): static { $this->recordedAt = $recordedAt; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $createdAt): static { $this->createdAt = $createdAt; return $this; }

    public function __toString(): string
    {
        return sprintf(
            'Snapshot route=%s time=%ds historic=%ds jam=%d @ %s',
            $this->routeId ?? '?',
            $this->time ?? 0,
            $this->historicTime ?? 0,
            $this->jamLevel ?? 0,
            $this->recordedAt->format('Y-m-d H:i:s')
        );
    }
}
