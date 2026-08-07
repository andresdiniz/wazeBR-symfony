<?php

namespace App\Security;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

class LoginFormAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    use TargetPathTrait;

    public const LOGIN_ROUTE = 'auth_login';
    public const USERNAME_FIELD = 'email';
    public const PASSWORD_FIELD = 'password';
    public const CSRF_TOKEN_ID = 'authenticate';

    private UrlGeneratorInterface $urlGenerator;
    private EntityManagerInterface $entityManager;
    private CsrfTokenManagerInterface $csrfTokenManager;

    public function __construct(
        UrlGeneratorInterface $urlGenerator,
        EntityManagerInterface $entityManager,
        CsrfTokenManagerInterface $csrfTokenManager
    ) {
        $this->urlGenerator = $urlGenerator;
        $this->entityManager = $entityManager;
        $this->csrfTokenManager = $csrfTokenManager;
    }

    public function supports(Request $request): ?bool
    {
        return 'auth_login' === $request->attributes->get('_route')
            && $request->isMethod('POST');
    }

    public function authenticate(Request $request): Passport
    {
        $email = $request->request->get(self::USERNAME_FIELD, '');
        $password = $request->request->get(self::PASSWORD_FIELD, '');
        $csrfToken = $request->request->get('_csrf_token', '');

        if (!is_string($email) || !is_string($password)) {
            throw new BadCredentialsException('Invalid credentials format');
        }

        // Normalize email: lowercase, trim whitespace
        $email = trim(strtolower($email));

        if ('' === $email || '' === $password) {
            throw new BadCredentialsException('Email and password are required');
        }

        // Validate CSRF token
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_TOKEN_ID, $csrfToken))) {
            throw new AuthenticationException('Invalid CSRF token');
        }

        // Load user from database
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);

        if (!$user instanceof User) {
            throw new BadCredentialsException('Invalid email or password');
        }

        if (!$user->isEnabled()) {
            throw new BadCredentialsException('Account is disabled');
        }

        return new Passport(
            new UserBadge($email),
            new PasswordCredentials($password),
            [new RememberMeBadge()]
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        // Regenerate session to prevent session fixation
        $request->getSession()->invalidate();
        
        if ($targetPath = $this->getTargetPath($request->getSession(), $firewallName)) {
            if ($this->isSafeTarget($targetPath)) {
                return new Response($targetPath);
            }
        }

        return new Response($this->urlGenerator->generate('dashboard_index'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        // Log without sensitive data
        error_log(sprintf(
            'Auth failure - IP: %s, UA: %s, Reason: %s',
            $request->getClientIp(),
            $request->headers->get('User-Agent', 'unknown'),
            $exception->getMessageKey()
        ));

        $request->getSession()->set(Security::AUTHENTICATION_ERROR, $exception);
        
        return new Response($this->urlGenerator->generate('auth_login'));
    }

    public function start(Request $request, AuthenticationException $authException = null): Response
    {
        return new Response($this->urlGenerator->generate('auth_login'));
    }

    private function isSafeTarget(string $path): bool
    {
        if (!str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return false;
        }
        
        $blockedPaths = ['/logout', '/_profiler', '/_wdt'];
        foreach ($blockedPaths as $blocked) {
            if (str_starts_with($path, $blocked)) {
                return false;
            }
        }

        return true;
    }
}
