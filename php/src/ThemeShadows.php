<?php

namespace Ordering\ThemeEngine;

/**
 * Shadows as the editor offers them instead of each level's numbers
 * (api-core's docs/THEMES_V2_PLAN.md §2.2), in independent choices:
 *
 * - a style for the whole theme (`tokens.shadow_style.style`): `none` (no
 *   shadows), `soft`, `normal` (the schema's levels, as the product looks
 *   today), `strong` or `solid` (no blur, the color at full strength);
 * - a color for the whole theme (`tokens.shadow_style.color`);
 * - a direction for the whole theme (`tokens.shadow_style.direction`):
 *   `down` (as the schema's levels fall), `down_right`, `down_left` or
 *   `around`;
 * - a size for each component: its level (`{shadow.sm}`…`{shadow.xl}`).
 *
 * The style, the color and the direction make the levels from the schema's
 * normal ones, so a product type's schema only defines those.
 */
final class ThemeShadows
{
    public const STYLES = [ 'none', 'soft', 'normal', 'strong', 'solid' ];

    public const DIRECTIONS = [ 'down', 'down_right', 'down_left', 'around' ];

    /**
     * The schema's levels in a style, a color (null keeps each level's) and
     * a direction.
     *
     * @param array<string, mixed> $levels the schema's `tokens.shadow`
     * @return array<string, mixed>
     */
    public static function apply(array $levels, string $style, ?string $color, string $direction = 'down'): array
    {
        $styled = self::styles($levels)[$style] ?? $levels;

        return array_map(function ($shadow) use ($color, $direction) {
            if (!is_array($shadow)) {
                return $shadow;
            }
            if ($color !== null) {
                $shadow['color'] = $color;
            }

            return self::direct($shadow, $direction);
        }, $styled);
    }

    /**
     * @param array<string, mixed> $levels the schema's `tokens.shadow`
     * @return array<string, array<string, mixed>> style => levels
     */
    public static function styles(array $levels): array
    {
        return [
            'none' => array_map(fn () => null, $levels),
            'soft' => array_map(fn ($shadow) => self::scale($shadow, 0.75, 1.3, 0.6), $levels),
            'normal' => $levels,
            'strong' => array_map(fn ($shadow) => self::scale($shadow, 1.5, 1.25, 1.6), $levels),
            'solid' => array_map(fn ($shadow) => is_array($shadow) ? array_merge(self::scale($shadow, 1.5, 0, 1), [ 'opacity' => 1 ]) : null, $levels),
        ];
    }

    /**
     * $shadow cast in $direction, as far as it falls. Around, a shadow
     * without blur grows by that distance instead, or it would not show.
     *
     * @param array<string, mixed> $shadow
     * @return array<string, mixed>
     */
    private static function direct(array $shadow, string $direction): array
    {
        $distance = max(abs($shadow['x'] ?? 0), abs($shadow['y'] ?? 0));
        [ $shadow['x'], $shadow['y'] ] = match ($direction) {
            'down_right' => [ $distance, $distance ],
            'down_left' => [ -$distance, $distance ],
            'around' => [ 0, 0 ],
            default => [ 0, $distance ],
        };
        if ($direction === 'around' && ($shadow['blur'] ?? 0) == 0) {
            $shadow['spread'] = ($shadow['spread'] ?? 0) + $distance;
        }

        return $shadow;
    }

    /**
     * $shadow with its offset, blur and opacity scaled (and its Android
     * elevation with the offset), or null for none.
     *
     * @param array<string, mixed>|null $shadow
     * @return array<string, mixed>|null
     */
    private static function scale($shadow, float $offset, float $blur, float $opacity): ?array
    {
        if (!is_array($shadow)) {
            return null;
        }

        $scaled = $shadow;
        foreach ([ 'x', 'y' ] as $axis) {
            if (isset($shadow[$axis])) {
                $scaled[$axis] = (int) round($shadow[$axis] * $offset);
            }
        }
        if (isset($shadow['blur'])) {
            $scaled['blur'] = (int) round($shadow['blur'] * $blur);
        }
        if (isset($shadow['opacity'])) {
            $scaled['opacity'] = round(min(0.5, $shadow['opacity'] * $opacity), 2);
        }
        if (isset($shadow['elevation'])) {
            $scaled['elevation'] = (int) min(24, round($shadow['elevation'] * $offset));
        }

        return $scaled;
    }
}
