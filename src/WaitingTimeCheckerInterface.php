<?php declare(strict_types=1);

namespace Symfony\Component\Messenger\Retry\Configurable;

use Symfony\Component\Messenger\Envelope;

interface WaitingTimeCheckerInterface
{
    public function getWaitingTime(Envelope $message, ?\Throwable $throwable = null): ?int;
}
