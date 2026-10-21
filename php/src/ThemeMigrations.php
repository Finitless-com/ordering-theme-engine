<?php

namespace Ordering\ThemeEngine;

/**
 * The renames and moves a schema declares (`$extensions["co.ordering.migrate"]`,
 * `[{ from, to }]`, api-core's docs/THEMES_V2_PLAN.md §6.4.2), so that a theme saved
 * before a key changed place keeps its value. They apply in order whenever
 * a theme is read or saved: a value at `from` goes to `to` unless the theme
 * already sets `to`, and `from` is dropped either way. Each also applies
 * under every platform the theme overrides (`platforms.<platform>.…`),
 * which sets its components, visibility and pages by the same paths.
 *
 * Paths are a theme's: the keys of `visibility` and `images` hold dots of
 * their own (`visibility.business.header.info`), any other path is a key
 * per segment. A page's section (`pages.<page>.sections.<key>`) is an item
 * of its list: it moves with its `visible`, renamed in its place within
 * the page, or to the end of another page's list.
 */
final class ThemeMigrations
{
    /** The sections whose paths a migration must also give the right shape. */
    private const SHAPED = [ 'visibility', 'images', 'pages' ];

    /**
     * @param array<string, mixed> $values
     * @param array<int, array{from: string, to: string}> $migrations
     * @return array<string, mixed>
     */
    public static function apply(array $values, array $migrations): array
    {
        foreach ($migrations as $migration) {
            $from = self::segments((string) ($migration['from'] ?? ''));
            $to = self::segments((string) ($migration['to'] ?? ''));
            if (count($from) < 2 || count($to) < 2) {
                continue;
            }
            $roots = [ [] ];
            foreach (is_array($values['platforms'] ?? null) ? array_keys($values['platforms']) : [] as $platform) {
                $roots[] = [ 'platforms', (string) $platform ];
            }
            foreach ($roots as $root) {
                $values = self::move($values, [ ...$root, ...$from ], [ ...$root, ...$to ], count($root));
            }
        }

        return $values;
    }

    /**
     * What a schema's migrations get wrong: each needs a `from` the schema
     * no longer has and a `to` it has, of the same section. In visibility,
     * images and pages `from` must also have the shape of a path of the
     * same kind as `to` (ThemeSchemas::kindAt()): a page's footer does not
     * become one of its sections.
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
                || ThemeSchemas::kindAt($schema, $from) !== null || ThemeSchemas::kindAt($schema, $to) === null
                || (in_array(explode('.', $from)[0], self::SHAPED, true) && self::shapeKind($from) !== ThemeSchemas::kindAt($schema, $to))) {
                $errors[] = [ 'path' => "migrate.{$index}", 'code' => "migrate.{$index}.invalid" ];
            }
        }

        return $errors;
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
     * of $depth segments (none, or a platform's).
     *
     * @param array<string, mixed> $values
     * @param array<int, string> $from
     * @param array<int, string> $to
     * @return array<string, mixed>
     */
    private static function move(array $values, array $from, array $to, int $depth): array
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
