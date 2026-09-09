<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\WazeFeedCollection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'debug:inspect-entity',
    description: 'Inspects WazeFeedCollection entity methods',
)]
class InspectEntityCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $reflection = new \ReflectionClass(WazeFeedCollection::class);
        
        $io->title('WazeFeedCollection Entity');
        
        $io->section('Properties');
        foreach ($reflection->getProperties() as $property) {
            $io->text(sprintf('- %s (%s)', $property->getName(), $property->getType() ?? 'mixed'));
        }
        
        $io->section('Methods (setters)');
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (str_starts_with($method->getName(), 'set')) {
                $params = [];
                foreach ($method->getParameters() as $param) {
                    $type = $param->getType() ? $param->getType()->getName() : 'mixed';
                    $params[] = sprintf('%s: %s', $param->getName(), $type);
                }
                $io->text(sprintf('- %s(%s)', $method->getName(), implode(', ', $params)));
            }
        }

        return Command::SUCCESS;
    }
}
