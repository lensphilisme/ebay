<?php
declare(strict_types=1);

namespace App\Services;

final class CjVariantParser
{
    private const SIZE_TOKENS = [
        'xxs', 'xs', 's', 'm', 'l', 'xl', 'xxl', 'xxxl', '3xl', '4xl', '5xl', '6xl', '7xl'
    ];

    private const MATERIAL_TOKENS = [
        'cotton', 'polyester', 'metal', 'plastic', 'wood', 'silicone', 'leather', 'glass', 'nylon', 'spandex', 'rubber'
    ];

    private const COLOR_TOKENS = [
        'black', 'white', 'red', 'blue', 'green', 'yellow', 'orange', 'purple', 'pink', 'grey', 'gray', 'brown', 'beige', 'gold', 'silver', 'transparent', 'light', 'dark'
    ];

    /** @return array<string, string> */
    public static function parseVariantKey(string $rawKey): array
    {
        $tokens = self::toTokenList($rawKey);
        $attributes = [];
        $leftovers = [];

        foreach ($tokens as $token) {
            $clean = self::normalizeWord($token);
            if ($clean === '') {
                continue;
            }

            // Inline volume (e.g. 100ml)
            if (preg_match('/(?:^|\s)(\d+(?:\.\d+)?)\s*(ml|l|oz)(?:$|\s)/i', $clean, $matches)) {
                if (!isset($attributes['Volume'])) {
                    $attributes['Volume'] = $matches[1] . strtoupper($matches[2]);
                    $clean = self::normalizeWord(str_replace($matches[0], ' ', $clean));
                }
            }

            $volume = self::parseVolume($clean);
            if ($volume !== null && !isset($attributes['Volume'])) {
                $attributes['Volume'] = $volume;
                continue;
            }

            if (!isset($attributes['Volume'])) {
                $embeddedVolume = self::extractEmbeddedToken($clean, '/\b\d+(?:\.\d+)?\s*(ml|l|oz)\b/i');
                if ($embeddedVolume['value'] !== null) {
                    $parsedVolume = self::parseVolume($embeddedVolume['value']);
                    if ($parsedVolume !== null) {
                        $attributes['Volume'] = $parsedVolume;
                        $clean = $embeddedVolume['rest'];
                    }
                }
            }

            if (!isset($attributes['Length'])) {
                $embeddedLength = self::extractEmbeddedToken($clean, '/\b\d+(?:\.\d+)?\s*(cm|mm|in|inch|inches|")\b/i');
                if ($embeddedLength['value'] !== null) {
                    $parsedLength = self::parseLength($embeddedLength['value']);
                    if ($parsedLength !== null) {
                        $attributes['Length'] = $parsedLength;
                        $clean = $embeddedLength['rest'];
                    }
                }
            }

            if (!isset($attributes['Size'])) {
                $embeddedSizePattern = '/\b(?:' . implode('|', self::SIZE_TOKENS) . ')\b/i';
                $embeddedSize = self::extractEmbeddedToken($clean, $embeddedSizePattern);
                if ($embeddedSize['value'] !== null) {
                    $parsedSize = self::parseSize($embeddedSize['value']);
                    if ($parsedSize !== null) {
                        $attributes['Size'] = $parsedSize;
                        $clean = $embeddedSize['rest'];
                    }
                }
            }

            if (!isset($attributes['Size'])) {
                $embeddedNumericSize = self::extractEmbeddedToken($clean, '/\bsize\s*(\d{1,3}(?:\.\d{1,2})?)\b/i');
                if ($embeddedNumericSize['value'] !== null && preg_match('/(\d{1,3}(?:\.\d{1,2})?)/', $embeddedNumericSize['value'], $sizeMatch) === 1) {
                    $attributes['Size'] = $sizeMatch[1];
                    $clean = $embeddedNumericSize['rest'];
                }
            }

            $size = self::parseSize($clean);
            if ($size !== null && !isset($attributes['Size'])) {
                $attributes['Size'] = $size;
                continue;
            }

            $length = self::parseLength($clean);
            if ($length !== null && !isset($attributes['Length'])) {
                $attributes['Length'] = $length;
                continue;
            }

            $material = self::parseMaterial($clean);
            if ($material !== null && !isset($attributes['Material'])) {
                $attributes['Material'] = $material;
                continue;
            }

            $color = self::parseColor($clean);
            if ($color !== null && !isset($attributes['Color'])) {
                $attributes['Color'] = $color;
                continue;
            }

            if ($clean !== '') {
                $leftovers[] = $clean;
            }
        }

        foreach ($leftovers as $leftover) {
            if ($leftover === '') {
                continue;
            }

            $color = self::parseColor($leftover);
            if ($color !== null && !isset($attributes['Color'])) {
                $attributes['Color'] = $color;
                continue;
            }

            if (!isset($attributes['Style'])) {
                $attributes['Style'] = $leftover;
                continue;
            }

            if (!isset($attributes['Option 1'])) {
                $attributes['Option 1'] = $leftover;
                continue;
            }
            if (!isset($attributes['Option 2'])) {
                $attributes['Option 2'] = $leftover;
                continue;
            }
            if (!isset($attributes['Option 3'])) {
                $attributes['Option 3'] = $leftover;
                continue;
            }
        }

        // Return cleaned and title-cased keys for eBay
        $cleanedAttributes = [];
        foreach ($attributes as $key => $value) {
            $val = self::normalizeAttributeValue($value);
            if ($val !== null) {
                // Ensure no eBay reserved keywords are used as is!
                $reserved = ['quantity', 'startprice', 'price', 'condition', 'sku', 'upc', 'isbn', 'ean'];
                if (in_array(strtolower($key), $reserved, true)) {
                    $key .= ' Option';
                }
                $cleanedAttributes[$key] = $val;
            }
        }

        return $cleanedAttributes;
    }

    /** @return list<string> */
    private static function toTokenList(string $raw): array
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return [];
        }

        if (str_starts_with($trimmed, '[') && str_ends_with($trimmed, ']')) {
            $parsed = json_decode($trimmed, true);
            if (is_array($parsed) && array_is_list($parsed)) {
                $res = [];
                foreach ($parsed as $v) {
                    $str = trim((string) $v);
                    if ($str !== '') $res[] = $str;
                }
                if ($res !== []) return $res;
            }
        }

        $tokens = preg_split('/[\-|,|\/]+/', $trimmed) ?: [];
        $res = [];
        foreach ($tokens as $v) {
            $v = trim($v);
            if ($v !== '') $res[] = $v;
        }
        return $res;
    }

    private static function normalizeWord(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }

    private static function normalizeAttributeValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $normalized = self::normalizeWord((string) $value);
        return $normalized === '' ? null : $normalized;
    }

    private static function parseVolume(string $token): ?string
    {
        if (preg_match('/^(\d+(?:\.\d+)?)\s*(ml|l|oz)$/i', $token, $match)) {
            return $match[1] . strtoupper($match[2]);
        }
        return null;
    }

    private static function parseSize(string $token): ?string
    {
        $normalized = strtolower(preg_replace('/\s+/', '', $token) ?? '');
        if (in_array($normalized, self::SIZE_TOKENS, true)) {
            return strtoupper($normalized);
        }
        if (preg_match('/^\d{1,3}(?:\.\d{1,2})?$/', $normalized) === 1) {
            return strtoupper($token);
        }
        return null;
    }

    private static function parseLength(string $token): ?string
    {
        if (preg_match('/^(\d+(?:\.\d+)?)\s*(cm|mm|in|inch|inches|")$/i', $token, $match)) {
            $unit = strtolower($match[2]);
            if ($unit === '"' || $unit === 'inch' || $unit === 'inches') {
                $unit = 'in';
            }
            return $match[1] . $unit;
        }
        return null;
    }

    /** @return array{value: string|null, rest: string} */
    private static function extractEmbeddedToken(string $input, string $pattern): array
    {
        if (preg_match($pattern, $input, $match)) {
            $value = self::normalizeWord($match[0]);
            $rest = self::normalizeWord(str_replace($match[0], ' ', $input));
            return ['value' => $value, 'rest' => $rest];
        }
        return ['value' => null, 'rest' => $input];
    }

    private static function parseMaterial(string $token): ?string
    {
        $normalized = strtolower($token);
        if (in_array($normalized, self::MATERIAL_TOKENS, true)) {
            return self::normalizeWord($token);
        }
        return null;
    }

    private static function parseColor(string $token): ?string
    {
        $normalized = strtolower(self::normalizeWord($token));
        if (in_array($normalized, self::COLOR_TOKENS, true)) {
            return self::normalizeWord($token);
        }

        $parts = preg_split('/\s+/', $normalized) ?: [];
        if ($parts !== [] && array_reduce($parts, static fn (bool $carry, string $part): bool => $carry && in_array($part, self::COLOR_TOKENS, true), true)) {
            return self::normalizeWord($token);
        }

        if (preg_match('/^(light|dark)\s+[a-z]+$/i', $normalized) === 1) {
            return self::normalizeWord($token);
        }

        return null;
    }
}
