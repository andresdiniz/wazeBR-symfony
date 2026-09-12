<?php

namespace App\Entity;

use App\Repository\WazeTvtSubRouteRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: WazeTvtSubRouteRepository::class)]
#[ORM\Table(name: 'waze_tvt_sub_route')]
#[ORM\UniqueConstraint(name: 'uniq_waze_tvt_sub_route', columns: ['partner_id', 'route_id', 'sub_route_id'])]
#[ORM\Index(columns: ['partner_id', 'route_id'])]
class WazeTvtSubRoute
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

    #[ORM\Column(name: 'waze_route_id', type: Types::BIGINT)]
    #[Assert\NotBlank]
    private ?string $wazeRouteId = null;

    #[ORM\Column(name: 'sub_route_id', type: Types::BIGINT)]
    #[Assert\NotBlank]
    private ?string $subRouteId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $fromName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $toName = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $length = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $time = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $historicTime = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    #[Assert\Range(min: 0, max: 10)]
    private ?int $jamLevel = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 8, nullable: true)]
    private ?string $bboxMinY = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 8, nullable: true)]
    private ?string $bboxMinX = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 8, nullable: true)]
    private ?string $bboxMaxY = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 8, nullable: true)]
    private ?string $bboxMaxX = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $line = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $irregularities = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getPartner(): ?Partner { return $this->partner; }
    public function setPartner(?Partner $partner): static { $this->partner = $partner; return $this; }
    public function getRoute(): ?WazeTvtRoute { return $this->route; }
    public function setRoute(?WazeTvtRoute $route): static { $this->route = $route; return $this; }
    public function getWazeRouteId(): ?string { return $this->wazeRouteId; }
    public function setWazeRouteId(?string $wazeRouteId): static { $this->wazeRouteId = $wazeRouteId; return $this; }
    public function getSubRouteId(): ?string { return $this->subRouteId; }
    public function setSubRouteId(?string $subRouteId): static { $this->subRouteId = $subRouteId; return $this; }
    public function getFromName(): ?string { return $this->fromName; }
    public function setFromName(?string $fromName): static { $this->fromName = $fromName; return $this; }
    public function getToName(): ?string { return $this->toName; }
    public function setToName(?string $toName): static { $this->toName = $toName; return $this; }
    public function getLength(): ?int { return $this->length; }
    public function setLength(?int $length): static { $this->length = $length; return $this; }
    public function getTime(): ?int { return $this->time; }
    public function setTime(?int $time): static { $this->time = $time; return $this; }
    public function getHistoricTime(): ?int { return $this->historicTime; }
    public function setHistoricTime(?int $historicTime): static { $this->historicTime = $historicTime; return $this; }
    public function getJamLevel(): ?int { return $this->jamLevel; }
    public function setJamLevel(?int $jamLevel): static { $this->jamLevel = $jamLevel; return $this; }
    public function getBboxMinY(): ?string { return $this->bboxMinY; }
    public function setBboxMinY(?string $value): static { $this->bboxMinY = $value; return $this; }
    public function getBboxMinX(): ?string { return $this->bboxMinX; }
    public function setBboxMinX(?string $value): static { $this->bboxMinX = $value; return $this; }
    public function getBboxMaxY(): ?string { return $this->bboxMaxY; }
    public function setBboxMaxY(?string $value): static { $this->bboxMaxY = $value; return $this; }
    public function getBboxMaxX(): ?string { return $this->bboxMaxX; }
    public function setBboxMaxX(?string $value): static { $this->bboxMaxX = $value; return $this; }
    public function getLine(): ?array { return $this->line; }
    public function setLine(?array $line): static { $this->line = $line; return $this; }
    public function getIrregularities(): ?array { return $this->irregularities; }
    public function setIrregularities(?array $irregularities): static { $this->irregularities = $irregularities; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $createdAt): static { $this->createdAt = $createdAt; return $this; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static { $this->updatedAt = $updatedAt; return $this; }
}
