<?php

declare(strict_types=1);

namespace App\Scheduler\Handler;

use App\Scheduler\Message\CollectWazeFeedMessage;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Executa o WazeCollectFeedCommand quando o Scheduler
 * dispara CollectWazeFeedMessage.
 */
#[AsMessageHandler]
final class CollectWazeFeedHandler
{
    public function __construct(
        private readonly KernelInterface $kernel,
    ) {}

    public function __invoke(CollectWazeFeedMessage $message): void
    {
        $application = new Application($this->kernel);
        $application->setAutoExit(false);

        $args = ['command' => 'app:waze:collect-feed'];
        if ($message->partnerSlug !== null) {
            $args['--partner'] = $message->partnerSlug;
        }

        $application->run(new ArrayInput($args), new NullOutput());
    }
}
