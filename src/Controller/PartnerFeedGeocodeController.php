<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\PartnerRepository;
use App\Service\RemoteUrlGuard;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Security;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class PartnerFeedGeocodeController extends AbstractController
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly PartnerRepository $partnerRepository,
        private readonly RemoteUrlGuard $remoteUrlGuard,
        private readonly Security $security,
        #[Autowire(service: 'limiter.reverse_geocode')]
        private readonly RateLimiterFactory $rateLimiter,
    ) {}

    #[Route(
        '/partner-feed/{code}/reverse-geocode',
        name: 'partner_feed_reverse_geocode',
        methods: ['GET'],
    )]
    public function reverseGeocode(string $code, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');
        $key = (string) ($this->security->getUser()?->getUserIdentifier() ?? $request->getClientIp() ?? 'anonymous');
        if (!$this->rateLimiter->create($key)->consume()->isAccepted()) {
            return $this->json(['error' => 'Limite de consultas atingido.'], 429);
        }

        $lat = $request->query->get('lat');
        $lon = $request->query->get('lon');

        if ($lat === null || $lon === null || !is_numeric($lat) || !is_numeric($lon)
            || (float) $lat < -90 || (float) $lat > 90 || (float) $lon < -180 || (float) $lon > 180) {
            return $this->json(['error' => 'lat e lon são obrigatórios'], 400);
        }

        $partner = $this->partnerRepository->findOneBy([
            'code'     => $code,
            'isActive' => true,
        ]);

        if ($partner === null) {
            return $this->json(['error' => 'Parceiro não encontrado'], 404);
        }

        $user = $this->security->getUser();
        if (!$user instanceof User || (!$user->isGlobalAdmin() && $user->getPartner()?->getId() !== $partner->getId())) {
            throw $this->createAccessDeniedException();
        }

        $token = $partner->getReverseGeocodingToken();
        if (!$token) {
            return $this->json(['error' => 'Token não configurado'], 422);
        }

        $base = $partner->getReverseGeocodingBaseUrl();
        if (!$this->remoteUrlGuard->isAllowedPublicHttpUrl($base)) {
            return $this->json(['error' => 'Serviço de geocodificação indisponível'], 503);
        }

        try {
            $response = $this->httpClient->request('GET', $base, [
                'query' => [
                    'lat'   => $lat,
                    'lon'   => $lon,
                    'token' => $token,
                ],
                'timeout' => 5,
            ]);

            return $this->json($response->toArray(false));
        } catch (\Throwable) {
            return $this->json(['error' => 'Falha ao consultar o serviço de geocodificação'], 502);
        }
    }
}
