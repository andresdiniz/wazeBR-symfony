<?php

declare(strict_types=1);
namespace App\Twig;

use App\Service\RouteUiTranslator;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class RoutesTranslationExtension extends AbstractExtension
{
    public function __construct(private readonly RouteUiTranslator $ui) {}
    public function getFunctions(): array
    {
        return [
            new TwigFunction('routes_i18n', [$this->ui, 'messages']),
            new TwigFunction('routes_label', [$this->ui, 'label']),
            new TwigFunction('routes_date', [$this->ui, 'date']),
        ];
    }
}
