<?php declare(strict_types=1);

namespace Symfony\Component\Messenger\Retry\Configurable;

use Symfony\Component\Messenger\Envelope;

interface RetryableCheckerInterface
{
    public function isRetryable(Envelope $message, ?\Throwable $throwable = null): ?bool;
}
