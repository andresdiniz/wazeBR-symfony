<?php

namespace App\Entity;

use App\Repository\WazeTvtRouteDefinitionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WazeTvtRouteDefinitionRepository::class)]
#[ORM\Table(name: 'waze_tvt_route_definition')]
class WazeTvtRouteDefinition
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: WazeTvtRoute::class, inversedBy: 'definitions')]
    #[ORM\JoinColumn(nullable: false)]
    private ?WazeTvtRoute $wazeTvtRoute = null;

    #[ORM\Column]
    private int $versionNumber = 1;

    #[ORM\Column(length: 64)]
    private string $definitionHash = '';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $originName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $destinationName = null;

    #[ORM\Column(nullable: true)]
    private ?int $distanceMeters = null;

    #[ORM\Column(type: Types::JSON)]
    private array $geometry = [];

    #[ORM\Column(length: 64)]
    private string $geometryHash = '';

    #[ORM\Column(nullable: true)]
    private ?int $segmentCount = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $metadata = null;

    #[ORM\Column]
    private bool $isCurrent = true;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $validFrom;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $validUntil = null;

    public function __construct()
    {
        $this->validFrom = new \DateTime();
    }

    public function getId(): ?int { return $this->id; }

    public function getWazeTvtRoute(): ?WazeTvtRoute { return $this->wazeTvtRoute; }
    public function setWazeTvtRoute(?WazeTvtRoute $wazeTvtRoute): static { $this->wazeTvtRoute = $wazeTvtRoute; return $this; }

    public function getVersionNumber(): int { return $this->versionNumber; }
    public function setVersionNumber(int $versionNumber): static { $this->versionNumber = $versionNumber; return $this; }

    public function getDefinitionHash(): string { return $this->definitionHash; }
    public function setDefinitionHash(string $definitionHash): static { $this->definitionHash = $definitionHash; return $this; }

    public function getName(): ?string { return $this->name; }
    public function setName(?string $name): static { $this->name = $name; return $this; }

    public function getOriginName(): ?string { return $this->originName; }
    public function setOriginName(?string $originName): static { $this->originName = $originName; return $this; }

    public function getDestinationName(): ?string { return $this->destinationName; }
    public function setDestinationName(?string $destinationName): static { $this->destinationName = $destinationName; return $this; }

    public function getDistanceMeters(): ?int { return $this->distanceMeters; }
    public function setDistanceMeters(?int $distanceMeters): static { $this->distanceMeters = $distanceMeters; return $this; }

    public function getGeometry(): array { return $this->geometry; }
    public function setGeometry(array $geometry): static { $this->geometry = $geometry; return $this; }

    public function getGeometryHash(): string { return $this->geometryHash; }
    public function setGeometryHash(string $geometryHash): static { $this->geometryHash = $geometryHash; return $this; }

    public function getSegmentCount(): ?int { return $this->segmentCount; }
    public function setSegmentCount(?int $segmentCount): static { $this->segmentCount = $segmentCount; return $this; }

    public function getMetadata(): ?array { return $this->metadata; }
    public function setMetadata(?array $metadata): static { $this->metadata = $metadata; return $this; }

    public function isCurrent(): bool { return $this->isCurrent; }
    public function setIsCurrent(bool $isCurrent): static { $this->isCurrent = $isCurrent; return $this; }

    public function getValidFrom(): \DateTimeInterface { return $this->validFrom; }
    public function setValidFrom(\DateTimeInterface $validFrom): static { $this->validFrom = $validFrom; return $this; }

    public function getValidUntil(): ?\DateTimeInterface { return $this->validUntil; }
    public function setValidUntil(?\DateTimeInterface $validUntil): static { $this->validUntil = $validUntil; return $this; }
}
