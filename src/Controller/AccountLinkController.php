<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\MonitoredLink;
use App\Enum\LinkType;
use App\Repository\MonitoredLinkRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Gerenciamento de links monitorados pelo administrador do parceiro.
 * Acessível a ROLE_ACCOUNT_ADMIN (e ROLE_SUPER_ADMIN por hierarquia).
 */
#[Route('/account/links', name: 'account_link_')]
#[IsGranted('ROLE_ACCOUNT_ADMIN')]
class AccountLinkController extends AbstractController
{
    public function __construct(
        private readonly MonitoredLinkRepository $linkRepository,
    ) {}

    // ──────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────

    /** Retorna o parceiro do usuário logado ou lança 403. */
    private function requirePartner(): \App\Entity\Partner
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $partner = $user->getPartner();

        if (!$partner) {
            throw $this->createAccessDeniedException('Usuário sem parceiro vinculado.');
        }

        return $partner;
    }

    // ──────────────────────────────────────────────────────────────────────
    // LIST
    // ──────────────────────────────────────────────────────────────────────

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $partner = $this->requirePartner();
        $links   = $this->linkRepository->findByPartner($partner);

        // Agrupa por tipo
        $grouped = [];
        foreach (LinkType::cases() as $type) {
            $grouped[$type->value] = [
                'type'  => $type,
                'links' => [],
            ];
        }
        foreach ($links as $link) {
            $grouped[$link->getLinkType()->value]['links'][] = $link;
        }

        return $this->render('account/link/index.html.twig', [
            'partner' => $partner,
            'grouped' => array_filter($grouped, fn ($g) => count($g['links']) > 0),
            'allGrouped' => $grouped,
            'types'   => LinkType::cases(),
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // NEW
    // ──────────────────────────────────────────────────────────────────────

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $partner = $this->requirePartner();
        $errors  = [];

        // Pré-seleciona tipo via query string (?type=waze_tvt)
        $preType = $request->query->get('type', 'waze_feed');

        if ($request->isMethod('POST')) {
            $url        = trim((string) $request->request->get('url'));
            $label      = trim((string) $request->request->get('label')) ?: null;
            $typeValue  = (string) $request->request->get('link_type', 'waze_feed');
            $feedFormat = (int) $request->request->get('feed_format', 1);
            $isActive   = (bool) $request->request->get('isActive', false);

            if (!$url) {
                $errors['url'] = 'A URL é obrigatória.';
            } elseif (!filter_var($url, FILTER_VALIDATE_URL)) {
                $errors['url'] = 'URL inválida.';
            }

            $linkType = LinkType::tryFrom($typeValue);
            if (!$linkType) {
                $errors['link_type'] = 'Tipo de link inválido.';
            }

            if (!$errors) {
                $link = (new MonitoredLink())
                    ->setPartner($partner)
                    ->setUrl($url)
                    ->setLabel($label)
                    ->setLinkType($linkType)
                    ->setFeedFormat($feedFormat)
                    ->setIsActive($isActive);

                $this->linkRepository->save($link);

                $this->addFlash('success', "Link '{$label}' adicionado com sucesso.");

                return $this->redirectToRoute('account_link_index');
            }
        }

        return $this->render('account/link/new.html.twig', [
            'partner' => $partner,
            'types'   => LinkType::cases(),
            'preType' => $preType,
            'errors'  => $errors,
            'old'     => $request->request->all(),
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // EDIT
    // ──────────────────────────────────────────────────────────────────────

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(MonitoredLink $link, Request $request): Response
    {
        $partner = $this->requirePartner();

        // Garante que o link pertence ao parceiro do usuário
        if ($link->getPartner()->getId() !== $partner->getId()) {
            throw $this->createAccessDeniedException();
        }

        $errors = [];

        if ($request->isMethod('POST')) {
            $url        = trim((string) $request->request->get('url'));
            $label      = trim((string) $request->request->get('label')) ?: null;
            $typeValue  = (string) $request->request->get('link_type', 'waze_feed');
            $feedFormat = (int) $request->request->get('feed_format', 1);
            $isActive   = (bool) $request->request->get('isActive', false);

            if (!$url) {
                $errors['url'] = 'A URL é obrigatória.';
            } elseif (!filter_var($url, FILTER_VALIDATE_URL)) {
                $errors['url'] = 'URL inválida.';
            }

            $linkType = LinkType::tryFrom($typeValue);
            if (!$linkType) {
                $errors['link_type'] = 'Tipo de link inválido.';
            }

            if (!$errors) {
                $link
                    ->setUrl($url)
                    ->setLabel($label)
                    ->setLinkType($linkType)
                    ->setFeedFormat($feedFormat)
                    ->setIsActive($isActive);

                $this->linkRepository->save($link);

                $this->addFlash('success', 'Link atualizado com sucesso.');

                return $this->redirectToRoute('account_link_index');
            }
        }

        return $this->render('account/link/edit.html.twig', [
            'partner' => $partner,
            'link'    => $link,
            'types'   => LinkType::cases(),
            'errors'  => $errors,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // DELETE
    // ──────────────────────────────────────────────────────────────────────

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(MonitoredLink $link, Request $request): Response
    {
        $partner = $this->requirePartner();

        if ($link->getPartner()->getId() !== $partner->getId()) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('delete_link_' . $link->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token inválido.');
            return $this->redirectToRoute('account_link_index');
        }

        $label = $link->getLabel() ?? $link->getUrl();
        $this->linkRepository->remove($link);

        $this->addFlash('success', "Link '{$label}' removido.");

        return $this->redirectToRoute('account_link_index');
    }

    // ──────────────────────────────────────────────────────────────────────
    // TOGGLE ACTIVE
    // ──────────────────────────────────────────────────────────────────────

    #[Route('/{id}/toggle', name: 'toggle', methods: ['POST'])]
    public function toggle(MonitoredLink $link, Request $request): Response
    {
        $partner = $this->requirePartner();

        if ($link->getPartner()->getId() !== $partner->getId()) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('toggle_link_' . $link->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token inválido.');
            return $this->redirectToRoute('account_link_index');
        }

        $link->setIsActive(!$link->isActive());
        $this->linkRepository->save($link);

        $status = $link->isActive() ? 'ativado' : 'desativado';
        $this->addFlash('success', "Link {$status}.");

        return $this->redirectToRoute('account_link_index');
    }
}
