<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Partner;
use App\Entity\PartnerFeedEvent;
use App\Repository\PartnerFeedEventRepository;
use App\Repository\PartnerRepository;
use App\Service\PartnerFeedService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/partner-feed', name: 'partner_feed_')]
final class PartnerFeedController extends AbstractController
{
    public function __construct(
        private readonly PartnerRepository $partnerRepository,
        private readonly PartnerFeedEventRepository $eventRepository,
        private readonly PartnerFeedService $feedService,
        private readonly EntityManagerInterface $em,
        private readonly Connection $connection,
    ) {}

    #[Route('/{code}.json', name: 'json', methods: ['GET'])]
    public function jsonFeed(string $code): JsonResponse
    {
        $partner = $this->getPartnerOr404($code);
        $json = $this->feedService->buildJson($partner);
        return new JsonResponse(json_decode($json, true, 512, JSON_THROW_ON_ERROR), Response::HTTP_OK, [
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }

    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('partner_feed/index.html.twig', [
            'partners' => $this->partnerRepository->findBy(['isActive' => true], ['name' => 'ASC']),
        ]);
    }

    #[Route('/{code}', name: 'show', methods: ['GET'])]
    public function show(string $code): Response
    {
        $partner = $this->getPartnerOr404($code);
        return $this->render('partner_feed/show.html.twig', [
            'partner' => $partner,
            'feedJson' => $this->feedService->buildJson($partner),
            'incidents' => $this->feedService->buildFeed($partner)['incidents'],
        ]);
    }

    #[Route('/{code}/events', name: 'event_list', methods: ['GET'])]
    #[IsGranted('ROLE_PARTNER_ADMIN')]
    public function eventList(string $code, Request $request): Response
    {
        $partner = $this->getPartnerOr404($code);
        $this->assertCanManagePartner($partner);
        $page = max(1, (int) $request->query->get('page', 1));
        $total = $this->eventRepository->countByPartner($partner);
        return $this->render('partner_feed/events/list.html.twig', [
            'partner' => $partner,
            'events' => $this->eventRepository->findByPartnerPaginated($partner, $page),
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / 25)),
            'usersById' => [],
        ]);
    }

    #[Route('/{code}/events/new', name: 'event_new', methods: ['GET'])]
    #[IsGranted('ROLE_PARTNER_ADMIN')]
    public function eventNew(string $code): Response
    {
        $partner = $this->getPartnerOr404($code);
        $this->assertCanManagePartner($partner);
        return $this->render('partner_feed/events/form.html.twig', [
            'partner' => $partner,
            'event' => null,
            'types' => PartnerFeedEvent::TYPES,
            'subtypes' => PartnerFeedEvent::SUBTYPES,
        ]);
    }

    #[Route('/{code}/events', name: 'event_create', methods: ['POST'])]
    #[IsGranted('ROLE_PARTNER_ADMIN')]
    public function eventCreate(string $code, Request $request): Response
    {
        $partner = $this->getPartnerOr404($code);
        $this->assertCanManagePartner($partner);
        if (!$this->isCsrfTokenValid('partner_feed_event_create_' . $partner->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }
        $event = new PartnerFeedEvent();
        $event->setPartner($partner);
        $user = $this->getUser();
        if ($user !== null && method_exists($user, 'getId')) {
            $event->setCreatedByUserId($user->getId());
        }
        $this->hydrateFromRequest($event, $request);
        $this->em->persist($event);
        $this->em->flush();
        $this->addFlash('success', sprintf('Evento #%s criado.', $event->getUuid() ?? $event->getId()));
        return $this->redirectToRoute('partner_feed_event_list', ['code' => $code]);
    }

    #[Route('/{code}/events/{id}/edit', name: 'event_edit', methods: ['GET'])]
    #[IsGranted('ROLE_PARTNER_ADMIN')]
    public function eventEdit(string $code, int $id): Response
    {
        $partner = $this->getPartnerOr404($code);
        $this->assertCanManagePartner($partner);
        return $this->render('partner_feed/events/form.html.twig', [
            'partner' => $partner,
            'event' => $this->getEventOr404($id, $partner),
            'types' => PartnerFeedEvent::TYPES,
            'subtypes' => PartnerFeedEvent::SUBTYPES,
        ]);
    }

    #[Route('/{code}/events/{id}/edit', name: 'event_update', methods: ['POST'])]
    #[IsGranted('ROLE_PARTNER_ADMIN')]
    public function eventUpdate(string $code, int $id, Request $request): Response
    {
        $partner = $this->getPartnerOr404($code);
        $this->assertCanManagePartner($partner);
        if (!$this->isCsrfTokenValid('partner_feed_event_update_' . $id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }
        $event = $this->getEventOr404($id, $partner);
        $this->hydrateFromRequest($event, $request);
        $user = $this->getUser();
        if ($user !== null && method_exists($user, 'getId')) {
            $event->setUpdatedByUserId($user->getId());
        }
        $this->em->flush();
        $this->addFlash('success', 'Evento atualizado.');
        return $this->redirectToRoute('partner_feed_event_list', ['code' => $code]);
    }

    #[Route('/{code}/events/{id}/toggle', name: 'event_toggle', methods: ['POST'])]
    #[IsGranted('ROLE_PARTNER_ADMIN')]
    public function eventToggle(string $code, int $id, Request $request): Response
    {
        $partner = $this->getPartnerOr404($code);
        $this->assertCanManagePartner($partner);
        if (!$this->isCsrfTokenValid('toggle_event_' . $id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }
        $event = $this->getEventOr404($id, $partner);
        $event->setIsActive(!$event->isActive());
        $user = $this->getUser();
        if ($user !== null && method_exists($user, 'getId')) {
            $event->setUpdatedByUserId($user->getId());
        }
        $this->em->flush();
        return $this->redirectToRoute('partner_feed_event_list', ['code' => $code]);
    }

    #[Route('/{code}/events/{id}/delete', name: 'event_delete', methods: ['POST'])]
    #[IsGranted('ROLE_PARTNER_ADMIN')]
    public function eventDelete(string $code, int $id, Request $request): Response
    {
        $partner = $this->getPartnerOr404($code);
        $this->assertCanManagePartner($partner);
        if (!$this->isCsrfTokenValid('delete_event_' . $id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }
        $event = $this->getEventOr404($id, $partner);
        $this->em->remove($event);
        $this->em->flush();
        return $this->redirectToRoute('partner_feed_event_list', ['code' => $code]);
    }

    private function getPartnerOr404(string $code): Partner
    {
        $partner = $this->partnerRepository->findOneBy(['code' => $code, 'isActive' => true]);
        if ($partner === null) throw $this->createNotFoundException(sprintf("Parceiro '%s' não encontrado.", $code));
        return $partner;
    }

    private function getEventOr404(int $id, Partner $partner): PartnerFeedEvent
    {
        $event = $this->eventRepository->find($id);
        if ($event === null || $event->getPartner()?->getId() !== $partner->getId()) throw $this->createNotFoundException('Evento não encontrado.');
        return $event;
    }

    private function assertCanManagePartner(Partner $partner): void
    {
        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User || $user->getId() === null) {
            throw $this->createAccessDeniedException();
        }
        $partnerId = $this->connection->fetchOne('SELECT partner_id FROM user WHERE id = ?', [$user->getId()]);
        $roles = $user->getRoles();
        $isGlobal = ($partnerId === false || $partnerId === null)
            && (in_array('ROLE_ADMIN', $roles, true) || in_array('ROLE_SUPER_ADMIN', $roles, true));
        if (!$isGlobal && (int) $partnerId !== $partner->getId()) {
            throw $this->createAccessDeniedException('Você só pode administrar eventos do parceiro vinculado à sua conta.');
        }
    }

    private function hydrateFromRequest(PartnerFeedEvent $event, Request $request): void
    {
        $r = $request->request;
        if ($r->has('cifsType') && $r->get('cifsType') !== '') $event->setCifsType((string) $r->get('cifsType'));
        if ($r->has('cifsSubtype')) $event->setCifsSubtype($r->get('cifsSubtype') !== '' ? (string) $r->get('cifsSubtype') : null);
        if ($r->has('street')) $event->setStreet((string) $r->get('street'));
        if ($r->has('reference')) $event->setReference($r->get('reference') !== '' ? (string) $r->get('reference') : null);
        if ($r->has('description')) $event->setDescription($r->get('description') !== '' ? (string) $r->get('description') : null);
        if ($r->has('city')) $event->setCity($r->get('city') !== '' ? (string) $r->get('city') : null);
        if ($r->has('polyline')) {
            $polyline = json_decode((string) $r->get('polyline'), true);
            if (is_array($polyline)) $event->setPolyline($polyline);
        }
        if ($r->has('direction') && $r->get('direction') !== '') $event->setDirection((string) $r->get('direction'));
        if ($r->has('startTime') && $r->get('startTime') !== '') $event->setStartTime(new \DateTimeImmutable((string) $r->get('startTime')));
        if ($r->has('endTime')) $event->setEndTime($r->get('endTime') !== '' ? new \DateTimeImmutable((string) $r->get('endTime')) : null);
        if ($r->has('isActive')) $event->setIsActive(filter_var($r->get('isActive'), FILTER_VALIDATE_BOOLEAN));
    }
}
