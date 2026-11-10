<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Partner;
use App\Entity\User;
use App\Repository\JamRepository;
use App\Service\JamShareLinkGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/jams', name: 'jam_share_')]
final class JamShareController extends AbstractController
{
    public function __construct(
        private readonly JamShareLinkGenerator $generator,
        private readonly JamRepository $jamRepository,
    ) {
    }

    #[Route('/{id}/share', name: 'create', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function create(int $id, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $partner = $this->resolvePartnerScope();
        $jam = $this->jamRepository->findJamById($id, $partner);

        if ($jam === null) {
            throw new NotFoundHttpException('Congestionamento não encontrado.');
        }

        $duration = (int) $request->getPayload()->get('durationHours', 24);

        try {
            $link = $this->generator->create($id, $duration);
        } catch (\InvalidArgumentException $exception) {
            return $this->json([
                'ok' => false,
                'error' => 'Duração inválida.',
            ], 400);
        }

        return $this->json([
            'ok' => true,
            'url' => $link['url'],
            'expiresAt' => $link['expiresAt']->format(DATE_ATOM),
        ]);
    }

    #[Route('/share/{payload}/{signature}', name: 'public', methods: ['GET'])]
    public function public(string $payload, string $signature): Response
    {
        $data = $this->generator->verify($payload, $signature);

        if ($data === null) {
            throw new NotFoundHttpException('Link inválido ou expirado.');
        }

        $jam = $this->jamRepository->findJamById((int) $data['id'], null);

        if ($jam === null) {
            throw new NotFoundHttpException('Congestionamento não encontrado.');
        }

        return $this->render('jam/share.html.twig', [
            'jam' => $jam,
        ]);
    }

    private function resolvePartnerScope(): ?Partner
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if (!$user instanceof User) {
            return null;
        }

        if ($user->isGlobalAdmin()) {
            return null;
        }

        return $user->getPartner();
    }
}
