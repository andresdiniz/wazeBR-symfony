<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\RouteDetailRepository;
use App\Service\PartnerScopeResolver;
use App\Service\RouteReportBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Exception\ExpiredSignedUriException;
use Symfony\Component\HttpFoundation\Exception\SignedUriException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Relatório da rota para imprimir / salvar em PDF / compartilhar por link.
 *
 *   GET  /routes/{id}/report          relatório (usuário logado, respeita o escopo de parceiro)
 *   POST /routes/{id}/report/share    gera um link assinado com validade (1, 7 ou 30 dias)
 *   GET  /relatorio/rota/{id}         relatório público — exige o link assinado e não expirado
 *
 * O link público é de LEITURA e não exige login: quem o tiver vê o relatório daquela
 * rota até a data de validade. Ele só pode ser invalidado de uma vez, trocando o
 * APP_SECRET (o que também derruba sessões e outros links assinados).
 */
final class RouteReportController extends AbstractController
{
    private const SHARE_DAYS = [1, 7, 30];
    private const SHARE_DEFAULT_DAYS = 7;

    public function __construct(
        private readonly RouteDetailRepository $repository,
        private readonly RouteReportBuilder $builder,
        private readonly PartnerScopeResolver $scope,
        private readonly UriSigner $uriSigner,
    ) {
    }

    #[Route('/routes/{id}/report', name: 'routes_report', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function report(int $id): Response
    {
        $detail = $this->repository->getRouteDetail($this->scope->resolve(), $id)
            ?? throw $this->createNotFoundException('Rota não encontrada.');

        return $this->renderReport($detail, false, null);
    }

    #[Route('/routes/{id}/report/share', name: 'routes_report_share', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function share(int $id, Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('route_share_' . $id, (string) $request->headers->get('X-CSRF-Token'))) {
            return $this->json(['ok' => false, 'error' => 'csrf'], 403);
        }

        // Só quem enxerga a rota pode gerar o link.
        $this->repository->getRouteDetail($this->scope->resolve(), $id)
            ?? throw $this->createNotFoundException('Rota não encontrada.');

        $days = (int) $request->request->get('days', self::SHARE_DEFAULT_DAYS);
        if (!in_array($days, self::SHARE_DAYS, true)) {
            $days = self::SHARE_DEFAULT_DAYS;
        }

        $expires = new \DateTimeImmutable("+{$days} days");
        $url = $this->uriSigner->sign(
            $this->generateUrl('routes_report_public', ['id' => $id], UrlGeneratorInterface::ABSOLUTE_URL),
            $expires,
        );

        $response = $this->json([
            'ok'        => true,
            'url'       => $url,
            'days'      => $days,
            'expiresAt' => $expires->format(DATE_ATOM),
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    #[Route('/relatorio/rota/{id}', name: 'routes_report_public', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function publicReport(int $id, Request $request): Response
    {
        try {
            $this->uriSigner->verify($request);
        } catch (ExpiredSignedUriException) {
            return $this->denied('Este link expirou. Peça um novo link a quem o enviou.', Response::HTTP_GONE);
        } catch (SignedUriException) {
            return $this->denied('Link inválido.', Response::HTTP_FORBIDDEN);
        }

        // O link já autoriza esta rota: sem escopo de parceiro (leitura anônima).
        $detail = $this->repository->getRouteDetail(null, $id);
        if ($detail === null) {
            return $this->denied('Rota não encontrada.', Response::HTTP_NOT_FOUND);
        }

        $expiration = (int) $request->query->get('_expiration', 0);

        return $this->renderReport(
            $detail,
            true,
            $expiration > 0 ? (new \DateTimeImmutable())->setTimestamp($expiration) : null,
        );
    }

    /** @param array<string,mixed> $detail */
    private function renderReport(array $detail, bool $public, ?\DateTimeImmutable $expiresAt): Response
    {
        $response = $this->render('routes/report.html.twig', $detail + [
            'report'    => $this->builder->build($detail),
            'public'    => $public,
            'expiresAt' => $expiresAt,
        ]);

        // Relatório com dados da rota: nunca indexar nem guardar em cache compartilhado.
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    private function denied(string $message, int $status): Response
    {
        $html = sprintf(
            '<!doctype html><html lang="pt-BR"><meta charset="utf-8"><meta name="robots" content="noindex">'
            . '<title>Relatório indisponível</title>'
            . '<body style="font-family:system-ui,sans-serif;max-width:520px;margin:12vh auto;padding:0 20px;color:#0f172a">'
            . '<h1 style="font-size:20px">Relatório indisponível</h1><p>%s</p></body></html>',
            htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        );

        return new Response($html, $status, ['X-Robots-Tag' => 'noindex, nofollow', 'Cache-Control' => 'no-store']);
    }
}
