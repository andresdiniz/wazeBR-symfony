<?php

namespace App\Entity;

use App\Repository\WazeTvtUserOnJamRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: WazeTvtUserOnJamRepository::class)]
#[ORM\Table(name: 'waze_tvt_user_on_jam')]
#[ORM\Index(columns: ['partner_id', 'recorded_at'])]
#[ORM\Index(columns: ['route_id', 'recorded_at'])]
class WazeTvtUserOnJam
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
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?WazeTvtRoute $route = null;

    #[ORM\Column(name: 'waze_route_id', type: Types::BIGINT, nullable: true)]
    private ?string $wazeRouteId = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    #[Assert\PositiveOrZero]
    private ?int $wazersCount = null;

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
        $this->recordedAt = new \DateTimeImmutable();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getPartner(): ?Partner { return $this->partner; }
    public function setPartner(?Partner $partner): static { $this->partner = $partner; return $this; }
    public function getRoute(): ?WazeTvtRoute { return $this->route; }
    public function setRoute(?WazeTvtRoute $route): static { $this->route = $route; return $this; }
    public function getWazeRouteId(): ?string { return $this->wazeRouteId; }
    public function setWazeRouteId(?string $wazeRouteId): static { $this->wazeRouteId = $wazeRouteId; return $this; }
    public function getWazersCount(): ?int { return $this->wazersCount; }
    public function setWazersCount(?int $wazersCount): static { $this->wazersCount = $wazersCount; return $this; }
    public function getJamLevel(): ?int { return $this->jamLevel; }
    public function setJamLevel(?int $jamLevel): static { $this->jamLevel = $jamLevel; return $this; }
    public function getRecordedAt(): \DateTimeImmutable { return $this->recordedAt; }
    public function setRecordedAt(\DateTimeImmutable $recordedAt): static { $this->recordedAt = $recordedAt; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $createdAt): static { $this->createdAt = $createdAt; return $this; }
}
