<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Entity\UserNotificationSubscription;
use App\Enum\CifsTypeEnum;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Catálogo de tipos/subtipos que o usuário pode escolher receber por e-mail,
 * normalização de nomes (aliases do feed Waze) e regras padrão.
 */
final class NotificationCatalog
{
    /** Nomes que o feed usa de formas diferentes e que tratamos como um só. */
    private const TYPE_ALIASES = [
        'WEATHERHAZARD' => 'HAZARD',
        'ROADCLOSED' => 'ROAD_CLOSED',
    ];

    private const SUBTYPE_ALIASES = [
        'HAZARD_ON_ROAD_POTHOLE' => 'HAZARD_ON_ROAD_POT_HOLE',
        'HAZARD_ON_ROAD_POTHOLES' => 'HAZARD_ON_ROAD_POT_HOLE',
    ];

    /** Subtipos que o feed de alertas traz além da especificação CIFS. */
    private const ALERT_EXTRA_SUBTYPES = [
        'HAZARD' => [
            'HAZARD_ON_ROAD_CONSTRUCTION',
            'HAZARD_ON_ROAD_EMERGENCY_VEHICLE',
            'HAZARD_ON_ROAD_OIL',
        ],
        'MISC' => [
            'MISC_ILLEGAL_PARKING',
            'MISC_POTHOLE',
        ],
    ];

    /** @var array<string, array<string, list<string>>> */
    private array $cache = [];

    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    /**
     * @return array<string, list<string>> [TIPO => [SUBTIPO, ...]]
     */
    public function types(string $category): array
    {
        if (isset($this->cache[$category])) {
            return $this->cache[$category];
        }

        $types = [];
        foreach (CifsTypeEnum::cases() as $case) {
            if ($category === UserNotificationSubscription::CATEGORY_ALERT && $case === CifsTypeEnum::CHIT_CHAT) {
                continue;
            }
            $types[$case->value] = $case->allowedSubtypes();
        }

        if ($category === UserNotificationSubscription::CATEGORY_ALERT) {
            foreach (self::ALERT_EXTRA_SUBTYPES as $type => $extra) {
                $types[$type] = array_values(array_unique([...($types[$type] ?? []), ...$extra]));
            }
            $types['CONSTRUCTION'] = $types['CONSTRUCTION'] ?? [];
        }

        return $this->cache[$category] = $types;
    }

    /**
     * Regras de quem ainda não configurou nada. Para alertas, reproduz exatamente o filtro
     * que existia fixo no código: acidentes, vias fechadas, perigo na pista/objeto e alagamento.
     * Eventos e briefing não tinham envio por tipo, então eventos começam sem regra.
     *
     * @return array<string, list<string>>
     */
    public function defaultRules(string $category): array
    {
        if ($category === UserNotificationSubscription::CATEGORY_ALERT) {
            return [
                'ACCIDENT' => [UserNotificationSubscription::WILDCARD],
                'ROAD_CLOSED' => [UserNotificationSubscription::WILDCARD],
                'HAZARD' => ['HAZARD_ON_ROAD', 'HAZARD_ON_ROAD_OBJECT', 'HAZARD_WEATHER_FLOOD'],
            ];
        }

        return [];
    }

    /** @return array{0: string, 1: ?string} */
    public function normalize(?string $type, ?string $subtype): array
    {
        $type = strtoupper(trim((string) $type));
        $type = self::TYPE_ALIASES[$type] ?? $type;

        $subtype = strtoupper(trim((string) $subtype));
        $subtype = self::SUBTYPE_ALIASES[$subtype] ?? $subtype;

        return [$type, $subtype === '' ? null : $subtype];
    }

    public function isValid(string $category, string $type, string $subtype): bool
    {
        $types = $this->types($category);
        if (!isset($types[$type])) {
            return false;
        }

        return $subtype === UserNotificationSubscription::WILDCARD || \in_array($subtype, $types[$type], true);
    }

    public function label(string $code): string
    {
        $key = 'code.' . $code;
        $translated = $this->translator->trans($key, [], 'alerts_ui');
        if ($translated !== $key) {
            return $translated;
        }

        return ucfirst(mb_strtolower(str_replace('_', ' ', $code)));
    }
}
