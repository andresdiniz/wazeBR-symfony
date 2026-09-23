<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Partner;
use App\Entity\User;
use App\Repository\TvRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

#[Route('/tv', name: 'tv_')]
final class TvController extends AbstractController
{
    /**
     * TTL do payload do wallboard (segundos).
     * A invalidação real acontece via TagAwareCache — este TTL é só a
     * rede de segurança caso o publish do Mercure falhe.
     */
    private const TV_CACHE_TTL = 120;

    /** Timeout total de uma requisição cURL à câmera (segundos). */
    private const CAMERA_TIMEOUT = 8;

    /** Quantas vezes retentar em 404/503 transitório. */
    private const CAMERA_RETRIES = 2;

    /** Delay entre retries (microssegundos). */
    private const CAMERA_RETRY_DELAY_US = 300_000;

    /** TTL de cache de segmentos .ts no browser (segundos). */
    private const HLS_SEGMENT_TTL = 30;

    /** TTL de cache de manifestos .m3u8 no browser (segundos). */
    private const HLS_MANIFEST_TTL = 2;

    public function __construct(
        private readonly Connection $connection,
        private readonly TagAwareCacheInterface $tvCache,
    ) {
    }

    // ─────────────────────────────────────────────────────────────────────
    // Páginas e API
    // ─────────────────────────────────────────────────────────────────────

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        return $this->render('tv/index.html.twig', [
            'partner' => $this->resolvePartnerScope(),
        ]);
    }

    #[Route('/api/data', name: 'api_data', methods: ['GET'])]
    public function apiData(TvRepository $repository): JsonResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $partner  = $this->resolvePartnerScope();
        $cacheTag = $partner ? 'tv_partner_' . $partner->getId() : 'tv_global';
        $cacheKey = 'tv_wallboard_' . md5(($partner?->getId() ?? 'global') . '|' . ($partner?->getCode() ?? ''));

        /** @var array<string,mixed> $payload */
        $payload = $this->tvCache->get($cacheKey, function (ItemInterface $item) use ($repository, $partner, $cacheTag) {
            $item->expiresAfter(self::TV_CACHE_TTL);
            $item->tag([$cacheTag]);

            return $repository->getWallboard($partner);
        });

        $response = $this->json([
            'ok'   => true,
            'data' => $this->normalizeDatesForJson($payload),
        ]);

        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Vary', 'Cookie, Authorization');
        $response->headers->set('CDN-Cache-Control', 'no-store');
        $response->headers->set('Cloudflare-CDN-Cache-Control', 'no-store');

        return $response;
    }

    /**
     * Informa ao frontend a URL pública do hub Mercure + o tópico a assinar.
     *
     * ⚠️ Nome `streamInfo` (não `stream`) porque AbstractController já tem
     * um método `stream()` com assinatura incompatível.
     */
    #[Route('/stream/info', name: 'stream_info', methods: ['GET'])]
    public function streamInfo(HubInterface $hub): JsonResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $partner   = $this->resolvePartnerScope();
        $partnerId = $partner?->getId();

        return $this->json([
            'hub'   => $hub->getPublicUrl(),
            'topic' => 'tv/' . ($partnerId ?? 'global'),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Proxy HLS com cache
    // ─────────────────────────────────────────────────────────────────────

    #[Route(
        '/camera/{id}/hls',
        name: 'camera_hls',
        requirements: ['id' => '\d+'],
        methods: ['GET']
    )]
    public function cameraHls(int $id, Request $request): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        // 🔑 Resolve o parceiro ANTES de fechar a sessão (usa getUser por baixo).
        $partner = $this->resolvePartnerScope();

        // 🔑 CRÍTICO: fecha a sessão para liberar o lock do arquivo.
        // Sem isso, hls.js dispara 4 segmentos em paralelo e eles se
        // serializam no arquivo de sessão, cada um esperando o anterior.
        if ($request->hasSession()) {
            $request->getSession()->save();
        }

        $camera = $this->loadCamera($id, $partner);
        if ($camera === null) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $baseUrl = $this->extractBaseUrl((string) $camera['url']);
        if ($baseUrl === null) {
            return new Response('', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $file = (string) $request->query->get('file', '');
        $file = ltrim($file, '/');

        if ($file === '' || str_contains($file, '..')) {
            return new Response('', Response::HTTP_BAD_REQUEST);
        }

        $targetUrl  = $baseUrl . $file;
        $isManifest = str_ends_with($file, '.m3u8');
        $isSegment  = str_ends_with($file, '.ts');

        [$body, $code, $ct, $err] = $this->fetchRemote($targetUrl);

        if ($body === null || $code < 200 || $code >= 400) {
            error_log("[TV HLS] fetch falhou: {$targetUrl} (HTTP {$code}, err: {$err})");

            if ($this->getParameter('kernel.debug')) {
                return new Response(
                    "Falha ao buscar HLS\n\nURL alvo: {$targetUrl}\nHTTP code: {$code}\nErro cURL: {$err}\n",
                    Response::HTTP_BAD_GATEWAY,
                    ['Content-Type' => 'text/plain; charset=utf-8']
                );
            }
            return new Response('', Response::HTTP_BAD_GATEWAY);
        }

        // ─────────────────────────────────────────────────────────────
        // SEGMENTO .ts — imutável, cache agressivo + ETag
        // ─────────────────────────────────────────────────────────────
        if ($isSegment || str_contains($ct, 'mp2t')) {
            $etag = '"' . md5($body) . '"';

            if ($this->matchesEtag($request, $etag)) {
                return new Response('', Response::HTTP_NOT_MODIFIED, [
                    'ETag'          => $etag,
                    'Cache-Control' => 'public, max-age=' . self::HLS_SEGMENT_TTL,
                ]);
            }

            return new Response($body, Response::HTTP_OK, [
                'Content-Type'   => 'video/mp2t',
                'Content-Length' => (string) strlen($body),
                'Cache-Control'  => 'public, max-age=' . self::HLS_SEGMENT_TTL,
                'ETag'           => $etag,
            ]);
        }

        // ─────────────────────────────────────────────────────────────
        // MANIFESTO .m3u8 — reescrita de URLs + cache curto + ETag
        // ─────────────────────────────────────────────────────────────
        if ($isManifest || str_contains($ct, 'mpegurl') || str_starts_with(ltrim($body), '#EXTM3U')) {
            $body = $this->rewriteHlsManifest($body, $file, $id);
            $etag = '"' . md5($body) . '"';

            if ($this->matchesEtag($request, $etag)) {
                return new Response('', Response::HTTP_NOT_MODIFIED, [
                    'ETag'          => $etag,
                    'Cache-Control' => 'public, max-age=' . self::HLS_MANIFEST_TTL,
                ]);
            }

            return new Response($body, Response::HTTP_OK, [
                'Content-Type'   => 'application/vnd.apple.mpegurl',
                'Content-Length' => (string) strlen($body),
                'Cache-Control'  => 'public, max-age=' . self::HLS_MANIFEST_TTL,
                'ETag'           => $etag,
            ]);
        }

        // ─────────────────────────────────────────────────────────────
        // OUTROS — fallback conservador
        // ─────────────────────────────────────────────────────────────
        return new Response($body, Response::HTTP_OK, [
            'Content-Type'   => $ct !== '' ? $ct : 'application/octet-stream',
            'Content-Length' => (string) strlen($body),
            'Cache-Control'  => 'no-store, no-cache, must-revalidate',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers privados — câmeras
    // ─────────────────────────────────────────────────────────────────────

    private function loadCamera(int $id, ?Partner $partner): ?array
    {
        $pf = '';
        $params = ['id' => $id];
        if ($partner !== null) {
            $pf = ' AND partner_id = :pid';
            $params['pid'] = $partner->getId();
        }

        $row = $this->connection->executeQuery(
            "SELECT id, url FROM partner_camera_link
             WHERE id = :id AND is_active = 1 {$pf} LIMIT 1",
            $params
        )->fetchAssociative();

        return $row ? ['id' => (int) $row['id'], 'url' => (string) $row['url']] : null;
    }

    private function extractBaseUrl(string $fullUrl): ?string
    {
        $p = parse_url($fullUrl);
        if (!isset($p['scheme'], $p['host'])) return null;

        $base = $p['scheme'] . '://' . $p['host'];
        if (!empty($p['port'])) $base .= ':' . $p['port'];
        return $base . '/';
    }

    private function rewriteHlsManifest(string $body, string $manifestFile, int $cameraId): string
    {
        $slashPos    = strrpos($manifestFile, '/');
        $manifestDir = $slashPos === false ? '' : substr($manifestFile, 0, $slashPos + 1);

        $lines = preg_split('/\r?\n/', $body) ?: [];
        $out   = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                $out[] = $line;
                continue;
            }

            if (preg_match('#^https?://#i', $trimmed)) {
                $parsed = parse_url($trimmed);
                $resolved = ltrim((string) ($parsed['path'] ?? ''), '/');
            } else {
                $resolved = $manifestDir . ltrim($trimmed, '/');
            }

            $out[] = '/tv/camera/' . $cameraId . '/hls?file=' . rawurlencode($resolved);
        }

        return implode("\n", $out);
    }

    /**
     * Busca uma URL remota via cURL.
     *
     * Retenta automaticamente em 404/503 — o IIS das câmeras USP costuma
     * devolver esses códigos por alguns milissegundos quando o stream
     * reinicia (rotação de segmento, restart do encoder).
     *
     * @return array{0:?string,1:int,2:string,3:string}
     */
    private function fetchRemote(string $url, int $attempt = 0): array
    {
        if (!function_exists('curl_init')) {
            return [null, 0, '', 'cURL não disponível'];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_TIMEOUT         => self::CAMERA_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT  => 3,
            CURLOPT_FOLLOWLOCATION  => true,
            CURLOPT_MAXREDIRS       => 3,
            CURLOPT_USERAGENT       => 'WazeBR-TV/1.0',
            CURLOPT_HTTPHEADER      => ['Accept: */*'],
            CURLOPT_SSL_VERIFYPEER  => false,
            CURLOPT_SSL_VERIFYHOST  => 0,
            CURLOPT_LOW_SPEED_LIMIT => 1024,
            CURLOPT_LOW_SPEED_TIME  => 3,
        ]);

        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ct   = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $err  = (string) curl_error($ch);
        curl_close($ch);

        // Retry para 404/503 transitório do servidor da câmera
        if (($code === 404 || $code === 503) && $attempt < self::CAMERA_RETRIES) {
            usleep(self::CAMERA_RETRY_DELAY_US);
            return $this->fetchRemote($url, $attempt + 1);
        }

        if ($body === false) {
            return [null, $code, $ct, $err];
        }
        return [(string) $body, $code, $ct, ''];
    }

    /**
     * Verifica se o cliente já tem a versão atual (If-None-Match).
     * Suporta múltiplos ETags separados por vírgula, como manda o RFC 7232.
     */
    private function matchesEtag(Request $request, string $etag): bool
    {
        $header = trim((string) $request->headers->get('If-None-Match', ''));
        if ($header === '') return false;
        if ($header === '*') return true;

        foreach (explode(',', $header) as $candidate) {
            if (trim($candidate) === $etag) return true;
        }
        return false;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers privados — gerais
    // ─────────────────────────────────────────────────────────────────────

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
