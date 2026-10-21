<?php

namespace Ordering\ThemeEngine;

/**
 * The renames and moves a schema declares (`$extensions["co.ordering.migrate"]`,
 * `[{ from, to }]`, api-core's docs/THEMES_V2_PLAN.md §6.4.2), so that a theme saved
 * before a key changed place keeps its value. They apply in order whenever
 * a theme is read or saved: a value at `from` goes to `to` unless the theme
 * already sets `to`, and `from` is dropped either way. Each also applies
 * under every platform the theme overrides (`platforms.<platform>.…`),
 * which sets its components, visibility and pages by the same paths. A
 * token's also rewrites every alias to it (`{color.brand}` becomes
 * `{color.primary}`), and a color's moves its value in each mode
 * (`modes.<mode>.color.…`) too.
 *
 * Paths are a theme's: the keys of `visibility` and `images` hold dots of
 * their own (`visibility.business.header.info`), any other path is a key
 * per segment. A page's section (`pages.<page>.sections.<key>`) is an item
 * of its list: it moves with its `visible`, renamed in its place within
 * the page, or to the end of another page's list.
 *
 * A move may also carry a `map` (a base's `co.ordering.upgrade`,
 * ThemeUpgrades): the value it moves is translated by it, and one it has no
 * entry for is dropped; with a map, `from` and `to` may be the same path.
 */
final class ThemeMigrations
{
    /** The sections whose paths a migration must also give the right shape. */
    private const SHAPED = [ 'visibility', 'images', 'pages' ];

    /**
     * @param array<string, mixed> $values
     * @param array<int, array{from: string, to: string, map?: array<string|int, mixed>}> $migrations
     * @return array<string, mixed>
     */
    public static function apply(array $values, array $migrations): array
    {
        foreach ($migrations as $migration) {
            $from = self::segments((string) ($migration['from'] ?? ''));
            $to = self::segments((string) ($migration['to'] ?? ''));
            $map = is_array($migration['map'] ?? null) ? $migration['map'] : null;
            if (count($from) < 2 || count($to) < 2 || ($from === $to && $map === null)) {
                continue;
            }
            foreach (self::roots($values) as $root) {
                $values = self::move($values, [ ...$root, ...$from ], [ ...$root, ...$to ], count($root), $map);
            }
            if ($from[0] === 'tokens' && $to[0] === 'tokens' && count($from) === 3 && count($to) === 3) {
                $values = self::moveToken($values, $from, $to, $map);
            }
        }

        return $values;
    }

    /**
     * $values without what is at each path, wherever a migration of it
     * would find it: under every platform the theme overrides too, a
     * color's value in each mode, and a page's section
     * (`pages.<page>.sections.<key>`) out of its list. A path that names a
     * group drops it whole; a map left empty goes too.
     *
     * @param array<string, mixed> $values
     * @param array<int, string> $paths
     * @return array<string, mixed>
     */
    public static function drop(array $values, array $paths): array
    {
        foreach ($paths as $path) {
            $segments = self::segments((string) $path);
            if (count($segments) < 2) {
                continue;
            }
            foreach (self::roots($values) as $root) {
                $values = self::remove($values, [ ...$root, ...$segments ], count($root));
            }
            if ($segments[0] === 'tokens' && $segments[1] === 'color' && count($segments) === 3) {
                foreach (is_array($values['modes'] ?? null) ? array_keys($values['modes']) : [] as $mode) {
                    $values = self::remove($values, [ 'modes', (string) $mode, 'color', $segments[2] ], 0);
                }
            }
        }

        return $values;
    }

    /**
     * Where a theme's paths start: its root, and each platform it overrides.
     *
     * @param array<string, mixed> $values
     * @return array<int, array<int, string>>
     */
    private static function roots(array $values): array
    {
        $roots = [ [] ];
        foreach (is_array($values['platforms'] ?? null) ? array_keys($values['platforms']) : [] as $platform) {
            $roots[] = [ 'platforms', (string) $platform ];
        }

        return $roots;
    }

    /**
     * $values without what is at $path, under a root of $depth segments: a
     * section, every item of its key out of its page's list (the list goes
     * when it is left empty).
     *
     * @param array<string, mixed> $values
     * @param array<int, string> $path
     * @return array<string, mixed>
     */
    private static function remove(array $values, array $path, int $depth): array
    {
        if (count($path) === $depth + 4 && $path[$depth] === 'pages' && $path[$depth + 2] === 'sections') {
            $key = array_pop($path);
            $list = self::has($values, $path) ? self::get($values, $path) : null;
            if (!is_array($list) || !array_is_list($list) || self::find($list, $key) === null) {
                return $values;
            }
            $kept = array_values(array_filter($list, fn ($item) => !is_array($item) || ($item['key'] ?? null) !== $key));
            if ($kept === []) {
                self::forget($values, $path);
            } else {
                self::set($values, $path, $kept);
            }

            return $values;
        }
        if (self::has($values, $path)) {
            self::forget($values, $path);
        }

        return $values;
    }

    /**
     * What a theme holds of a token besides its value, once it moved: its
     * value in each mode (a color's, `modes.<mode>.color.<name>`), moved
     * the same way, and every alias to it, which names the new token.
     *
     * @param array<string, mixed> $values
     * @param array{0: string, 1: string, 2: string} $from `tokens, <group>, <name>`
     * @param array{0: string, 1: string, 2: string} $to
     * @param array<string|int, mixed>|null $map
     * @return array<string, mixed>
     */
    private static function moveToken(array $values, array $from, array $to, ?array $map): array
    {
        if ($from[1] === 'color' && $to[1] === 'color') {
            foreach (is_array($values['modes'] ?? null) ? array_keys($values['modes']) : [] as $mode) {
                $values = self::move($values, [ 'modes', (string) $mode, 'color', $from[2] ], [ 'modes', (string) $mode, 'color', $to[2] ], 0, $map);
            }
        }

        return $from === $to ? $values : self::realias($values, [ $from[1], $from[2] ], "{{$to[1]}.{$to[2]}}");
    }

    /**
     * $node with every alias to the token $old (`[group, name]`) as $alias.
     *
     * @param array<string|int, mixed> $node
     * @param array{0: string, 1: string} $old
     * @return array<string|int, mixed>
     */
    private static function realias(array $node, array $old, string $alias): array
    {
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $node[$key] = self::realias($value, $old, $alias);
            } else if (ThemeFormat::alias($value) === $old) {
                $node[$key] = $alias;
            }
        }

        return $node;
    }

    /**
     * What a schema's migrations get wrong: each needs a `from` the schema
     * no longer has and a `to` it has, of the same section. In visibility,
     * images and pages `from` must also have the shape of a path of the
     * same kind as `to` (ThemeSchemas::kindAt()): a page's footer does not
     * become one of its sections. A `from` of tokens or components the
     * schema still has as a group (`components.button.base`) is no key it
     * no longer has: moving it would move a group a theme still sets.
     *
     * @param array<string, mixed> $schema in tuples
     * @return array<int, array{path: string, code: string}>
     */
    public static function check(array $schema): array
    {
        $errors = [];
        $migrations = $schema['migrate'] ?? [];
        if (!is_array($migrations) || !array_is_list($migrations)) {
            return [ [ 'path' => 'migrate', 'code' => 'migrate.invalid' ] ];
        }
        foreach ($migrations as $index => $migration) {
            $from = is_array($migration) ? ($migration['from'] ?? null) : null;
            $to = is_array($migration) ? ($migration['to'] ?? null) : null;
            if (!is_string($from) || !is_string($to) || $from === $to
                || explode('.', $from)[0] !== explode('.', $to)[0]
                || ThemeSchemas::kindAt($schema, $from) !== null || ThemeSchemas::kindAt($schema, $to) === null || self::isGroup($schema, $from)
                || (in_array(explode('.', $from)[0], self::SHAPED, true) && self::shapeKind($from) !== ThemeSchemas::kindAt($schema, $to))) {
                $errors[] = [ 'path' => "migrate.{$index}", 'code' => "migrate.{$index}.invalid" ];
            }
        }

        return $errors;
    }

    /**
     * Whether a path of tokens or components names what the schema still
     * has, though no key (a group of tokens, a component's base, a variant).
     *
     * @param array<string, mixed> $schema in tuples
     */
    private static function isGroup(array $schema, string $path): bool
    {
        $segments = explode('.', $path);

        return in_array($segments[0], [ 'tokens', 'components' ], true) && self::has($schema, $segments);
    }

    /**
     * The kind ThemeSchemas::kindAt() would give a path of visibility,
     * images or pages by its shape alone, whether the schema has it or not;
     * null when no theme stores such a path.
     */
    private static function shapeKind(string $path): ?string
    {
        $segments = explode('.', $path);
        $name = fn (?string $segment) => $segment !== null && preg_match('/^[a-z0-9_]+$/', $segment) === 1;
        if (in_array($segments[0], [ 'visibility', 'images' ], true)) {
            $key = implode('.', array_slice($segments, 1));

            return count($segments) > 1 && preg_match(ThemeSchemas::KEY, $key) === 1 ? ($segments[0] === 'visibility' ? 'bool' : 'image') : null;
        }
        if ($segments[0] !== 'pages' || !$name($segments[1] ?? null)) {
            return null;
        }

        return match (true) {
            count($segments) === 2 => 'page',
            count($segments) === 3 && $segments[2] === 'footer' => 'bool',
            count($segments) === 3 && $segments[2] === 'sections' => 'sections',
            count($segments) === 4 && $segments[2] === 'sections' && $name($segments[3]) => 'section',
            default => null,
        };
    }

    /**
     * $values with what is at $from moved to $to, both under the same root
     * of $depth segments (none, or a platform's), translated by $map when
     * there is one. A section moves as it is.
     *
     * @param array<string, mixed> $values
     * @param array<int, string> $from
     * @param array<int, string> $to
     * @param array<string|int, mixed>|null $map
     * @return array<string, mixed>
     */
    private static function move(array $values, array $from, array $to, int $depth, ?array $map = null): array
    {
        $section = fn (array $segments) => count($segments) === $depth + 4 && $segments[$depth] === 'pages' && $segments[$depth + 2] === 'sections';
        if ($section($from) || $section($to)) {
            return $section($from) && $section($to) ? self::moveSection($values, $from, $to) : $values;
        }
        if (!self::has($values, $from)) {
            return $values;
        }
        $value = self::get($values, $from);
        self::forget($values, $from);
        if ($map !== null) {
            // A string or an integer the map translates; anything else it drops.
            if (!(is_string($value) || is_int($value)) || !array_key_exists($value, $map)) {
                return $values;
            }
            $value = $map[$value];
        }
        if (!self::has($values, $to)) {
            self::set($values, $to, $value);
        }

        return $values;
    }

    /**
     * A section from one page's list to another's (or the same one's): the
     * first item of the key goes, renamed, to the place it had when the
     * list is the same and to the end of the other one otherwise, unless
     * that list has the new key already. Every item of the old key is
     * dropped, and a list left empty with it.
     *
     * @param array<string, mixed> $values
     * @param array<int, string> $from `…, pages, <page>, sections, <key>`
     * @param array<int, string> $to
     * @return array<string, mixed>
     */
    private static function moveSection(array $values, array $from, array $to): array
    {
        $from_key = array_pop($from);
        $to_key = array_pop($to);
        $list = self::has($values, $from) ? self::get($values, $from) : null;
        $index = is_array($list) && array_is_list($list) ? self::find($list, $from_key) : null;
        if ($index === null || ($from === $to && $from_key === $to_key)) {
            return $values;
        }
        $moved = [ 'key' => $to_key ] + $list[$index];
        $same = $from === $to;
        $target = $same ? $list : (self::has($values, $to) ? self::get($values, $to) : []);
        $taken = !is_array($target) || !array_is_list($target) || self::find($target, $to_key) !== null;

        $kept = [];
        foreach ($list as $position => $item) {
            if (!is_array($item) || ($item['key'] ?? null) !== $from_key) {
                $kept[] = $item;
            } else if ($same && $position === $index && !$taken) {
                $kept[] = $moved;
            }
        }
        if ($kept === []) {
            self::forget($values, $from);
        } else {
            self::set($values, $from, $kept);
        }
        if (!$same && !$taken) {
            $target[] = $moved;
            self::set($values, $to, $target);
        }

        return $values;
    }

    /**
     * The index of the first item of a section list with that key.
     *
     * @param array<int, mixed> $list
     */
    private static function find(array $list, string $key): ?int
    {
        foreach ($list as $index => $item) {
            if (is_array($item) && ($item['key'] ?? null) === $key) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private static function segments(string $path): array
    {
        $segments = explode('.', $path);

        return in_array($segments[0], [ 'visibility', 'images' ], true) && count($segments) > 1
            ? [ $segments[0], implode('.', array_slice($segments, 1)) ]
            : $segments;
    }

    /**
     * @param array<string, mixed> $tree
     * @param array<int, string> $segments
     */
    private static function has(array $tree, array $segments): bool
    {
        foreach ($segments as $segment) {
            if (!is_array($tree) || !array_key_exists($segment, $tree)) {
                return false;
            }
            $tree = $tree[$segment];
        }

        return true;
    }

    /**
     * @param array<string, mixed> $tree
     * @param array<int, string> $segments
     * @return mixed
     */
    private static function get(array $tree, array $segments)
    {
        foreach ($segments as $segment) {
            $tree = $tree[$segment];
        }

        return $tree;
    }

    /**
     * @param array<string, mixed> $tree
     * @param array<int, string> $segments
     */
    private static function set(array &$tree, array $segments, $value): void
    {
        $node = &$tree;
        foreach ($segments as $segment) {
            if (!is_array($node)) {
                return;
            }
            if (!array_key_exists($segment, $node)) {
                $node[$segment] = [];
            }
            $node = &$node[$segment];
        }
        $node = $value;
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
        self::forget($tree[$key], $segments);
        if ($tree[$key] === []) {
            unset($tree[$key]);
        }
    }
}
