<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\PartnerRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class PartnerFeedGeocodeController extends AbstractController
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly PartnerRepository $partnerRepository,
    ) {}

    #[Route(
        '/partner-feed/{code}/reverse-geocode',
        name: 'partner_feed_reverse_geocode',
        methods: ['GET'],
    )]
    public function reverseGeocode(string $code, Request $request): JsonResponse
    {
        $lat = $request->query->get('lat');
        $lon = $request->query->get('lon');

        if ($lat === null || $lon === null) {
            return $this->json(['error' => 'lat e lon são obrigatórios'], 400);
        }

        $partner = $this->partnerRepository->findOneBy([
            'code'     => $code,
            'isActive' => true,
        ]);

        if ($partner === null) {
            return $this->json(['error' => 'Parceiro não encontrado'], 404);
        }

        $token = $partner->getReverseGeocodingToken();
        if (!$token) {
            return $this->json(['error' => 'Token não configurado'], 422);
        }

        $base = $partner->getReverseGeocodingBaseUrl();

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
        } catch (\Throwable $e) {
            return $this->json(
                ['error' => 'Falha ao consultar Waze', 'detail' => $e->getMessage()],
                502,
            );
        }
    }
}
