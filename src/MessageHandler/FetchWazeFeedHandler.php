<?php

namespace App\MessageHandler;

use App\Message\FetchWazeFeedMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class FetchWazeFeedHandler
{
    // Inject your 5 services directly into the constructor!
    public function __construct(
        // private MyService1 $service1,
        // private MyService2 $service2,
    ) {}

    public function __invoke(FetchWazeFeedMessage $message): void
    {
        // Put your Waze feed fetching logic here
    }
}
