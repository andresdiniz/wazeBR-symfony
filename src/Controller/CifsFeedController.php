<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Partner;
use App\Repository\PartnerRepository;
use App\Service\CifsFeedBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
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
    public function jsonFeed(string $code, Request $request): Response
    {
        $partner = $this->findActivePartner($code);
        if (!$partner) {
            return $this->json(
                ['error' => 'Parceiro nao encontrado ou inativo'],
                Response::HTTP_NOT_FOUND
            );
        }

        $body = json_encode(
            $this->feedBuilder->buildJsonPayload($partner),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        return $this->finalize(new Response($body), 'application/json; charset=utf-8', $request);
    }

    #[Route('/waze/{code}.xml', name: 'xml', methods: ['GET'])]
    public function xmlFeed(string $code, Request $request): Response
    {
        $partner = $this->findActivePartner($code);
        if (!$partner) {
            return new Response(
                '<error>Parceiro nao encontrado ou inativo</error>',
                Response::HTTP_NOT_FOUND,
                ['Content-Type' => 'application/xml; charset=utf-8']
            );
        }

        return $this->finalize(
            new Response($this->feedBuilder->buildXmlPayload($partner)),
            'application/xml; charset=utf-8',
            $request
        );
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

    private function finalize(Response $response, string $contentType, Request $request): Response
    {
        $response->headers->set('Content-Type', $contentType);
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Cache-Control', 'public, no-cache');
        $response->setEtag(md5((string) $response->getContent()));
        $response->isNotModified($request);

        return $response;
    }
}
