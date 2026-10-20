<?php

namespace Ordering\ThemeEngine;

/**
 * Where each value of a resolved theme comes from, so the editor says it
 * ("From Identity", "From Card") without resolving anything itself
 * (api-core's docs/THEMES_V2_PLAN.md §6.3.4). Only what is not the schema's default
 * is listed, by path:
 *
 * - `own`: what the theme (or the edits over it) sets;
 * - `inherited`: what a component takes from its parent (`inherits`);
 * - `identity`: what follows Identity's theme-wide shadow, rounding or
 *   border (`follows`) and nothing sets;
 * - `derived`: a color the API derives from another (ThemeColors::DERIVED)
 *   that nothing sets.
 */
final class ThemeSources
{
    public const OWN = 'own';

    public const INHERITED = 'inherited';

    public const IDENTITY = 'identity';

    public const DERIVED = 'derived';

    /**
     * How deep a value sits in each section; `components` and the
     * platforms' components end at a property of the schema instead.
     */
    private const DEPTH = [ 'tokens' => 3, 'visibility' => 2, 'images' => 2, 'pages' => 3, 'modes' => 4 ];

    /**
     * @param array<string, mixed> $values the theme's own values, edits applied
     * @param array<string, mixed>|null $schema
     * @return array<string, string> `{ path: own | inherited | identity | derived }`
     */
    public static function of(array $values, ?array $schema): array
    {
        $own = ThemeResolver::valid($values, $schema);
        $sources = array_fill_keys(self::leaves($own, $schema ?? []), self::OWN);

        foreach (self::leaves(ThemeResolver::inherit($own, $schema), $schema ?? []) as $path) {
            $sources[$path] ??= self::INHERITED;
        }
        $follows = is_array($schema['follows'] ?? null) ? $schema['follows'] : ($schema === null ? [] : ThemeOverall::follows($schema));
        foreach (array_keys($follows) as $path) {
            $sources[$path] ??= self::IDENTITY;
        }
        foreach (array_keys(ThemeColors::DERIVED) as $color) {
            $sources["tokens.color.{$color}"] ??= self::DERIVED;
        }

        return $sources;
    }

    /**
     * The alias each value of a theme resolved from (`{radius.xl}`), by
     * path, so the editor names a level instead of guessing it from its
     * number: a composite value's (a border's width) at its own path.
     *
     * @param array<string, mixed> $unresolved ThemeResolver::resolve()'s, before its aliases
     * @param array<string, mixed>|null $schema
     * @return array<string, string>
     */
    public static function aliases(array $unresolved, ?array $schema): array
    {
        $aliases = [];
        $walk = function ($value, string $path) use (&$walk, &$aliases) {
            if (is_string($value) && ThemeFormat::alias($value) !== null) {
                $aliases[$path] = $value;
            } else if (is_array($value) && !array_is_list($value)) {
                foreach ($value as $key => $child) {
                    $walk($child, "{$path}.{$key}");
                }
            }
        };
        foreach (self::leaves($unresolved, $schema ?? []) as $path) {
            $value = $unresolved;
            foreach (self::segments($path) as $segment) {
                $value = $value[$segment] ?? null;
            }
            $walk($value, $path);
        }

        return $aliases;
    }

    /**
     * A path's keys: the keys of `visibility` and `images` (and of a
     * platform's visibility) are paths themselves.
     *
     * @return array<int, string>
     */
    public static function segments(string $path): array
    {
        $segments = explode('.', $path);
        if (in_array($segments[0], [ 'visibility', 'images' ], true) && count($segments) > 1) {
            return [ $segments[0], implode('.', array_slice($segments, 1)) ];
        }
        if ($segments[0] === 'platforms' && ($segments[2] ?? null) === 'visibility' && count($segments) > 3) {
            return [ ...array_slice($segments, 0, 3), implode('.', array_slice($segments, 3)) ];
        }

        return $segments;
    }

    /**
     * The paths of a theme's values, down to each value.
     *
     * @param array<string, mixed> $values
     * @param array<string, mixed> $schema
     * @return array<int, string>
     */
    private static function leaves(array $values, array $schema, string $path = ''): array
    {
        $leaves = [];
        foreach ($values as $key => $value) {
            $at = $path === '' ? (string) $key : "{$path}.{$key}";
            if (self::isValue($at, $value, $schema)) {
                $leaves[] = $at;
            } else {
                array_push($leaves, ...self::leaves($value, $schema, $at));
            }
        }

        return $leaves;
    }

    private static function isValue(string $path, $value, array $schema): bool
    {
        if (!is_array($value) || $value === [] || array_is_list($value)) {
            return true;
        }

        $segments = explode('.', $path);
        $depth = self::DEPTH[$segments[0]] ?? null;
        if ($depth !== null) {
            return count($segments) >= $depth;
        }
        // A platform's pages, as deep as the pages; its components, as the components.
        if ($segments[0] === 'platforms') {
            if (($segments[2] ?? null) === 'pages') {
                return count($segments) >= 5;
            }
            $segments = array_slice($segments, 2);
        }
        if (($segments[0] ?? null) !== 'components') {
            return false;
        }
        // A group's scope changes its components with their own properties.
        $component_path = preg_replace('/^components\.[a-z0-9_]+\.scope\./', 'components.', implode('.', $segments));

        return ThemeSchemas::specAt($schema, $component_path) !== null;
    }
}
