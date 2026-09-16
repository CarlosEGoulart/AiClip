<?php

namespace App\Exceptions;

use RuntimeException;

class ProcessMediaException extends RuntimeException
{
    public function __construct(
        string $message = '',
        public readonly int $exitCode = 1,
        public readonly string $stderr = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $this->exitCode, $previous);
    }

    /**
     * Create exception from worker JSON output.
     */
    public static function fromWorkerOutput(array $output, int $exitCode = 1): static
    {
        return new static(
            $output['error'] ?? 'Unknown worker error',
            $exitCode,
            $output['stderr'] ?? '',
        );
    }

    /**
     * Create exception for timeout.
     */
    public static function timeout(int $timeoutSeconds): static
    {
        return new static(
            "Worker process timed out after {$timeoutSeconds}s",
            1,
            '',
        );
    }
}
