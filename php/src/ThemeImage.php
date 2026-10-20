<?php

namespace Ordering\ThemeEngine;

/**
 * What a schema may say of a theme image: the formats its key takes
 * (`images.<key>`: `[default, version 1 path, formats?, hints?]`,
 * self::DEFAULT_FORMATS when it names none or null) and what the editor
 * recommends (the hints: size, ratio, alpha, weight), within the limits an
 * upload has. Inspecting an uploaded file is api-core's (its own
 * App\Themes\ThemeImage, which adds that to this).
 */
class ThemeImage
{
    public const MAX_BYTES = 5000000;
    public const MAX_SIDE = 10000;

    /** Every format a schema may give an image key. */
    public const FORMATS = [ 'jpg', 'png', 'webp', 'avif', 'ico', 'svg' ];

    /** What an image key takes when its schema entry names no formats. */
    public const DEFAULT_FORMATS = [ 'jpg', 'png', 'webp', 'avif' ];

    /**
     * The formats an image key of the schema takes, or null when the
     * schema has no such key.
     *
     * @param array<string, mixed> $schema
     * @return list<string>|null
     */
    public static function formats(array $schema, string $key): ?array
    {
        $entry = $schema['images'][$key] ?? null;
        if (!is_array($entry)) {
            return null;
        }

        return $entry[2] ?? self::DEFAULT_FORMATS;
    }

    /**
     * Whether a schema entry's formats are valid: a non-empty list of
     * self::FORMATS, each once.
     */
    public static function validFormats(mixed $formats): bool
    {
        return is_array($formats) && $formats !== [] && array_is_list($formats)
            && count(array_unique($formats)) === count($formats)
            && array_diff($formats, self::FORMATS) === [];
    }

    /**
     * Whether a schema entry's hints are valid: `{ size?: [width, height],
     * ratio?: "w:h", alpha?: bool, max_kb?: n, safe?: "w:h" }`, positive
     * integers, and nothing else. `safe` is the ratio of the central part
     * every container shows.
     */
    public static function validHints(mixed $hints): bool
    {
        if (!is_array($hints) || ($hints !== [] && array_is_list($hints))
            || array_diff(array_keys($hints), [ 'size', 'ratio', 'alpha', 'max_kb', 'safe' ]) !== []) {
            return false;
        }
        $positive = fn ($value) => is_int($value) && $value > 0;
        if (array_key_exists('size', $hints) && !(is_array($hints['size']) && array_is_list($hints['size'])
            && count($hints['size']) === 2 && $positive($hints['size'][0]) && $positive($hints['size'][1])
            && $hints['size'][0] <= self::MAX_SIDE && $hints['size'][1] <= self::MAX_SIDE)) {
            return false;
        }
        foreach ([ 'ratio', 'safe' ] as $ratio) {
            if (array_key_exists($ratio, $hints) && !(is_string($hints[$ratio]) && preg_match('/^[1-9]\d{0,3}:[1-9]\d{0,3}$/', $hints[$ratio]) === 1)) {
                return false;
            }
        }
        if (array_key_exists('alpha', $hints) && !is_bool($hints['alpha'])) {
            return false;
        }

        return !array_key_exists('max_kb', $hints) || ($positive($hints['max_kb']) && $hints['max_kb'] * 1000 <= self::MAX_BYTES);
    }
}
