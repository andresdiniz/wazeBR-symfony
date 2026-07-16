<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PartnerRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PartnerRepository::class)]
#[ORM\Table(name: 'partners')]
class Partner
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 80, unique: true)]
    private string $slug = '';

    #[ORM\Column(length: 120)]
    private string $name = '';

    #[ORM\Column(length: 180, unique: true)]
    private string $email = '';

    #[ORM\Column(length: 64, unique: true)]
    private string $apiToken = '';

    /** Bounding box geográfica: "lat_min,lng_min,lat_max,lng_max" */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $bbox = null;

    /** Estados CEMADEN monitorados, ex: ["MG","SP"] */
    #[ORM\Column(type: 'json')]
    private array $cemadenStates = [];

    #[ORM\Column]
    private bool $isActive = true;

    /**
     * Intervalo em minutos entre coletas para este parceiro.
     * NULL = usa o padrão global do sistema (definido em WazeFeedSchedule).
     * Valores permitidos: 5, 10, 15, 30, 60.
     */
    #[ORM\Column(nullable: true)]
    private ?int $refreshIntervalMinutes = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\OneToMany(targetEntity: User::class, mappedBy: 'partner')]
    private Collection $users;

    #[ORM\OneToMany(targetEntity: WazeAlert::class, mappedBy: 'partner')]
    private Collection $alerts;

    #[ORM\OneToMany(targetEntity: WazeTrafficJam::class, mappedBy: 'partner')]
    private Collection $trafficJams;

    #[ORM\OneToMany(targetEntity: CemadenData::class, mappedBy: 'partner')]
    private Collection $cemadenData;

    #[ORM\OneToMany(targetEntity: WazeRoute::class, mappedBy: 'partner')]
    private Collection $routes;

    #[ORM\OneToMany(targetEntity: MonitoredCity::class, mappedBy: 'partner')]
    private Collection $cities;

    #[ORM\OneToMany(targetEntity: MonitoredLink::class, mappedBy: 'partner')]
    private Collection $links;

    public function __construct()
    {
        $this->createdAt   = new \DateTimeImmutable();
        $this->users       = new ArrayCollection();
        $this->alerts      = new ArrayCollection();
        $this->trafficJams = new ArrayCollection();
        $this->cemadenData = new ArrayCollection();
        $this->routes      = new ArrayCollection();
        $this->cities      = new ArrayCollection();
        $this->links       = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getSlug(): string { return $this->slug; }
    public function setSlug(string $s): static { $this->slug = $s; return $this; }
    public function getName(): string { return $this->name; }
    public function setName(string $n): static { $this->name = $n; return $this; }
    public function getEmail(): string { return $this->email; }
    public function setEmail(string $e): static { $this->email = $e; return $this; }
    public function getApiToken(): string { return $this->apiToken; }
    public function setApiToken(string $t): static { $this->apiToken = $t; return $this; }
    public function getBbox(): ?string { return $this->bbox; }
    public function setBbox(?string $b): static { $this->bbox = $b; return $this; }
    public function getCemadenStates(): array { return $this->cemadenStates; }
    public function setCemadenStates(array $s): static { $this->cemadenStates = $s; return $this; }
    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $v): static { $this->isActive = $v; return $this; }
    public function getRefreshIntervalMinutes(): ?int { return $this->refreshIntervalMinutes; }
    public function setRefreshIntervalMinutes(?int $v): static { $this->refreshIntervalMinutes = $v; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUsers(): Collection { return $this->users; }
    public function getAlerts(): Collection { return $this->alerts; }
    public function getTrafficJams(): Collection { return $this->trafficJams; }
    public function getCemadenData(): Collection { return $this->cemadenData; }
    public function getRoutes(): Collection { return $this->routes; }
    public function getCities(): Collection { return $this->cities; }
    public function getLinks(): Collection { return $this->links; }

    public function generateApiToken(): static
    {
        $this->apiToken = bin2hex(random_bytes(32));
        return $this;
    }
}
