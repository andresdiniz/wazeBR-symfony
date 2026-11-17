<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Entity\UserNotificationPreference;
use App\Entity\UserNotificationSubscription;
use App\Service\Notification\NotificationCatalog;
use App\Service\Notification\NotificationPreferenceService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Página em que o próprio usuário escolhe quais e-mails quer receber.
 * Cada usuário só enxerga e altera as PRÓPRIAS preferências.
 */
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class NotificationPreferenceController extends AbstractController
{
    private const CSRF_ID = 'account_notifications';

    public function __construct(
        private readonly NotificationPreferenceService $preferences,
        private readonly NotificationCatalog $catalog,
    ) {
    }

    #[Route('/account/notifications', name: 'account_notifications', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Token CSRF inválido.');
            }

            $this->preferences->save($user, [
                'email_enabled' => $request->request->has('email_enabled'),
                'alerts_enabled' => $request->request->has('alerts_enabled'),
                'events_enabled' => $request->request->has('events_enabled'),
                'briefing_enabled' => $request->request->has('briefing_enabled'),
                'rules' => $this->postedRules($request),
            ]);
            $this->addFlash('success', 'Preferências de notificação salvas.');

            return $this->redirectToRoute('account_notifications');
        }

        $preference = $this->preferences->getOrCreate($user);

        return $this->render('account/notifications.html.twig', [
            'preference' => $preference,
            'email' => $user->getEmail(),
            'groups' => [
                UserNotificationSubscription::CATEGORY_ALERT => $this->buildGroup($preference, UserNotificationSubscription::CATEGORY_ALERT),
                UserNotificationSubscription::CATEGORY_EVENT => $this->buildGroup($preference, UserNotificationSubscription::CATEGORY_EVENT),
            ],
            'csrf_id' => self::CSRF_ID,
        ]);
    }

    /**
     * @return array<string, list<string>>
     */
    private function postedRules(Request $request): array
    {
        $posted = $request->request->all()['rules'] ?? [];
        if (!\is_array($posted)) {
            return [];
        }

        $rules = [];
        foreach ([UserNotificationSubscription::CATEGORY_ALERT, UserNotificationSubscription::CATEGORY_EVENT] as $category) {
            $values = $posted[$category] ?? [];
            $rules[$category] = \is_array($values)
                ? array_values(array_filter($values, '\is_string'))
                : [];
        }

        return $rules;
    }

    /**
     * Modelo de exibição de uma categoria: tipos, subtipos e o que está marcado.
     *
     * @return list<array{code: string, label: string, all: bool, subtypes: list<array{code: string, label: string, checked: bool}>}>
     */
    private function buildGroup(UserNotificationPreference $preference, string $category): array
    {
        $rules = $this->preferences->effectiveRules($preference, $category);
        $group = [];

        foreach ($this->catalog->types($category) as $type => $subtypes) {
            $selected = $rules[$type] ?? [];
            $all = \in_array(UserNotificationSubscription::WILDCARD, $selected, true);

            $items = [];
            foreach ($subtypes as $subtype) {
                $items[] = [
                    'code' => $subtype,
                    'label' => $this->catalog->label($subtype),
                    'checked' => $all || \in_array($subtype, $selected, true),
                ];
            }

            $group[] = [
                'code' => $type,
                'label' => $this->catalog->label($type),
                // Tipo sem subtipos: marcado se houver qualquer regra para ele.
                'all' => $all || ($subtypes === [] && $selected !== []),
                'subtypes' => $items,
            ];
        }

        return $group;
    }
}
