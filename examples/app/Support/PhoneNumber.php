<?php

namespace App\Support;

/**
 * Phone number normalization for WhatsApp wa.me/ links (default: Italian +39).
 *
 * Pure, dependency-free value object. Not registered in any service provider —
 * call the static methods directly.
 */
final class PhoneNumber
{
    /**
     * Normalize a phone string to E.164 digits (no leading +) for wa.me/ links.
     *
     * Rules, applied in order:
     *   1. null / blank → null.
     *   2. International prefix recognised BEFORE stripping: if the string starts
     *      with '+' or '00', drop the prefix, strip non-digits, and use the digits
     *      as-is when >= 8 remain (no extra '39'); otherwise null.
     *   3. Otherwise strip non-digits: if the result starts with '3' and is 9–10
     *      digits long → prepend '39' (Italian mobile).
     *   4. Any other case (too short, not starting with 3, landline without prefix)
     *      → null (caller falls back to a "copy number" button).
     */
    public static function toE164(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $trimmed = trim($raw);

        if ($trimmed === '') {
            return null;
        }

        // 2. International prefix detected before stripping.
        if (str_starts_with($trimmed, '+') || str_starts_with($trimmed, '00')) {
            $withoutPrefix = str_starts_with($trimmed, '+')
                ? substr($trimmed, 1)
                : substr($trimmed, 2);

            $digits = preg_replace('/\D/', '', $withoutPrefix) ?? '';

            return strlen($digits) >= 8 ? $digits : null;
        }

        // 3. National number — strip non-digits.
        $digits = preg_replace('/\D/', '', $trimmed) ?? '';
        $len    = strlen($digits);

        if (str_starts_with($digits, '3') && ($len === 9 || $len === 10)) {
            return '39'.$digits;
        }

        // 4. Ambiguous / too short / landline without prefix.
        return null;
    }

    /**
     * Returns true if the number can be used in a wa.me/ link.
     */
    public static function isNormalizable(?string $raw): bool
    {
        return self::toE164($raw) !== null;
    }
}
