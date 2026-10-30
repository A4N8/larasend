<?php

namespace App\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use JsonException;

class EmailEventCursor
{
    /**
     * @param  array<string, int|string>  $context
     * @param  array{phase: string, legacy_after_id: int, legacy_upper_id: int, after_sequence: int, upper_sequence: int}  $state
     */
    public function encode(array $context, array $state): string
    {
        return Crypt::encryptString(json_encode(['version' => 1, ...$context, ...$state], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, int|string>  $context
     * @return array{phase: string, legacy_after_id: int, legacy_upper_id: int, after_sequence: int, upper_sequence: int}
     */
    public function decode(string $cursor, array $context): array
    {
        try {
            $decoded = json_decode(Crypt::decryptString($cursor), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            throw $this->invalidCursor();
        }

        if (! is_array($decoded) || ($decoded['version'] ?? null) !== 1) {
            throw $this->invalidCursor();
        }

        foreach ($context as $field => $value) {
            if (($decoded[$field] ?? null) !== $value) {
                throw $this->invalidCursor();
            }
        }

        if (! in_array($decoded['phase'] ?? null, ['legacy', 'sequence', 'checkpoint'], true)) {
            throw $this->invalidCursor();
        }

        foreach (['legacy_after_id', 'legacy_upper_id', 'after_sequence', 'upper_sequence'] as $field) {
            if (! isset($decoded[$field]) || ! is_int($decoded[$field]) || $decoded[$field] < 0) {
                throw $this->invalidCursor();
            }
        }

        if ($decoded['legacy_after_id'] > $decoded['legacy_upper_id'] || $decoded['after_sequence'] > $decoded['upper_sequence']) {
            throw $this->invalidCursor();
        }

        return array_intersect_key($decoded, array_flip(['phase', 'legacy_after_id', 'legacy_upper_id', 'after_sequence', 'upper_sequence']));
    }

    private function invalidCursor(): ValidationException
    {
        return ValidationException::withMessages(['cursor' => 'The history cursor is invalid for this connection or email.']);
    }
}
