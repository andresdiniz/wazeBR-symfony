<?php

namespace App\Entity;

use App\Repository\WazeCountRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Armazena os dados de contagem de usuários por nível de congestionamento
 * vindos do array "usersOnJams" do feed feeds-tvt.
 *
 * Cada registro representa uma coleta completa (snapshot),
 * armazenando todos os níveis de jamLevel como colunas separadas
 * para facilitar queries analíticas.
 */
#[ORM\Entity(repositoryClass: WazeCountRepository::class)]
#[ORM\Table(name: 'waze_counts')]
class WazeCount
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Partner dono deste snapshot de contagem.
     */
    #[ORM\ManyToOne(targetEntity: Partner::class, inversedBy: 'wazeCounts')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Partner $partner = null;

    /**
     * Timestamp da coleta
     */
    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $collectedAt = null;

    /**
     * Número de usuários em vias com jamLevel 0 (trânsito livre)
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 1, nullable: true)]
    private ?string $wazersLevel0 = null;

    /**
     * Número de usuários em vias com jamLevel 1 (leve)
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 1, nullable: true)]
    private ?string $wazersLevel1 = null;

    /**
     * Número de usuários em vias com jamLevel 2 (moderado)
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 1, nullable: true)]
    private ?string $wazersLevel2 = null;

    /**
     * Número de usuários em vias com jamLevel 3 (intenso)
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 1, nullable: true)]
    private ?string $wazersLevel3 = null;

    /**
     * Número de usuários em vias com jamLevel 4 (parado)
     * Pode ser decimal (ex: 10.5) conforme resposta da API
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 1, nullable: true)]
    private ?string $wazersLevel4 = null;

    /**
     * Soma total de usuários em jams (todos os níveis)
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 1, nullable: true)]
    private ?string $wazersTotal = null;

    /**
     * JSON bruto do array usersOnJams para auditoria
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $rawData = null;

    public function getId(): ?int { return $this->id; }

    public function getPartner(): ?Partner { return $this->partner; }
    public function setPartner(?Partner $partner): static { $this->partner = $partner; return $this; }

    public function getCollectedAt(): ?\DateTimeInterface { return $this->collectedAt; }
    public function setCollectedAt(?\DateTimeInterface $collectedAt): static { $this->collectedAt = $collectedAt; return $this; }

    public function getWazersLevel0(): ?string { return $this->wazersLevel0; }
    public function setWazersLevel0(float|string|null $wazersLevel0): static { $this->wazersLevel0 = $wazersLevel0 !== null ? (string) $wazersLevel0 : null; return $this; }

    public function getWazersLevel1(): ?string { return $this->wazersLevel1; }
    public function setWazersLevel1(float|string|null $wazersLevel1): static { $this->wazersLevel1 = $wazersLevel1 !== null ? (string) $wazersLevel1 : null; return $this; }

    public function getWazersLevel2(): ?string { return $this->wazersLevel2; }
    public function setWazersLevel2(float|string|null $wazersLevel2): static { $this->wazersLevel2 = $wazersLevel2 !== null ? (string) $wazersLevel2 : null; return $this; }

    public function getWazersLevel3(): ?string { return $this->wazersLevel3; }
    public function setWazersLevel3(float|string|null $wazersLevel3): static { $this->wazersLevel3 = $wazersLevel3 !== null ? (string) $wazersLevel3 : null; return $this; }

    public function getWazersLevel4(): ?string { return $this->wazersLevel4; }
    public function setWazersLevel4(float|string|null $wazersLevel4): static { $this->wazersLevel4 = $wazersLevel4 !== null ? (string) $wazersLevel4 : null; return $this; }

    public function getWazersTotal(): ?string { return $this->wazersTotal; }
    public function setWazersTotal(float|string|null $wazersTotal): static { $this->wazersTotal = $wazersTotal !== null ? (string) $wazersTotal : null; return $this; }

    public function getRawData(): ?array { return $this->rawData; }
    public function setRawData(?array $rawData): static { $this->rawData = $rawData; return $this; }

    /**
     * Popula todos os níveis a partir do array usersOnJams da API.
     * Formato esperado: [{"wazersCount": 193, "jamLevel": 0}, ...]
     */
    public function hydrateFromApiArray(array $usersOnJams): static
    {
        $this->rawData = $usersOnJams;
        $total = 0.0;

        foreach ($usersOnJams as $entry) {
            $level = (int) ($entry['jamLevel'] ?? -1);
            $count = (float) ($entry['wazersCount'] ?? 0);
            $total += $count;

            match ($level) {
                0 => $this->setWazersLevel0($count),
                1 => $this->setWazersLevel1($count),
                2 => $this->setWazersLevel2($count),
                3 => $this->setWazersLevel3($count),
                4 => $this->setWazersLevel4($count),
                default => null,
            };
        }

        $this->setWazersTotal($total);

        return $this;
    }
}
