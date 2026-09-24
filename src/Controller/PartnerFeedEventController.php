<?php

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

#[Route('/partner-feed/{slug}/events')]
#[IsGranted('ROLE_EDITOR')]
class PartnerFeedEventController extends AbstractController
{
    public function __construct(
        private readonly PartnerRepository          $partnerRepository,
        private readonly PartnerFeedEventRepository $eventRepository,
        private readonly PartnerFeedService         $feedService,
        private readonly EntityManagerInterface     $em,
    ) {}

    /** GET /partner-feed/{slug}/events */
    #[Route('', name: 'partner_event_list', methods: ['GET'])]
    public function list(string $slug, Request $request): Response
    {
        $partner = $this->getPartnerOr404($slug);
        $page    = max(1, (int) $request->query->get('page', 1));
        $events  = $this->eventRepository->findByPartnerPaginated($slug, $page);
        $total   = $this->eventRepository->countByPartner($slug);

        return $this->render('partner_feed/events/list.html.twig', [
            'partner' => $partner,
            'events'  => $events,
            'page'    => $page,
            'total'   => $total,
            'pages'   => (int) ceil($total / 25),
        ]);
    }

    /** GET /partner-feed/{slug}/events/new */
    #[Route('/new', name: 'partner_event_new', methods: ['GET'])]
    public function new(string $slug): Response
    {
        $partner = $this->getPartnerOr404($slug);

        return $this->render('partner_feed/events/form.html.twig', [
            'partner' => $partner,
            'event'   => null,
            'types'   => PartnerFeedEvent::TYPES,
            'subtypes' => PartnerFeedEvent::SUBTYPES,
        ]);
    }

    /** POST /partner-feed/{slug}/events */
    #[Route('', name: 'partner_event_create', methods: ['POST'])]
    public function create(string $slug, Request $request): Response
    {
        $partner = $this->getPartnerOr404($slug);

        $event = new PartnerFeedEvent();
        $event->setPartnerId($slug);

        $this->hydrateFromRequest($event, $request);

        // Geocodificação reversa automática
        $suggested = $this->feedService->suggestStreetName($partner, $event);
        if ($suggested && !$request->request->get('street')) {
            $event->setStreet($suggested);
        }

        $this->em->persist($event);
        $this->em->flush();

        $this->addFlash('success', "Evento #{$event->getIncidentId()} criado com sucesso.");

        return $this->redirectToRoute('partner_event_list', ['slug' => $slug]);
    }

    /** GET /partner-feed/{slug}/events/{id}/edit */
    #[Route('/{id}/edit', name: 'partner_event_edit', methods: ['GET'])]
    public function edit(string $slug, int $id): Response
    {
        $partner = $this->getPartnerOr404($slug);
        $event   = $this->getEventOr404($id, $slug);

        return $this->render('partner_feed/events/form.html.twig', [
            'partner'  => $partner,
            'event'    => $event,
            'types'    => PartnerFeedEvent::TYPES,
            'subtypes' => PartnerFeedEvent::SUBTYPES,
        ]);
    }

    /** POST /partner-feed/{slug}/events/{id}/edit */
    #[Route('/{id}/edit', name: 'partner_event_update', methods: ['POST'])]
    public function update(string $slug, int $id, Request $request): Response
    {
        $partner = $this->getPartnerOr404($slug);
        $event   = $this->getEventOr404($id, $slug);

        $this->hydrateFromRequest($event, $request);
        $event->setUpdatedAt(new \DateTimeImmutable());

        // Regeocodificar se lat/lon mudou
        if ($request->request->get('latitude') || $request->request->get('longitude')) {
            $this->feedService->suggestStreetName($partner, $event);
        }

        $this->em->flush();

        $this->addFlash('success', "Evento atualizado.");

        return $this->redirectToRoute('partner_event_list', ['slug' => $slug]);
    }

    /** POST /partner-feed/{slug}/events/{id}/toggle */
    #[Route('/{id}/toggle', name: 'partner_event_toggle', methods: ['POST'])]
    public function toggle(string $slug, int $id): Response
    {
        $event = $this->getEventOr404($id, $slug);

        $event->setStatus(
            $event->getStatus() === PartnerFeedEvent::STATUS_ACTIVE
                ? PartnerFeedEvent::STATUS_INACTIVE
                : PartnerFeedEvent::STATUS_ACTIVE
        );
        $event->setUpdatedAt(new \DateTimeImmutable());

        $this->em->flush();

        $this->addFlash('success', 'Status do evento atualizado.');

        return $this->redirectToRoute('partner_event_list', ['slug' => $slug]);
    }

    /** DELETE /partner-feed/{slug}/events/{id} */
    #[Route('/{id}/delete', name: 'partner_event_delete', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(string $slug, int $id): Response
    {
        $event = $this->getEventOr404($id, $slug);
        $this->em->remove($event);
        $this->em->flush();

        $this->addFlash('success', 'Evento excluído.');

        return $this->redirectToRoute('partner_event_list', ['slug' => $slug]);
    }

    /**
     * Geocodificação reversa via AJAX.
     * POST /partner-feed/{slug}/events/geocode
     */
    #[Route('/geocode', name: 'partner_event_geocode', methods: ['POST'])]
    public function geocode(string $slug, Request $request): JsonResponse
    {
        $partner = $this->getPartnerOr404($slug);

        $lat = (float) $request->request->get('lat', 0);
        $lon = (float) $request->request->get('lon', 0);

        if (!$lat || !$lon) {
            return new JsonResponse(['error' => 'lat e lon são obrigatórios'], Response::HTTP_BAD_REQUEST);
        }

        $results = $this->feedService->reverseGeocode($partner, $lat, $lon);

        return new JsonResponse(['result' => $results]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function getPartnerOr404(string $slug): \App\Entity\Partner
    {
        $partner = $this->partnerRepository->findBySlug($slug);
        if (!$partner) {
            throw $this->createNotFoundException("Parceiro '{$slug}' não encontrado.");
        }
        return $partner;
    }

    private function getEventOr404(int $id, string $slug): PartnerFeedEvent
    {
        $event = $this->eventRepository->find($id);
        if (!$event || $event->getPartnerId() !== $slug) {
            throw $this->createNotFoundException('Evento não encontrado.');
        }
        return $event;
    }

    private function hydrateFromRequest(PartnerFeedEvent $event, Request $request): void
    {
        $r = $request->request;

        if ($r->has('type'))        $event->setType($r->get('type'));
        if ($r->has('subtype'))     $event->setSubtype($r->get('subtype') ?: null);
        if ($r->has('polyline'))    $event->setPolyline($r->get('polyline'));
        if ($r->has('street'))      $event->setStreet($r->get('street'));
        if ($r->has('direction'))   $event->setDirection($r->get('direction'));
        if ($r->has('description')) $event->setDescription($r->get('description'));
        if ($r->has('status'))      $event->setStatus($r->get('status'));
        if ($r->has('latitude'))    $event->setLatitude($r->get('latitude') ?: null);
        if ($r->has('longitude'))   $event->setLongitude($r->get('longitude') ?: null);

        if ($r->has('starttime') && $r->get('starttime')) {
            $event->setStarttime(new \DateTimeImmutable($r->get('starttime')));
        }
        if ($r->has('endtime') && $r->get('endtime')) {
            $event->setEndtime(new \DateTimeImmutable($r->get('endtime')));
        } else {
            $event->setEndtime(null);
        }
    }
}
