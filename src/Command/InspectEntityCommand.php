<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Partner;
use App\Entity\WazeFeed;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'debug:inspect-entity',
    description: 'Inspects entity methods',
)]
class InspectEntityCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('entity', InputArgument::REQUIRED, 'Entity class name (e.g., Partner, WazeFeed)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $entityName = $input->getArgument('entity');
        
        $className = match($entityName) {
            'Partner' => Partner::class,
            'WazeFeed' => WazeFeed::class,
            default => throw new \InvalidArgumentException("Unknown entity: $entityName"),
        };

        $reflection = new \ReflectionClass($className);
        
        $io->title(sprintf('%s Entity', $entityName));
        
        $io->section('Properties');
        foreach ($reflection->getProperties() as $property) {
            $io->text(sprintf('- %s', $property->getName()));
        }
        
        $io->section('Methods (getters)');
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (str_starts_with($method->getName(), 'get') || str_starts_with($method->getName(), 'is')) {
                $io->text(sprintf('- %s()', $method->getName()));
            }
        }

        return Command::SUCCESS;
    }
}
