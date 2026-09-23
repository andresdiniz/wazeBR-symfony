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
    private const CACHE_TTL = 110;

    public function __construct(
        private readonly TagAwareCacheInterface $tvCache,
    ) {
    }

    #[Route('/stream', name: 'stream', methods: ['GET'])]
public function sse(TvRepository $repository): StreamedResponse
{
    $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

    $partner = $this->resolvePartnerScope();

    // 🔑 CRÍTICO: fecha a sessão para liberar o lock ANTES de entrar
    // no loop de longa duração. Sem isso, todo request do mesmo usuário
    // (cameraHls, apiData) bloqueia esperando este aqui.
    if ($this->container->has('request_stack')) {
        $request = $this->container->get('request_stack')->getCurrentRequest();
        if ($request && $request->hasSession()) {
            $request->getSession()->save();
        }
    }

    $cacheKey = 'tv_wallboard_' . md5(
        ($partner?->getId() ?? 'global') . '|' . ($partner?->getCode() ?? '')
    );

    @set_time_limit(self::MAX_DURATION + 15);
    @ini_set('max_execution_time', (string) (self::MAX_DURATION + 15));

    $response = new StreamedResponse(function () use ($repository, $partner, $cacheKey): void {
        // ... resto do código atual
    });

    // ... headers atuais
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
