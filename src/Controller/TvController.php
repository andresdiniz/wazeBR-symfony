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
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

#[Route('/tv', name: 'tv_')]
final class TvController extends AbstractController
{
    /** TTL do payload inteiro da TV (segundos). */
    private const TV_CACHE_TTL = 20;

    /** Timeout do fetch da câmera (segundos). */
    private const CAMERA_TIMEOUT = 15;

    public function __construct(
        private readonly Connection $connection,
        private readonly TagAwareCacheInterface $tvCache,
    ) {
    }

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

        $partner = $this->resolvePartnerScope();

        // Chave distinta por parceiro (global = admin sem partner)
        $cacheKey = 'tv_wallboard_' . md5(($partner?->getId() ?? 'global') . '|' . ($partner?->getCode() ?? ''));

        /** @var array<string,mixed> $payload */
        $payload = $this->tvCache->get($cacheKey, function (ItemInterface $item) use ($repository, $partner) {
            $item->expiresAfter(self::TV_CACHE_TTL);

            return $repository->getWallboard($partner);
        });

        $response = $this->json([
            'ok'   => true,
            'data' => $this->normalizeDatesForJson($payload),
        ]);

        // ── Cache HTTP ──
        // O browser e o Cloudflare podem guardar por até 10s. Isso
        // derruba ainda mais o tráfego caso várias TVs abram ao mesmo
        // tempo. O `must-revalidate` garante que ninguém usa cache
        // velho depois disso.
        // DEPOIS (à prova de proxy):
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Vary', 'Cookie, Authorization');
        $response->headers->set('CDN-Cache-Control', 'no-store');
        $response->headers->set('Cloudflare-CDN-Cache-Control', 'no-store');

        return $response;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Proxy HLS (inalterado do que já mandei)
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

        $camera = $this->loadCamera($id);
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

        $targetUrl = $baseUrl . $file;

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

        $isManifest = str_contains($ct, 'mpegurl')
            || str_contains($file, '.m3u8')
            || str_starts_with(ltrim($body), '#EXTM3U');

        if ($isManifest) {
            $body = $this->rewriteHlsManifest($body, $file, $id);
            $ct   = 'application/vnd.apple.mpegurl';
        } elseif (str_contains($ct, 'mp2t') || str_ends_with($file, '.ts')) {
            $ct = 'video/mp2t';
        }

        return new Response($body, Response::HTTP_OK, [
            'Content-Type'   => $ct !== '' ? $ct : 'application/octet-stream',
            'Content-Length' => (string) strlen($body),
            'Cache-Control'  => 'no-store, no-cache, must-revalidate',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // HLS helpers (iguais aos que já mandei)
    // ─────────────────────────────────────────────────────────────────────

    private function loadCamera(int $id): ?array
    {
        $partner = $this->resolvePartnerScope();
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

    /** @return array{0:?string,1:int,2:string,3:string} */
    private function fetchRemote(string $url): array
    {
        if (!function_exists('curl_init')) {
            return [null, 0, '', 'cURL não disponível'];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::CAMERA_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_USERAGENT      => 'WazeBR-TV/1.0',
            CURLOPT_HTTPHEADER     => ['Accept: */*'],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);

        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ct   = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $err  = (string) curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return [null, $code, $ct, $err];
        }
        return [(string) $body, $code, $ct, ''];
    }

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
