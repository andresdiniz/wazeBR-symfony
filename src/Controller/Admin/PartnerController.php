<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Partner;
use App\Entity\User;
use App\Form\PartnerType;
use App\Form\PartnerUserType;
use App\Repository\PartnerRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/parceiros', name: 'admin_partner_')]
#[IsGranted('ROLE_SUPER_ADMIN')]
class PartnerController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PartnerRepository $partnerRepository,
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $hasher,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/partner/index.html.twig', [
            'partners' => $this->partnerRepository->findAllWithUserCount(),
        ]);
    }

    #[Route('/novo', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $partner = new Partner();
        $partner->generateApiToken();

        $form = $this->createForm(PartnerType::class, $partner);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Gera slug a partir do nome se não preenchido
            if (empty($partner->getSlug())) {
                $partner->setSlug($this->generateSlug($partner->getName()));
            }

            $this->em->persist($partner);
            $this->em->flush();

            $this->addFlash('success', 'Parceiro "' . $partner->getName() . '" criado com sucesso.');

            return $this->redirectToRoute('admin_partner_show', ['id' => $partner->getId()]);
        }

        return $this->render('admin/partner/new.html.twig', [
            'form'    => $form,
            'partner' => $partner,
        ]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'])]
    public function show(Partner $partner): Response
    {
        return $this->render('admin/partner/show.html.twig', [
            'partner' => $partner,
            'users'   => $this->userRepository->findByPartner($partner),
        ]);
    }

    #[Route('/{id}/editar', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(Partner $partner, Request $request): Response
    {
        $form = $this->createForm(PartnerType::class, $partner);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();
            $this->addFlash('success', 'Parceiro atualizado com sucesso.');

            return $this->redirectToRoute('admin_partner_show', ['id' => $partner->getId()]);
        }

        return $this->render('admin/partner/edit.html.twig', [
            'form'    => $form,
            'partner' => $partner,
        ]);
    }

    #[Route('/{id}/regenerar-token', name: 'regenerate_token', methods: ['POST'])]
    public function regenerateToken(Partner $partner, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('regen_token_' . $partner->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF inválido.');
            return $this->redirectToRoute('admin_partner_show', ['id' => $partner->getId()]);
        }

        $partner->generateApiToken();
        $this->em->flush();

        $this->addFlash('success', 'API Token regenerado com sucesso.');

        return $this->redirectToRoute('admin_partner_show', ['id' => $partner->getId()]);
    }

    #[Route('/{id}/toggle-ativo', name: 'toggle_active', methods: ['POST'])]
    public function toggleActive(Partner $partner, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('toggle_' . $partner->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF inválido.');
            return $this->redirectToRoute('admin_partner_index');
        }

        $partner->setIsActive(!$partner->isActive());
        $this->em->flush();

        $status = $partner->isActive() ? 'ativado' : 'desativado';
        $this->addFlash('success', 'Parceiro "' . $partner->getName() . '" ' . $status . '.');

        return $this->redirectToRoute('admin_partner_index');
    }

    #[Route('/{id}/usuarios/novo', name: 'user_new', methods: ['GET', 'POST'])]
    public function newUser(Partner $partner, Request $request): Response
    {
        $user = new User();
        $user->setPartner($partner);
        $user->setRoles(['ROLE_ACCOUNT_ADMIN']);

        $form = $this->createForm(PartnerUserType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $plainPassword = $form->get('plainPassword')->getData();
            $user->setPassword($this->hasher->hashPassword($user, $plainPassword));

            $this->em->persist($user);
            $this->em->flush();

            $this->addFlash('success', 'Usuário administrador "' . $user->getName() . '" criado.');

            return $this->redirectToRoute('admin_partner_show', ['id' => $partner->getId()]);
        }

        return $this->render('admin/partner/user_new.html.twig', [
            'form'    => $form,
            'partner' => $partner,
        ]);
    }

    private function generateSlug(string $name): string
    {
        $slug = mb_strtolower($name);
        $slug = preg_replace('/[áàãâä]/u', 'a', $slug);
        $slug = preg_replace('/[éèêë]/u', 'e', $slug);
        $slug = preg_replace('/[íìîï]/u', 'i', $slug);
        $slug = preg_replace('/[óòõôö]/u', 'o', $slug);
        $slug = preg_replace('/[úùûü]/u', 'u', $slug);
        $slug = preg_replace('/[ç]/u', 'c', $slug);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        return trim($slug, '-');
    }
}
