<?php

declare(strict_types=1);

namespace App\Scheduler\Handler;

use App\Scheduler\Message\CollectWazeTvtMessage;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Executa o WazeCollectTvtCommand quando o Scheduler
 * dispara CollectWazeTvtMessage.
 */
#[AsMessageHandler]
final class CollectWazeTvtHandler
{
    public function __construct(
        private readonly KernelInterface $kernel,
    ) {}

    public function __invoke(CollectWazeTvtMessage $message): void
    {
        $application = new Application($this->kernel);
        $application->setAutoExit(false);

        $args = ['command' => 'app:waze:collect-tvt'];
        if ($message->partnerSlug !== null) {
            $args['--partner'] = $message->partnerSlug;
        }

        $application->run(new ArrayInput($args), new NullOutput());
    }
}
