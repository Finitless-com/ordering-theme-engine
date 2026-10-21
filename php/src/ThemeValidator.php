<?php

namespace Ordering\ThemeEngine;

/**
 * Checks a product's version 2 theme against the schema of its product
 * type (ThemeSchemas). A theme may set any part: what it leaves out takes
 * the schema's default. Each problem is `{path, code}`, the code being the
 * path and the rule (`tokens.color.primary.invalid`): `unknown` (not in the
 * schema), `invalid` (wrong type, format or option), `alias` (an alias to
 * a token that does not exist or is of another kind), `alpha` (a translucent
 * color where the schema keeps it opaque), `out_of_range` (a number outside
 * its editor's limits) or `format` (an image of a format its key does not
 * take).
 */
final class ThemeValidator
{
    public const SECTIONS = [ 'tokens', 'components', 'visibility', 'images', 'pages', 'modes', 'platforms' ];

    /**
     * @param array<string, mixed> $values
     * @param array<string, mixed> $schema
     * @return array<int, array{path: string, code: string}>
     */
    public static function validate(array $values, array $schema): array
    {
        $errors = [];
        if ($values !== [] && array_is_list($values)) {
            return [ [ 'path' => '', 'code' => 'invalid' ] ];
        }

        foreach ($values as $section => $value) {
            $path = (string) $section;
            if (!in_array($section, self::SECTIONS, true)) {
                $errors[] = self::error($path, 'unknown');
                continue;
            }
            if (!is_array($value) || ($value !== [] && array_is_list($value))) {
                $errors[] = self::error($path, 'invalid');
                continue;
            }

            switch ($section) {
                case 'tokens':
                    array_push($errors, ...self::tokens($value, 'tokens'));
                    // Of a token the schema narrows, only the options it keeps.
                    foreach (is_array($schema['token_options'] ?? null) ? $schema['token_options'] : [] as $token => $options) {
                        [ $group, $name ] = explode('.', (string) $token, 2);
                        if (is_array($value[$group] ?? null) && array_key_exists($name, $value[$group]) && !in_array($value[$group][$name], $options, true)) {
                            $errors[] = self::error("tokens.{$token}", 'invalid');
                        }
                    }
                    // Of the groups each schema names itself, only its own.
                    foreach (ThemeFormat::OWN_GROUPS as $group) {
                        foreach (is_array($value[$group] ?? null) ? array_keys($value[$group]) : [] as $name) {
                            if (in_array($name, ThemeFormat::tokenNames($group), true) && !array_key_exists($name, $schema['tokens'][$group] ?? [])) {
                                $errors[] = self::error("tokens.{$group}.{$name}", 'unknown');
                            }
                        }
                    }
                    break;
                case 'components':
                    array_push($errors, ...self::components($value, $schema, 'components'));
                    break;
                case 'visibility':
                case 'images':
                    array_push($errors, ...self::keys($value, $schema, $section, $path));
                    break;
                case 'pages':
                    array_push($errors, ...ThemePages::validate($value, $schema, 'pages'));
                    break;
                case 'modes':
                    foreach ($value as $mode => $groups) {
                        if (!in_array($mode, ThemeFormat::MODES, true) || !is_array($groups)) {
                            $errors[] = self::error("modes.{$mode}", 'unknown');
                            continue;
                        }
                        foreach ($groups as $group => $colors) {
                            if ($group !== 'color' || !is_array($colors)) {
                                $errors[] = self::error("modes.{$mode}.{$group}", 'unknown');
                                continue;
                            }
                            array_push($errors, ...self::tokens([ 'color' => $colors ], "modes.{$mode}"));
                            // Of the schema's own colors only, as `tokens.color`.
                            foreach (array_keys($colors) as $name) {
                                if (in_array($name, ThemeFormat::tokenNames('color'), true) && !array_key_exists($name, $schema['tokens']['color'] ?? [])) {
                                    $errors[] = self::error("modes.{$mode}.color.{$name}", 'unknown');
                                }
                            }
                        }
                    }
                    break;
                case 'platforms':
                    foreach ($value as $platform => $override) {
                        $platform_path = "platforms.{$platform}";
                        if (!in_array($platform, $schema['platforms'] ?? [], true) || !is_array($override)
                            || array_diff(array_keys($override), [ 'components', 'pages', 'visibility' ]) !== []) {
                            $errors[] = self::error($platform_path, 'unknown');
                            continue;
                        }
                        foreach ([ 'components', 'pages', 'visibility' ] as $platform_section) {
                            $set = $override[$platform_section] ?? [];
                            if (!is_array($set) || ($set !== [] && array_is_list($set))) {
                                $errors[] = self::error("{$platform_path}.{$platform_section}", 'invalid');
                            } else if ($platform_section === 'components') {
                                array_push($errors, ...self::components($set, $schema, "{$platform_path}.components", false));
                            } else if ($platform_section === 'visibility') {
                                // A platform shows or hides what the schema can, by the same keys.
                                array_push($errors, ...self::keys($set, $schema, 'visibility', "{$platform_path}.visibility"));
                            } else {
                                array_push($errors, ...ThemePages::validate($set, $schema, "{$platform_path}.pages"));
                            }
                        }
                    }
                    break;
            }
        }

        // A theme refers only to the colors, text styles and opacities its schema names.
        $refers = function ($node, string $path) use (&$refers, $schema, &$errors) {
            if (is_array($node)) {
                foreach ($node as $key => $child) {
                    $refers($child, $path === '' ? (string) $key : "{$path}.{$key}");
                }
            } else if (($alias = ThemeFormat::alias($node)) !== null && in_array($alias[0], ThemeFormat::OWN_GROUPS, true) && !array_key_exists($alias[1], $schema['tokens'][$alias[0]] ?? [])) {
                $errors[] = self::error($path, 'alias');
            }
        };
        $refers(array_intersect_key($values, array_flip(self::SECTIONS)), '');
        array_push($errors, ...self::translucent($values, $schema));
        array_push($errors, ...self::ranges($values, $schema));

        // Once each, though a value's own check may have said it too.
        return array_values(array_intersect_key($errors, array_unique(array_column($errors, 'code'))));
    }

    /**
     * A theme's visibility or images (or a platform's visibility), each key
     * one the schema has, with a value of its kind.
     *
     * @param array<string, mixed> $set
     * @param array<string, mixed> $schema
     * @return array<int, array{path: string, code: string}>
     */
    private static function keys(array $set, array $schema, string $section, string $path): array
    {
        $errors = [];
        $kind = $section === 'visibility' ? 'bool' : 'image';
        foreach ($set as $key => $item) {
            if (!array_key_exists($key, $schema[$section] ?? [])) {
                $errors[] = self::error("{$path}.{$key}", 'unknown');
            } else if (($rule = self::value($kind, $item)) !== null) {
                $errors[] = self::error("{$path}.{$key}", $rule);
            } else if ($kind === 'image' && !self::inFormats($item, ThemeImage::formats($schema, (string) $key) ?? ThemeImage::DEFAULT_FORMATS)) {
                $errors[] = self::error("{$path}.{$key}", 'format');
            }
        }

        return $errors;
    }

    /**
     * Whether an image URL is of one of its key's formats, by the extension
     * of its path (`jpeg` is `jpg`). One without the extension of a format
     * the engine knows, or none at all, says nothing of its format: only an
     * upload tells it.
     *
     * @param array<int, string> $formats
     */
    private static function inFormats(string $url, array $formats): bool
    {
        if ($url === '') {
            return true;
        }
        $extension = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        $extension = $extension === 'jpeg' ? 'jpg' : $extension;

        return !in_array($extension, ThemeImage::FORMATS, true) || in_array($extension, $formats, true);
    }

    /**
     * The numbers a theme sets outside the limits its schema's editor gives
     * them (`limits`, NodeCompiler): a number below the node's `min` or
     * above its `max` is `<path>.out_of_range`, and a typography style's
     * field outside the node's `fields` (a token's own, else the
     * `tokens.typography` group's, which a component's custom style takes
     * too) is `<path>.<field>.out_of_range`, both at the value's path. The
     * node that declares a property decides, as for `alpha`. An alias names
     * a level: it is not checked.
     *
     * @param array<string, mixed> $values
     * @param array<string, mixed> $schema
     * @return array<int, array{path: string, code: string}>
     */
    private static function ranges(array $values, array $schema): array
    {
        $limits = is_array($schema['limits'] ?? null) ? $schema['limits'] : [];
        if ($limits === []) {
            return [];
        }
        $errors = [];
        $check = function ($value, string $path, string $node) use ($limits, &$errors) {
            array_push($errors, ...self::outOfRange($value, $path, $limits[$node] ?? null, $limits['tokens.typography']['fields'] ?? null));
        };

        foreach (is_array($values['tokens'] ?? null) ? $values['tokens'] : [] as $group => $names) {
            foreach (is_array($names) ? $names : [] as $name => $value) {
                $check($value, "tokens.{$group}.{$name}", "tokens.{$group}.{$name}");
            }
        }
        self::eachComponentValue($values, function ($value, string $path, array $candidates) use ($schema, $check) {
            $check($value, $path, self::declaring($schema, $candidates));
        });

        return $errors;
    }

    /**
     * What is out of range of a value against its node's limits (null:
     * none): a number, or each numeric field of a typography style, whose
     * fields are the node's own or, when it has none, $fields.
     *
     * @param array<string, mixed>|null $limits
     * @param array<string, mixed>|null $fields
     * @return array<int, array{path: string, code: string}>
     */
    private static function outOfRange($value, string $path, ?array $limits, ?array $fields): array
    {
        $outside = fn ($number, $range) => (is_int($number) || is_float($number)) && is_array($range)
            && ((isset($range['min']) && $number < $range['min']) || (isset($range['max']) && $number > $range['max']));
        if (is_int($value) || is_float($value)) {
            return $outside($value, $limits) ? [ self::error($path, 'out_of_range') ] : [];
        }
        if (!ThemeFormat::isTypography($value)) {
            return [];
        }
        $errors = [];
        foreach (is_array($limits['fields'] ?? null) ? $limits['fields'] : ($fields ?? []) as $field => $range) {
            if ($outside($value[$field] ?? null, $range)) {
                $errors[] = [ 'path' => $path, 'code' => "{$path}.{$field}.out_of_range" ];
            }
        }

        return $errors;
    }

    /**
     * The first of a value's candidate nodes the schema declares (the
     * variant's own property, else its base's…), or the first one.
     *
     * @param array<string, mixed> $schema
     * @param array<int, string> $candidates
     */
    private static function declaring(array $schema, array $candidates): string
    {
        foreach ($candidates as $candidate) {
            if (ThemeSchemas::specAt($schema, $candidate) !== null) {
                return $candidate;
            }
        }

        return $candidates[0];
    }

    /**
     * Each value a theme sets on its components and on each platform's
     * override of them, with its path and the schema nodes that may declare
     * it, in order (a variant's own property, then its base's; a group's
     * scope, then the scoped component's; a platform's default, then the
     * base's): $visit($value, $path, $candidates). Slots choose, they set
     * nothing.
     *
     * @param array<string, mixed> $values
     */
    private static function eachComponentValue(array $values, callable $visit): void
    {
        $component = function (array $sections, string $path, callable $at) use ($visit, &$component) {
            foreach ($sections as $section => $props) {
                if (!is_array($props) || $section === 'slots') {
                    continue;
                }
                if ($section === 'scope') {
                    foreach ($props as $scoped => $changes) {
                        if (is_array($changes)) {
                            $component($changes, "{$path}.scope.{$scoped}", fn (string $rest) => [ ...$at("scope.{$scoped}.{$rest}"), "components.{$scoped}.{$rest}" ]);
                        }
                    }
                    continue;
                }
                if ($section === 'base') {
                    foreach ($props as $prop => $value) {
                        $visit($value, "{$path}.base.{$prop}", $at("base.{$prop}"));
                    }
                    continue;
                }
                foreach ($props as $name => $variant) {
                    if (!is_array($variant)) {
                        continue;
                    }
                    foreach ($variant as $prop => $value) {
                        if ($prop === 'states' && is_array($value)) {
                            foreach ($value as $state => $state_props) {
                                foreach (is_array($state_props) ? $state_props : [] as $state_prop => $state_value) {
                                    $visit($state_value, "{$path}.{$section}.{$name}.states.{$state}.{$state_prop}", [ ...$at("{$section}.{$name}.states.{$state}.{$state_prop}"), ...$at("{$section}.{$name}.{$state_prop}"), ...$at("base.{$state_prop}") ]);
                                }
                            }
                            continue;
                        }
                        $visit($value, "{$path}.{$section}.{$name}.{$prop}", $section === 'colors' ? $at("colors.{$name}.{$prop}") : [ ...$at("{$section}.{$name}.{$prop}"), ...$at("base.{$prop}") ]);
                    }
                }
            }
        };

        foreach (is_array($values['components'] ?? null) ? $values['components'] : [] as $name => $sections) {
            if (is_array($sections)) {
                $component($sections, "components.{$name}", fn (string $rest) => [ "components.{$name}.{$rest}" ]);
            }
        }
        foreach (is_array($values['platforms'] ?? null) ? $values['platforms'] : [] as $platform => $override) {
            foreach (is_array($override['components'] ?? null) ? $override['components'] : [] as $name => $sections) {
                if (is_array($sections)) {
                    $component($sections, "platforms.{$platform}.components.{$name}", fn (string $rest) => str_starts_with($rest, 'base.')
                        ? [ "components.{$name}.platforms.{$platform}." . substr($rest, 5), "components.{$name}.{$rest}" ]
                        : [ "components.{$name}.{$rest}" ]);
                }
            }
        }
    }

    /**
     * The colors a theme makes translucent where its schema keeps them
     * opaque: a node the editor does not mark `alpha` (NodeCompiler, the
     * schema's `alpha`) takes no `transparent`, `#RGBA`, `#RRGGBBAA` or
     * `rgba()` below full alpha, nor an alias to a color that is one (as the
     * theme sets it, else as the schema). The node that declares a property
     * decides: a variant's own, else its base's. A schema that marks none
     * (from before the mark) is not checked.
     *
     * @param array<string, mixed> $values
     * @param array<string, mixed> $schema
     * @return array<int, array{path: string, code: string}>
     */
    private static function translucent(array $values, array $schema): array
    {
        // A schema from before the editor marked translucent colors keeps none opaque.
        if (!is_array($schema['alpha'] ?? null)) {
            return [];
        }
        $alpha = array_flip($schema['alpha']);
        $colors = (is_array($values['tokens']['color'] ?? null) ? $values['tokens']['color'] : []) + (is_array($schema['tokens']['color'] ?? null) ? $schema['tokens']['color'] : []);
        $errors = [];

        $check = function ($value, string $path, array $candidates) use ($alpha, $colors, $schema, &$errors, &$check) {
            $node = self::declaring($schema, $candidates);
            $spec = ThemeSchemas::specAt($schema, $node);
            if ($spec !== null && !in_array($spec[0], [ 'color', 'role', 'border' ], true)) {
                return;
            }
            if (is_array($value)) {
                // A border with no width shows no color.
                if (array_key_exists('color', $value) && !in_array($value['width'] ?? null, [ 0, ThemeFormat::NO_BORDER ], true)) {
                    $check($value['color'], "{$path}.color", [ $node ]);
                }

                return;
            }
            $alias = ThemeFormat::alias($value);
            $color = $alias !== null && $alias[0] === 'color' ? ($colors[$alias[1]] ?? null) : $value;
            if (ThemeFormat::isTranslucent($color) && !isset($alpha[$node])) {
                $errors[] = self::error($path, 'alpha');
            }
        };

        foreach (is_array($values['tokens']['color'] ?? null) ? $values['tokens']['color'] : [] as $name => $value) {
            $check($value, "tokens.color.{$name}", [ "tokens.color.{$name}" ]);
        }
        foreach (is_array($values['modes'] ?? null) ? $values['modes'] : [] as $mode => $groups) {
            foreach (is_array($groups['color'] ?? null) ? $groups['color'] : [] as $name => $value) {
                $check($value, "modes.{$mode}.color.{$name}", [ "tokens.color.{$name}" ]);
            }
        }
        self::eachComponentValue($values, $check);

        return $errors;
    }

    /**
     * The codes alone, as a refusal carries them.
     *
     * @param array<int, array{path: string, code: string}> $errors
     * @return array<int, string>
     */
    public static function codes(array $errors): array
    {
        return array_values(array_unique(array_column($errors, 'code')));
    }

    /**
     * Tokens: their group and name exist, and each value is a literal of
     * the group's kind.
     *
     * @param array<string, mixed> $tokens
     * @return array<int, array{path: string, code: string}>
     */
    public static function tokens(array $tokens, string $path): array
    {
        $errors = [];
        foreach ($tokens as $group => $names) {
            $group_path = "{$path}.{$group}";
            $known = ThemeFormat::tokenNames((string) $group);
            if ($known === []) {
                $errors[] = self::error($group_path, 'unknown');
                continue;
            }
            if (!is_array($names)) {
                $errors[] = self::error($group_path, 'invalid');
                continue;
            }

            foreach ($names as $name => $value) {
                $token_path = "{$group_path}.{$name}";
                if (!in_array($name, $known, true)) {
                    $errors[] = self::error($token_path, 'unknown');
                    continue;
                }

                $valid = match ($group) {
                    'shadow_style' => match ($name) {
                        'style' => in_array($value, ThemeShadows::STYLES, true),
                        'direction' => in_array($value, ThemeShadows::DIRECTIONS, true),
                        'size' => in_array($value, ThemeFormat::SHADOW, true),
                        default => ThemeFormat::isColor($value),
                    },
                    'motion_style' => match ($name) {
                        'easing' => in_array($value, ThemeFormat::EASINGS, true),
                        default => is_bool($value),
                    },
                    'focus_ring' => match ($name) {
                        'width' => in_array($value, ThemeFormat::BORDER, true),
                        'offset' => in_array($value, ThemeFormat::SPACE, true),
                        default => in_array($value, ThemeFormat::COLORS, true),
                    },
                    'border_style' => match ($name) {
                        'radius' => in_array($value, ThemeFormat::RADIUS, true),
                        'width' => $value === 'none' || in_array($value, ThemeFormat::BORDER, true),
                        default => in_array($value, ThemeFormat::BORDER_STYLES, true),
                    },
                    'layout' => $name === 'mode' ? in_array($value, ThemeFormat::LAYOUT_MODES, true) : ThemeFormat::isNumber($value),
                    'color' => ThemeFormat::isColor($value),
                    'font' => is_string($value) && array_key_exists($value, ThemeFormat::FONTS),
                    'typography' => ThemeFormat::isTypography($value),
                    'shadow' => ThemeFormat::isShadow($value),
                    'opacity' => ThemeFormat::isOpacity($value),
                    default => ThemeFormat::isNumber($value),
                };
                if (!$valid) {
                    $errors[] = self::error($token_path, 'invalid');
                }
            }
        }

        return $errors;
    }

    /**
     * Component properties: the component, section and property exist in
     * the schema (a variant, state, size or shape may also set a property of the
     * component's base), and each value fits the property's kind. A
     * group's scope changes its components the same way, and its slots
     * choose only among what each slot's component has (ThemeSchemas); a
     * platform override sets neither.
     *
     * @param array<string, mixed> $components
     * @param array<string, mixed> $schema
     * @return array<int, array{path: string, code: string}>
     */
    private static function components(array $components, array $schema, string $path, bool $groups = true): array
    {
        $errors = [];
        foreach ($components as $component => $sections) {
            $component_path = "{$path}.{$component}";
            $spec = $schema['components'][$component] ?? null;
            if ($spec === null || !is_array($sections)) {
                $errors[] = self::error($component_path, 'unknown');
                continue;
            }
            $base = $spec['base'] ?? [];

            foreach ($sections as $section => $props) {
                $section_path = "{$component_path}.{$section}";
                $group = $groups && in_array($section, [ 'scope', 'slots' ], true) && is_array($spec[$section] ?? null);
                if (!is_array($props) || (!$group && !in_array($section, [ 'base', 'variants', 'sizes', 'shapes', 'colors', 'appearances' ], true))) {
                    $errors[] = self::error($section_path, 'unknown');
                    continue;
                }

                if ($section === 'scope') {
                    foreach ($props as $scoped => $changes) {
                        if (!array_key_exists($scoped, $spec['scope'])) {
                            $errors[] = self::error("{$section_path}.{$scoped}", 'unknown');
                            continue;
                        }
                        array_push($errors, ...self::components([ $scoped => $changes ], $schema, $section_path, false));
                    }
                    continue;
                }
                if ($section === 'slots') {
                    foreach ($props as $name => $choices) {
                        $slot_path = "{$section_path}.{$name}";
                        $slot = $spec['slots'][$name] ?? null;
                        if (!is_array($slot) || !is_array($choices)) {
                            $errors[] = self::error($slot_path, 'unknown');
                            continue;
                        }
                        $choosable = $schema['components'][$slot['component'] ?? null] ?? [];
                        foreach ($choices as $axis => $choice) {
                            // Its component is the schema's: a theme may repeat it, never change it.
                            if ($axis === 'component' && $choice === ($slot['component'] ?? null)) {
                                continue;
                            }
                            if ($axis === 'component' || !array_key_exists($axis, $slot)) {
                                $errors[] = self::error("{$slot_path}.{$axis}", 'unknown');
                            } else if ($choice !== null && !(is_string($choice) && array_key_exists($choice, $choosable[ThemeFormat::SLOT_AXES[$axis]] ?? []))) {
                                $errors[] = self::error("{$slot_path}.{$axis}", 'invalid');
                            }
                        }
                    }
                    continue;
                }

                if ($section === 'base') {
                    array_push($errors, ...self::props($props, $base, $section_path));
                    continue;
                }
                foreach ($props as $name => $variant) {
                    $variant_path = "{$section_path}.{$name}";
                    $variant_spec = $spec[$section][$name] ?? null;
                    if ($variant_spec === null || !is_array($variant)) {
                        $errors[] = self::error($variant_path, 'unknown');
                        continue;
                    }
                    $states = $variant['states'] ?? [];
                    $state_specs = $variant_spec['states'] ?? [];
                    unset($variant['states'], $variant_spec['states']);
                    // A color may set any of its roles, declared or not.
                    $own = $section === 'colors'
                        ? $variant_spec + array_fill_keys(ThemeFormat::ROLES, [ 'color', null ])
                        : $variant_spec + $base;
                    array_push($errors, ...self::props($variant, $own, $variant_path));

                    foreach (in_array($section, [ 'variants', 'appearances' ], true) && is_array($states) ? $states : [] as $state => $state_props) {
                        $state_path = "{$variant_path}.states.{$state}";
                        if (!array_key_exists($state, $state_specs) || !is_array($state_props)) {
                            $errors[] = self::error($state_path, 'unknown');
                            continue;
                        }
                        array_push($errors, ...self::props($state_props, $state_specs[$state] + $own, $state_path));
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $props
     * @param array<string, mixed> $specs
     * @return array<int, array{path: string, code: string}>
     */
    /**
     * A site's settings (api-core's SiteSettings) against its schema's `site` block,
     * each problem at `site.<path>`.
     *
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $schema
     * @return array<int, array{path: string, code: string}>
     */
    public static function settings(array $settings, array $schema): array
    {
        if ($settings !== [] && array_is_list($settings)) {
            return [ self::error('site', 'invalid') ];
        }

        return self::tree($settings, is_array($schema['site'] ?? null) ? $schema['site'] : [], 'site', is_array($schema['limits'] ?? null) ? $schema['limits'] : []);
    }

    /**
     * A block of settings in groups (`site`), against the schema's, each
     * number within its editor's limits.
     *
     * @param array<string, mixed> $values
     * @param array<string, mixed> $specs
     * @param array<string, mixed> $limits the schema's, by path
     * @return array<int, array{path: string, code: string}>
     */
    private static function tree(array $values, array $specs, string $path, array $limits): array
    {
        $errors = [];
        foreach ($values as $key => $value) {
            $at = "{$path}.{$key}";
            $spec = $specs[$key] ?? null;
            if (!is_array($spec)) {
                $errors[] = self::error($at, 'unknown');
            } else if (!array_is_list($spec)) {
                array_push($errors, ...(is_array($value) && ($value === [] || !array_is_list($value)) ? self::tree($value, $spec, $at, $limits) : [ self::error($at, 'invalid') ]));
            } else if (($rule = self::value($spec[0], $value, $spec[2] ?? null, $spec[3] ?? null)) !== null) {
                $errors[] = self::error($at, $rule);
            } else {
                array_push($errors, ...self::outOfRange($value, $at, $limits[$at] ?? null, null));
            }
        }

        return $errors;
    }

    private static function props(array $props, array $specs, string $path): array
    {
        $errors = [];
        foreach ($props as $prop => $value) {
            $prop_path = "{$path}.{$prop}";
            $spec = $specs[$prop] ?? null;
            if ($spec === null) {
                $errors[] = self::error($prop_path, 'unknown');
            } else if ($value === null && $spec[1] === null && in_array($spec[0], [ 'color', 'role' ], true)) {
                // A color without a default of its own (ThemeSchemas), as its default says.
                continue;
            } else if (($rule = self::value($spec[0], $value, $spec[2] ?? null, $spec[3] ?? null)) !== null) {
                $errors[] = self::error($prop_path, $rule);
            }
        }

        return $errors;
    }

    /**
     * What is wrong with a value of a kind (null when nothing): `invalid`
     * or `alias`. Its rules (self::validRules()) may let a typography
     * also be its own values, and keep a string shorter or to a pattern
     * (an empty string is unset, so any pattern takes it).
     *
     * @param array<int, mixed>|null $options
     * @param array<string, mixed>|null $rules
     */
    public static function value(string $kind, $value, ?array $options = null, ?array $rules = null): ?string
    {
        if ($kind === 'text' && ($rules['custom'] ?? false) === true && ThemeFormat::isTypography($value)) {
            return null;
        }
        if ($kind === 'string' && is_string($value) && $value !== '') {
            if (mb_strlen($value) > ($rules['max_length'] ?? ThemeFormat::STRING_MAX_LENGTH)
                || (is_string($rules['pattern'] ?? null) && !ThemePattern::matches($rules['pattern'], $value))) {
                return 'invalid';
            }
        }
        if ($kind === 'role' && is_string($value) && preg_match(ThemeFormat::ROLE, $value)) {
            return null;
        }
        if ($kind === 'border' && is_array($value) && is_string($value['color'] ?? null) && preg_match(ThemeFormat::ROLE, $value['color'])) {
            // The border of an appearance, in a role of its color.
            $value['color'] = '#000000';
        }

        $alias = ThemeFormat::alias($value);
        if ($alias !== null) {
            $group = ThemeFormat::PROPERTY_KINDS[$kind] ?? null;
            [ $alias_group, $name ] = $alias;

            if ($group !== $alias_group || !in_array($name, ThemeFormat::tokenNames($alias_group), true)) {
                return 'alias';
            }

            // A level the property leaves out of its options.
            return is_array($options) && in_array($kind, ThemeFormat::LEVEL_OPTION_KINDS, true) && !in_array($name, $options, true) ? 'invalid' : null;
        }

        $valid = match ($kind) {
            'color', 'role' => ThemeFormat::isColor($value),
            // A level of rounding and a typography style, only by alias.
            'radius', 'text' => false,
            'size', 'icon', 'space', 'motion', 'number' => ThemeFormat::isNumber($value),
            'opacity' => ThemeFormat::isOpacity($value),
            'font_role' => in_array($value, ThemeFormat::FONT_ROLES, true),
            'border' => self::isBorder($value),
            'shadow' => ThemeFormat::isShadow($value),
            'image' => ThemeFormat::isImage($value),
            'bool' => is_bool($value),
            'option', 'layout' => is_array($options) && in_array($value, $options, true),
            'string' => is_string($value) && mb_strlen($value) <= ThemeFormat::STRING_MAX_LENGTH,
            'html' => is_string($value) && strlen($value) <= ThemeFormat::HTML_MAX_LENGTH,
            'id' => $value === null || (is_int($value) && $value >= 0) || (is_string($value) && strlen($value) <= ThemeFormat::ID_MAX_LENGTH),
            default => false,
        };

        return $valid ? null : 'invalid';
    }

    /**
     * The rules a property's fourth element may set: `custom` (true, on a
     * typography), `max_length` (characters, at most the engine's) and
     * `pattern` (a regular expression in the subset ECMAScript and PCRE
     * read alike, ThemePattern, that also compiles here), on a string.
     */
    public static function validRules(string $kind, $rules): bool
    {
        if (!is_array($rules) || $rules === [] || array_is_list($rules) || array_diff(array_keys($rules), [ 'custom', 'max_length', 'pattern' ]) !== []) {
            return false;
        }
        if (array_key_exists('custom', $rules) && ($kind !== 'text' || $rules['custom'] !== true)) {
            return false;
        }
        if (array_key_exists('max_length', $rules) && ($kind !== 'string' || !is_int($rules['max_length']) || $rules['max_length'] < 1 || $rules['max_length'] > ThemeFormat::STRING_MAX_LENGTH)) {
            return false;
        }

        if (!array_key_exists('pattern', $rules)) {
            return true;
        }
        if ($kind !== 'string' || !is_string($rules['pattern']) || $rules['pattern'] === '') {
            return false;
        }
        $source = ThemePattern::source($rules['pattern']);
        if ($source === null) {
            return false;
        }
        // A pattern PCRE cannot compile warns: whatever handles warnings, it is only a refusal here.
        set_error_handler(fn () => true);
        try {
            return preg_match(ThemePattern::regex($source), '') !== false;
        } finally {
            restore_error_handler();
        }
    }

    /**
     * None (null, or `none`: what a theme sets to have none, since saving a
     * null removes the key) or `{ width, color, style? }`, the width a
     * number or a border token, the color a color or a color token.
     */
    private static function isBorder($value): bool
    {
        if ($value === null || $value === ThemeFormat::NO_BORDER) {
            return true;
        }
        if (!is_array($value) || array_diff(array_keys($value), [ 'width', 'color', 'style' ]) !== [] || !isset($value['width'], $value['color'])) {
            return false;
        }

        $width = ThemeFormat::alias($value['width']);
        $width_ok = $width === null
            ? ThemeFormat::isNumber($value['width'])
            : $width[0] === 'border' && in_array($width[1], ThemeFormat::BORDER, true);

        return $width_ok
            && self::value('color', $value['color']) === null
            && (!array_key_exists('style', $value) || in_array($value['style'], ThemeFormat::BORDER_STYLES, true));
    }

    /**
     * @return array{path: string, code: string}
     */
    private static function error(string $path, string $rule): array
    {
        return [ 'path' => $path, 'code' => $path === '' ? $rule : "{$path}.{$rule}" ];
    }
}
