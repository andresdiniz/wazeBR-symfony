<?php

namespace App\Entity;

use App\Repository\WazeFeedCollectionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WazeFeedCollectionRepository::class)]
#[ORM\Table(name: 'waze_feed_collection')]
#[ORM\Index(columns: ['waze_feed_id', 'started_at'], name: 'IDX_WAZE_COLLECTION_FEED')]
#[ORM\Index(columns: ['status', 'started_at'], name: 'IDX_WAZE_COLLECTION_STATUS')]
class WazeFeedCollection
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: WazeFeed::class, inversedBy: 'collections')]
    #[ORM\JoinColumn(nullable: false)]
    private ?WazeFeed $wazeFeed = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $startedAt;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $finishedAt = null;

    /** RUNNING, SUCCESS, FAILED, PARTIAL */
    #[ORM\Column(length: 20)]
    private string $status = 'RUNNING';

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $httpStatus = null;

    #[ORM\Column]
    private int $alertsReceived = 0;

    #[ORM\Column]
    private int $jamsReceived = 0;

    #[ORM\Column]
    private int $routesReceived = 0;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $payloadHash = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $createdAt;

    public function __construct()
    {
        $this->startedAt = new \DateTime();
        $this->createdAt = new \DateTime();
    }

    public function succeed(int $alerts, int $jams, int $routes, ?string $payloadHash): void
    {
        $this->status = 'SUCCESS';
        $this->finishedAt = new \DateTime();
        $this->alertsReceived = $alerts;
        $this->jamsReceived = $jams;
        $this->routesReceived = $routes;
        $this->payloadHash = $payloadHash;
    }

    public function fail(\Throwable $e): void
    {
        $this->status = 'FAILED';
        $this->finishedAt = new \DateTime();
        $this->errorMessage = substr($e->getMessage(), 0, 500);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getWazeFeed(): ?WazeFeed
    {
        return $this->wazeFeed;
    }

    public function setWazeFeed(?WazeFeed $wazeFeed): static
    {
        $this->wazeFeed = $wazeFeed;
        return $this;
    }

    public function getStartedAt(): \DateTimeInterface
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): ?\DateTimeInterface
    {
        return $this->finishedAt;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getHttpStatus(): ?int
    {
        return $this->httpStatus;
    }

    public function setHttpStatus(?int $httpStatus): static
    {
        $this->httpStatus = $httpStatus;
        return $this;
    }

    public function getAlertsReceived(): int
    {
        return $this->alertsReceived;
    }

    public function getJamsReceived(): int
    {
        return $this->jamsReceived;
    }

    public function getRoutesReceived(): int
    {
        return $this->routesReceived;
    }

    public function getPayloadHash(): ?string
    {
        return $this->payloadHash;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }
}
