<?php

namespace App\Entity;

use App\Repository\WazeTvtIrregularityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: WazeTvtIrregularityRepository::class)]
#[ORM\Table(name: 'waze_tvt_irregularity')]
#[ORM\UniqueConstraint(name: 'uniq_waze_tvt_irregularity', columns: ['partner_id', 'route_id', 'sub_route_id', 'content_hash'])]
#[ORM\Index(columns: ['partner_id', 'recorded_at'])]
#[ORM\Index(columns: ['route_id', 'recorded_at'])]
class WazeTvtIrregularity
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

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?WazeTvtSubRoute $subRoute = null;

    #[ORM\Column(name: 'waze_route_id', type: Types::BIGINT)]
    #[Assert\NotBlank]
    private ?string $wazeRouteId = null;

    #[ORM\Column(name: 'waze_sub_route_id', type: Types::BIGINT, nullable: true)]
    private ?string $wazeSubRouteId = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $type = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $subtype = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'content_hash', length: 64)]
    #[Assert\NotBlank]
    private ?string $contentHash = null;

    #[ORM\Column(type: Types::JSON)]
    private array $payload = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Assert\NotNull]
    private \DateTimeImmutable $recordedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(name: 'is_active', options: ['default' => 1])]
    private int $isActive = 1;

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
    public function getSubRoute(): ?WazeTvtSubRoute { return $this->subRoute; }
    public function setSubRoute(?WazeTvtSubRoute $subRoute): static { $this->subRoute = $subRoute; return $this; }
    public function getWazeRouteId(): ?string { return $this->wazeRouteId; }
    public function setWazeRouteId(?string $wazeRouteId): static { $this->wazeRouteId = $wazeRouteId; return $this; }
    public function getWazeSubRouteId(): ?string { return $this->wazeSubRouteId; }
    public function setWazeSubRouteId(?string $wazeSubRouteId): static { $this->wazeSubRouteId = $wazeSubRouteId; return $this; }
    public function getType(): ?string { return $this->type; }
    public function setType(?string $type): static { $this->type = $type; return $this; }
    public function getSubtype(): ?string { return $this->subtype; }
    public function setSubtype(?string $subtype): static { $this->subtype = $subtype; return $this; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): static { $this->description = $description; return $this; }
    public function getContentHash(): ?string { return $this->contentHash; }
    public function setContentHash(?string $contentHash): static { $this->contentHash = $contentHash; return $this; }
    public function getPayload(): array { return $this->payload; }
    public function setPayload(array $payload): static { $this->payload = $payload; return $this; }
    public function getRecordedAt(): \DateTimeImmutable { return $this->recordedAt; }
    public function setRecordedAt(\DateTimeImmutable $recordedAt): static { $this->recordedAt = $recordedAt; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $createdAt): static { $this->createdAt = $createdAt; return $this; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static { $this->updatedAt = $updatedAt; return $this; }
    public function getIsActive(): int { return $this->isActive; }
    public function setIsActive(int $isActive): static { $this->isActive = $isActive; return $this; }

    public function generateContentHash(): static
    {
        $payload = $this->payload;
        ksort($payload);
        $this->contentHash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        return $this;
    }
}
