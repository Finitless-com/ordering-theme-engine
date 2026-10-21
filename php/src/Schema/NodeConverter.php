<?php

namespace Ordering\ThemeEngine\Schema;

use Ordering\ThemeEngine\ThemeFormat;

use Ordering\ThemeEngine\ThemeSchemas;

/**
 * Turns a theme schema written in tuples (api-core's docs/THEMES_V2_PLAN.md §2.2)
 * into the node format (NodeFormat), and takes out of it the version 1
 * paths, which api-core keeps on its own (NodeCompiler::compile()'s `$v1`).
 *
 * NodeCompiler::compile() of what this answers gives back the same schema
 * once it declares what its engine would apply by rule
 * (ThemeSchemas::declare()), which resolves the same: the conversion is
 * safe to run on any schema of the old format.
 */
final class NodeConverter
{
    /**
     * @param array<string, mixed> $schema a schema in tuples: with its version
     *        1 paths (a `v1` block, `[default, path]` entries), or without
     *        them (visibility as its default alone, images as `[default,
     *        formats, hints?]`), as the product's repository writes it once
     *        it no longer reads version 1
     * @param array<string, mixed>|null $v1 for a schema without version 1
     *        paths, the ones api-core keeps for it (its `v1-map.json`)
     * @return array{schema: array<string, mixed>, v1: array<string, mixed>}
     */
    public static function convert(array $schema, ?array $v1 = null): array
    {
        $paths = array_key_exists('v1', $schema);
        // What follows Identity and how colors derive are declared on each node.
        $schema = ThemeSchemas::declare($schema);
        $nodes = [ '$schema' => NodeFormat::ID ];
        if (is_array($schema['migrate'] ?? null)) {
            $nodes['$extensions'] = [ NodeFormat::MIGRATE => $schema['migrate'] ];
        }
        foreach ([ 'product_type', 'version', 'format', 'platforms' ] as $key) {
            if (array_key_exists($key, $schema)) {
                $nodes[$key] = $schema[$key];
            }
        }

        $nodes['tokens'] = self::tokens(is_array($schema['tokens'] ?? null) ? $schema['tokens'] : [], is_array($schema['token_options'] ?? null) ? $schema['token_options'] : []);

        $inherits = is_array($schema['inherits'] ?? null) ? $schema['inherits'] : [];
        $nodes['components'] = [];
        foreach (is_array($schema['components'] ?? null) ? $schema['components'] : [] as $component => $spec) {
            $node = self::component(is_array($spec) ? $spec : []);
            if (isset($inherits[$component])) {
                $node = [ '$extensions' => [ NodeFormat::EXTENSION => [ 'inherits' => $inherits[$component] ] ] ] + $node;
            }
            $nodes['components'][$component] = $node;
        }
        $nodes = self::rules($nodes, $schema);
        foreach (is_array($schema['follows'] ?? null) ? $schema['follows'] : [] as $path => $group) {
            $node = &$nodes;
            foreach (explode('.', (string) $path) as $segment) {
                $node = &$node[$segment];
            }
            $node['$extensions'][NodeFormat::EXTENSION]['follows'] = $group;
            unset($node);
        }

        $kept = $v1;
        $v1 = [
            'v1' => is_array($schema['v1'] ?? null) ? $schema['v1'] : [],
            'visibility' => [],
            'images' => [],
            'pages' => [],
        ];

        $nodes['visibility'] = [ '$type' => 'boolean' ];
        foreach (is_array($schema['visibility'] ?? null) ? $schema['visibility'] : [] as $key => $entry) {
            $nodes['visibility'][$key] = [ '$value' => $paths ? $entry[0] ?? null : $entry ];
            if ($paths && array_key_exists(2, $entry)) {
                $nodes['visibility'][$key]['$extensions'] = [ NodeFormat::EXTENSION => [ 'platforms' => $entry[2] ] ];
            }
            $v1['visibility'][$key] = $paths ? $entry[1] ?? null : null;
        }

        $nodes['images'] = [ '$type' => 'image' ];
        foreach (is_array($schema['images'] ?? null) ? $schema['images'] : [] as $key => $entry) {
            if (!$paths) {
                // `[default, formats, hints?]`: the version 1 path was the second.
                array_splice($entry, 1, 0, [ null ]);
            }
            $node = [ '$value' => $entry[0] ?? '' ];
            $own = [];
            if (array_key_exists(2, $entry)) {
                $own['formats'] = $entry[2];
            }
            if (array_key_exists(3, $entry)) {
                $own['hints'] = $entry[3];
            }
            if ($own !== []) {
                $node['$extensions'] = [ NodeFormat::EXTENSION => $own ];
            }
            $nodes['images'][$key] = $node;
            $v1['images'][$key] = $entry[1] ?? null;
        }

        $nodes['pages'] = [];
        foreach (is_array($schema['pages'] ?? null) ? $schema['pages'] : [] as $page => $spec) {
            $nodes['pages'][$page] = [];
            foreach (is_array($spec) ? $spec : [] as $field => $tuple) {
                if ($field === 'sections' && is_array($tuple[1] ?? null)) {
                    foreach ($tuple[1] as $index => $section) {
                        $from = array_intersect_key($section, [ 'v1' => true, 'v1_default' => true ]);
                        if ($from !== []) {
                            $v1['pages'][$page][$section['key']] = $from;
                        }
                        $tuple[1][$index] = array_diff_key($section, $from);
                    }
                }
                $nodes['pages'][$page][$field] = self::node($tuple);
            }
        }

        if (is_array($schema['site'] ?? null)) {
            $nodes['site'] = self::tree($schema['site']);
        }

        // What the engine reads of the editor's metadata: the colors it may
        // make translucent, and the numeric limits.
        $editor = [];
        foreach (is_array($schema['alpha'] ?? null) ? $schema['alpha'] : [] as $path) {
            $editor[] = [ (string) $path, [ 'alpha' => true ] ];
        }
        foreach (is_array($schema['limits'] ?? null) ? $schema['limits'] : [] as $path => $limits) {
            $editor[] = [ (string) $path, is_array($limits) ? $limits : [] ];
        }
        foreach ($editor as [ $path, $metadata ]) {
            $node = &$nodes;
            foreach (explode('.', $path) as $segment) {
                if (!is_array($node[$segment] ?? null)) {
                    unset($node);
                    continue 2;
                }
                $node = &$node[$segment];
            }
            $node['$extensions'][NodeFormat::EDITOR] = array_merge($node['$extensions'][NodeFormat::EDITOR] ?? [], $metadata);
            unset($node);
        }

        return [ 'schema' => $nodes, 'v1' => $paths || $kept === null ? $v1 : $kept ];
    }

    /**
     * A schema in nodes with what its engine would otherwise apply by rule
     * declared on each node (ThemeSchemas::declare()): the colors' `derive`
     * and the properties' `follows` it lacks. Nothing else changes, its
     * editor metadata included, and it resolves the same.
     *
     * @param array<string, mixed> $nodes
     * @return array<string, mixed>
     */
    public static function declare(array $nodes): array
    {
        $tuples = NodeCompiler::compile($nodes)['schema'];
        // Nothing declared yet: the engine's rule says it.
        if (($tuples['follows'] ?? []) === []) {
            unset($tuples['follows']);
        }
        $declared = ThemeSchemas::declare($tuples);
        $nodes = self::rules($nodes, $declared, false);
        foreach (is_array($declared['follows'] ?? null) ? $declared['follows'] : [] as $path => $group) {
            $node = &$nodes;
            foreach (explode('.', (string) $path) as $segment) {
                $node = &$node[$segment];
            }
            if (is_array($node) && !isset($node['$extensions'][NodeFormat::EXTENSION]['follows'])) {
                $node['$extensions'][NodeFormat::EXTENSION]['follows'] = $group;
            }
            unset($node);
        }

        return $nodes;
    }

    /**
     * The nodes with the derivation rules of a schema in tuples on each
     * node they derive: the colors' `derive`, the components' `derive`, and
     * each matrix on its component.
     *
     * @param array<string, mixed> $nodes
     * @param array<string, mixed> $schema
     * @param bool $replace false keeps a rule a node already declares
     * @return array<string, mixed>
     */
    private static function rules(array $nodes, array $schema, bool $replace = true): array
    {
        $put = function (array &$node, string $key, array $rule) use ($replace) {
            if ($replace || !isset($node['$extensions'][NodeFormat::EXTENSION][$key])) {
                $node['$extensions'][NodeFormat::EXTENSION][$key] = $rule;
            }
        };
        foreach (is_array($schema['derive'] ?? null) ? $schema['derive'] : [] as $color => $rule) {
            if (is_array($nodes['tokens']['color'][$color] ?? null)) {
                $put($nodes['tokens']['color'][$color], 'derive', $rule);
            }
        }
        foreach (is_array($schema['component_derive'] ?? null) ? $schema['component_derive'] : [] as $component => $rules) {
            foreach (is_array($rules) ? $rules : [] as $path => $rule) {
                $node = &$nodes['components'][$component];
                foreach (explode('.', (string) $path) as $segment) {
                    $node = &$node[$segment];
                }
                if (is_array($node) && array_key_exists('$value', $node)) {
                    $put($node, 'derive', $rule);
                }
                unset($node);
            }
        }
        foreach (is_array($schema['matrix'] ?? null) ? $schema['matrix'] : [] as $component => $matrix) {
            if (is_array($nodes['components'][$component] ?? null)) {
                $put($nodes['components'][$component], 'matrix', $matrix);
            }
        }

        return $nodes;
    }

    /**
     * Each group of tokens with its node type: on the group when all its
     * tokens share it, on each token when not.
     *
     * @param array<string, mixed> $tokens
     * @param array<string, array<int, mixed>> $narrowed the options a schema keeps of a token it narrows, by `group.name`
     * @return array<string, mixed>
     */
    private static function tokens(array $tokens, array $narrowed = []): array
    {
        $nodes = [];
        $colors = is_array($tokens['color'] ?? null) ? array_keys($tokens['color']) : ThemeFormat::COLORS;
        foreach ($tokens as $group => $names) {
            $declared = [];
            foreach (is_array($names) ? $names : [] as $name => $value) {
                $declared[$name] = NodeFormat::token((string) $group, (string) $name, $colors) ?? [ null, null ];
            }
            $types = array_unique(array_map(fn (array $token) => $token[0] ?? '', $declared));
            $shared = count($types) === 1 && reset($types) !== '' && array_filter($declared, fn (array $token) => $token[1] !== null) === [];

            $nodes[$group] = $shared ? [ '$type' => reset($types) ] : [];
            foreach (is_array($names) ? $names : [] as $name => $value) {
                [ $type, $options ] = $declared[$name];
                $options = $narrowed["{$group}.{$name}"] ?? $options;
                $node = $shared || $type === null ? [] : [ '$type' => $type ];
                $node['$value'] = $value;
                if ($options !== null) {
                    $node['$extensions'] = [ NodeFormat::EXTENSION => [ 'options' => $options ] ];
                }
                $nodes[$group][$name] = $node;
            }
        }

        return $nodes;
    }

    /**
     * @param array<string, mixed> $spec
     * @return array<string, mixed>
     */
    private static function component(array $spec): array
    {
        $node = [];
        foreach ($spec as $section => $props) {
            $props = is_array($props) ? $props : [];
            $node[$section] = match ($section) {
                'base' => self::props($props),
                'platforms' => array_map(fn ($platform) => self::props(is_array($platform) ? $platform : []), $props),
                'slots' => array_map(fn ($slot) => [ '$type' => 'slot', '$value' => $slot ], $props),
                'scope' => array_map(fn ($changes) => self::tree(is_array($changes) ? $changes : []), $props),
                default => array_map(fn ($variant) => self::variant(is_array($variant) ? $variant : []), $props),
            };
        }

        return $node;
    }

    /**
     * A variant, size, shape, color or appearance, its states in place.
     *
     * @param array<string, mixed> $variant
     * @return array<string, mixed>
     */
    private static function variant(array $variant): array
    {
        $node = [];
        foreach ($variant as $prop => $spec) {
            $node[$prop] = $prop === 'states'
                ? array_map(fn ($state) => self::props(is_array($state) ? $state : []), is_array($spec) ? $spec : [])
                : self::node($spec);
        }

        return $node;
    }

    /**
     * @param array<string, mixed> $props
     * @return array<string, mixed>
     */
    private static function props(array $props): array
    {
        return array_map(fn ($spec) => self::node($spec), $props);
    }

    /**
     * What a scope changes of a component: groups down to its tuples.
     *
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    private static function tree(array $changes): array
    {
        foreach ($changes as $key => $value) {
            $changes[$key] = self::isTuple($value) ? self::node($value) : (is_array($value) ? self::tree($value) : $value);
        }

        return $changes;
    }

    /**
     * A tuple `[kind, default, options?, rules?]` as its node.
     *
     * @return array<string, mixed>
     */
    private static function node($tuple): array
    {
        if (!self::isTuple($tuple)) {
            // Not a tuple: kept as it is, for NodeCompiler and the check to refuse.
            return [ '$value' => $tuple ];
        }

        [ $type, $scale ] = NodeFormat::KINDS[$tuple[0]] ?? [ $tuple[0], null ];
        $node = [ '$type' => $type, '$value' => $tuple[1] ?? null ];
        $own = [];
        if ($scale !== null) {
            $own['scale'] = $scale;
        }
        if (array_key_exists(2, $tuple) && ($tuple[2] !== null || !array_key_exists(3, $tuple))) {
            $own['options'] = $tuple[2];
        }
        $rules = is_array($tuple[3] ?? null) ? $tuple[3] : [];
        $own += array_intersect_key($rules, [ 'max_length' => true, 'pattern' => true ]);
        if ($own !== []) {
            $node['$extensions'] = [ NodeFormat::EXTENSION => $own ];
        }
        // A typography that may hold its own values is the editor's to say.
        if (($rules['custom'] ?? null) === true) {
            $node['$extensions'][NodeFormat::EDITOR] = [ 'custom' => true ];
        }

        return $node;
    }

    /**
     * A schema in nodes as JSON. PHP decodes `{}` and `[]` alike, so an empty
     * group is written back as `{}`; a `$value` is written as it is.
     *
     * @param array<string, mixed> $nodes
     */
    public static function json(array $nodes): string
    {
        $objects = function ($node, bool $value = false) use (&$objects) {
            if (!is_array($node) || $value) {
                return $node;
            }
            if ($node === []) {
                return new \stdClass();
            }
            if (array_is_list($node)) {
                return $node;
            }
            foreach ($node as $key => $child) {
                if (in_array($key, [ NodeFormat::MIGRATE, NodeFormat::UPGRADE ], true) && is_array($child)) {
                    $node[$key] = self::changesJson($key, $child);
                    continue;
                }
                // An extension is an object even when it is left empty; what it holds is written as it is.
                $node[$key] = in_array($key, [ NodeFormat::EXTENSION, NodeFormat::EDITOR ], true) && $child === []
                    ? new \stdClass()
                    : $objects($child, $key === '$value' || $key === NodeFormat::EXTENSION || $key === NodeFormat::EDITOR);
            }

            return $node;
        };

        return json_encode($objects($nodes), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }

    /**
     * A root's migrations or upgrade block as JSON reads it: its lists are
     * lists even when empty (`moves`, `drop`, a rewrite's `paths`), and a
     * move's `map` is an object even when its keys are 0…n-1.
     *
     * @param array<string|int, mixed> $block
     * @return array<string|int, mixed>|\stdClass
     */
    private static function changesJson(string $key, array $block): array|\stdClass
    {
        $moves = fn (array $moves) => array_map(fn ($move) => is_array($move) && is_array($move['map'] ?? null) ? [ 'map' => (object) $move['map'] ] + $move : $move, $moves);
        if ($key === NodeFormat::MIGRATE) {
            return array_is_list($block) ? $moves($block) : (object) $block;
        }
        if ($block === [] || array_is_list($block)) {
            return $block === [] ? new \stdClass() : $block;
        }
        if (is_array($block['moves'] ?? null) && array_is_list($block['moves'])) {
            $block['moves'] = $moves($block['moves']);
        }
        if (is_array($block['rewrite'] ?? null)) {
            $block['rewrite'] = $block['rewrite'] === [] ? new \stdClass() : $block['rewrite'];
        }

        return $block;
    }

    private static function isTuple($value): bool
    {
        return is_array($value) && array_is_list($value) && is_string($value[0] ?? null) && count($value) >= 2 && count($value) <= 4;
    }
}
