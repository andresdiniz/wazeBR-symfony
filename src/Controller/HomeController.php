<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    // src/Controller/HomeController.php
#[Route('/', name: 'app_landing')]
public function landing(): Response
{
    $response = $this->render('landing.html.twig');

    // Browser guarda 3660s, CDN/proxy 5min. Depois disso, revalida.
    $response->setPublic();
    $response->setMaxAge(3660);
    $response->setSharedMaxAge(6000);
    $response->headers->addCacheControlDirective('must-revalidate');

    return $response;
}
}
