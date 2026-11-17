<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Entity\User;
use App\Entity\UserNotificationPreference;
use App\Entity\UserNotificationSubscription;
use App\Repository\UserNotificationPreferenceRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Única fonte de verdade sobre "este usuário quer receber este e-mail?".
 * Usuário sem registro de preferências recebe o padrão (ver NotificationCatalog::defaultRules).
 */
final class NotificationPreferenceService
{
    public const CATEGORY_BRIEFING = 'BRIEFING';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserNotificationPreferenceRepository $preferences,
        private readonly NotificationCatalog $catalog,
    ) {
    }

    /** Devolve as preferências do usuário, criando o registro padrão na primeira vez. */
    public function getOrCreate(User $user): UserNotificationPreference
    {
        $preference = $this->preferences->findOneByUser($user);
        if ($preference !== null) {
            return $preference;
        }

        $preference = new UserNotificationPreference($user);
        $preference->replaceRules(
            UserNotificationSubscription::CATEGORY_ALERT,
            $this->catalog->defaultRules(UserNotificationSubscription::CATEGORY_ALERT),
        );
        $this->entityManager->persist($preference);
        $this->entityManager->flush();

        return $preference;
    }

    /**
     * Regras efetivas de uma categoria (as salvas ou, sem registro, o padrão).
     *
     * @return array<string, list<string>>
     */
    public function effectiveRules(?UserNotificationPreference $preference, string $category): array
    {
        return $preference !== null
            ? $preference->rulesFor($category)
            : $this->catalog->defaultRules($category);
    }

    public function wantsAlert(?UserNotificationPreference $preference, ?string $type, ?string $subtype): bool
    {
        return $this->wants($preference, UserNotificationSubscription::CATEGORY_ALERT, $type, $subtype);
    }

    public function wantsEvent(?UserNotificationPreference $preference, ?string $type, ?string $subtype): bool
    {
        return $this->wants($preference, UserNotificationSubscription::CATEGORY_EVENT, $type, $subtype);
    }

    public function wantsBriefing(?UserNotificationPreference $preference): bool
    {
        return $preference === null
            ? true
            : $preference->isEmailEnabled() && $preference->isBriefingEnabled();
    }

    /**
     * Filtra $users deixando só quem quer receber esse alerta/evento.
     *
     * @param list<User> $users
     *
     * @return list<User>
     */
    public function recipientsFor(array $users, string $category, ?string $type, ?string $subtype): array
    {
        $indexed = $this->preferences->findIndexedByUsers($users);
        $recipients = [];
        foreach ($users as $user) {
            $preference = $indexed[$user->getId()] ?? null;
            if ($this->wants($preference, $category, $type, $subtype)) {
                $recipients[] = $user;
            }
        }

        return $recipients;
    }

    /**
     * @param list<User> $users
     *
     * @return list<User>
     */
    public function briefingRecipients(array $users): array
    {
        $indexed = $this->preferences->findIndexedByUsers($users);

        return array_values(array_filter(
            $users,
            fn (User $user): bool => $this->wantsBriefing($indexed[$user->getId()] ?? null),
        ));
    }

    /**
     * Salva o que veio da página de configuração.
     *
     * @param array{
     *     email_enabled?: bool,
     *     alerts_enabled?: bool,
     *     events_enabled?: bool,
     *     briefing_enabled?: bool,
     *     rules?: array<string, list<string>>
     * } $input rules[CATEGORIA] = lista de "TIPO|SUBTIPO" (SUBTIPO pode ser "*")
     */
    public function save(User $user, array $input): UserNotificationPreference
    {
        $preference = $this->getOrCreate($user);
        $preference
            ->setEmailEnabled((bool) ($input['email_enabled'] ?? false))
            ->setAlertsEnabled((bool) ($input['alerts_enabled'] ?? false))
            ->setEventsEnabled((bool) ($input['events_enabled'] ?? false))
            ->setBriefingEnabled((bool) ($input['briefing_enabled'] ?? false));

        foreach ([UserNotificationSubscription::CATEGORY_ALERT, UserNotificationSubscription::CATEGORY_EVENT] as $category) {
            $preference->replaceRules($category, $this->parseRules($category, $input['rules'][$category] ?? []));
        }

        $this->entityManager->flush();

        return $preference;
    }

    /**
     * @param list<mixed> $selected
     *
     * @return array<string, list<string>>
     */
    private function parseRules(string $category, array $selected): array
    {
        $rules = [];
        foreach ($selected as $value) {
            if (!\is_string($value) || !str_contains($value, '|')) {
                continue;
            }
            [$type, $subtype] = explode('|', $value, 2);
            $type = strtoupper(trim($type));
            $subtype = $subtype === UserNotificationSubscription::WILDCARD ? $subtype : strtoupper(trim($subtype));

            if ($this->catalog->isValid($category, $type, $subtype)) {
                $rules[$type][] = $subtype;
            }
        }

        $known = $this->catalog->types($category);
        foreach ($rules as $type => $subtypes) {
            $subtypes = array_values(array_unique($subtypes));
            $all = $known[$type] ?? [];
            $coversAll = \in_array(UserNotificationSubscription::WILDCARD, $subtypes, true)
                || ($all !== [] && array_diff($all, $subtypes) === []);

            $rules[$type] = $coversAll ? [UserNotificationSubscription::WILDCARD] : $subtypes;
        }

        return $rules;
    }

    private function wants(?UserNotificationPreference $preference, string $category, ?string $type, ?string $subtype): bool
    {
        [$type, $subtype] = $this->catalog->normalize($type, $subtype);
        if ($type === '') {
            return false;
        }

        if ($preference !== null) {
            $enabled = $preference->isEmailEnabled() && match ($category) {
                UserNotificationSubscription::CATEGORY_ALERT => $preference->isAlertsEnabled(),
                UserNotificationSubscription::CATEGORY_EVENT => $preference->isEventsEnabled(),
                default => false,
            };
            if (!$enabled) {
                return false;
            }
        } elseif ($category !== UserNotificationSubscription::CATEGORY_ALERT) {
            return false; // eventos só por escolha explícita do usuário
        }

        $subtypes = $this->effectiveRules($preference, $category)[$type] ?? [];

        return \in_array(UserNotificationSubscription::WILDCARD, $subtypes, true)
            || ($subtype !== null && \in_array($subtype, $subtypes, true));
    }
}
