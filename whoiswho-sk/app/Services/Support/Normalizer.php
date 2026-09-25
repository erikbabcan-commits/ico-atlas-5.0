<?php

namespace App\Services\Support;

class Normalizer
{
    public static function ico(?string $ico): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $ico);
        if ($digits === null || $digits === '' || strlen($digits) !== 8) {
            return null;
        }

        return $digits;
    }

    public static function personNameNorm(string $givenName, string $familyName): string
    {
        return self::slug($familyName . '-' . $givenName);
    }

    public static function seatNorm(?string $street, ?string $municipality, ?string $postalCode): ?string
    {
        $postalCode = $postalCode !== null ? preg_replace('/\s+/', '', $postalCode) : null;
        $parts = array_filter([$street, $municipality, $postalCode], fn ($p) => $p !== null && $p !== '');

        if ($parts === []) {
            return null;
        }

        return self::slug(implode('|', $parts));
    }

    public static function slug(string $value): string
    {
        $value = mb_strtolower($value);
        $value = str_replace(
            ['á', 'ä', 'č', 'ď', 'é', 'í', 'ĺ', 'ľ', 'ň', 'ó', 'ô', 'ŕ', 'š', 'ť', 'ú', 'ý', 'ž'],
            ['a', 'a', 'c', 'd', 'e', 'i', 'l', 'l', 'n', 'o', 'o', 'r', 's', 't', 'u', 'y', 'z'],
            $value
        );
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        $value = trim($value, '-');

        return $value === '' ? 'unknown' : $value;
    }
}
