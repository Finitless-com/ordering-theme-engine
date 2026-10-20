<?php

namespace Ordering\ThemeEngine;

/**
 * The renames and moves a schema declares (`$extensions["co.ordering.migrate"]`,
 * `[{ from, to }]`, api-core's docs/THEMES_V2_PLAN.md §6.4.2), so that a theme saved
 * before a key changed place keeps its value. They apply in order whenever
 * a theme is read or saved: a value at `from` goes to `to` unless the theme
 * already sets `to`, and `from` is dropped either way.
 *
 * Paths are a theme's: the keys of `visibility` and `images` hold dots of
 * their own (`visibility.business.header.info`), any other path is a key
 * per segment.
 */
final class ThemeMigrations
{
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
            if (count($from) < 2 || count($to) < 2 || !self::has($values, $from)) {
                continue;
            }
            $value = self::get($values, $from);
            self::forget($values, $from);
            if (!self::has($values, $to)) {
                self::set($values, $to, $value);
            }
        }

        return $values;
    }

    /**
     * What a schema's migrations get wrong: each needs a `from` the schema
     * no longer has and a `to` it has, of the same section.
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
                || ThemeSchemas::kindAt($schema, $from) !== null || ThemeSchemas::kindAt($schema, $to) === null) {
                $errors[] = [ 'path' => "migrate.{$index}", 'code' => "migrate.{$index}.invalid" ];
            }
        }

        return $errors;
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
