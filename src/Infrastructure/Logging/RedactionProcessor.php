<?php

declare(strict_types=1);

namespace App\Infrastructure\Logging;

use Monolog\LogRecord;

final class RedactionProcessor
{
    private const REDACTED = '[REDACTED]';
    private const REDACTED_EMAIL = '[REDACTED_EMAIL]';
    private const MAX_DEPTH = 8;

    public function __invoke(LogRecord $record): LogRecord
    {
        $message = ($record->context['exception'] ?? null) instanceof \Throwable
            ? self::REDACTED
            : $this->sanitizeText($record->message);

        return $record->with(
            message: $message,
            context: $this->sanitizeMap($record->context),
            extra: $this->sanitizeMap($record->extra),
        );
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return array<array-key, mixed>
     */
    private function sanitizeMap(array $values, int $depth = 0): array
    {
        $sanitized = [];

        foreach ($values as $key => $value) {
            $sanitized[$key] = $this->isSensitiveKey($key)
                ? self::REDACTED
                : $this->sanitizeValue($value, $depth + 1);
        }

        return $sanitized;
    }

    private function sanitizeValue(mixed $value, int $depth): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            return self::REDACTED;
        }

        if (\is_array($value)) {
            return $this->sanitizeMap($value, $depth);
        }

        if ($value instanceof \Throwable) {
            return [
                'type' => $value::class,
                'message' => self::REDACTED,
            ];
        }

        if ($value instanceof \Stringable) {
            return $this->sanitizeText((string) $value);
        }

        if (\is_object($value) || \is_resource($value)) {
            return self::REDACTED;
        }

        return \is_string($value) ? $this->sanitizeText($value) : $value;
    }

    private function isSensitiveKey(int|string $key): bool
    {
        if (!\is_string($key)) {
            return false;
        }

        return 1 === preg_match(
            '/(?:address|api.?key|authorization|body|content|cookie|credential|csrf|dsn|email|mfa|pass(?:word|wd)?|private.?key|recipient|recovery|secret|session|token)/i',
            $key,
        );
    }

    private function sanitizeText(string $value): string
    {
        $value = $this->replace(
            '/[A-Z0-9.!#$%&\'*+\/=?^_`{|}~-]+@[A-Z0-9](?:[A-Z0-9.-]{0,251}[A-Z0-9])?/i',
            self::REDACTED_EMAIL,
            $value,
        );
        $value = $this->replace(
            '/\b(Bearer)\s+\S+/i',
            '$1 '.self::REDACTED,
            $value,
        );
        $value = $this->replace(
            '/\b(password|passwd|secret|token|api[_-]?key)\s*([=:])\s*[^\s,;]+/i',
            '$1$2'.self::REDACTED,
            $value,
        );
        $value = $this->replace(
            '/\b([a-z][a-z0-9+.-]*:\/\/)[^@\s\/:]+:[^@\s]+@/i',
            '$1'.self::REDACTED.'@',
            $value,
        );

        return trim($this->replace('/[\x00-\x1F\x7F]+/', ' ', $value));
    }

    private function replace(string $pattern, string $replacement, string $subject): string
    {
        return preg_replace($pattern, $replacement, $subject) ?? self::REDACTED;
    }
}
