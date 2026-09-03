<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Serviço para implementar rate limiting avançado
 * 
 * Protege contra:
 * - Brute force em login
 * - Abuso de endpoints de reset de senha
 * - Enumeração de contas
 * - Ataques de força bruta distribuídos
 */
class RateLimiterService
{
    private const CACHE_PREFIX = 'rate_limit:';
    private const LOGIN_MAX_ATTEMPTS = 5;
    private const LOGIN_WINDOW = 60; // 1 minute
    private const FORGOT_PASSWORD_MAX_ATTEMPTS = 3;
    private const FORGOT_PASSWORD_WINDOW = 3600; // 1 hour
    private const RESET_PASSWORD_MAX_ATTEMPTS = 5;
    private const RESET_PASSWORD_WINDOW = 3600; // 1 hour

    public function __construct(
        // AdapterInterface (Symfony\Component\Cache\Adapter) não tem
        // autowiring automático — o FrameworkBundle só cria alias
        // autowireable pra CacheItemPoolInterface (PSR-6), apontando
        // pro pool "cache.app" configurado em config/packages/cache.yaml.
        // Os métodos usados aqui (getItem/save/deleteItem) já fazem
        // parte da interface PSR-6, então a troca é direta.
        private readonly CacheItemPoolInterface $cache,
    ) {}

    /**
     * Verifica se o IP excedeu o limite de tentativas de login
     */
    public function isLoginRateLimited(Request $request): bool
    {
        return $this->checkRateLimit(
            $this->getClientIp($request),
            'login',
            self::LOGIN_MAX_ATTEMPTS,
            self::LOGIN_WINDOW
        );
    }

    /**
     * Registra uma tentativa de login falhada
     */
    public function recordFailedLogin(Request $request): void
    {
        $this->recordAttempt(
            $this->getClientIp($request),
            'login',
            self::LOGIN_WINDOW
        );
    }

    /**
     * Limpa o contador de tentativas de login para um IP
     */
    public function clearLoginAttempts(Request $request): void
    {
        $this->clearAttempts($this->getClientIp($request), 'login');
    }

    /**
     * Verifica se o email excedeu o limite de solicitações de reset de senha
     */
    public function isForgotPasswordRateLimited(string $email): bool
    {
        return $this->checkRateLimit(
            hash('sha256', $email),
            'forgot_password',
            self::FORGOT_PASSWORD_MAX_ATTEMPTS,
            self::FORGOT_PASSWORD_WINDOW
        );
    }

    /**
     * Registra uma solicitação de reset de senha
     */
    public function recordForgotPasswordRequest(string $email): void
    {
        $this->recordAttempt(
            hash('sha256', $email),
            'forgot_password',
            self::FORGOT_PASSWORD_WINDOW
        );
    }

    /**
     * Verifica se o IP excedeu o limite de tentativas de reset de senha
     */
    public function isResetPasswordRateLimited(Request $request): bool
    {
        return $this->checkRateLimit(
            $this->getClientIp($request),
            'reset_password',
            self::RESET_PASSWORD_MAX_ATTEMPTS,
            self::RESET_PASSWORD_WINDOW
        );
    }

    /**
     * Registra uma tentativa de reset de senha
     */
    public function recordResetPasswordAttempt(Request $request): void
    {
        $this->recordAttempt(
            $this->getClientIp($request),
            'reset_password',
            self::RESET_PASSWORD_WINDOW
        );
    }

    /**
     * Obtém o número de tentativas restantes para um cliente
     */
    public function getRemainingAttempts(Request $request, string $action): int
    {
        $key = self::CACHE_PREFIX . $action . ':' . $this->getClientIp($request);
        $item = $this->cache->getItem($key);

        if (!$item->isHit()) {
            return match ($action) {
                'login' => self::LOGIN_MAX_ATTEMPTS,
                'forgot_password' => self::FORGOT_PASSWORD_MAX_ATTEMPTS,
                'reset_password' => self::RESET_PASSWORD_MAX_ATTEMPTS,
                default => 0,
            };
        }

        $attempts = $item->get() ?? 0;
        $max = match ($action) {
            'login' => self::LOGIN_MAX_ATTEMPTS,
            'forgot_password' => self::FORGOT_PASSWORD_MAX_ATTEMPTS,
            'reset_password' => self::RESET_PASSWORD_MAX_ATTEMPTS,
            default => 0,
        };

        return max(0, $max - $attempts);
    }

    /**
     * Verifica o rate limit genérico
     */
    private function checkRateLimit(
        string $identifier,
        string $action,
        int $maxAttempts,
        int $window
    ): bool {
        $key = self::CACHE_PREFIX . $action . ':' . $identifier;
        $item = $this->cache->getItem($key);

        if (!$item->isHit()) {
            return false;
        }

        $attempts = $item->get() ?? 0;
        return $attempts >= $maxAttempts;
    }

    /**
     * Registra uma tentativa
     */
    private function recordAttempt(string $identifier, string $action, int $window): void
    {
        $key = self::CACHE_PREFIX . $action . ':' . $identifier;
        $item = $this->cache->getItem($key);

        $attempts = ($item->get() ?? 0) + 1;
        $item->set($attempts);
        $item->expiresAfter($window);

        $this->cache->save($item);
    }

    /**
     * Limpa os registros de tentativas
     */
    private function clearAttempts(string $identifier, string $action): void
    {
        $key = self::CACHE_PREFIX . $action . ':' . $identifier;
        $this->cache->deleteItem($key);
    }

    /**
     * Obtém o IP do cliente de forma segura.
     *
     * Não lemos os headers X-Forwarded-For / CF-Connecting-IP na mão:
     * como o projeto não define `trusted_proxies` em framework.yaml,
     * esses headers não são cegamente confiáveis — qualquer cliente
     * pode enviá-los diretamente e burlar o rate limit trocando o
     * valor a cada requisição. `Request::getClientIp()` já resolve
     * isso corretamente: só considera X-Forwarded-For quando a
     * requisição vem de um proxy listado em `trusted_proxies`; caso
     * contrário retorna o IP real da conexão TCP.
     *
     * Se a aplicação estiver atrás de um proxy reverso/CDN em produção,
     * configure `framework.trusted_proxies` e `trusted_headers` (ver
     * config/packages/framework.yaml) — sem isso, todo IP aqui será o
     * do proxy, não o do visitante.
     */
    private function getClientIp(Request $request): string
    {
        return $request->getClientIp() ?? '127.0.0.1';
    }
}
