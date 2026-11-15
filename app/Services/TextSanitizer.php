<?php

namespace App\Services;

class TextSanitizer
{
    public static function withoutNullBytes(mixed $value): mixed
    {
        if (is_string($value)) {
            return str_replace("\0", '', $value);
        }
        if (!is_array($value)) {
            return $value;
        }

        $clean = [];
        foreach ($value as $key => $item) {
            // PostgreSQL also rejects NUL characters in JSON object keys.
            $key = is_string($key) ? str_replace("\0", '', $key) : $key;
            $clean[$key] = self::withoutNullBytes($item);
        }

        return $clean;
    }
}
