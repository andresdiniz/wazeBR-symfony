<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Partner;
use App\Entity\User;
use App\Repository\PartnerRepository;
use App\Repository\TvRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

#[Route('/tv', name: 'tv_')]
final class TvStreamController extends AbstractController
{
    private const MAX_DURATION = 55;
    private const TICK_SECONDS = 5;
    private const CACHE_TTL = 110;

    public function __construct(
        private readonly TagAwareCacheInterface $tvCache,
        private readonly Connection $connection,
        private readonly PartnerRepository $partnerRepository,
    ) {
    }

    #[Route('/stream', name: 'stream', methods: ['GET'])]
    public function sse(TvRepository $repository): StreamedResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');
        $partner = $this->resolvePartnerScope();

        $request = $this->container->get('request_stack')->getCurrentRequest();
        if ($request && $request->hasSession()) {
            $request->getSession()->save();
        }

        $cacheKey = 'tv_wallboard_' . md5(($partner?->getId() ?? 'global') . '|' . ($partner?->getCode() ?? ''));
        $cacheTag = $partner ? 'tv_partner_' . $partner->getId() : 'tv_global';
        @set_time_limit(self::MAX_DURATION + 15);

        $response = new StreamedResponse(function () use ($repository, $partner, $cacheKey, $cacheTag): void {
            $startedAt = microtime(true);
            do {
                if (connection_aborted()) {
                    break;
                }

                try {
                    $payload = $this->tvCache->get($cacheKey, function (ItemInterface $item) use ($repository, $partner, $cacheTag): array {
                        $item->expiresAfter(self::CACHE_TTL);
                        $item->tag([$cacheTag]);

                        return $repository->getWallboard($partner);
                    });
                    echo 'data: ' . json_encode($this->normalizeDatesForJson($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n\n";
                } catch (\Throwable) {
                    echo ": heartbeat\n\n";
                }

                if (function_exists('ob_flush')) {
                    @ob_flush();
                }
                flush();

                if (microtime(true) - $startedAt + self::TICK_SECONDS > self::MAX_DURATION) {
                    break;
                }
                sleep(self::TICK_SECONDS);
            } while (true);
        });

        $response->headers->set('Content-Type', 'text/event-stream; charset=utf-8');
        $response->headers->set('Cache-Control', 'private, no-cache, no-store, must-revalidate');
        $response->headers->set('X-Accel-Buffering', 'no');
        $response->headers->set('Connection', 'keep-alive');

        return $response;
    }

    private function resolvePartnerScope(): ?Partner
    {
        $user = $this->getUser();
        if (!$user instanceof User || $user->getId() === null) {
            throw $this->createAccessDeniedException();
        }

        // Use o vínculo salvo, não a associação que pode ter ficado desatualizada na sessão.
        $partnerId = $this->connection->fetchOne('SELECT partner_id FROM user WHERE id = ?', [$user->getId()]);
        if ($partnerId === false || $partnerId === null || $partnerId === '') {
            $roles = $user->getRoles();
            if (in_array('ROLE_ADMIN', $roles, true) || in_array('ROLE_SUPER_ADMIN', $roles, true)) {
                return null;
            }
            throw $this->createAccessDeniedException('Seu usuário não possui parceiro vinculado.');
        }

        $partner = $this->partnerRepository->find((int) $partnerId);
        if ($partner === null) {
            throw $this->createAccessDeniedException('O parceiro vinculado não existe.');
        }

        return $partner;
    }

    private function normalizeDatesForJson(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $out[$key] = $this->normalizeDatesForJson($item);
            }

            return $out;
        }

        return $value;
    }
}
