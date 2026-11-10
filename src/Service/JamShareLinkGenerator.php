<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class JamShareLinkGenerator
{
    private const ALLOWED_DURATIONS = [24, 72, 168, 720];

    public function __construct(
        #[Autowire('%env(APP_SECRET)%')]
        private readonly string $appSecret,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function create(int $jamId, int $durationHours): array
    {
        if (!in_array($durationHours, self::ALLOWED_DURATIONS, true)) {
            throw new \InvalidArgumentException('Duração inválida.');
        }

        $expiresAt = new \DateTimeImmutable('+' . $durationHours . ' hours');
        $payload = $this->encode([
            'id' => $jamId,
            'exp' => $expiresAt->getTimestamp(),
        ]);

        $signature = hash_hmac('sha256', $payload, $this->appSecret);

        $url = $this->urlGenerator->generate(
            'jam_share_public',
            ['payload' => $payload, 'signature' => $signature],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        return [
            'url' => $url,
            'expiresAt' => $expiresAt,
        ];
    }

    public function verify(string $payload, string $signature): ?array
    {
        $expected = hash_hmac('sha256', $payload, $this->appSecret);

        if (!hash_equals($expected, $signature)) {
            return null;
        }

        $data = json_decode($this->decode($payload), true);

        if (!is_array($data) || !isset($data['id'], $data['exp'])) {
            return null;
        }

        if ((int) $data['exp'] < time()) {
            return null;
        }

        return $data;
    }

    private function encode(array $data): string
    {
        return rtrim(strtr(base64_encode(json_encode($data, JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
    }

    private function decode(string $payload): string
    {
        $remainder = strlen($payload) % 4;

        if ($remainder) {
            $payload .= str_repeat('=', 4 - $remainder);
        }

        return base64_decode(strtr($payload, '-_', '+/'), true) ?: '';
    }
}
