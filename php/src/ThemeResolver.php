<?php

namespace Ordering\ThemeEngine;

/**
 * A version 2 theme over its schema's defaults, every alias resolved:
 * what api-core serves for a site's theme (its own App\Themes\ThemeResolver
 * reads the site's theme and its schema, and answers in the version its
 * client reads, api-core's docs/THEMES_V2_PLAN.md §2.4).
 */
class ThemeResolver
{
    /**
     * A version 2 theme over its schema's defaults, every alias resolved.
     *
     * @param array<string, mixed> $values what the theme stores
     * @param array<string, mixed>|null $schema
     * @param array<string, mixed>|null $unresolved filled with the same
     *        values right before their aliases are resolved, for the editor
     *        to name each level (ThemeSources::aliases())
     * @return array<string, mixed>
     */
    public static function resolve(array $values, ?array $schema, ?array &$unresolved = null): array
    {
        $own = self::inherit(self::valid($values, $schema), $schema);
        $defaults = $schema === null ? [] : ThemeSchemas::defaults($schema);
        // The shadow style, color and direction remake the levels; what
        // the theme sets level by level still goes over them. The theme's
        // choices go over the schema's own.
        $shadow_style = self::overall($defaults, $own, 'shadow_style');
        if ($shadow_style !== [] && is_array($defaults['tokens']['shadow'] ?? null)) {
            $defaults['tokens']['shadow'] = ThemeShadows::apply($defaults['tokens']['shadow'], $shadow_style['style'] ?? 'normal', $shadow_style['color'] ?? null, $shadow_style['direction'] ?? 'down');
        }
        // The theme-wide shadow size, rounding and border are every
        // component's default; what the theme sets on a component goes
        // over them.
        if ($schema !== null) {
            $border_style = self::overall($defaults, $own, 'border_style');
            $defaults = ThemeOverall::apply($defaults, $schema, [
                'shadow' => $shadow_style['size'] ?? null,
                'radius' => $border_style['radius'] ?? null,
                'border_width' => $border_style['width'] ?? null,
                'border_style' => $border_style['style'] ?? null,
            ]);
        }
        $values = self::merge($defaults, $own);
        if ($schema !== null) {
            $values = self::resolvePages($values, $schema);
        }
        [ $values, $scoped_own ] = self::scope($values, $own);
        if ($schema !== null && is_array($values['components'] ?? null)) {
            $values['components'] = self::noBorders($values['components'], $schema, 'components');
        }
        // A platform changes properties of a component's base, of the same kinds.
        foreach ($schema !== null && is_array($values['platforms'] ?? null) ? $values['platforms'] : [] as $platform => $override) {
            if (is_array($override['components'] ?? null)) {
                $values['platforms'][$platform]['components'] = self::noBorders($override['components'], $schema, 'components');
            }
        }
        // A state's shadow the theme does not set keeps the schema's
        // distance from the resting one, Identity's or the theme's.
        $specs = is_array($schema['components'] ?? null) ? $schema['components'] : [];
        $rules = ThemeDerivations::of($schema);
        $own_components = is_array($own['components'] ?? null) ? $own['components'] : [];
        if (is_array($values['components'] ?? null)) {
            $values['components'] = ThemeDerivations::steps($values['components'], $own_components, $rules['derive'], $specs);
        }
        foreach ($scoped_own as $group => $set) {
            $values['components'][$group]['scope'] = ThemeDerivations::steps($values['components'][$group]['scope'], $set, $rules['derive'], $specs);
        }
        if (is_array($values['tokens']['color'] ?? null)) {
            $values['tokens']['color'] = ThemeColors::derive(
                $values['tokens']['color'],
                is_array($own['tokens']['color'] ?? null) ? $own['tokens']['color'] : [],
                is_array($defaults['tokens']['color'] ?? null) ? $defaults['tokens']['color'] : [],
                is_array($schema['derive'] ?? null) ? $schema['derive'] : null
            );
        }
        $unresolved = $values;
        $values = self::resolveAliases($values);
        $full = $values['tokens']['radius']['full'] ?? null;
        $colors = is_array($values['tokens']['color'] ?? null) ? $values['tokens']['color'] : [];
        if (is_array($values['components'] ?? null)) {
            $values['components'] = ThemeDerivations::shades($values['components'], $own_components, $rules['derive'], $specs);
            $values['components'] = ThemeDerivations::insets($values['components'], $own_components, $rules['derive'], $full, $specs);
            $values['components'] = ThemeDerivations::matrices($values['components'], $colors, $own_components, $rules['matrix']);
        }
        foreach ($scoped_own as $group => $set) {
            $scope = ThemeDerivations::shades($values['components'][$group]['scope'], $set, $rules['derive'], $specs);
            $scope = ThemeDerivations::insets($scope, $set, $rules['derive'], $full, $specs);
            $values['components'][$group]['scope'] = ThemeDerivations::matrices($scope, $colors, $set, $rules['matrix']);
        }
        return $values;
    }

    /**
     * The sections of every page, and of each page a platform overrides,
     * in the order to paint them (ThemePages::order()).
     *
     * @param array<string, mixed> $values
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private static function resolvePages(array $values, array $schema): array
    {
        if (is_array($values['pages'] ?? null)) {
            $values['pages'] = ThemePages::resolve($values['pages'], $schema);
        }
        foreach (is_array($values['platforms'] ?? null) ? $values['platforms'] : [] as $platform => $override) {
            if (is_array($override['pages'] ?? null)) {
                $values['platforms'][$platform]['pages'] = ThemePages::resolve($override['pages'], $schema);
            }
        }

        return $values;
    }

    /**
     * The borders set to `none` (by the theme, or by a group's scope in the
     * schema) as null (no border), the way a client reads them. A group's
     * scope holds its component's paths.
     *
     * @param array<string, mixed> $values the components over their defaults, under $path
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private static function noBorders(array $values, array $schema, string $path): array
    {
        foreach ($values as $key => $value) {
            $at = "{$path}.{$key}";
            if (is_array($value)) {
                $values[$key] = self::noBorders($value, $schema, $at);
            } else if ($value === ThemeFormat::NO_BORDER && ThemeSchemas::kindAt($schema, preg_replace('/^components\.[a-z0-9_]+\.scope\./', 'components.', $at)) === 'border') {
                $values[$key] = null;
            }
        }

        return $values;
    }

    /**
     * Each component a group changes (its `scope`): the component as it is
     * everywhere, with the group's changes over it, so a client swaps it
     * whole inside the group. A component the group does not change is
     * left out of its scope (the client uses the global one).
     *
     * @param array<string, mixed> $values the theme over its defaults
     * @param array<string, mixed> $own what the theme itself sets
     * @return array{0: array<string, mixed>, 1: array<string, array<string, mixed>>} the values, and by group what the theme sets on each scoped component
     */
    private static function scope(array $values, array $own): array
    {
        $scoped_own = [];
        foreach (is_array($values['components'] ?? null) ? $values['components'] : [] as $group => $component) {
            if (!is_array($component['scope'] ?? null)) {
                continue;
            }
            foreach ($component['scope'] as $scoped => $changes) {
                if (!is_array($changes) || $changes === [] || !is_array($values['components'][$scoped] ?? null)) {
                    unset($values['components'][$group]['scope'][$scoped]);
                    continue;
                }
                $values['components'][$group]['scope'][$scoped] = self::merge($values['components'][$scoped], $changes);
                $global = $own['components'][$scoped] ?? null;
                $changed = $own['components'][$group]['scope'][$scoped] ?? null;
                $scoped_own[$group][$scoped] = self::merge(is_array($global) ? $global : [], is_array($changed) ? $changed : []);
            }
            if ($values['components'][$group]['scope'] === []) {
                unset($values['components'][$group]['scope']);
                unset($scoped_own[$group]);
            }
        }

        return [ $values, $scoped_own ];
    }

    /**
     * A theme-wide style group (`shadow_style`, `border_style`): the
     * theme's choices over the schema's defaults.
     *
     * @param array<string, mixed> $defaults
     * @param array<string, mixed> $own
     * @return array<string, mixed>
     */
    private static function overall(array $defaults, array $own, string $group): array
    {
        $schema = $defaults['tokens'][$group] ?? null;
        $theme = $own['tokens'][$group] ?? null;

        return array_merge(is_array($schema) ? $schema : [], is_array($theme) ? $theme : []);
    }

    /**
     * $values with what they set on a parent (the schema's `inherits`) also
     * set on each component that inherits from it: its base, its variants,
     * states included, and its sizes, for the properties that component has and
     * the theme does not set there.
     *
     * @param array<string, mixed> $values a theme's valid values
     * @param array<string, mixed>|null $schema
     * @return array<string, mixed>
     */
    public static function inherit(array $values, ?array $schema): array
    {
        foreach (is_array($schema['inherits'] ?? null) ? $schema['inherits'] : [] as $component => $parent) {
            foreach ([ 'base', 'variants', 'sizes' ] as $section) {
                $set = $values['components'][$parent][$section] ?? null;
                $spec = $schema['components'][$component][$section] ?? null;
                if (!is_array($set) || !is_array($spec)) {
                    continue;
                }
                $own = $values['components'][$component][$section] ?? null;
                $followed = self::follow($set, is_array($own) ? $own : [], $spec);
                if ($followed !== []) {
                    $values['components'][$component][$section] = $followed;
                }
            }
        }

        return $values;
    }

    /**
     * $own with what $set has and $own does not, where $spec has a
     * property.
     *
     * @param array<string, mixed> $set the parent's values
     * @param array<string, mixed> $own the child's values
     * @param array<string, mixed> $spec the child's specs at the same level
     * @return array<string, mixed>
     */
    private static function follow(array $set, array $own, array $spec): array
    {
        foreach ($set as $key => $value) {
            $prop = $spec[$key] ?? null;
            if (!is_array($prop)) {
                continue;
            }
            if (array_is_list($prop)) {
                if (!array_key_exists($key, $own)) {
                    $own[$key] = $value;
                }
            } else if (is_array($value)) {
                $nested = self::follow($value, is_array($own[$key] ?? null) ? $own[$key] : [], $prop);
                if ($nested !== []) {
                    $own[$key] = $nested;
                }
            }
        }

        return $own;
    }

    /**
     * What of a stored theme fits its schema: a value the schema no longer
     * has or that is not valid (written outside the editor, or before the
     * schema changed) is left out, so its default shows instead; what the
     * schema renamed or moved is first taken where it went (ThemeMigrations),
     * and a number outside its limits or its format's range takes the
     * closest one within them (ThemeValidator::clamp()). Without a schema,
     * only what has the shape of a theme.
     *
     * @param array<string, mixed> $values
     * @param array<string, mixed>|null $schema
     * @return array<string, mixed>
     */
    public static function valid(array $values, ?array $schema): array
    {
        if ($values !== [] && array_is_list($values)) {
            return [];
        }
        if ($schema === null) {
            return array_filter($values, fn ($section, $key) => in_array($key, ThemeValidator::SECTIONS, true) && is_array($section), ARRAY_FILTER_USE_BOTH);
        }

        // What the schema renamed or moved is read where it went.
        // What the schema renamed or moved is read where it went, each number within its range.
        $values = ThemeValidator::clamp(ThemeMigrations::apply($values, is_array($schema['migrate'] ?? null) ? $schema['migrate'] : []), $schema);
        $lists = [];
        foreach (ThemeValidator::validate($values, $schema) as $error) {
            // A section left out of its page's list, which stays a list.
            if (preg_match('/^((?:platforms\.[a-z0-9_]+\.)?pages\.[a-z0-9_]+\.sections)\.\d+$/', $error['path'], $match) === 1) {
                $lists[$match[1]] = true;
            }
            [ $section, $rest ] = array_pad(explode('.', $error['path'], 2), 2, null);
            if (in_array($section, [ 'visibility', 'images' ], true) && $rest !== null) {
                // Their keys hold dots of their own.
                unset($values[$section][$rest]);
                continue;
            }
            if (preg_match('/^platforms\.([a-z0-9_]+)\.visibility\.(.+)$/', $error['path'], $match) === 1) {
                unset($values['platforms'][$match[1]]['visibility'][$match[2]]);
                continue;
            }
            self::forget($values, $error['path']);
        }
        foreach (array_keys($lists) as $path) {
            $node = &$values;
            foreach (explode('.', $path) as $segment) {
                if (!is_array($node[$segment] ?? null)) {
                    unset($node);
                    continue 2;
                }
                $node = &$node[$segment];
            }
            $node = array_values($node);
            unset($node);
        }

        return $values;
    }

    /**
     * What of a site's settings fits its schema, as self::valid() a theme:
     * what the schema renamed or moved within its base (its migrations of
     * `site.` paths) is first taken where it went and each number is
     * clamped within its range (ThemeValidator::clampSettings()), then each setting
     * ThemeValidator::settings() finds wrong is left out, so its default
     * shows instead. A list is no settings: none.
     *
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $schema compiled
     * @return array<string, mixed>
     */
    public static function validSettings(array $settings, array $schema): array
    {
        $migrations = array_values(array_filter(is_array($schema['migrate'] ?? null) ? $schema['migrate'] : [], fn ($migration) => is_array($migration)
            && is_string($migration['from'] ?? null) && explode('.', $migration['from'])[0] === 'site'));
        $document = ThemeMigrations::apply([ 'site' => $settings ], $migrations);
        $settings = ThemeValidator::clampSettings(is_array($document['site'] ?? null) ? $document['site'] : [], $schema);
        foreach (ThemeValidator::settings($settings, $schema) as $error) {
            if ($error['path'] === 'site') {
                return [];
            }
            self::forget($settings, implode('.', array_slice(explode('.', $error['path']), 1)));
        }

        return $settings;
    }

    /**
     * @param array<string, mixed> $tree
     */
    private static function forget(array &$tree, string $path): void
    {
        $segments = explode('.', $path);
        $last = array_pop($segments);
        $node = &$tree;
        foreach ($segments as $segment) {
            if (!is_array($node[$segment] ?? null)) {
                return;
            }
            $node = &$node[$segment];
        }
        unset($node[$last]);
    }

    /**
     * $over on $base: maps merge key by key, an empty one sets nothing, and
     * anything else (a list, a scalar, null) replaces.
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $over
     * @return array<string, mixed>
     */
    public static function merge(array $base, array $over): array
    {
        foreach ($over as $key => $value) {
            // An empty map ({} decodes as []) sets nothing.
            if ($value === [] && is_array($base[$key] ?? null)) {
                continue;
            }
            if (is_array($value) && !array_is_list($value) && is_array($base[$key] ?? null) && !array_is_list($base[$key])) {
                $base[$key] = self::merge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    /**
     * Every alias of the components (and of their platform overrides)
     * replaced by its token's value, and each typography with the `family`
     * of its font role.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public static function resolveAliases(array $values): array
    {
        $tokens = is_array($values['tokens'] ?? null) ? $values['tokens'] : [];
        foreach (is_array($tokens['typography'] ?? null) ? $tokens['typography'] : [] as $style => $typography) {
            if (is_array($typography)) {
                $tokens['typography'][$style]['family'] = is_array($tokens['font'] ?? null) ? ($tokens['font'][ThemeFormat::typographyRole((string) $style)] ?? null) : null;
            }
        }
        if (isset($values['tokens'])) {
            $values['tokens'] = $tokens;
        }

        if (is_array($values['components'] ?? null)) {
            $values['components'] = self::resolveIn($values['components'], $tokens);
        }
        foreach (is_array($values['platforms'] ?? null) ? $values['platforms'] : [] as $platform => $override) {
            if (is_array($override['components'] ?? null)) {
                $values['platforms'][$platform]['components'] = self::resolveIn($override['components'], $tokens);
            }
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $tokens
     * @return array<string, mixed>
     */
    private static function resolveIn(array $node, array $tokens): array
    {
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $node[$key] = self::resolveIn($value, $tokens);
            } else if (($alias = ThemeFormat::alias($value)) !== null) {
                $group = $tokens[$alias[0]] ?? null;
                $node[$key] = is_array($group) ? ($group[$alias[1]] ?? null) : null;
            }
        }

        return $node;
    }
}
