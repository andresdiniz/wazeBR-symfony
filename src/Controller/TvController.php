<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Partner;
use App\Entity\User;
use App\Repository\PartnerRepository;
use App\Repository\TvRepository;
use App\Service\CongestionForecastService;
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
    private const TV_CACHE_TTL = 120;
    private const CAMERA_TIMEOUT = 8;
    private const CAMERA_RETRIES = 2;
    private const CAMERA_RETRY_DELAY_US = 300000;
    private const HLS_SEGMENT_TTL = 30;
    private const HLS_MANIFEST_TTL = 2;

    public function __construct(
        private readonly Connection $connection,
        private readonly PartnerRepository $partnerRepository,
        private readonly TagAwareCacheInterface $tvCache,
        private readonly CongestionForecastService $congestionForecastService,
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
        $cacheTag = $partner ? 'tv_partner_' . $partner->getId() : 'tv_global';
        $cacheKey = 'tv_wallboard_' . md5(($partner?->getId() ?? 'global') . '|' . ($partner?->getCode() ?? ''));

        $payload = $this->tvCache->get($cacheKey, function (ItemInterface $item) use ($repository, $partner, $cacheTag) {
            $item->expiresAfter(self::TV_CACHE_TTL);
            $item->tag([$cacheTag]);

            return $repository->getWallboard($partner);
        });

        $payload['congestionForecast'] = $this->congestionForecastService->getUpcomingForWallboard(
            $partner,
            30,
            2,
        );

        $response = $this->json([
            'ok' => true,
            'data' => $this->normalizeDatesForJson($payload),
        ]);

        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Vary', 'Cookie, Authorization');
        $response->headers->set('CDN-Cache-Control', 'no-store');
        $response->headers->set('Cloudflare-CDN-Cache-Control', 'no-store');

        return $response;
    }

    #[Route('/stream/info', name: 'stream_info', methods: ['GET'])]
    public function streamInfo(HubInterface $hub): JsonResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $partner = $this->resolvePartnerScope();
        $partnerId = $partner?->getId();

        return $this->json([
            'hub' => $hub->getPublicUrl(),
            'topic' => 'tv/' . ($partnerId ?? 'global'),
        ]);
    }

    #[Route('/camera/{id}/hls', name: 'camera_hls', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function cameraHls(int $id, Request $request): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $partner = $this->resolvePartnerScope();

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

        $file = ltrim((string) $request->query->get('file', ''), '/');

        if ($file === '' || str_contains($file, '..')) {
            return new Response('', Response::HTTP_BAD_REQUEST);
        }

        $targetUrl = $baseUrl . $file;
        $isManifest = str_ends_with($file, '.m3u8');
        $isSegment = str_ends_with($file, '.ts');

        [$body, $code, $ct, $err] = $this->fetchRemote($targetUrl);

        if ($body === null || $code < 200 || $code >= 400) {
            error_log("[TV HLS] fetch falhou: {$targetUrl} (HTTP {$code}, err: {$err})");

            if ($this->getParameter('kernel.debug')) {
                return new Response(
                    "Falha ao buscar HLS\n\nURL alvo: {$targetUrl}\nHTTP code: {$code}\nErro cURL: {$err}\n",
                    Response::HTTP_BAD_GATEWAY,
                    ['Content-Type' => 'text/plain; charset=utf-8'],
                );
            }

            return new Response('', Response::HTTP_BAD_GATEWAY);
        }

        if ($isSegment || str_contains($ct, 'mp2t')) {
            $etag = '"' . md5($body) . '"';

            if ($this->matchesEtag($request, $etag)) {
                return new Response('', Response::HTTP_NOT_MODIFIED, [
                    'ETag' => $etag,
                    'Cache-Control' => 'private, no-store, max-age=0',
                ]);
            }

            return new Response($body, Response::HTTP_OK, [
                'Content-Type' => 'video/mp2t',
                'Content-Length' => (string) strlen($body),
                'Cache-Control' => 'private, no-store, max-age=0',
                'ETag' => $etag,
            ]);
        }

        if ($isManifest || str_contains($ct, 'mpegurl') || str_starts_with(ltrim($body), '#EXTM3U')) {
            $body = $this->rewriteHlsManifest($body, $file, $id);
            $etag = '"' . md5($body) . '"';

            if ($this->matchesEtag($request, $etag)) {
                return new Response('', Response::HTTP_NOT_MODIFIED, [
                    'ETag' => $etag,
                    'Cache-Control' => 'private, no-store, max-age=0',
                ]);
            }

            return new Response($body, Response::HTTP_OK, [
                'Content-Type' => 'application/vnd.apple.mpegurl',
                'Content-Length' => (string) strlen($body),
                'Cache-Control' => 'private, no-store, max-age=0',
                'ETag' => $etag,
            ]);
        }

        return new Response($body, Response::HTTP_OK, [
            'Content-Type' => $ct !== '' ? $ct : 'application/octet-stream',
            'Content-Length' => (string) strlen($body),
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

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
            $params,
        )->fetchAssociative();

        return $row ? ['id' => (int) $row['id'], 'url' => (string) $row['url']] : null;
    }

    private function extractBaseUrl(string $fullUrl): ?string
    {
        $p = parse_url($fullUrl);

        if (!isset($p['scheme'], $p['host'])) {
            return null;
        }

        $base = $p['scheme'] . '://' . $p['host'];

        if (!empty($p['port'])) {
            $base .= ':' . $p['port'];
        }

        return $base . '/';
    }

    private function rewriteHlsManifest(string $body, string $manifestFile, int $cameraId): string
    {
        $slashPos = strrpos($manifestFile, '/');
        $manifestDir = $slashPos === false ? '' : substr($manifestFile, 0, $slashPos + 1);
        $lines = preg_split('/\r?\n/', $body) ?: [];
        $out = [];

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
     * @return array{0:?string,1:int,2:string,3:string}
     */
    private function isSafeRemoteTarget(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $host = strtolower(rtrim($parts['host'], '.'));
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $addresses = [$host];
        } else {
            $records = dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
            $addresses = array_values(array_unique(array_filter(array_map(
                static fn (array $record): string => (string) ($record['ip'] ?? $record['ipv6'] ?? ''),
                $records,
            ))));
        }

        if ($addresses === []) {
            return false;
        }

        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
        }

        return true;
    }
    private function fetchRemote(string $url, int $attempt = 0): array
    {
        if (!function_exists('curl_init')) {
            return [null, 0, '', 'cURL não disponível'];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::CAMERA_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_USERAGENT => 'WazeBR-TV/1.0',
            CURLOPT_HTTPHEADER => ['Accept: */*'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_LOW_SPEED_LIMIT => 1024,
            CURLOPT_LOW_SPEED_TIME => 3,
        ]);

        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ct = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $err = (string) curl_error($ch);
        curl_close($ch);

        if (($code === 404 || $code === 503) && $attempt < self::CAMERA_RETRIES) {
            usleep(self::CAMERA_RETRY_DELAY_US);

            return $this->fetchRemote($url, $attempt + 1);
        }

        if ($body === false) {
            return [null, $code, $ct, $err];
        }

        return [(string) $body, $code, $ct, ''];
    }

    private function matchesEtag(Request $request, string $etag): bool
    {
        $header = trim((string) $request->headers->get('If-None-Match', ''));

        if ($header === '') {
            return false;
        }

        if ($header === '*') {
            return true;
        }

        foreach (explode(',', $header) as $candidate) {
            if (trim($candidate) === $etag) {
                return true;
            }
        }

        return false;
    }

    private function resolvePartnerScope(): ?Partner
    {
        $user = $this->getUser();
        if (!$user instanceof User || $user->getId() === null) {
            throw $this->createAccessDeniedException();
        }

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

            foreach ($value as $k => $v) {
                $out[$k] = $this->normalizeDatesForJson($v);
            }

            return $out;
        }

        return $value;
    }
}
