<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\NotificationRepository;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/notificacoes', name: 'notification_')]
#[IsGranted('ROLE_USER')]
class NotificationController extends AbstractController
{
    public function __construct(
        private readonly TenantContext          $tenantContext,
        private readonly NotificationRepository $notifRepo,
    ) {}

    #[Route('', name: 'index')]
    public function index(Request $request): Response
    {
        $partner = $this->tenantContext->requirePartner();
        $page    = max(1, (int) $request->query->get('page', 1));

        return $this->render('notification/index.html.twig', [
            'partner'       => $partner,
            'notifications' => $this->notifRepo->findByPartner($partner, $page),
            'page'          => $page,
        ]);
    }

    /** API: retorna contagem de não lidas para o badge do header */
    #[Route('/api/unread-count', name: 'api_unread_count', methods: ['GET'])]
    public function unreadCount(): JsonResponse
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        return $this->json(['count' => $this->notifRepo->countUnreadByUser($user)]);
    }

    /** API: marca todas as não lidas do usuário como lidas */
    #[Route('/api/mark-all-read', name: 'api_mark_all_read', methods: ['POST'])]
    public function markAllRead(): JsonResponse
    {
        /** @var \App\Entity\User $user */
        $user  = $this->getUser();
        $notifs = $this->notifRepo->findUnreadByUser($user, limit: 500);

        foreach ($notifs as $n) {
            $n->markAsRead();
        }

        $this->notifRepo->getEntityManager()->flush();

        return $this->json(['marked' => count($notifs)]);
    }

    /** API: lista notificações não lidas para o dropdown do header */
    #[Route('/api/unread', name: 'api_unread', methods: ['GET'])]
    public function unread(): JsonResponse
    {
        /** @var \App\Entity\User $user */
        $user  = $this->getUser();
        $notifs = $this->notifRepo->findUnreadByUser($user, limit: 10);

        return $this->json(array_map(fn ($n) => [
            'id'        => $n->getId(),
            'type'      => $n->getType(),
            'title'     => $n->getTitle(),
            'body'      => $n->getBody(),
            'createdAt' => $n->getCreatedAt()->format('c'),
        ], $notifs));
    }
}
