<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PartnerApiLinkRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PartnerApiLinkRepository::class)]
#[ORM\Table(name: 'partner_api_link')]
#[ORM\Index(name: 'idx_partner_api_link_partner', columns: ['partner_id'])]
#[ORM\Index(name: 'idx_partner_api_link_partner_type_active', columns: ['partner_id', 'type', 'active'])]
class PartnerApiLink
{
    public const TYPE_ALERTS = 'alerts';
    public const TYPE_TRAFFIC = 'traffic';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Partner::class, inversedBy: 'apiLinks')]
    #[ORM\JoinColumn(name: 'partner_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Partner $partner = null;

    #[ORM\Column(length: 20)]
    private ?string $type = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 2048)]
    private ?string $url = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $updatedAt = null;

    public function getId(): ?int { return $this->id; }
    public function getPartner(): ?Partner { return $this->partner; }

    public function setPartner(?Partner $partner): static
    {
        $this->partner = $partner;
        return $this;
    }

    public function getType(): ?string { return $this->type; }

    public function setType(string $type): static
    {
        if (!in_array($type, [self::TYPE_ALERTS, self::TYPE_TRAFFIC], true)) {
            throw new \InvalidArgumentException('API link type must be alerts or traffic.');
        }
        $this->type = $type;
        return $this;
    }

    public function isAlerts(): bool { return $this->type === self::TYPE_ALERTS; }
    public function isTraffic(): bool { return $this->type === self::TYPE_TRAFFIC; }
    public function getName(): ?string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }
    public function getUrl(): ?string { return $this->url; }
    public function setUrl(string $url): static { $this->url = $url; return $this; }
    public function isActive(): bool { return $this->active; }
    public function setActive(bool $active): static { $this->active = $active; return $this; }
    public function getCreatedAt(): ?\DateTimeInterface { return $this->createdAt; }
    public function setCreatedAt(\DateTimeInterface $createdAt): static { $this->createdAt = $createdAt; return $this; }
    public function getUpdatedAt(): ?\DateTimeInterface { return $this->updatedAt; }
    public function setUpdatedAt(?\DateTimeInterface $updatedAt): static { $this->updatedAt = $updatedAt; return $this; }
}
