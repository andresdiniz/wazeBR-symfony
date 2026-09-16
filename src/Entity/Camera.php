<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CameraRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Câmera pública de um parceiro.
 *
 * ─── Sobre o campo `urlType` ────────────────────────────────────────
 *   snapshot  → URL de imagem JPG/PNG (o mais comum, ~200KB)
 *   mjpeg     → stream Motion-JPEG servido pelo IP camera
 *   iframe    → player embedado (YouTube, Vimeo, players de terceiros)
 *   hls       → playlist .m3u8 (não recomendado para TV — 2-4s de lag)
 *   rtsp      → não roda no browser, só para referência
 *   other     → qualquer outra coisa
 *
 * ─── Sobre `sortOrder` ──────────────────────────────────────────────
 *   A ordem em que aparecem no carrossel da TV é dada por
 *   (sortOrder ASC, id ASC). Empate no sortOrder cai no id, então a
 *   ordem é sempre determinística.
 */
#[ORM\Entity(repositoryClass: CameraRepository::class)]
#[ORM\Table(name: 'camera')]
#[ORM\Index(name: 'idx_camera_partner_active', columns: ['partner_id', 'active'])]
#[ORM\Index(name: 'idx_camera_partner_order', columns: ['partner_id', 'sort_order'])]
class Camera
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    private ?Partner $partner = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private ?string $name = null;

    /** URL de acesso: snapshot JPG, MJPEG, playlist HLS, iframe embed... */
    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    private ?string $url = null;

    #[ORM\Column(name: 'url_type', length: 20, options: ['default' => 'snapshot'])]
    #[Assert\Choice(
        choices: ['snapshot', 'mjpeg', 'iframe', 'hls', 'rtsp', 'other'],
        message: 'Tipo de URL inválido.',
    )]
    private string $urlType = 'snapshot';

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 7, nullable: true)]
    #[Assert\Range(min: -90, max: 90)]
    private ?string $latitude = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 7, nullable: true)]
    #[Assert\Range(min: -180, max: 180)]
    private ?string $longitude = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(length: 2, nullable: true)]
    #[Assert\Length(exactly: 2)]
    private ?string $state = null;

    /** Comentário livre — observações do operador sobre a câmera. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    /** Ordem no carrossel da TV (ASC, empate cai no id). */
    #[ORM\Column(name: 'sort_order', type: Types::SMALLINT, options: ['default' => 0])]
    private int $sortOrder = 0;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
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

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = trim($name);
        return $this;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function setUrl(string $url): static
    {
        $this->url = trim($url);
        return $this;
    }

    public function getUrlType(): string
    {
        return $this->urlType;
    }

    public function setUrlType(string $urlType): static
    {
        $this->urlType = $urlType;
        return $this;
    }

    public function getLatitude(): ?string
    {
        return $this->latitude;
    }

    public function setLatitude(?string $latitude): static
    {
        $this->latitude = $latitude;
        return $this;
    }

    public function getLongitude(): ?string
    {
        return $this->longitude;
    }

    public function setLongitude(?string $longitude): static
    {
        $this->longitude = $longitude;
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

    public function getState(): ?string
    {
        return $this->state;
    }

    public function setState(?string $state): static
    {
        $this->state = $state;
        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;
        return $this;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): static
    {
        $this->sortOrder = $sortOrder;
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

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function __toString(): string
    {
        return sprintf(
            '%s (%s) #%d',
            $this->name ?? 'Câmera',
            $this->urlType,
            $this->id ?? 0,
        );
    }
}
