<?php

namespace Ordering\ThemeEngine;

use Ordering\ThemeEngine\Schema\NodeCompiler;
use Ordering\ThemeEngine\Schema\NodeFormat;
use Ordering\ThemeEngine\Schema\SchemaDiff;

/**
 * How a theme of one base takes its values in the next one (26 → 27): the
 * first version of a base declares the step from the previous base in
 * `$extensions["co.ordering.upgrade"]` (docs/schema-format.md §15),
 * `{ from, moves: [{ from, to, map? }], drop: [paths], rewrite?: { id, paths } }`,
 * and a theme saved with an older base is read through each step in turn
 * (26 → 27 → 28), with the last version of every base on the way.
 *
 * A step over a theme's values, in order:
 *
 * 1. what the last version of the previous base moved (its
 *    `co.ordering.migrate`) where it went: each key keeps its path;
 * 2. a value whose kind (type or scale) changed at the same path is
 *    dropped, unless a move takes it or the rewrite does: a size in px is
 *    no ratio;
 * 3. the moves, in order, as a migration moves a key (ThemeMigrations): a
 *    value goes to `to` unless the theme sets it, `from` goes either way, a
 *    platform's override and a token's modes and aliases follow, and a
 *    `map` translates the value (one it has no entry for is dropped);
 * 4. what `drop` names, wherever a migration would find it;
 * 5. the rewrite, what the base cannot declare: a callback the caller
 *    passes (api-core runs the class it registers under that id);
 * 6. the next version's migrations and ThemeResolver::valid(): what does
 *    not fit is left out, so its default shows.
 *
 * A site's settings take the same step by their `site.` paths. A
 * schema's own checks of the block are self::check()'s, at publish.
 *
 * The schemas are compiled (tuples), except self::check()'s, in nodes.
 */
final class ThemeUpgrades
{
    /** The sections of a component whose entries may set a property of its base. */
    private const SECTIONS = [ 'variants', 'sizes', 'shapes', 'colors', 'appearances' ];

    /**
     * Whether a compiled schema's `upgrade` has the shape the engine reads:
     * an integer `from` and the lists `moves` and `drop`.
     *
     * @param mixed $block
     */
    public static function isBlock($block): bool
    {
        return is_array($block) && is_int($block['from'] ?? null)
            && is_array($block['moves'] ?? null) && array_is_list($block['moves'])
            && is_array($block['drop'] ?? null) && array_is_list($block['drop']);
    }

    /**
     * What a schema's upgrade block gets wrong against the last version of
     * the previous base (null when the product type has none), both in
     * nodes. Each problem is `{ path, code }`:
     *
     * - `upgrade.required`: there is a previous base, and no block;
     * - `upgrade.from.invalid`: `from` is not the previous base, below the
     *   schema's own (or there is no previous base); nothing else is checked;
     * - `upgrade.moves.<n>.from.unknown`: the previous base has no such key;
     * - `upgrade.moves.<n>.to.unknown`: this one has none, also once its own
     *   migrations move it;
     * - `upgrade.moves.<n>.kind`: another section, or another kind without
     *   a `map`;
     * - `upgrade.moves.<n>.map.invalid`: a value that is no option or level
     *   of the destination;
     * - `upgrade.moves.<n>.duplicate`: a `from` an earlier move has;
     * - `upgrade.drop.<n>.unknown`: a path the previous base does not have;
     * - `upgrade.<path>.unaccounted`: a key of the previous base that
     *   neither arrives at the same path with the same kind nor is moved,
     *   dropped or rewritten (a path also covers what is under it, but in
     *   visibility and images, whose keys hold dots of their own).
     *
     * Whether api-core has the rewrite is api-core's to check.
     *
     * @param array<string, mixed> $next in nodes
     * @param array<string, mixed>|null $previous in nodes, the last version of the previous base
     * @return array<int, array{path: string, code: string}>
     */
    public static function check(array $next, ?array $previous): array
    {
        $block = $next['$extensions'][NodeFormat::UPGRADE] ?? null;
        if ($previous === null) {
            return $block === null ? [] : [ self::error('upgrade.from', 'invalid') ];
        }
        if ($block === null) {
            return [ self::error('upgrade', 'required') ];
        }
        $from = is_array($block) ? ($block['from'] ?? null) : null;
        $base = (int) explode('.', (string) ($next['version'] ?? ''))[0];
        if (!is_int($from) || $from >= $base || $from !== (int) explode('.', (string) ($previous['version'] ?? ''))[0]) {
            return [ self::error('upgrade.from', 'invalid') ];
        }

        $before = NodeCompiler::compile($previous)['schema'];
        $after = NodeCompiler::compile($next)['schema'];
        $moves = is_array($block['moves'] ?? null) ? $block['moves'] : [];
        $drop = is_array($block['drop'] ?? null) ? $block['drop'] : [];
        $errors = [];

        $seen = [];
        foreach ($moves as $index => $move) {
            $at = "upgrade.moves.{$index}";
            $source = is_array($move) && is_string($move['from'] ?? null) ? $move['from'] : null;
            $target = is_array($move) && is_string($move['to'] ?? null) ? self::follow($after['migrate'] ?? [], $move['to']) : null;
            $map = is_array($move) ? ($move['map'] ?? null) : null;
            $source_kind = $source === null ? null : ThemeSchemas::kindAt($before, $source);
            $target_kind = $target === null ? null : ThemeSchemas::kindAt($after, $target);
            if ($source_kind === null) {
                $errors[] = self::error("{$at}.from", 'unknown');
            }
            if ($target_kind === null) {
                $errors[] = self::error("{$at}.to", 'unknown');
            }
            if ($source_kind !== null && $target_kind !== null) {
                if (explode('.', $source)[0] !== explode('.', $target)[0] || ($source_kind !== $target_kind && $map === null)) {
                    $errors[] = self::error($at, 'kind');
                }
                if ($map !== null && !self::validMap($map, $after, $target)) {
                    $errors[] = self::error("{$at}.map", 'invalid');
                }
            }
            if ($source !== null && isset($seen[$source])) {
                $errors[] = self::error($at, 'duplicate');
            }
            if ($source !== null) {
                $seen[$source] = true;
            }
        }

        $leaves = SchemaDiff::leaves($previous);
        foreach ($drop as $index => $path) {
            if (!is_string($path) || (ThemeSchemas::kindAt($before, $path) === null && !self::holds(array_keys($leaves), $path))) {
                $errors[] = self::error("upgrade.drop.{$index}", 'unknown');
            }
        }

        $rewritten = is_array($block['rewrite']['paths'] ?? null) ? $block['rewrite']['paths'] : [];
        $taken = array_values(array_filter([ ...array_map(fn ($move) => is_array($move) ? ($move['from'] ?? null) : null, $moves), ...$drop, ...$rewritten ], 'is_string'));
        $arrived = SchemaDiff::leaves($next);
        foreach ($leaves as $path => $leaf) {
            $path = (string) $path;
            if (self::arrives($path, $leaf, $before, $after, $arrived) || self::coveredBy($path, $taken)) {
                continue;
            }
            $errors[] = self::error("upgrade.{$path}", 'unaccounted');
        }

        return $errors;
    }

    /**
     * A theme's values of the previous base in the next one (one step,
     * self's steps 1 to 6). $rewrite runs the block's `rewrite`, when it
     * declares one: `fn (string $id, array $values, array $previous, array
     * $next): array`, the values as they are after `drop` and both schemas
     * compiled; without it the rewrite is skipped, and what its paths hold
     * is read as any other value.
     *
     * @param array<string, mixed> $values what the theme stores
     * @param array<string, mixed> $next compiled, the first base's version to read it with
     * @param array<string, mixed> $previous compiled, the last version of the theme's base
     * @return array<string, mixed>
     */
    public static function apply(array $values, array $next, array $previous, ?callable $rewrite = null): array
    {
        return ThemeResolver::valid(self::step($values, $next, $previous, $rewrite, false), $next);
    }

    /**
     * A theme's values through every step: $schemas compiled, from the last
     * version of the theme's base to the version to read it with (the last
     * of each base on the way between them). With one schema, what of the
     * values fits it (ThemeResolver::valid()).
     *
     * @param array<string, mixed> $values
     * @param array<int, array<string, mixed>> $schemas
     * @return array<string, mixed>
     */
    public static function chain(array $values, array $schemas, ?callable $rewrite = null): array
    {
        $schemas = array_values($schemas);
        if (count($schemas) === 1) {
            return ThemeResolver::valid($values, $schemas[0]);
        }
        for ($index = 1; $index < count($schemas); $index++) {
            $values = self::apply($values, $schemas[$index], $schemas[$index - 1], $rewrite);
        }

        return $values;
    }

    /**
     * A site's settings (the `site` block's values) of the previous base in
     * the next one: the same step by their `site.` paths, ending in what of
     * them fits the next version (ThemeValidator::settings()). $rewrite
     * receives them as `['site' => $settings]`, and answers the same.
     *
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $next compiled
     * @param array<string, mixed> $previous compiled
     * @return array<string, mixed>
     */
    public static function settings(array $settings, array $next, array $previous, ?callable $rewrite = null): array
    {
        $document = self::step([ 'site' => $settings ], $next, $previous, $rewrite, true);

        return self::validSettings(is_array($document['site'] ?? null) ? $document['site'] : [], $next);
    }

    /**
     * A site's settings through every step, as self::chain() takes a theme.
     *
     * @param array<string, mixed> $settings
     * @param array<int, array<string, mixed>> $schemas
     * @return array<string, mixed>
     */
    public static function settingsChain(array $settings, array $schemas, ?callable $rewrite = null): array
    {
        $schemas = array_values($schemas);
        if (count($schemas) === 1) {
            return self::validSettings($settings, $schemas[0]);
        }
        for ($index = 1; $index < count($schemas); $index++) {
            $settings = self::settings($settings, $schemas[$index], $schemas[$index - 1], $rewrite);
        }

        return $settings;
    }

    /**
     * A theme's values of one version as an older one reads them, for a
     * client of that version: what has the same path and kind in both
     * survives, if it fits the older one (ThemeResolver::valid()); anything
     * else takes the older one's default.
     *
     * @param array<string, mixed> $values of $from
     * @param array<string, mixed> $from compiled, the version the values are of
     * @param array<string, mixed> $to compiled, the older version
     * @return array<string, mixed>
     */
    public static function cutDown(array $values, array $from, array $to): array
    {
        return ThemeResolver::valid(self::changed($values, $from, $to, []), $to);
    }

    /**
     * A site's settings of one version as an older one reads them, as
     * self::cutDown() a theme.
     *
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $from compiled
     * @param array<string, mixed> $to compiled
     * @return array<string, mixed>
     */
    public static function cutDownSettings(array $settings, array $from, array $to): array
    {
        $document = self::changed([ 'site' => $settings ], $from, $to, []);

        return self::validSettings(is_array($document['site'] ?? null) ? $document['site'] : [], $to);
    }

    /**
     * Steps 1 to 5 over a theme's values, or over `['site' => $settings]`.
     *
     * @param array<string, mixed> $document
     * @param array<string, mixed> $next
     * @param array<string, mixed> $previous
     * @return array<string, mixed>
     */
    private static function step(array $document, array $next, array $previous, ?callable $rewrite, bool $site): array
    {
        $block = self::isBlock($next['upgrade'] ?? null) ? $next['upgrade'] : [ 'moves' => [], 'drop' => [] ];
        $ours = fn (array $paths) => array_values(array_filter($paths, fn ($path) => is_string($path) && (explode('.', $path)[0] === 'site') === $site));
        $document = ThemeMigrations::apply($document, self::within($previous['migrate'] ?? [], $site));

        $moves = self::within($block['moves'], $site);
        $declared = is_array($block['rewrite'] ?? null) ? $block['rewrite'] : null;
        $rewritten = $declared !== null && $rewrite !== null && is_array($declared['paths'] ?? null) ? $ours($declared['paths']) : [];
        $document = self::changed($document, $previous, $next, [ ...array_column($moves, 'from'), ...$rewritten ]);
        $document = ThemeMigrations::apply($document, $moves);
        $document = ThemeMigrations::drop($document, $ours($block['drop']));
        if ($declared !== null && $rewrite !== null) {
            $document = $rewrite((string) ($declared['id'] ?? ''), $document, $previous, $next);
        }

        return $document;
    }

    /**
     * The moves or migrations of a theme ($site false) or of a site's
     * settings ($site true): those whose `from` is a string of its paths.
     *
     * @param mixed $moves
     * @return array<int, array<string, mixed>>
     */
    private static function within($moves, bool $site): array
    {
        return array_values(array_filter(is_array($moves) ? $moves : [], fn ($move) => is_array($move) && is_string($move['from'] ?? null)
            && (explode('.', $move['from'])[0] === 'site') === $site));
    }

    /**
     * $document without each value whose kind is another in $after than in
     * $before at the same path, unless $taken covers it (by its path in the
     * schema: a platform's override and a mode's color by their own).
     *
     * @param array<string, mixed> $document
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @param array<int, string> $taken
     * @return array<string, mixed>
     */
    private static function changed(array $document, array $before, array $after, array $taken): array
    {
        $paths = [];
        $walk = function (array $node, array $path) use (&$walk, &$paths, $before, $after, $taken) {
            foreach ($node as $key => $child) {
                $at = [ ...$path, (string) $key ];
                $was = self::storedKind($before, $at);
                $is = self::storedKind($after, $at);
                if ($was !== null || $is !== null) {
                    if ($was !== null && $is !== null && $was !== $is && !self::coveredBy(self::canonical($at), $taken)) {
                        $paths[] = $at;
                    }
                    continue;
                }
                if (is_array($child)) {
                    $walk($child, $at);
                }
            }
        };
        $walk($document, []);
        foreach ($paths as $path) {
            self::forget($document, $path);
        }

        return $document;
    }

    /**
     * The kind a schema gives the value a theme stores at $at (its
     * segments, a key of visibility or images whole): a token's, a mode's
     * color's, a visibility key's, an image's, a page's footer or sections,
     * a component property's as ThemeValidator reads it (a variant, state,
     * size or shape setting one of its base's, a group's scope one of the
     * scoped component's), a platform's override's, a site setting's; null
     * for what is no value of the schema.
     *
     * @param array<string, mixed> $schema
     * @param array<int, string> $at
     */
    private static function storedKind(array $schema, array $at): ?string
    {
        $count = count($at);

        return match ($at[0]) {
            'tokens' => $count === 3 ? ThemeSchemas::kindAt($schema, implode('.', $at)) : null,
            'modes' => $count === 4 && $at[2] === 'color' ? ThemeSchemas::kindAt($schema, "tokens.color.{$at[3]}") : null,
            'visibility', 'images' => $count === 2 ? ThemeSchemas::kindAt($schema, implode('.', $at)) : null,
            'site' => $count >= 2 ? ThemeSchemas::kindAt($schema, implode('.', $at)) : null,
            'pages' => $count === 3 ? ThemeSchemas::kindAt($schema, implode('.', $at)) : null,
            'components' => self::componentKind($schema, array_slice($at, 1)),
            'platforms' => match (true) {
                $count < 3 => null,
                $at[2] === 'components' => self::componentKind($schema, array_slice($at, 3)),
                in_array($at[2], [ 'visibility', 'pages' ], true) => self::storedKind($schema, array_slice($at, 2)),
                default => null,
            },
            default => null,
        };
    }

    /**
     * The kind of a component property a theme stores, by its path under
     * `components` (`card.variants.default.states.hover.shadow`).
     *
     * @param array<string, mixed> $schema
     * @param array<int, string> $rest
     */
    private static function componentKind(array $schema, array $rest): ?string
    {
        $spec = $schema['components'][$rest[0] ?? ''] ?? null;
        if (count($rest) < 3 || !is_array($spec)) {
            return null;
        }
        $section = $rest[1];
        if ($section === 'scope') {
            return count($rest) >= 4 ? self::componentKind($schema, array_slice($rest, 2)) : null;
        }
        if ($section === 'base') {
            return count($rest) === 3 ? self::kind($spec['base'][$rest[2]] ?? null) : null;
        }
        $own = $spec[$section][$rest[2]] ?? null;
        if (!in_array($section, self::SECTIONS, true) || !is_array($own)) {
            return null;
        }
        // A color may set any of its roles; any other entry, a property of its base.
        $property = fn (string $name) => self::kind($own[$name] ?? null)
            ?? ($section === 'colors' ? (in_array($name, ThemeFormat::ROLES, true) ? 'color' : null) : self::kind($spec['base'][$name] ?? null));
        if (count($rest) === 4) {
            return $rest[3] === 'states' ? null : $property($rest[3]);
        }

        return count($rest) === 6 && $rest[3] === 'states' ? self::kind($own['states'][$rest[4]][$rest[5]] ?? null) ?? $property($rest[5]) : null;
    }

    /**
     * A tuple's kind, or null for anything else.
     *
     * @param mixed $tuple
     */
    private static function kind($tuple): ?string
    {
        return is_array($tuple) && array_is_list($tuple) && is_string($tuple[0] ?? null) ? $tuple[0] : null;
    }

    /**
     * The path a schema's paths go by: a platform's override's without the
     * platform, a mode's color's as its token's.
     *
     * @param array<int, string> $at
     */
    private static function canonical(array $at): string
    {
        if ($at[0] === 'platforms' && count($at) >= 3) {
            $at = array_slice($at, 2);
        } else if ($at[0] === 'modes' && count($at) === 4 && $at[2] === 'color') {
            $at = [ 'tokens', 'color', $at[3] ];
        }

        return implode('.', $at);
    }

    /**
     * Whether $path is one of $prefixes or under one: a key of visibility
     * or images only is itself.
     *
     * @param array<int, string> $prefixes
     */
    private static function coveredBy(string $path, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if ($path === $prefix || (!in_array(explode('.', $prefix)[0], [ 'visibility', 'images' ], true) && str_starts_with($path, "{$prefix}."))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether $prefix is a group of $paths (one of them is under it).
     *
     * @param array<int, string|int> $paths
     */
    private static function holds(array $paths, string $prefix): bool
    {
        foreach ($paths as $path) {
            if ((string) $path !== $prefix && self::coveredBy((string) $path, [ $prefix ])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Where a version's migrations take a path, in order: a migration of a
     * group (a page) takes what is under it too.
     *
     * @param mixed $migrations
     */
    private static function follow($migrations, string $path): string
    {
        foreach (is_array($migrations) ? $migrations : [] as $migration) {
            $from = is_array($migration) ? ($migration['from'] ?? null) : null;
            $to = is_array($migration) ? ($migration['to'] ?? null) : null;
            if (is_string($from) && is_string($to) && self::coveredBy($path, [ $from ])) {
                $path = $to . substr($path, strlen($from));
            }
        }

        return $path;
    }

    /**
     * Whether a key of the previous base arrives at its own path (where the
     * next version's migrations take it) with the same kind: the one its
     * schema gives it, or for a node no theme stores by that path (a
     * platform's default, a scope's, a slot) the same type and scale.
     *
     * @param array{type: ?string, scale: mixed} $leaf
     * @param array<string, mixed> $before compiled
     * @param array<string, mixed> $after compiled
     * @param array<string, array{type: ?string, scale: mixed}> $arrived the next version's leaves
     */
    private static function arrives(string $path, array $leaf, array $before, array $after, array $arrived): bool
    {
        $target = self::follow($after['migrate'] ?? [], $path);
        $kind = ThemeSchemas::kindAt($before, $path);
        if ($kind !== null) {
            return $kind === ThemeSchemas::kindAt($after, $target);
        }

        return isset($arrived[$target]) && $arrived[$target]['type'] === $leaf['type'] && $arrived[$target]['scale'] === $leaf['scale'];
    }

    /**
     * Whether every value of a move's `map` is an option or a level of its
     * destination: an option of an option or layout property, or of a token
     * with options; an alias to a level a property takes (of its group, its
     * options included, and of the schema's own colors, styles and
     * opacities).
     *
     * @param mixed $map
     * @param array<string, mixed> $schema compiled
     */
    private static function validMap($map, array $schema, string $target): bool
    {
        if (!is_array($map)) {
            return false;
        }
        $segments = explode('.', $target);
        if ($segments[0] === 'tokens') {
            $colors = is_array($schema['tokens']['color'] ?? null) && $schema['tokens']['color'] !== [] ? array_map('strval', array_keys($schema['tokens']['color'])) : ThemeFormat::COLORS;
            $token = count($segments) === 3 ? NodeFormat::token($segments[1], $segments[2], $colors) : null;
            $options = $schema['token_options']["{$segments[1]}.{$segments[2]}"] ?? ($token[1] ?? null);

            return is_array($options) && array_filter($map, fn ($value) => !in_array($value, $options, true)) === [];
        }
        $spec = ThemeSchemas::specAt($schema, $target);
        if ($spec === null) {
            return false;
        }
        [ $kind, , $options, $rules ] = $spec + [ null, null, null, null ];
        foreach ($map as $value) {
            if (in_array($kind, ThemeFormat::OPTION_KINDS, true)) {
                if (!is_array($options) || !in_array($value, $options, true)) {
                    return false;
                }
                continue;
            }
            $alias = ThemeFormat::alias($value);
            if ((ThemeFormat::PROPERTY_KINDS[$kind] ?? null) === null || $alias === null
                || ThemeValidator::value($kind, $value, is_array($options) ? $options : null, is_array($rules) ? $rules : null) !== null
                || (in_array($alias[0], ThemeFormat::OWN_GROUPS, true) && !array_key_exists($alias[1], $schema['tokens'][$alias[0]] ?? []))) {
                return false;
            }
        }

        return true;
    }

    /**
     * What of a site's settings fits a schema: its migrations of `site.`
     * applied, then each setting ThemeValidator::settings() finds wrong left
     * out.
     *
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $schema compiled
     * @return array<string, mixed>
     */
    private static function validSettings(array $settings, array $schema): array
    {
        $document = ThemeMigrations::apply([ 'site' => $settings ], self::within($schema['migrate'] ?? [], true));
        $settings = is_array($document['site'] ?? null) ? $document['site'] : [];
        foreach (ThemeValidator::settings($settings, $schema) as $error) {
            if ($error['path'] === 'site') {
                return [];
            }
            $segments = array_slice(explode('.', $error['path']), 1);
            $last = array_pop($segments);
            $node = &$settings;
            foreach ($segments as $segment) {
                if (!is_array($node[$segment] ?? null)) {
                    continue 2;
                }
                $node = &$node[$segment];
            }
            unset($node[$last]);
            unset($node);
        }

        return $settings;
    }

    /**
     * Drops the value, and each map it leaves empty.
     *
     * @param array<string, mixed> $tree
     * @param array<int, string> $segments
     */
    private static function forget(array &$tree, array $segments): void
    {
        $key = array_shift($segments);
        if ($segments === []) {
            unset($tree[$key]);

            return;
        }
        if (!is_array($tree[$key] ?? null)) {
            return;
        }
        self::forget($tree[$key], $segments);
        if ($tree[$key] === []) {
            unset($tree[$key]);
        }
    }

    /**
     * @return array{path: string, code: string}
     */
    private static function error(string $path, string $rule): array
    {
        return [ 'path' => $path, 'code' => "{$path}.{$rule}" ];
    }
}
