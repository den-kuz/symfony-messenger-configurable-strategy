<?php declare(strict_types=1);

namespace Symfony\Component\Messenger\Retry\Configurable;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\InvalidArgumentException;
use Symfony\Component\Messenger\Retry\RetryStrategyInterface;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;

/**
 * Configuration reference:
 *
 * It must be an array of items with the following structure:
 *      message_class: string|string[]            - class(es) of message
 *      exception_class: string|string[]          - class(es) of exception
 *      exception_code: string|int|string[]|int[] - code(s) of exception
 *
 *      retryable: bool|callable|null - True: always retryable, False: always not retryable, Null: depends on max_retries
 *                                      Also, can be callable/invokable object, that must return bool|null.
 *      delay: int|callable|null      - Int: Delay in milliseconds, Null: use default
 *                                      Also, can be callable/invokable object, that must return int|null
 *      max_retries: int|null         - Max retries count for configuration, Null/0 means there is no max retries
 *      multiplier: int|float|null    - Custom multiplier for configuration. Null/0 means there is no multiplier (or multiplier=1)
 *      max_delay: int|null           - Custom max delay for configuration. Null/0 means there is no max delay
 *
 * NOTE: Be careful. The strategy uses the first matching configuration. Sort your configurations by priority, or split them by different services
 * NOTE: Matching uses the following algorithm:
 *
 * - Exception is instance of ANY provided exception classes AND
 * - Exception code in array of provided exception codes AND
 * - Message class is instance of ANY provided message classes
 *
 * If some parts not provided, it means "true" in condition.
 * Examples:
 * For
 *     exception_class: [Exception1, Exception2]
 *     exception_code: [1, 2, 3]
 *
 * It means:
 * (($e instanceof Exception1) OR ($e instanceof Exception2)) AND
 * (($e->getCode() === 1) OR ($e->getCode() === 2) OR ($e->getCode() === 3))
 *
 * For
 *     message_class: [Message1, Message2]
 *     exception_class: [Exception1, Exception2]
 *     exception_code: [1, 2, 3]
 *
 * It means:
 * (($msg instanceof Message1) OR ($msg instanceof Message2)) AND
 * (($e instanceof Exception1) OR ($e instanceof Exception2)) AND
 * (($e->getCode() === 1) OR ($e->getCode() === 2) OR ($e->getCode() === 3))
 */
class ConfigurableRetryStrategy implements RetryStrategyInterface
{
    private $defaultDelay;
    private $defaultMaxDelay;
    private $defaultMaxRetries;
    private $defaultMultiplier;
    private $configuration;

    public function __construct(
        int $defaultDelay = 1000,
        int $defaultMaxDelay = 0,
        int $defaultMaxRetries = 10,
        float $defaultMultiplier = 1,
        array $configuration = []
    ) {
        $this->configuration = $configuration;

        if ($defaultMaxRetries < 0) {
            throw new InvalidArgumentException(sprintf('Max retries must be greater than or equal to zero: "%s" given.', $defaultMaxRetries));
        }
        $this->defaultMaxRetries = $defaultMaxRetries;

        if ($defaultDelay < 0) {
            throw new InvalidArgumentException(sprintf('Delay must be greater than or equal to zero: "%s" given.', $defaultDelay));
        }
        $this->defaultDelay = $defaultDelay;

        if ($defaultMaxDelay < 0) {
            throw new InvalidArgumentException(sprintf('Max delay must be greater than or equal to zero: "%s" given.', $defaultMaxDelay));
        }
        $this->defaultMaxDelay = $defaultMaxDelay;

        if ($defaultMultiplier < 1) {
            throw new InvalidArgumentException(sprintf('Multiplier must be greater than or equal to 1: "%s" given.', $defaultMaxDelay));
        }
        $this->defaultMultiplier = $defaultMultiplier;
    }

    public function isRetryable(Envelope $message, ?\Throwable $throwable = null): bool
    {
        $actualException = $this->getActualThrowable($throwable);
        foreach ($this->configuration as $configItem) {
            if (!$this->isConfigMatched($message, $actualException, $configItem)) {
                continue;
            }

            return $this->isRetryableForConfig($message, $actualException, $configItem);
        }

        return !$this->defaultMaxRetries || RedeliveryStamp::getRetryCountFromEnvelope($message) < $this->defaultMaxRetries;
    }

    public function getWaitingTime(Envelope $message, ?\Throwable $throwable = null): int
    {
        $actualException = $this->getActualThrowable($throwable);
        foreach ($this->configuration as $configItem) {
            if (!$this->isConfigMatched($message, $actualException, $configItem)) {
                continue;
            }

            return $this->getWaitingTimeForConfig($message, $actualException, $configItem);
        }

        $delay = $this->defaultDelay * $this->defaultMultiplier ** RedeliveryStamp::getRetryCountFromEnvelope($message);
        if ($this->defaultMaxDelay && $delay > $this->defaultMaxDelay) {
            $delay = $this->defaultMaxDelay;
        }

        return (int) $delay;
    }

    private function isRetryableForConfig(Envelope $envelope, ?\Throwable $throwable, array $config): bool
    {
        if (array_key_exists('retryable', $config)) {
            $retryable = $config['retryable'];
            if (is_callable($retryable)) {
                $retryable = $retryable($envelope, $throwable);
            } elseif ($retryable instanceof RetryableCheckerInterface) {
                $retryable = $retryable->isRetryable($envelope, $throwable);
            }

            if (!is_null($retryable)) {
                return (bool) $retryable;
            }
        }

        $maxRetries = array_key_exists('max_retries', $config) ? (int) $config['max_retries'] : $this->defaultMaxRetries;
        if ($maxRetries < 0) {
            throw new InvalidArgumentException(sprintf('Max retries must be greater than or equal to zero: "%s" given.', $maxRetries));
        }

        return !$maxRetries || RedeliveryStamp::getRetryCountFromEnvelope($envelope) < $maxRetries;
    }

    private function getWaitingTimeForConfig(Envelope $envelope, ?\Throwable $throwable, array $config)
    {
        $delay = null;
        if (array_key_exists('delay', $config)) {
            $delay = $config['delay'];
            if (is_callable($delay)) {
                $delay = $delay($envelope, $throwable);
            } elseif ($delay instanceof WaitingTimeCheckerInterface) {
                $delay = $delay->getWaitingTime($envelope, $throwable);
            }
        }

        if (is_null($delay)) {
            $delay = $this->defaultDelay;
        }
        $delay = (int) $delay;

        if ($delay < 0) {
            throw new InvalidArgumentException(sprintf('Delay must be greater than or equal to zero: "%s" given.', $delay));
        }

        $multiplier = array_key_exists('multiplier', $config) ? $config['multiplier'] : $this->defaultMultiplier;
        if ($multiplier) {
            $multiplier = (float) $multiplier;
            if ($multiplier < 1) {
                throw new InvalidArgumentException(sprintf('Multiplier must be greater than or equal to 1: "%s" given.', $multiplier));
            }

            $delay = $delay * $multiplier ** RedeliveryStamp::getRetryCountFromEnvelope($envelope);
        }

        $maxDelay = array_key_exists('max_delay', $config) ? $config['max_delay'] : $this->defaultMaxDelay;
        if ($maxDelay) {
            $maxDelay = (int) $maxDelay;
            if ($maxDelay < 0) {
                throw new InvalidArgumentException(sprintf('Max delay must be greater than or equal to zero: "%s" given.', $maxDelay));
            }

            if ($delay > $maxDelay) {
                $delay = $maxDelay;
            }
        }

        return (int) $delay;
    }

    private function isConfigMatched(Envelope $envelope, ?\Throwable $throwable, array $config): bool
    {
        $messageClasses = (array) ($config['message_class'] ?? []);
        $exceptionClasses = (array) ($config['exception_class'] ?? []);
        $exceptionCodes = (array) ($config['exception_code'] ?? []);

        return
            (!$messageClasses || $this->isObjectAnyOfClass($envelope->getMessage(), $messageClasses)) &&
            (!$exceptionClasses || $this->isObjectAnyOfClass($throwable, $exceptionClasses)) &&
            (!$exceptionCodes || $this->isThrowableAnyOfCode($throwable, $exceptionClasses));
    }

    private function isObjectAnyOfClass(?object $object, array $classes): bool
    {
        if (!$object) {
            return false;
        }

        foreach ($classes as $class) {
            if ($object instanceof $class) {
                return true;
            }
        }

        return false;
    }

    private function isThrowableAnyOfCode(?\Throwable $throwable, array $codes): bool
    {
        if (!$throwable) {
            return false;
        }

        foreach ($codes as $code) {
            if ($throwable->getCode() === $code) {
                return true;
            }
        }

        return false;
    }

    private function getActualThrowable(?\Throwable $throwable): ?\Throwable
    {
        $actualThrowable = $throwable;
        if ($actualThrowable instanceof HandlerFailedException && isset($actualThrowable->getNestedExceptions()[0])) {
            $actualThrowable = $actualThrowable->getNestedExceptions()[0];
        }

        return $actualThrowable;
    }
}
