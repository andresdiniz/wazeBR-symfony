<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\MonitoredLink;
use App\Entity\Partner;
use App\Enum\LinkType;
use App\Repository\MonitoredLinkRepository;
use App\Repository\PartnerRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * CRUD de MonitoredLink no painel admin.
 * Rotas base: /admin/links
 */
#[Route('/admin/links', name: 'admin_link_')]
#[IsGranted('ROLE_ADMIN')]
class MonitoredLinkAdminController extends AbstractController
{
    public function __construct(
        private readonly MonitoredLinkRepository $linkRepo,
        private readonly PartnerRepository       $partnerRepo,
        private readonly EntityManagerInterface  $em,
    ) {}

    /** Lista todos os links agrupados por parceiro */
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $links = $this->linkRepo->findAll();

        $byPartner = [];
        foreach ($links as $link) {
            $pid = $link->getPartner()?->getId() ?? 0;
            $byPartner[$pid]['partner'] = $link->getPartner();
            $byPartner[$pid]['links'][] = $link;
        }

        return $this->render('admin/link/index.html.twig', [
            'by_partner' => $byPartner,
        ]);
    }

    /** Formulário de criação de link */
    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $partners = $this->partnerRepo->findActivePartners();

        if ($request->isMethod('POST')) {
            $partnerId = (int) $request->request->get('partner_id');
            $partner   = $this->partnerRepo->find($partnerId);

            if (!$partner) {
                $this->addFlash('error', 'Parceiro não encontrado.');
                return $this->redirectToRoute('admin_link_new');
            }

            $linkTypeValue = $request->request->get('link_type', LinkType::WazeFeed->value);
            $linkType = LinkType::tryFrom($linkTypeValue);
            if ($linkType === null) {
                $this->addFlash('error', 'Tipo inválido.');
                return $this->redirectToRoute('admin_link_new');
            }

            $feedFormat = (int) $request->request->get('feed_format', 1);

            $link = (new MonitoredLink())
                ->setLabel((string) $request->request->get('label'))
                ->setUrl((string) $request->request->get('url'))
                ->setLinkType($linkType)
                ->setFeedFormat($feedFormat)
                ->setIsActive(true);

            $link->setPartner($partner);

            $this->em->persist($link);
            $this->em->flush();

            $this->addFlash('success',
                "Link '{$link->getLabel()}' ({$linkType->label()}) criado para {$partner->getName()}."
            );

            return $this->redirectToRoute('admin_link_index');
        }

        return $this->render('admin/link/new.html.twig', [
            'partners'   => $partners,
            'link_types' => LinkType::cases(),
        ]);
    }

    /** Editar link */
    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(MonitoredLink $link, Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $linkTypeValue = $request->request->get('link_type', $link->getLinkType()->value);
            $linkType = LinkType::tryFrom($linkTypeValue);
            if ($linkType === null) {
                $this->addFlash('error', 'Tipo inválido.');
                return $this->redirectToRoute('admin_link_edit', ['id' => $link->getId()]);
            }

            $feedFormat = (int) $request->request->get('feed_format', $link->getFeedFormat());

            $link
                ->setLabel((string) $request->request->get('label'))
                ->setUrl((string) $request->request->get('url'))
                ->setLinkType($linkType)
                ->setFeedFormat($feedFormat);

            $this->em->flush();

            $this->addFlash('success', "Link '{$link->getLabel()}' atualizado.");
            return $this->redirectToRoute('admin_link_index');
        }

        return $this->render('admin/link/edit.html.twig', [
            'link'       => $link,
            'link_types' => LinkType::cases(),
        ]);
    }

    /** Ativar / desativar */
    #[Route('/{id}/toggle', name: 'toggle', methods: ['POST'])]
    public function toggle(MonitoredLink $link): Response
    {
        $link->setIsActive(!$link->isActive());
        $this->em->flush();

        $status = $link->isActive() ? 'ativado' : 'desativado';
        $this->addFlash('success', "Link '{$link->getLabel()}' {$status}.");

        return $this->redirectToRoute('admin_link_index');
    }

    /** Deletar link */
    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(MonitoredLink $link, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('delete_link_' . $link->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF inválido.');
            return $this->redirectToRoute('admin_link_index');
        }

        $label = $link->getLabel() ?? $link->getUrl();
        $this->em->remove($link);
        $this->em->flush();

        $this->addFlash('success', "Link '{$label}' removido.");
        return $this->redirectToRoute('admin_link_index');
    }
}
