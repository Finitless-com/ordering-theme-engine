<?php

namespace Ordering\ThemeEngine\Schema;

use Ordering\ThemeEngine\ThemeSchemas;

/**
 * Turns a theme schema in the node format (NodeFormat) back into the tuples
 * the rest of the engine reads, adding the version 1 paths api-core keeps
 * on its own (`resources/themes/{product_type}/v1-map.json`).
 *
 * It does not validate values: ThemeSchemas::check() does that on what this
 * answers, as it always did. What it does refuse is a node it cannot turn
 * into a tuple, and a token whose declared type or options are not the
 * ones the engine validates it with (NodeFormat::token()).
 */
final class NodeCompiler
{
    /**
     * @param array<string, mixed> $schema a schema in nodes
     * @param array<string, mixed> $v1 the version 1 paths: `v1`, `visibility`,
     *        `images` and `pages`, as NodeConverter::convert() answers them
     * @return array{schema: array<string, mixed>, errors: array<int, array{path: string, code: string}>}
     */
    public static function compile(array $schema, array $v1 = []): array
    {
        $errors = [];
        $fail = function (string $path, string $rule) use (&$errors) {
            $errors[] = [ 'path' => $path, 'code' => "{$path}.{$rule}" ];
        };
        $follows = [];
        // A group's `$type` is its nodes' unless they declare their own.
        foreach ([ 'components', 'pages', 'site' ] as $block) {
            if (is_array($schema[$block] ?? null)) {
                $schema[$block] = self::inheritTypes($schema[$block], null);
            }
        }

        $tuples = [];
        foreach ([ 'product_type', 'version', 'format' ] as $key) {
            if (array_key_exists($key, $schema)) {
                $tuples[$key] = $schema[$key];
            }
        }

        $token_options = [];
        $tuples['tokens'] = self::tokens(is_array($schema['tokens'] ?? null) ? $schema['tokens'] : [], $fail, $token_options);

        $tuples['components'] = [];
        $inherits = [];
        foreach (self::children(is_array($schema['components'] ?? null) ? $schema['components'] : []) as $component => $node) {
            $node = is_array($node) ? $node : [];
            $parent = $node['$extensions'][NodeFormat::EXTENSION]['inherits'] ?? null;
            if ($parent !== null) {
                $inherits[$component] = $parent;
            }
            $tuples['components'][$component] = self::component(self::children($node), "components.{$component}", $fail, $follows);
        }
        if ($inherits !== []) {
            $tuples['inherits'] = $inherits;
        }

        $tuples['visibility'] = [];
        foreach (self::children(is_array($schema['visibility'] ?? null) ? $schema['visibility'] : []) as $key => $node) {
            $tuples['visibility'][$key] = [ $node['$value'] ?? null, $v1['visibility'][$key] ?? null ];
            // Its default on a platform that shows it otherwise (`platforms.<platform>.visibility`).
            $own = is_array($node) ? ($node['$extensions'][NodeFormat::EXTENSION] ?? []) : [];
            if (array_key_exists('platforms', $own)) {
                $tuples['visibility'][$key][] = $own['platforms'];
            }
        }

        $tuples['images'] = [];
        foreach (self::children(is_array($schema['images'] ?? null) ? $schema['images'] : []) as $key => $node) {
            $own = is_array($node) ? ($node['$extensions'][NodeFormat::EXTENSION] ?? []) : [];
            $entry = [ $node['$value'] ?? null, $v1['images'][$key] ?? null ];
            if (array_key_exists('hints', $own)) {
                array_push($entry, $own['formats'] ?? null, $own['hints']);
            } else if (array_key_exists('formats', $own)) {
                $entry[] = $own['formats'];
            }
            $tuples['images'][$key] = $entry;
        }

        $tuples['pages'] = [];
        foreach (self::children(is_array($schema['pages'] ?? null) ? $schema['pages'] : []) as $page => $fields) {
            $tuples['pages'][$page] = [];
            foreach (self::children(is_array($fields) ? $fields : []) as $field => $node) {
                $tuple = self::tuple($node, "pages.{$page}.{$field}", $fail);
                if ($field === 'sections' && is_array($tuple[1] ?? null)) {
                    foreach ($tuple[1] as $index => $section) {
                        $from = $v1['pages'][$page][$section['key'] ?? ''] ?? [];
                        $tuple[1][$index] = $section + array_intersect_key($from, [ 'v1' => true, 'v1_default' => true ]);
                    }
                }
                $tuples['pages'][$page][$field] = $tuple;
            }
        }

        if (array_key_exists('platforms', $schema)) {
            $tuples['platforms'] = $schema['platforms'];
        }
        // The colors the editor may make translucent (editor `alpha`), by path:
        // any other color of a theme is opaque (ThemeValidator::translucent()).
        $alpha = [];
        foreach ([ 'tokens', 'components' ] as $block) {
            self::alpha(is_array($schema[$block] ?? null) ? $schema[$block] : [], $block, $alpha);
        }
        if ($alpha !== []) {
            $tuples['alpha'] = $alpha;
        }
        // The options a schema keeps of a token it narrows (NodeFormat::NARROWABLE).
        if ($token_options !== []) {
            $tuples['token_options'] = $token_options;
        }
        // The renames and moves a theme's values follow (ThemeMigrations).
        if (is_array($schema['$extensions'][NodeFormat::MIGRATE] ?? null)) {
            $tuples['migrate'] = $schema['$extensions'][NodeFormat::MIGRATE];
        }
        if (is_array($schema['site'] ?? null)) {
            $tuples['site'] = self::tree(self::children($schema['site']), 'site', $fail);
        }
        // The site's settings convert and project through the same `v1` block,
        // under `site.`: each setting the schema has.
        $paths = is_array($v1['v1'] ?? null) ? $v1['v1'] : [];
        foreach (is_array($v1['site'] ?? null) ? $v1['site'] : [] as $key => $path) {
            $node = $tuples['site'] ?? null;
            foreach (explode('.', (string) $key) as $segment) {
                $node = is_array($node) ? ($node[$segment] ?? null) : null;
            }
            if (is_string($path) && is_array($node) && array_is_list($node)) {
                $paths["site.{$key}"] = $path;
            }
        }
        // The map outlives what a product removes (a layout it no longer has): only what the schema has.
        // Visibility, images and pages carry theirs in their own entries.
        $paths = array_filter($paths, fn ($path, $v2_path) => ThemeSchemas::kindAt($tuples, (string) $v2_path) !== null
            && in_array(explode('.', (string) $v2_path)[0], [ 'tokens', 'components', 'site' ], true), ARRAY_FILTER_USE_BOTH);
        if ($paths !== []) {
            $tuples['v1'] = $paths;
        }
        // A schema that declares nothing follows Identity by the engine's rule (ThemeOverall::follows()).
        if ($follows !== []) {
            $tuples['follows'] = $follows;
        }
        // A schema that declares no derivation keeps the engine's (ThemeColors::rules()).
        $derive = [];
        foreach (self::children(is_array($schema['tokens']['color'] ?? null) ? $schema['tokens']['color'] : []) as $color => $node) {
            $rule = is_array($node) ? ($node['$extensions'][NodeFormat::EXTENSION]['derive'] ?? null) : null;
            if ($rule !== null) {
                $derive[$color] = $rule;
            }
        }
        if ($derive !== []) {
            $tuples['derive'] = $derive;
        }
        // Likewise the components' rules (ThemeDerivations::rules()).
        $component_derive = [];
        $matrix = [];
        foreach (self::children(is_array($schema['components'] ?? null) ? $schema['components'] : []) as $component => $node) {
            if (!is_array($node)) {
                continue;
            }
            if (is_array($node['$extensions'][NodeFormat::EXTENSION]['matrix'] ?? null)) {
                $matrix[$component] = $node['$extensions'][NodeFormat::EXTENSION]['matrix'];
            }
            foreach (self::derivations(self::children($node['variants'] ?? []), 'variants') as $path => $rule) {
                $component_derive[$component][$path] = $rule;
            }
        }
        if ($component_derive !== [] || $matrix !== []) {
            $tuples['component_derive'] = $component_derive;
            $tuples['matrix'] = $matrix;
        }

        return [ 'schema' => $tuples, 'errors' => $errors ];
    }

    /**
     * Whether a schema is written in nodes rather than in tuples.
     *
     * @param array<string, mixed> $schema
     */
    public static function isNodes(array $schema): bool
    {
        return ($schema['$schema'] ?? null) === NodeFormat::ID;
    }

    /**
     * @param array<string, mixed> $groups
     * @param array<string, array<int, mixed>> $narrowed the options of each token the schema narrows, by `group.name`, filled in
     * @return array<string, mixed>
     */
    private static function tokens(array $groups, callable $fail, array &$narrowed): array
    {
        $tokens = [];
        $colors = array_keys(self::children(is_array($groups['color'] ?? null) ? $groups['color'] : []));
        foreach (self::children($groups) as $group => $node) {
            $node = is_array($node) ? $node : [];
            $shared = $node['$type'] ?? null;
            $tokens[$group] = [];
            foreach (self::children($node) as $name => $token) {
                $path = "tokens.{$group}.{$name}";
                if (!NodeFormat::isNode($token)) {
                    $fail($path, 'invalid');
                    continue;
                }
                $expected = NodeFormat::token((string) $group, (string) $name, $colors);
                $options = $token['$extensions'][NodeFormat::EXTENSION]['options'] ?? null;
                $type = $token['$type'] ?? $shared;
                $narrows = $expected !== null && in_array("{$group}.{$name}", NodeFormat::NARROWABLE, true) && $options !== $expected[1]
                    && is_array($options) && $options !== [] && array_is_list($options)
                    && count(array_unique($options)) === count($options) && array_diff($options, $expected[1]) === [];
                if ($expected !== null && (($type !== $expected[0] && $type !== (NodeFormat::FORMER_TYPES[$group] ?? null)) || ($options !== $expected[1] && !$narrows))) {
                    $fail($path, 'type');
                } else if ($narrows) {
                    $narrowed["{$group}.{$name}"] = $options;
                }
                $tokens[$group][$name] = $token['$value'];
            }
        }

        return $tokens;
    }

    /**
     * @param array<string, mixed> $parts
     * @return array<string, mixed>
     */
    /**
     * @param array<string, string> $follows what follows Identity, by path, filled in
     */
    private static function component(array $parts, string $path, callable $fail, array &$follows): array
    {
        $spec = [];
        foreach ($parts as $section => $node) {
            $node = self::children(is_array($node) ? $node : []);
            $section_path = "{$path}.{$section}";
            if ($section === 'base') {
                $spec[$section] = self::props($node, $section_path, $fail);
                $follows += self::follows($node, $section_path);
            } else if ($section === 'platforms') {
                $spec[$section] = [];
                foreach ($node as $platform => $props) {
                    $spec[$section][$platform] = self::props(self::children(is_array($props) ? $props : []), "{$section_path}.{$platform}", $fail);
                }
            } else if ($section === 'slots') {
                $spec[$section] = array_map(fn ($slot) => is_array($slot) && NodeFormat::isNode($slot) ? $slot['$value'] : $slot, $node);
            } else if ($section === 'scope') {
                $spec[$section] = [];
                foreach ($node as $scoped => $changes) {
                    $spec[$section][$scoped] = self::tree(self::children(is_array($changes) ? $changes : []), "{$section_path}.{$scoped}", $fail);
                }
            } else {
                $spec[$section] = [];
                foreach ($node as $name => $variant) {
                    $variant_path = "{$section_path}.{$name}";
                    $spec[$section][$name] = [];
                    foreach (self::children(is_array($variant) ? $variant : []) as $prop => $prop_node) {
                        if ($prop !== 'states') {
                            $spec[$section][$name][$prop] = self::tuple($prop_node, "{$variant_path}.{$prop}", $fail);
                            $follows += self::follows([ $prop => $prop_node ], $variant_path);
                            continue;
                        }
                        $spec[$section][$name]['states'] = [];
                        foreach (self::children(is_array($prop_node) ? $prop_node : []) as $state => $props) {
                            $spec[$section][$name]['states'][$state] = self::props(self::children(is_array($props) ? $props : []), "{$variant_path}.states.{$state}", $fail);
                        }
                    }
                }
            }
        }

        return $spec;
    }

    /**
     * @param array<string, mixed> $props
     * @return array<string, mixed>
     */
    private static function props(array $props, string $path, callable $fail): array
    {
        $tuples = [];
        foreach ($props as $prop => $node) {
            $tuples[$prop] = self::tuple($node, "{$path}.{$prop}", $fail);
        }

        return $tuples;
    }

    /**
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    private static function tree(array $changes, string $path, callable $fail): array
    {
        $changes = self::children($changes);
        foreach ($changes as $key => $value) {
            $changes[$key] = NodeFormat::isNode($value)
                ? self::tuple($value, "{$path}.{$key}", $fail)
                : (is_array($value) ? self::tree($value, "{$path}.{$key}", $fail) : $value);
        }

        return $changes;
    }

    /**
     * A node as its tuple `[kind, default, options?, rules?]`, the rules
     * what else its value must be: `custom` (a typography that may hold its
     * own values, from its editor), `max_length` and `pattern` (a string).
     * A node without a type is answered as its value, for
     * ThemeSchemas::check() to refuse.
     *
     * @return mixed
     */
    private static function tuple($node, string $path, callable $fail)
    {
        if (!NodeFormat::isNode($node)) {
            $fail($path, 'invalid');

            return $node;
        }
        if (!is_string($node['$type'] ?? null)) {
            return $node['$value'];
        }

        $own = $node['$extensions'][NodeFormat::EXTENSION] ?? [];
        $kind = NodeFormat::kindOf($node['$type'], $own['scale'] ?? null);
        if ($kind === null) {
            $fail($path, 'type');

            return $node['$value'];
        }

        $rules = array_filter([
            'custom' => ($node['$extensions'][NodeFormat::EDITOR]['custom'] ?? null) === true ? true : null,
            'max_length' => $own['max_length'] ?? null,
            'pattern' => $own['pattern'] ?? null,
        ], fn ($rule) => $rule !== null);
        $tuple = [ $kind, $node['$value'] ];
        if (array_key_exists('options', $own) || $rules !== []) {
            $tuple[] = $own['options'] ?? null;
        }
        if ($rules !== []) {
            $tuple[] = $rules;
        }

        return $tuple;
    }

    /**
     * The paths of the nodes under $tree whose editor marks them `alpha`.
     *
     * @param array<string, mixed> $tree
     * @param array<int, string> $paths
     */
    private static function alpha(array $tree, string $path, array &$paths): void
    {
        foreach (self::children($tree) as $key => $child) {
            if (!is_array($child)) {
                continue;
            }
            if (NodeFormat::isNode($child)) {
                if (($child['$extensions'][NodeFormat::EDITOR]['alpha'] ?? false) === true) {
                    $paths[] = "{$path}.{$key}";
                }
            } else {
                self::alpha($child, "{$path}.{$key}", $paths);
            }
        }
    }

    /**
     * The tree with each node's `$type` set from its closest group when the
     * node does not declare one.
     *
     * @param array<string, mixed> $tree
     * @return array<string, mixed>
     */
    private static function inheritTypes(array $tree, ?string $type): array
    {
        $type = is_string($tree['$type'] ?? null) ? $tree['$type'] : $type;
        foreach ($tree as $key => $child) {
            if (str_starts_with((string) $key, '$') || !is_array($child)) {
                continue;
            }
            if (NodeFormat::isNode($child)) {
                if (!isset($child['$type']) && $type !== null) {
                    $tree[$key] = [ '$type' => $type ] + $child;
                }
            } else {
                $tree[$key] = self::inheritTypes($child, $type);
            }
        }

        return $tree;
    }

    /**
     * The `derive` of the nodes under a group, by their path from it.
     *
     * @param array<string, mixed> $group
     * @return array<string, array<string, mixed>>
     */
    private static function derivations(array $group, string $path): array
    {
        $rules = [];
        foreach ($group as $key => $node) {
            if (!is_array($node)) {
                continue;
            }
            if (NodeFormat::isNode($node)) {
                $rule = $node['$extensions'][NodeFormat::EXTENSION]['derive'] ?? null;
                if (is_array($rule)) {
                    $rules["{$path}.{$key}"] = $rule;
                }
            } else {
                $rules += self::derivations(self::children($node), "{$path}.{$key}");
            }
        }

        return $rules;
    }

    /**
     * Which of a block's nodes follow Identity, by path.
     *
     * @param array<string, mixed> $nodes
     * @return array<string, string>
     */
    private static function follows(array $nodes, string $path): array
    {
        $follows = [];
        foreach ($nodes as $prop => $node) {
            $group = is_array($node) ? ($node['$extensions'][NodeFormat::EXTENSION]['follows'] ?? null) : null;
            if ($group !== null) {
                $follows["{$path}.{$prop}"] = $group;
            }
        }

        return $follows;
    }

    /**
     * What a group holds: everything but its `$` keys.
     *
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private static function children(array $node): array
    {
        return array_filter($node, fn ($key) => !str_starts_with((string) $key, '$'), ARRAY_FILTER_USE_KEY);
    }
}
