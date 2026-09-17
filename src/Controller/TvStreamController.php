<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Partner;
use App\Entity\User;
use App\Repository\TvRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * Endpoint SSE para o wallboard da TV.
 *
 * Objetivo: manter UMA conexão HTTP aberta por ~55s e, dentro dela,
 * UMA única conexão MySQL reaproveitada entre os ticks. Desta forma
 * derrubamos o custo de abertura de conexão (que é o que estoura o
 * max_connections_per_hour do Hostinger).
 *
 * NOTA: o método público NÃO pode se chamar `stream()` porque
 * AbstractController já define `stream(string $view, ...)` na
 * assinatura. Usamos `sse()`.
 */
#[Route('/tv', name: 'tv_')]
final class TvStreamController extends AbstractController
{
    /** Duração máxima da sessão SSE (segundos). */
    private const MAX_DURATION = 55;

    /** Intervalo entre ticks dentro da sessão (segundos). */
    private const TICK_SECONDS = 5;

    /**
     * TTL do cache do payload. Mesma chave do TvController::apiData —
     * assim os dois endpoints compartilham cache e não duplicam queries.
     */
    private const CACHE_TTL = 20;

    public function __construct(
        private readonly TagAwareCacheInterface $tvCache,
    ) {
    }

    #[Route('/stream', name: 'stream', methods: ['GET'])]
    public function sse(TvRepository $repository): StreamedResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $partner = $this->resolvePartnerScope();

        $cacheKey = 'tv_wallboard_' . md5(
            ($partner?->getId() ?? 'global') . '|' . ($partner?->getCode() ?? '')
        );

        @set_time_limit(self::MAX_DURATION + 15);
        @ini_set('max_execution_time', (string) (self::MAX_DURATION + 15));

        $response = new StreamedResponse(function () use ($repository, $partner, $cacheKey): void {
            while (ob_get_level() > 0) {
                @ob_end_flush();
            }
            @ob_implicit_flush(true);

            echo "retry: 3000\n\n";
            flush();

            $startedAt = time();
            $lastHash  = null;

            while (
                !connection_aborted()
                && (time() - $startedAt) < self::MAX_DURATION
            ) {
                try {
                    $payload = $this->tvCache->get(
                        $cacheKey,
                        function (ItemInterface $item) use ($repository, $partner) {
                            $item->expiresAfter(self::CACHE_TTL);
                            return $repository->getWallboard($partner);
                        },
                    );

                    $json = json_encode(
                        ['ok' => true, 'data' => $this->normalizeDatesForJson($payload)],
                        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                    );

                    if ($json !== false) {
                        $hash = md5($json);

                        if ($hash !== $lastHash) {
                            echo "event: data\n";
                            echo "data: {$json}\n\n";
                            $lastHash = $hash;
                        } else {
                            echo ": keep-alive\n\n";
                        }

                        flush();
                    }
                } catch (\Throwable $e) {
                    error_log('[TV stream] ' . $e->getMessage());
                }

                sleep(self::TICK_SECONDS);
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream; charset=utf-8');
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('X-Accel-Buffering', 'no');
        $response->headers->set('Content-Encoding', 'identity');
        $response->headers->set('Vary', 'Cookie, Authorization');
        $response->headers->set('CDN-Cache-Control', 'no-store');
        $response->headers->set('Cloudflare-CDN-Cache-Control', 'no-store');

        return $response;
    }

    // ─────────────────────────────────────────────────────────────────

    private function resolvePartnerScope(): ?Partner
    {
        /** @var User|null $user */
        $user = $this->getUser();
        if (!$user instanceof User) return null;
        if ($user->isGlobalAdmin()) return null;
        return $user->getPartner();
    }

    private function normalizeDatesForJson(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) return $value->format(DATE_ATOM);
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) $out[$k] = $this->normalizeDatesForJson($v);
            return $out;
        }
        return $value;
    }
}
