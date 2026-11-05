<?php

namespace App\Log;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Sanitizes security-sensitive values from log context before they are
 * written to any log channel. Masks or excludes access tokens, refresh
 * tokens, API secrets, DB credentials, storage keys, bucket names, and
 * prompts so they never appear in log records.
 */
class SanitizeLogProcessor implements ProcessorInterface
{
    /**
     * Context keys that must never be written to logs in plaintext.
     *
     * @var list<string>
     */
    protected array $sensitiveKeys = [
        'access_token',
        'refresh_token',
        'api_secret',
        'client_secret',
        'db_password',
        'password',
        'secret',
        'storage_key',
        'storage_disk',
        'bucket',
        'prompt',
        'connection_string',
        'dsn',
    ];

    /**
     * Masked placeholder used in place of a sensitive value.
     */
    protected string $mask = '[REDACTED]';

    /**
     * Process a log record, sanitizing its context and any exception
     * message that might embed secrets (e.g. DB connection strings with
     * passwords).
     */
    public function __invoke(LogRecord $record): LogRecord
    {
        $context = $this->sanitizeContext($record->getContext() ?? []);

        $message = $record->getMessage();
        if (is_string($message)) {
            $message = $this->sanitizeString($message);
        }

        if (isset($context['exception'])) {
            $context['exception'] = $this->sanitizeException(
                $context['exception']
            );
        }

        return new LogRecord(
            $record->getDatetime(),
            $record->getChannel(),
            $record->getLevel(),
            $message,
            $context,
        );
    }

    /**
     * Sanitize a log context array, replacing sensitive keys with a mask.
     */
    protected function sanitizeContext(array $context): array
    {
        $sanitized = [];

        foreach ($context as $key => $value) {
            $keyLower = strtolower((string) $key);

            if ($this->isSensitiveKey($keyLower)) {
                $sanitized[$key] = $this->mask;
                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizeContext($value);
            } elseif (is_string($value)) {
                $sanitized[$key] = $this->sanitizeString($value);
            } elseif ($value instanceof \Throwable) {
                $sanitized[$key] = $this->sanitizeException($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Determine whether a context key is security-sensitive.
     */
    protected function isSensitiveKey(string $key): bool
    {
        foreach ($this->sensitiveKeys as $sensitive) {
            if ($key === $sensitive || str_contains($key, $sensitive)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sanitize a string, redacting any embedded tokens/secrets patterns.
     */
    protected function sanitizeString(string $value): string
    {
        // Redact DB connection strings that may embed passwords.
        $value = preg_replace(
            '/password=[^;\s]+/i',
            'password=[REDACTED]',
            $value
        );

        // Redact access_token / refresh_token query parameters.
        $value = preg_replace(
            '/(access_token|refresh_token)=[^&\s]+/i',
            '$1=[REDACTED]',
            $value
        );

        return $value;
    }

    /**
     * Sanitize a throwable: return a safe message without stack trace or
     * embedded credentials.
     */
    protected function sanitizeException(\Throwable $exception): array
    {
        return [
            'class' => get_class($exception),
            'message' => $this->sanitizeString($exception->getMessage()),
        ];
    }

    /**
     * Get the list of sensitive keys (for testing/introspection).
     *
     * @return list<string>
     */
    public function sensitiveKeys(): array
    {
        return $this->sensitiveKeys;
    }
}
