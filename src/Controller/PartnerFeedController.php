<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Partner;
use App\Entity\PartnerFeedEvent;
use App\Repository\PartnerFeedEventRepository;
use App\Repository\PartnerRepository;
use App\Service\CifsEventValidator;
use App\Service\PartnerFeedService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
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
        private readonly CifsEventValidator $validator,
        #[Autowire('%env(APP_TIMEZONE)%')]
        private readonly string $appTimezone,
        #[Autowire(service: 'monolog.logger.audit')]
        private readonly LoggerInterface $auditLogger,
    ) {}

    #[Route('/{code}.json', name: 'json', methods: ['GET'])]
    public function jsonFeed(string $code, Request $request): Response
    {
        $partner = $this->getPartnerOr404($code);
        $json = $this->feedService->buildJson($partner, false);

        $response = new Response($json, Response::HTTP_OK, [
            'Content-Type' => 'application/json; charset=utf-8',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
        // Revalida a cada request (o Waze sempre vê o estado atual) mas permite 304 barato.
        $response->headers->set('Cache-Control', 'public, no-cache');
        $response->setEtag(md5($json));
        $response->isNotModified($request);

        return $response;
    }

    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $response = $this->render('partner_feed/index.html.twig', [
            'partners' => $this->partnerRepository->findBy(['isActive' => true], ['name' => 'ASC']),
        ]);
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    #[Route('/{code}', name: 'show', methods: ['GET'])]
    public function show(string $code): Response
    {
        $partner = $this->getPartnerOr404($code);
        $response = $this->render('partner_feed/show.html.twig', [
            'partner' => $partner,
            'feedJson' => $this->feedService->buildJson($partner),
            'incidents' => $this->feedService->buildFeed($partner)['incidents'],
        ]);
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
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
        $parseErrors = $this->hydrateFromRequest($event, $request);
        if ($response = $this->rejectIfInvalid($partner, $event, $parseErrors)) {
            return $response;
        }
        $this->em->persist($event);
        $this->em->flush();
        $this->flashWarnings($event);
        $this->logEventAction('created', $partner, (int) $event->getId());
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
        $parseErrors = $this->hydrateFromRequest($event, $request);
        if ($response = $this->rejectIfInvalid($partner, $event, $parseErrors)) {
            return $response;
        }
        $user = $this->getUser();
        if ($user !== null && method_exists($user, 'getId')) {
            $event->setUpdatedByUserId($user->getId());
        }
        $this->em->flush();
        $this->flashWarnings($event);
        $this->logEventAction('updated', $partner, $id);
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
        $wasActive = $event->isActive();
        $event->setIsActive(!$wasActive);
        $user = $this->getUser();
        if ($user !== null && method_exists($user, 'getId')) {
            $event->setUpdatedByUserId($user->getId());
        }
        $this->em->flush();
        $this->logEventAction($event->isActive() ? 'activated' : 'deactivated', $partner, $id, [
            'previous_is_active' => $wasActive,
            'is_active' => $event->isActive(),
        ]);
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
        $this->logEventAction('deleted', $partner, $id);
        return $this->redirectToRoute('partner_feed_event_list', ['code' => $code]);
    }

    private function logEventAction(string $action, Partner $partner, int $eventId, array $context = []): void
    {
        $user = $this->getUser();
        $this->auditLogger->info('partner_feed.event.' . $action, [
            'action' => $action,
            'user_id' => $user instanceof \App\Entity\User ? $user->getId() : null,
            'partner_id' => $partner->getId(),
            'event_id' => $eventId,
            'occurred_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM),
        ] + $context);
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

    /**
     * Se o evento não cumpre a spec CIFS, NÃO grava: devolve o formulário (422)
     * com o que o usuário digitou e a lista de erros.
     *
     * @param string[] $parseErrors
     */
    private function rejectIfInvalid(Partner $partner, PartnerFeedEvent $event, array $parseErrors): ?Response
    {
        $errors = array_merge($parseErrors, $this->validator->validate($event)['errors']);
        if ($errors === []) {
            return null;
        }

        foreach ($errors as $error) {
            $this->addFlash('error', $error);
        }

        return $this->render('partner_feed/events/form.html.twig', [
            'partner' => $partner,
            'event' => $event,
            'types' => PartnerFeedEvent::TYPES,
            'subtypes' => PartnerFeedEvent::SUBTYPES,
        ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    private function flashWarnings(PartnerFeedEvent $event): void
    {
        foreach ($this->validator->validate($event)['warnings'] as $warning) {
            $this->addFlash('warning', $warning);
        }
    }

    /**
     * O <input type="datetime-local"> manda horário local SEM fuso. Interpreta em
     * APP_TIMEZONE e guarda em UTC (o kernel e o banco trabalham em UTC).
     */
    private function parseLocalDateTime(string $value): ?\DateTimeImmutable
    {
        try {
            $local = new \DateTimeImmutable(trim($value), new \DateTimeZone($this->appTimezone));
        } catch (\Exception) {
            return null;
        }

        return $local->setTimezone(new \DateTimeZone('UTC'));
    }

    /** @return string[] erros de leitura dos campos (a validação CIFS roda depois) */
    private function hydrateFromRequest(PartnerFeedEvent $event, Request $request): array
    {
        $errors = [];
        $r = $request->request;
        if ($r->has('cifsType') && $r->get('cifsType') !== '') $event->setCifsType(trim((string) $r->get('cifsType')));
        if ($r->has('cifsSubtype')) $event->setCifsSubtype($r->get('cifsSubtype') !== '' ? trim((string) $r->get('cifsSubtype')) : null);
        if ($r->has('street')) $event->setStreet(trim((string) $r->get('street')));
        if ($r->has('reference')) $event->setReference($r->get('reference') !== '' ? (string) $r->get('reference') : null);
        if ($r->has('description')) $event->setDescription($r->get('description') !== '' ? trim((string) $r->get('description')) : null);
        if ($r->has('city')) $event->setCity($r->get('city') !== '' ? (string) $r->get('city') : null);
        if ($r->has('polyline') && trim((string) $r->get('polyline')) !== '') {
            $polyline = json_decode((string) $r->get('polyline'), true);
            if (is_array($polyline)) {
                $event->setPolyline(array_values($polyline));
            } else {
                $errors[] = 'Polyline ilegível: marque os pontos no mapa novamente.';
            }
        }
        if ($r->has('direction') && $r->get('direction') !== '') $event->setDirection((string) $r->get('direction'));
        if ($r->has('startTime') && $r->get('startTime') !== '') {
            $start = $this->parseLocalDateTime((string) $r->get('startTime'));
            if ($start === null) {
                $errors[] = 'Data de início inválida.';
            } else {
                $event->setStartTime($start);
            }
        }
        if ($r->has('endTime')) {
            if ($r->get('endTime') === '') {
                $event->setEndTime(null);
            } else {
                $end = $this->parseLocalDateTime((string) $r->get('endTime'));
                if ($end === null) {
                    $errors[] = 'Data de fim inválida.';
                } else {
                    $event->setEndTime($end);
                }
            }
        }
        if ($r->has('isActive')) $event->setIsActive(filter_var($r->get('isActive'), FILTER_VALIDATE_BOOLEAN));

        return $errors;
    }
}
