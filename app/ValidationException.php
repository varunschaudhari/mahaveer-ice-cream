<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

/**
 * Thrown by services when user input is rejected. $errors maps a form field (or '_' for the
 * whole form) to a lang key — or [lang key, params] — so pages can show each message next to
 * its field (see field_error()).
 */
final class ValidationException extends RuntimeException
{
    /** @param array<string, string|array{0: string, 1: array}> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Validation failed: ' . implode(', ', array_map(
            static fn ($field, $error) => $field . '=' . (is_array($error) ? $error[0] : $error),
            array_keys($errors),
            $errors
        )));
    }

    /** @param string|array{0: string, 1: array} $error lang key, or [lang key, params] */
    public static function one(string $field, string|array $error): self
    {
        return new self([$field => $error]);
    }

    /** The first error (for API responses that show one message). */
    public function first(): string|array
    {
        return $this->errors[array_key_first($this->errors)];
    }
}
