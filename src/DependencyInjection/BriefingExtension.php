<?php

declare(strict_types=1);

namespace App\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

/**
 * Lê config/packages/briefing.yaml e expõe os parâmetros para o container.
 *
 * Registrar no Kernel ou via bundle (se o projeto usar um AppBundle):
 *
 *   // src/Kernel.php — método registerContainerConfiguration() ou
 *   // via Bundle::build() se organizado como bundle.
 *
 * A forma mais simples para um projeto Symfony sem bundle customizado
 * é apenas chamar:
 *
 *   $container->loadFromExtension('briefing', [...]);
 *
 * O que acontece aqui:
 *   1. Lê o YAML de configuração
 *   2. Filtra apenas parceiros com ativo: true
 *   3. Expõe como parâmetros do container
 */
final class BriefingExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        // Merge de múltiplos arquivos de config se houver
        $config = array_merge_recursive(...$configs);

        $strategy  = $config['strategy']  ?? 'always';
        $parceiros = $config['parceiros'] ?? [];

        // Filtra apenas parceiros ativos
        $ativos = array_values(array_filter(
            $parceiros,
            fn(array $p) => ($p['ativo'] ?? true) === true
        ));

        $container->setParameter('briefing.strategy',          $strategy);
        $container->setParameter('briefing.parceiros_ativos',  $ativos);
        $container->setParameter('briefing.gemini_model',      $config['gemini_model'] ?? 'gemini-1.5-flash-latest');
        $container->setParameter('briefing.schedule',          $config['schedule'] ?? '0 9 * * 1-5');
    }

    public function getAlias(): string
    {
        return 'briefing';
    }
}
