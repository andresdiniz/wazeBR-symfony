<?php

declare(strict_types=1);

namespace App\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;

final class BriefingExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = array_replace([], ...$configs);
        $strategy = (string) ($config['strategy'] ?? 'always');

        if (!in_array($strategy, ['always', 'rotate', 'weekly'], true)) {
            throw new \InvalidArgumentException(
                'briefing.strategy deve ser always, rotate ou weekly.',
            );
        }

        $container->setParameter('briefing.strategy', $strategy);
        $container->setParameter(
            'briefing.schedule',
            (string) ($config['schedule'] ?? '0 9 * * 1-5'),
        );
        $container->setParameter(
            'briefing.max_tokens_prompt',
            (int) ($config['max_tokens_prompt'] ?? 800),
        );
    }

    public function getAlias(): string
    {
        return 'briefing';
    }
}
