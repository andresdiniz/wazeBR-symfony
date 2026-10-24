<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AdminController extends AbstractController
{
    #[Route('/admin/overview', name: 'admin_overview', methods: ['GET'])]
    public function index(): Response
    {
        return $this->redirectToRoute('admin_users_index');
    }
}