<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    /**
     * Home page - public access
     * Main landing page for the wazeBR system
     */
    #[Route('/', name: 'home', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('home/index.html.twig', [
            'current_route' => 'home',
        ]);
        if ($this->getUser()) {
            // Super admin vai direto para a gestão de parceiros
            if ($this->isGranted('ROLE_SUPER_ADMIN')) {
                return $this->redirectToRoute('admin_partner_index');
            }

            return $this->redirectToRoute('dashboard_index');
        }

        return $this->render('home/landing.html.twig');
    }
}


