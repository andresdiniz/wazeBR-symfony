<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\CollectWazeFeedMessage;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Handler do Messenger que executa o WazeCollectFeedCommand
 * quando o Scheduler dispara CollectWazeFeedMessage.
 *
 * Usa a Application do Symfony para rodar o Command interno,
 * preservando o DI container completo.
 */
#[AsMessageHandler]
final class CollectWazeFeedHandler
{
    public function __construct(
        private readonly KernelInterface $kernel,
    ) {}

    public function __invoke(CollectWazeFeedMessage $message): void
    {
        $application = new \Symfony\Bundle\FrameworkBundle\Console\Application($this->kernel);
        $application->setAutoExit(false);

        $args = ['command' => 'app:waze:collect-feed'];

        if ($message->partnerSlug !== null) {
            $args['--partner'] = $message->partnerSlug;
        }

        $input = new ArrayInput($args);
        $application->run($input, new NullOutput());
    }
}
