<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\PartnerFeedEvent;
use App\Repository\PartnerFeedEventRepository;
use App\Repository\PartnerRepository;
use App\Service\PartnerFeedService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Endpoints do Partner Feed.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  /partner-feed/{code}/feed.json  →  PUBLIC (Waze busca aqui)           │
 * │  /partner-feed/**                →  ROLE_EDITOR  (admin / editor)      │
 * │                                    ROLE_OPERATOR não tem acesso        │
 * └─────────────────────────────────────────────────────────────────────────┘
 */
#[Route('/partner-feed')]
class PartnerFeedController extends AbstractController
{
    public function __construct(
        private readonly PartnerRepository          $partnerRepository,
        private readonly PartnerFeedEventRepository $eventRepository,
        private readonly PartnerFeedService         $feedService,
        private readonly EntityManagerInterface     $em,
    ) {}

    // ── Feed público ──────────────────────────────────────────────────────────

    /**
     * URL registrada no Waze Partner Hub.
     * GET /partner-feed/{code}/feed.json
     */
    #[Route('/{code}/feed.json', name: 'partner_feed_json', methods: ['GET'])]
    public function feedJson(string $code): JsonResponse
    {
        $partner = $this->partnerRepository->findOneBy(['code' => $code, 'isActive' => true]);

        if ($partner === null) {
            return new JsonResponse(['incidents' => []], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse(
            $this->feedService->buildFeed($partner),
            Response::HTTP_OK,
            [
                'Cache-Control' => 'no-store, no-cache, must-revalidate',
                'Pragma'        => 'no-cache',
            ],
        );
    }

    // ── Painel — somente ROLE_EDITOR / ROLE_ADMIN ────────────────────────────

    /**
     * Lista todos os parceiros ativos com resumo do feed.
     * GET /partner-feed/
     */
    #[Route('/', name: 'partner_feed_index', methods: ['GET'])]
    #[IsGranted('ROLE_EDITOR')]
    public function index(): Response
    {
        $partners = $this->partnerRepository->findBy(['isActive' => true], ['name' => 'ASC']);

        return $this->render('partner_feed/index.html.twig', [
            'partners' => $partners,
        ]);
    }

    /**
     * Painel de um parceiro: prévia do feed + link para eventos.
     * GET /partner-feed/{code}
     */
    #[Route('/{code}', name: 'partner_feed_show', methods: ['GET'])]
    #[IsGranted('ROLE_EDITOR')]
    public function show(string $code): Response
    {
        $partner = $this->getPartnerOr404($code);
        $feed    = $this->feedService->buildFeed($partner);

        return $this->render('partner_feed/show.html.twig', [
            'partner'   => $partner,
            'feedJson'  => json_encode($feed, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            'incidents' => $feed['incidents'],
        ]);
    }

    // ── Eventos ───────────────────────────────────────────────────────────────

    /**
     * Lista paginada de eventos de um parceiro.
     * GET /partner-feed/{code}/events
     */
    #[Route('/{code}/events', name: 'partner_event_list', methods: ['GET'])]
    #[IsGranted('ROLE_EDITOR')]
    public function eventList(string $code, Request $request): Response
    {
        $partner = $this->getPartnerOr404($code);
        $page    = max(1, (int) $request->query->get('page', 1));

        return $this->render('partner_feed/events/list.html.twig', [
            'partner' => $partner,
            'events'  => $this->eventRepository->findByPartnerPaginated($partner, $page),
            'total'   => $this->eventRepository->countByPartner($partner),
            'page'    => $page,
            'pages'   => (int) ceil($this->eventRepository->countByPartner($partner) / 25),
        ]);
    }

    /**
     * Formulário de criação.
     * GET /partner-feed/{code}/events/new
     */
    #[Route('/{code}/events/new', name: 'partner_event_new', methods: ['GET'])]
    #[IsGranted('ROLE_EDITOR')]
    public function eventNew(string $code): Response
    {
        return $this->render('partner_feed/events/form.html.twig', [
            'partner'  => $this->getPartnerOr404($code),
            'event'    => null,
            'types'    => PartnerFeedEvent::TYPES,
            'subtypes' => PartnerFeedEvent::SUBTYPES,
        ]);
    }

    /**
     * Criação de evento.
     * POST /partner-feed/{code}/events
     */
    #[Route('/{code}/events', name: 'partner_event_create', methods: ['POST'])]
    #[IsGranted('ROLE_EDITOR')]
    public function eventCreate(string $code, Request $request): Response
    {
        $partner = $this->getPartnerOr404($code);

        $event = new PartnerFeedEvent();
        $event->setPartner($partner);

        $this->hydrateFromRequest($event, $request);

        // Geocodificação reversa automática quando rua não foi digitada
        if (trim($request->request->get('street', '')) === '') {
            $suggested = $this->feedService->suggestStreetName($partner, $event);
            if ($suggested !== null) {
                $event->setStreet($suggested);
            }
        }

        $this->em->persist($event);
        $this->em->flush();

        $this->addFlash('success', "Evento #{$event->getIncidentId()} criado.");

        return $this->redirectToRoute('partner_event_list', ['code' => $code]);
    }

    /**
     * Formulário de edição.
     * GET /partner-feed/{code}/events/{id}/edit
     */
    #[Route('/{code}/events/{id}/edit', name: 'partner_event_edit', methods: ['GET'])]
    #[IsGranted('ROLE_EDITOR')]
    public function eventEdit(string $code, int $id): Response
    {
        $partner = $this->getPartnerOr404($code);

        return $this->render('partner_feed/events/form.html.twig', [
            'partner'  => $partner,
            'event'    => $this->getEventOr404($id, $partner),
            'types'    => PartnerFeedEvent::TYPES,
            'subtypes' => PartnerFeedEvent::SUBTYPES,
        ]);
    }

    /**
     * Atualização de evento.
     * POST /partner-feed/{code}/events/{id}/edit
     */
    #[Route('/{code}/events/{id}/edit', name: 'partner_event_update', methods: ['POST'])]
    #[IsGranted('ROLE_EDITOR')]
    public function eventUpdate(string $code, int $id, Request $request): Response
    {
        $partner = $this->getPartnerOr404($code);
        $event   = $this->getEventOr404($id, $partner);

        $this->hydrateFromRequest($event, $request);
        $event->setUpdatedAt(new \DateTimeImmutable());

        // Re-geocodificar quando lat/lon foi alterado
        if (
            $request->request->get('latitude') !== null
            || $request->request->get('longitude') !== null
        ) {
            $this->feedService->suggestStreetName($partner, $event);
        }

        $this->em->flush();

        $this->addFlash('success', 'Evento atualizado.');

        return $this->redirectToRoute('partner_event_list', ['code' => $code]);
    }

    /**
     * Alterna ativo ↔ inativo.
     * POST /partner-feed/{code}/events/{id}/toggle
     */
    #[Route('/{code}/events/{id}/toggle', name: 'partner_event_toggle', methods: ['POST'])]
    #[IsGranted('ROLE_EDITOR')]
    public function eventToggle(string $code, int $id): Response
    {
        $partner = $this->getPartnerOr404($code);
        $event   = $this->getEventOr404($id, $partner);

        $event->setStatus(
            $event->getStatus() === PartnerFeedEvent::STATUS_ACTIVE
                ? PartnerFeedEvent::STATUS_INACTIVE
                : PartnerFeedEvent::STATUS_ACTIVE,
        );
        $event->setUpdatedAt(new \DateTimeImmutable());
        $this->em->flush();

        $this->addFlash('success', 'Status atualizado.');

        return $this->redirectToRoute('partner_event_list', ['code' => $code]);
    }

    /**
     * Exclusão de evento — somente ADMIN.
     * POST /partner-feed/{code}/events/{id}/delete
     */
    #[Route('/{code}/events/{id}/delete', name: 'partner_event_delete', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function eventDelete(string $code, int $id): Response
    {
        $partner = $this->getPartnerOr404($code);
        $event   = $this->getEventOr404($id, $partner);

        $this->em->remove($event);
        $this->em->flush();

        $this->addFlash('success', 'Evento excluído.');

        return $this->redirectToRoute('partner_event_list', ['code' => $code]);
    }

    /**
     * Geocodificação reversa via AJAX.
     * POST /partner-feed/{code}/events/geocode
     */
    #[Route('/{code}/events/geocode', name: 'partner_event_geocode', methods: ['POST'])]
    #[IsGranted('ROLE_EDITOR')]
    public function eventGeocode(string $code, Request $request): JsonResponse
    {
        $partner = $this->getPartnerOr404($code);

        $lat = (float) $request->request->get('lat', 0);
        $lon = (float) $request->request->get('lon', 0);

        if ($lat === 0.0 || $lon === 0.0) {
            return new JsonResponse(
                ['error' => 'lat e lon são obrigatórios'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        return new JsonResponse([
            'result' => $this->feedService->reverseGeocode($partner, $lat, $lon),
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function getPartnerOr404(string $code): \App\Entity\Partner
    {
        $partner = $this->partnerRepository->findOneBy([
            'code'     => $code,
            'isActive' => true,
        ]);

        if ($partner === null) {
            throw $this->createNotFoundException("Parceiro '{$code}' não encontrado.");
        }

        return $partner;
    }

    private function getEventOr404(int $id, \App\Entity\Partner $partner): PartnerFeedEvent
    {
        $event = $this->eventRepository->find($id);

        if ($event === null || $event->getPartner() !== $partner) {
            throw $this->createNotFoundException('Evento não encontrado.');
        }

        return $event;
    }

    private function hydrateFromRequest(
        PartnerFeedEvent $event,
        Request $request,
    ): void {
        $r = $request->request;

        if ($r->has('type'))      $event->setType($r->get('type'));
        if ($r->has('subtype'))   $event->setSubtype($r->get('subtype') ?: null);
        if ($r->has('polyline'))  $event->setPolyline($r->get('polyline'));
        if ($r->has('street') && $r->get('street') !== '') {
            $event->setStreet($r->get('street'));
        }
        if ($r->has('direction'))  $event->setDirection($r->get('direction'));
        if ($r->has('description')) $event->setDescription($r->get('description') ?: null);
        if ($r->has('status'))     $event->setStatus($r->get('status'));
        if ($r->has('latitude'))   $event->setLatitude($r->get('latitude') ?: null);
        if ($r->has('longitude'))  $event->setLongitude($r->get('longitude') ?: null);

        if ($r->has('starttime') && $r->get('starttime') !== '') {
            $event->setStarttime(new \DateTimeImmutable($r->get('starttime')));
        }

        $event->setEndtime(
            ($r->has('endtime') && $r->get('endtime') !== '')
                ? new \DateTimeImmutable($r->get('endtime'))
                : null,
        );
    }
}
