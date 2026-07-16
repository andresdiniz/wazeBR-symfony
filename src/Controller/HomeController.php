<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function index(): Response
    {
        if ($this->getUser()) {
            // Super admin vai direto para a gestão de parceiros
            if ($this->isGranted('ROLE_SUPER_ADMIN')) {
                return $this->redirectToRoute('admin_partner_index');
            }

            return $this->redirectToRoute('dashboard_index');
        }

        return $this->render('home/index.html.twig');
    }
}
