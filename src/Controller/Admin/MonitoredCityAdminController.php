<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\MonitoredCity;
use App\Repository\MonitoredCityRepository;
use App\Repository\PartnerRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * CRUD de MonitoredCity no painel admin.
 * Cidades são vinculadas a um parceiro e usadas para busca de clima via WeatherAPI.
 */
#[Route('/admin/cities', name: 'admin_city_')]
#[IsGranted('ROLE_SUPER_ADMIN')]
class MonitoredCityAdminController extends AbstractController
{
    public function __construct(
        private readonly MonitoredCityRepository $cityRepo,
        private readonly PartnerRepository       $partnerRepo,
        private readonly EntityManagerInterface  $em,
        private readonly SettingsAdminController $settings,
    ) {}

    /** Lista todas as cidades (todas as ativas + inativas) */
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $cities = $this->em->getRepository(MonitoredCity::class)
            ->createQueryBuilder('c')
            ->leftJoin('c.partner', 'p')
            ->addSelect('p')
            ->orderBy('p.name', 'ASC')
            ->addOrderBy('c.state', 'ASC')
            ->addOrderBy('c.name', 'ASC')
            ->getQuery()->getResult();

        return $this->render('admin/city/index.html.twig', [
            'cities'        => $cities,
            'weatherApiKey' => $this->settings->getWeatherApiKey(),
        ]);
    }

    /** Formulário de nova cidade */
    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $partners = $this->partnerRepo->findActivePartners();

        if ($request->isMethod('POST')) {
            $partner = $this->partnerRepo->find((int) $request->request->get('partner_id'));

            if (!$partner) {
                $this->addFlash('error', 'Parceiro não encontrado.');
                return $this->redirectToRoute('admin_city_new');
            }

            $city = (new MonitoredCity())
                ->setName((string) $request->request->get('name'))
                ->setState(strtoupper((string) $request->request->get('state')))
                ->setPartner($partner)
                ->setIsActive(true);

            $this->em->persist($city);
            $this->em->flush();

            $this->addFlash('success', "Cidade '{$city->getName()}/{$city->getState()}' criada para {$partner->getName()}.");
            return $this->redirectToRoute('admin_city_index');
        }

        return $this->render('admin/city/new.html.twig', [
            'partners' => $partners,
        ]);
    }

    /** Editar cidade */
    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(MonitoredCity $city, Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $city
                ->setName((string) $request->request->get('name'))
                ->setState(strtoupper((string) $request->request->get('state')));

            $this->em->flush();

            $this->addFlash('success', "Cidade '{$city->getName()}' atualizada.");
            return $this->redirectToRoute('admin_city_index');
        }

        return $this->render('admin/city/edit.html.twig', [
            'city' => $city,
        ]);
    }

    /** Ativar / desativar */
    #[Route('/{id}/toggle', name: 'toggle', methods: ['POST'])]
    public function toggle(MonitoredCity $city): Response
    {
        $city->setIsActive(!$city->isActive());
        $this->em->flush();

        $status = $city->isActive() ? 'ativada' : 'desativada';
        $this->addFlash('success', "Cidade '{$city->getName()}' {$status}.");

        return $this->redirectToRoute('admin_city_index');
    }

    /** Deletar cidade */
    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(MonitoredCity $city, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('del_city_' . $city->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF inválido.');
            return $this->redirectToRoute('admin_city_index');
        }

        $name = "{$city->getName()}/{$city->getState()}";
        $this->em->remove($city);
        $this->em->flush();

        $this->addFlash('success', "Cidade '{$name}' removida.");
        return $this->redirectToRoute('admin_city_index');
    }
}
