<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'download_audit_log')]
#[ORM\Index(columns: ['requested_at'], name: 'idx_download_audit_requested_at')]
#[ORM\Index(columns: ['user_id', 'requested_at'], name: 'idx_download_audit_user_at')]
#[ORM\Index(columns: ['partner_id', 'requested_at'], name: 'idx_download_audit_partner_at')]
final class DownloadAuditLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: Partner::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Partner $partner = null;

    #[ORM\Column(length: 255)]
    private string $route;

    #[ORM\Column(length: 255)]
    private string $filename;

    #[ORM\Column(length: 127, nullable: true)]
    private ?string $contentType;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $clientIpHash;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $requestedAt;

    public function __construct(User $user, ?Partner $partner, string $route, string $filename, ?string $contentType, ?string $clientIpHash)
    {
        $this->user = $user;
        $this->partner = $partner;
        $this->route = $route;
        $this->filename = $filename;
        $this->contentType = $contentType;
        $this->clientIpHash = $clientIpHash;
        $this->requestedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
