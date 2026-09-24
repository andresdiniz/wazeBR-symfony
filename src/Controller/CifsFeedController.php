<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Partner;
use App\Repository\PartnerRepository;
use App\Service\CifsFeedBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Endpoint publico que o Waze consome (feed outbound CIFS).
 *
 * Rotas (mesmo padrao do identificador {code} ja usado em partner_feed_json):
 *   GET /feeds/waze/{code}.json
 *   GET /feeds/waze/{code}.xml
 *
 * Sem login: o identificador publico do parceiro e a coluna `code`.
 * Para camada extra de seguranca, gere um token por parceiro e valide
 * via query string (?token=...).
 */
#[Route('/feeds', name: 'app_cifs_feed_')]
final class CifsFeedController extends AbstractController
{
    public function __construct(
        private readonly PartnerRepository $partnerRepository,
        private readonly CifsFeedBuilder $feedBuilder,
    ) {
    }

    #[Route('/waze/{code}.json', name: 'json', methods: ['GET'])]
    public function jsonFeed(string $code): JsonResponse
    {
        $partner = $this->findActivePartner($code);
        if (!$partner) {
            return $this->json(
                ['error' => 'Parceiro nao encontrado ou inativo'],
                Response::HTTP_NOT_FOUND
            );
        }

        $response = new JsonResponse($this->feedBuilder->buildJsonPayload($partner), Response::HTTP_OK);
        $response->headers->set('Content-Type', 'application/json; charset=utf-8');
        $this->disableCache($response);

        return $response;
    }

    #[Route('/waze/{code}.xml', name: 'xml', methods: ['GET'])]
    public function xmlFeed(string $code): Response
    {
        $partner = $this->findActivePartner($code);
        if (!$partner) {
            return new Response(
                '<error>Parceiro nao encontrado ou inativo</error>',
                Response::HTTP_NOT_FOUND,
                ['Content-Type' => 'application/xml']
            );
        }

        $response = new Response($this->feedBuilder->buildXmlPayload($partner), Response::HTTP_OK);
        $response->headers->set('Content-Type', 'application/xml; charset=utf-8');
        $this->disableCache($response);

        return $response;
    }

    private function findActivePartner(string $code): ?Partner
    {
        $partner = $this->partnerRepository->findOneBy(['code' => $code]);
        if (!$partner) {
            return null;
        }
        if (method_exists($partner, 'isActive') && !$partner->isActive()) {
            return null;
        }
        return $partner;
    }

    private function disableCache(Response $response): void
    {
        $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');
    }
}