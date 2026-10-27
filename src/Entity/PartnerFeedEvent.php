<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PartnerFeedEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: PartnerFeedEventRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'partner_feed_event')]
class PartnerFeedEvent
{
    public const TYPES = [
        'ROAD_CLOSED' => 'Via Fechada', 'ACCIDENT' => 'Acidente', 'HAZARD' => 'Perigo',
        'POLICE' => 'Polícia', 'CHIT_CHAT' => 'Conversa', 'JAM' => 'Congestionamento',
    ];

    public const SUBTYPES = [
        'ACCIDENT_MINOR' => 'Acidente leve', 'ACCIDENT_MAJOR' => 'Acidente grave',
        'HAZARD_ON_ROAD' => 'Obstáculo na via', 'HAZARD_ON_ROAD_CAR_STOPPED' => 'Veículo parado na via',
        'HAZARD_ON_ROAD_CONSTRUCTION' => 'Obra na via', 'HAZARD_ON_ROAD_EMERGENCY_VEHICLE' => 'Veículo de emergência na via',
        'HAZARD_ON_ROAD_ICE' => 'Gelo na via', 'HAZARD_ON_ROAD_LANE_CLOSED' => 'Faixa fechada na via',
        'HAZARD_ON_ROAD_OBJECT' => 'Objeto na via', 'HAZARD_ON_ROAD_OIL' => 'Óleo na via',
        'HAZARD_ON_ROAD_POT_HOLE' => 'Buraco na via', 'HAZARD_ON_ROAD_ROAD_KILL' => 'Atropelamento na via',
        'HAZARD_ON_ROAD_TRAFFIC_LIGHT_FAULT' => 'Falha no semáforo', 'HAZARD_ON_SHOULDER' => 'Obstáculo no acostamento',
        'HAZARD_ON_SHOULDER_ANIMALS' => 'Animais no acostamento', 'HAZARD_ON_SHOULDER_CAR_STOPPED' => 'Veículo parado no acostamento',
        'HAZARD_ON_SHOULDER_MISSING_SIGN' => 'Placa faltando no acostamento', 'HAZARD_WEATHER' => 'Condição climática',
        'HAZARD_WEATHER_FLOOD' => 'Inundação', 'HAZARD_WEATHER_FOG' => 'Neblina',
        'HAZARD_WEATHER_FREEZING_RAIN' => 'Chuva congelante', 'HAZARD_WEATHER_HAIL' => 'Chuva de granizo',
        'HAZARD_WEATHER_HEAT_WAVE' => 'Onda de calor', 'HAZARD_WEATHER_HEAVY_RAIN' => 'Chuva forte',
        'HAZARD_WEATHER_HEAVY_SNOW' => 'Neve forte', 'HAZARD_WEATHER_HURRICANE' => 'Furacão',
        'HAZARD_WEATHER_MONSOON' => 'Monção', 'HAZARD_WEATHER_TORNADO' => 'Tornado',
        'ROAD_CLOSED_HAZARD' => 'Fechado por perigo', 'ROAD_CLOSED_CONSTRUCTION' => 'Fechado por obra',
        'ROAD_CLOSED_EVENT' => 'Fechado por evento', 'JAM_LIGHT_TRAFFIC' => 'Tráfego leve',
        'JAM_MODERATE_TRAFFIC' => 'Tráfego moderado', 'JAM_HEAVY_TRAFFIC' => 'Tráfego intenso',
        'JAM_STAND_STILL_TRAFFIC' => 'Tráfego parado', 'POLICE_VISIBLE' => 'Polícia visível',
        'POLICE_HIDING' => 'Polícia escondida', 'POLICE_WITH_MOBILE_CAMERA' => 'Polícia com câmera móvel',
    ];

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 36, unique: true)]
    private ?string $uuid = null;

    #[ORM\ManyToOne(inversedBy: 'partnerFeedEvents')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Partner $partner = null;

    #[ORM\Column(type: Types::STRING, length: 50)]
    private ?string $cifsType = null;

    #[ORM\Column(type: Types::STRING, length: 100, nullable: true)]
    private ?string $cifsSubtype = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private ?string $street = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $reference = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::STRING, length: 100, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private array $polyline = [];

    #[ORM\Column(type: Types::STRING, length: 50, options: ['default' => 'BOTH_DIRECTIONS'])]
    private string $direction = 'BOTH_DIRECTIONS';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeInterface $startTime = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeInterface $endTime = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeInterface $creationTime = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeInterface $updateTime = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $deactivatedReason = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $createdByUserId = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $updatedByUserId = null;

    public function __construct()
    {
        $now = new \DateTimeImmutable();
        $this->uuid = Uuid::v4()->toRfc4122();
        $this->creationTime = $now;
        $this->updateTime = $now;
        $this->startTime = $now;
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updateTime = new \DateTimeImmutable();
    }

    public static function getSubtypesForType(string $type): array
    {
        return array_filter(self::SUBTYPES, fn (string $key): bool => str_starts_with($key, $type . '_'), ARRAY_FILTER_USE_KEY);
    }

    public static function isValidSubtype(string $type, ?string $subtype): bool
    {
        return $subtype === null || $subtype === '' || str_starts_with($subtype, $type . '_');
    }

    public function getId(): ?int { return $this->id; }
    public function getUuid(): ?string { return $this->uuid; }
    public function setUuid(string $uuid): self { $this->uuid = $uuid; return $this; }
    public function getPartner(): ?Partner { return $this->partner; }
    public function setPartner(?Partner $partner): self { $this->partner = $partner; return $this; }
    public function getCifsType(): ?string { return $this->cifsType; }
    public function setCifsType(string $value): self { $this->cifsType = $value; return $this; }
    public function getCifsSubtype(): ?string { return $this->cifsSubtype; }
    public function setCifsSubtype(?string $value): self { $this->cifsSubtype = $value; return $this; }
    public function getStreet(): ?string { return $this->street; }
    public function setStreet(string $value): self { $this->street = $value; return $this; }
    public function getReference(): ?string { return $this->reference; }
    public function setReference(?string $value): self { $this->reference = $value; return $this; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $value): self { $this->description = $value; return $this; }
    public function getCity(): ?string { return $this->city; }
    public function setCity(?string $value): self { $this->city = $value; return $this; }
    public function getPolyline(): array { return $this->polyline; }
    public function setPolyline(array $value): self { $this->polyline = $value; return $this; }
    public function getDirection(): string { return $this->direction; }
    public function setDirection(string $value): self { $this->direction = $value; return $this; }
    public function getStartTime(): ?\DateTimeInterface { return $this->startTime; }
    public function setStartTime(?\DateTimeInterface $value): self { $this->startTime = $value; return $this; }
    public function getEndTime(): ?\DateTimeInterface { return $this->endTime; }
    public function setEndTime(?\DateTimeInterface $value): self { $this->endTime = $value; return $this; }
    public function getCreationTime(): ?\DateTimeInterface { return $this->creationTime; }
    public function setCreationTime(?\DateTimeInterface $value): self { $this->creationTime = $value; return $this; }
    public function getUpdateTime(): ?\DateTimeInterface { return $this->updateTime; }
    public function setUpdateTime(?\DateTimeInterface $value): self { $this->updateTime = $value; return $this; }
    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $value): self { $this->isActive = $value; return $this; }
    public function getDeactivatedReason(): ?string { return $this->deactivatedReason; }
    public function setDeactivatedReason(?string $value): self { $this->deactivatedReason = $value; return $this; }
    public function getCreatedByUserId(): ?int { return $this->createdByUserId; }
    public function setCreatedByUserId(?int $value): self { $this->createdByUserId = $value; return $this; }
    public function getUpdatedByUserId(): ?int { return $this->updatedByUserId; }
    public function setUpdatedByUserId(?int $value): self { $this->updatedByUserId = $value; return $this; }

    public function toCifsPolyline(): string
    {
        $parts = [];
        foreach ($this->polyline as $point) {
            if (is_array($point) && isset($point[0], $point[1])) {
                $parts[] = sprintf('%.6f %.6f', (float) $point[0], (float) $point[1]);
            }
        }
        return implode(' ', $parts);
    }

    public function toCifsArray(): array
    {
        $format = static fn (?\DateTimeInterface $date): ?string => $date?->format('Y-m-d\TH:i:sP');
        return [
            'id' => $this->uuid, 'creationtime' => $format($this->creationTime),
            'updatetime' => $format($this->updateTime), 'type' => $this->cifsType,
            'subtype' => $this->cifsSubtype, 'street' => $this->street,
            'description' => $this->description, 'city' => $this->city,
            'polyline' => $this->toCifsPolyline(), 'direction' => $this->direction,
            'starttime' => $format($this->startTime), 'endtime' => $format($this->endTime),
            'reference' => $this->reference,
        ];
    }
}