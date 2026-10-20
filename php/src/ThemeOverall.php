<?php

namespace Ordering\ThemeEngine;

/**
 * The theme-wide shadow and border the editor offers in Identity
 * (api-core's docs/THEMES_V2_PLAN.md §2.2) become the default of every component's
 * shadow, rounding and border that follows them, and each component still
 * overrides them:
 *
 * - `tokens.shadow_style.size`: a level of ThemeFormat::SHADOW;
 * - `tokens.border_style.radius`: a level of ThemeFormat::RADIUS;
 * - `tokens.border_style.width`: `none` or a level of ThemeFormat::BORDER.
 *   A component without a border gets one of `{color.border}`;
 * - `tokens.border_style.style`: one of ThemeFormat::BORDER_STYLES, for
 *   every border there is.
 *
 * Which properties follow them, the schema declares: `follows`, `{ path:
 * shadow | radius | border }` (in the node format, each node's
 * `co.ordering.follows`; docs/schema-format.md). A schema in
 * tuples that does not declare it gets it from self::follows(), the rule
 * this class applied before the schema said it:
 *
 * - a state keeps the schema's default (its shadow keeps the schema's
 *   distance from the resting one, see ThemeDerivations::steps());
 * - so does a shadow or border the schema gives as numbers: by design (the
 *   bottom bar casts upwards, a checkbox's box);
 * - and a shadow of what has a transparent background (outline and ghost
 *   buttons), which would draw a box around nothing. A tab bar's container
 *   is the exception: a transparent one still casts Identity's shadow;
 * - and a rounding of `none` or `full` (circles and pills stay so);
 * - and the button's appearances' borders: each appearance is its border;
 * - and the platforms' overrides.
 *
 * A rounding whose options leave out Identity's level keeps its own: that
 * one is decided by the theme's level, not by the schema.
 */
final class ThemeOverall
{
    public const GROUPS = [ 'shadow', 'radius', 'border' ];

    /**
     * $defaults with the theme-wide choices (a missing one leaves each
     * component's).
     *
     * @param array<string, mixed> $defaults ThemeSchemas::defaults()
     * @param array<string, mixed> $schema
     * @param array{shadow?: string, radius?: string, border_width?: string, border_style?: string} $overall
     * @return array<string, mixed>
     */
    public static function apply(array $defaults, array $schema, array $overall): array
    {
        $overall = array_filter($overall, 'is_string');
        if ($overall === []) {
            return $defaults;
        }

        $follows = is_array($schema['follows'] ?? null) ? $schema['follows'] : self::follows($schema);
        foreach ($follows as $path => $group) {
            $segments = explode('.', (string) $path);
            $spec = ThemeSchemas::propertySpec($schema, array_slice($segments, 1));
            if ($spec === null) {
                continue;
            }

            // A path the defaults do not have is left alone, not created.
            $node = $defaults;
            foreach ($segments as $segment) {
                if (!is_array($node) || !array_key_exists($segment, $node)) {
                    continue 2;
                }
                $node = $node[$segment];
            }
            $value = &$defaults;
            foreach ($segments as $segment) {
                $value = &$value[$segment];
            }
            if ($group === 'shadow' && isset($overall['shadow'])) {
                $value = "{shadow.{$overall['shadow']}}";
            } else if ($group === 'radius' && isset($overall['radius']) && (!is_array($spec[2] ?? null) || in_array($overall['radius'], $spec[2], true))) {
                $value = "{radius.{$overall['radius']}}";
            } else if ($group === 'border') {
                $value = self::border($spec[1], $overall);
            }
            unset($value);
        }

        return $defaults;
    }

    /**
     * Which properties of a schema in tuples follow Identity, by the rule of
     * this class's docblock.
     *
     * @param array<string, mixed> $schema
     * @return array<string, string> `{ path: shadow | radius | border }`
     */
    public static function follows(array $schema): array
    {
        $follows = [];
        foreach ($schema['components'] ?? [] as $component => $spec) {
            foreach (is_array($spec) ? $spec : [] as $section => $props) {
                if ($section === 'base') {
                    $follows += self::props("components.{$component}.base", is_array($props) ? $props : [], false);
                } else if (in_array($section, [ 'variants', 'sizes', 'shapes', 'colors', 'appearances' ], true)) {
                    foreach (is_array($props) ? $props : [] as $name => $variant) {
                        unset($variant['states']);
                        $follows += self::props("components.{$component}.{$section}.{$name}", is_array($variant) ? $variant : [], $section === 'appearances',
                            $component === 'tabs' && $section === 'variants');
                    }
                }
            }
        }

        return $follows;
    }

    /**
     * A schema in tuples that declares which properties follow Identity
     * (`follows`), and in which a followed shadow or rounding has as its
     * default what Identity's default makes of it: the one it declared
     * would never show. Two keep their own, because something else reads
     * them: a rounding with options (it shows when the theme's level is not
     * one of them), and the shadow of a component whose states have one
     * (ThemeDerivations::steps() keeps each state as many levels from
     * it as the schema puts them).
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    public static function declare(array $schema): array
    {
        if (array_key_exists('follows', $schema)) {
            return $schema;
        }

        $follows = self::follows($schema);
        $levels = [
            'shadow' => $schema['tokens']['shadow_style']['size'] ?? null,
            'radius' => $schema['tokens']['border_style']['radius'] ?? null,
        ];
        foreach ($follows as $path => $group) {
            $level = $levels[$group] ?? null;
            if (!is_string($level)) {
                continue;
            }
            $spec = &$schema;
            foreach (explode('.', $path) as $segment) {
                $spec = &$spec[$segment];
            }
            $kept = $group === 'shadow' ? self::statesFollow($schema, explode('.', $path)[1]) : is_array($spec[2] ?? null);
            if (!$kept) {
                $spec[1] = "{{$group}.{$level}}";
            }
            unset($spec);
        }
        $schema['follows'] = $follows;

        return $schema;
    }

    /**
     * Whether a component has a state with a shadow of its own, which
     * ThemeDerivations::steps() places from the schema's resting one.
     *
     * @param array<string, mixed> $schema
     */
    private static function statesFollow(array $schema, string $component): bool
    {
        foreach ($schema['components'][$component]['variants'] ?? [] as $variant) {
            foreach (is_array($variant['states'] ?? null) ? $variant['states'] : [] as $state) {
                if (is_array($state) && array_key_exists('shadow', $state)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $props the specs of one block
     * @param bool $shadowed whether it casts a shadow even when transparent
     * @return array<string, string>
     */
    private static function props(string $path, array $props, bool $appearance, bool $shadowed = false): array
    {
        $follows = [];
        $transparent = !$shadowed && ($props['background'][1] ?? null) === 'transparent';
        foreach ($props as $prop => $spec) {
            if (!is_array($spec) || !array_key_exists(1, $spec)) {
                continue;
            }
            [ $kind, $default ] = $spec;
            $alias = ThemeFormat::alias($default);
            if ($kind === 'shadow' && !$transparent && ($default === null || ($alias[0] ?? null) === 'shadow')) {
                $follows["{$path}.{$prop}"] = 'shadow';
            } else if ($kind === 'radius' && ($alias[0] ?? null) === 'radius' && !in_array($alias[1], [ 'none', 'full' ], true)) {
                $follows["{$path}.{$prop}"] = 'radius';
            } else if ($kind === 'border' && !$appearance && ($default === null || (is_array($default) && (ThemeFormat::alias($default['width'] ?? null)[0] ?? null) === 'border'))) {
                $follows["{$path}.{$prop}"] = 'border';
            }
        }

        return $follows;
    }

    /**
     * A border with Identity's width and style: none without a width, one of
     * `{color.border}` when it had none (its default, null or `"none"`).
     *
     * @param mixed $border the schema's default
     */
    private static function border(mixed $border, array $overall): ?array
    {
        $border = is_array($border) ? $border : null;
        $width = $overall['border_width'] ?? null;
        if ($width === 'none') {
            return null;
        }
        if ($width !== null) {
            $border = [ 'width' => "{border.{$width}}", 'color' => $border['color'] ?? '{color.border}', 'style' => $border['style'] ?? 'solid' ];
        }
        if ($border !== null && isset($overall['border_style'])) {
            $border['style'] = $overall['border_style'];
        }

        return $border;
    }
}
