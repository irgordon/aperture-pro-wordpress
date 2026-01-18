<?php

namespace AperturePro\Storage\Retry;

use AperturePro\Helpers\Logger;

class RetryExecutor
{
    private int $maxRetries = 3;

    public function run(callable $fn): mixed
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

                if (class_exists(Logger::class)) {
                     Logger::log('warning', 'storage_retry', sprintf(
                        'Retrying operation (attempt %d/%d) after %dms due to: %s',
                        $attempts,
                        $this->maxRetries,
                        $backoff,
                        $e->getMessage()
                    ), [
                        'exception' => get_class($e),
                        'attempt' => $attempts,
                    ]);
                }

                usleep($backoff * 1000);
            }
        }
    }

    private function shouldRetry(\Throwable $e): bool
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

    private function calculateBackoff(int $attempt): int
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
