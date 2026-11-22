<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\DownloadAuditLog;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Security;

/** Persists an audit entry whenever an authenticated user receives an attachment. */
final class DownloadAuditSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Security $security,
        #[Autowire(service: 'monolog.logger.audit')]
        private readonly LoggerInterface $auditLogger,
        #[Autowire('%kernel.secret%')]
        private readonly string $ipHashSecret,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['onResponse', -100]];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $response = $event->getResponse();
        $disposition = (string) $response->headers->get('Content-Disposition', '');
        if (!str_contains(strtolower($disposition), 'attachment')) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return;
        }

        $request = $event->getRequest();
        $filename = $this->filename($disposition) ?? 'download';
        $ip = $request->getClientIp();

        try {
            $this->entityManager->persist(new DownloadAuditLog(
                $user,
                $user->getPartner(),
                (string) ($request->attributes->get('_route') ?? $request->getPathInfo()),
                $filename,
                $response->headers->get('Content-Type'),
                $ip !== null ? hash_hmac('sha256', $ip, $this->ipHashSecret) : null,
            ));
            $this->entityManager->flush();
        } catch (\Throwable $exception) {
            // Audit availability must not prevent a legitimate export.
            $this->auditLogger->error('download_audit.persist_failed', ['exception' => $exception]);
        }
    }

    private function filename(string $disposition): ?string
        {
            if (preg_match('/filename\*?=(?:UTF-8\'|")?([^;"]+)/i', $disposition, $matches) !== 1) {
                return null;
            }

            return mb_substr(rawurldecode(trim($matches[1], " \t\"")), 0, 255);
        }
}
