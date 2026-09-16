<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Partner;
use App\Entity\User;
use App\Repository\TvRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/tv', name: 'tv_')]
final class TvController extends AbstractController
{
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
        $payload = $repository->getWallboard($partner);

        $response = $this->json([
            'ok'   => true,
            'data' => $this->normalizeDatesForJson($payload),
        ]);

        // Nunca cachear — a TV precisa do estado corrente
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }

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
