<?php

namespace AperturePro\Storage\Traits;

use AperturePro\Helpers\Logger;

trait Retryable
{
    /**
     * Maximum number of retries.
     */
    protected int $maxRetries = 3;

    /**
     * Execute a callable with retry logic.
     *
     * @param callable $fn
     * @return mixed
     * @throws \Throwable
     */
    protected function executeWithRetry(callable $fn)
    {
        $attempts = 0;

        while (true) {
            try {
                return $fn();
            } catch (\Throwable $e) {
                $attempts++;

                if ($attempts > $this->maxRetries || !$this->shouldRetry($e)) {
                    throw $e;
                }

                $backoff = $this->calculateBackoff($attempts);

                // Log the retry attempt
                if (class_exists(Logger::class)) {
                     Logger::log('warning', 'storage_retry', sprintf(
                        'Retrying operation (attempt %d/%d) after %dms due to: %s',
                        $attempts,
                        $this->maxRetries,
                        $backoff,
                        $e->getMessage()
                    ), [
                        'driver' => (new \ReflectionClass($this))->getShortName(),
                        'exception' => get_class($e),
                        'attempt' => $attempts,
                    ]);
                }

                usleep($backoff * 1000);
            }
        }
    }

    /**
     * Determine if the exception is retryable.
     *
     * @param \Throwable $e
     * @return bool
     */
    protected function shouldRetry(\Throwable $e): bool
    {
        $message = $e->getMessage();
        $code = $e->getCode();

        // 4xx client errors (except 429) - Do NOT retry
        if ($code >= 400 && $code < 500 && $code != 429) {
            return false;
        }

        // Common retryable codes/messages

        // Network / Timeout
        if (stripos($message, 'timeout') !== false ||
            stripos($message, 'timed out') !== false ||
            stripos($message, 'cURL error 28') !== false) {
            return true;
        }

        // AWS/S3 specific
        if (stripos($message, 'RequestTimeout') !== false ||
            stripos($message, 'ThrottlingException') !== false ||
            stripos($message, 'SlowDown') !== false ||
            stripos($message, '503') !== false) {
            return true;
        }

        // HTTP 429 Too Many Requests
        if ($code == 429) {
            return true;
        }

        // Server errors 5xx
        if ($code >= 500 && $code < 600) {
            return true;
        }

        // Filesystem locks
        if (stripos($message, 'lock') !== false ||
            stripos($message, 'Resource temporarily unavailable') !== false) {
            return true;
        }

        return false;
    }

    /**
     * Calculate backoff with jitter.
     *
     * Strategy: 100ms -> 250ms -> 500ms
     *
     * @param int $attempt
     * @return int Milliseconds
     */
    protected function calculateBackoff(int $attempt): int
    {
        $backoff = match ($attempt) {
            1 => 100,
            2 => 250,
            3 => 500,
            default => 500,
        };

        // Add jitter: ±20%
        $jitter = $backoff * 0.2;
        $backoff += rand((int) -$jitter, (int) $jitter);

        return (int) max($backoff, 10);
    }
}
