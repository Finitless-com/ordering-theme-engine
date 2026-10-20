<?php

namespace Ordering\ThemeEngine;

/**
 * The pages of a schema (`pages`): whether each one shows the footer and
 * which of its sections it paints, in what order.
 *
 * A page of the schema: `{ footer: ["bool", default], sections:
 * ["sections", [{ key, visible, hideable, group, v1?, v1_default? }, …]] }`.
 * - `footer` null follows `visibility.footer`;
 * - the list's order is the default order;
 * - a section moves only among those of its `group`; with null it stays
 *   in its place;
 * - `hideable` false: always visible (the page does not work without it);
 * - `v1`: the version 1 path, ending in `.hidden`, of a section version 1
 *   could hide already;
 * - `v1_default`: whether a version 1 theme without that path shows the
 *   section (shown when absent). Only the web reads it: a conversion
 *   carries only the sections whose path the version 1 theme sets.
 *
 * A page of a theme (`pages.<page>`, and `platforms.<platform>.pages.<page>`
 * over it field by field): `{ footer: bool|null, sections: [{ key, visible },
 * …] }`. Resolved, its sections are self::order()ed.
 */
final class ThemePages
{
    private const NAME = '/^[a-z0-9_]+$/';

    private const V1_PATH = '/^[a-z0-9_]+(\.[a-z0-9_]+)*\.hidden$/';

    /**
     * What a schema's pages get wrong. A section's `v1` must not be another
     * control's: whichever is projected last would win.
     *
     * @param array<string, mixed> $schema
     * @return array<int, array{path: string, code: string}>
     */
    public static function check(array $schema): array
    {
        $errors = [];
        $fail = function (string $path, string $rule) use (&$errors) {
            $errors[] = [ 'path' => $path, 'code' => "{$path}.{$rule}" ];
        };

        $pages = $schema['pages'] ?? [];
        if (!is_array($pages) || ($pages !== [] && array_is_list($pages))) {
            $fail('pages', 'invalid');

            return $errors;
        }

        $v1_paths = [];
        foreach ($schema['visibility'] ?? [] as $entry) {
            if (is_string($entry[1] ?? null)) {
                $v1_paths[$entry[1]] = true;
            }
        }

        foreach ($pages as $page => $spec) {
            $path = "pages.{$page}";
            if (!preg_match(self::NAME, (string) $page) || !is_array($spec) || ($spec !== [] && array_is_list($spec))) {
                $fail($path, 'invalid');
                continue;
            }
            foreach (array_diff(array_keys($spec), [ 'footer', 'sections' ]) as $field) {
                $fail("{$path}.{$field}", 'unknown');
            }

            $footer = $spec['footer'] ?? null;
            if (!is_array($footer) || !array_is_list($footer) || count($footer) !== 2 || $footer[0] !== 'bool'
                || !($footer[1] === null || is_bool($footer[1]))) {
                $fail("{$path}.footer", 'invalid');
            }

            $sections = $spec['sections'] ?? null;
            if (!is_array($sections) || !array_is_list($sections) || count($sections) !== 2 || $sections[0] !== 'sections'
                || !is_array($sections[1]) || !array_is_list($sections[1])) {
                $fail("{$path}.sections", 'invalid');
                continue;
            }
            $keys = [];
            foreach ($sections[1] as $index => $section) {
                $key = is_array($section) && is_string($section['key'] ?? null) ? $section['key'] : (string) $index;
                $section_path = "{$path}.sections.{$key}";
                if (!is_array($section) || !preg_match(self::NAME, $key)
                    || array_diff(array_keys($section), [ 'key', 'visible', 'hideable', 'group', 'v1', 'v1_default' ]) !== []
                    || (array_key_exists('v1_default', $section) && !is_bool($section['v1_default']))
                    || !is_bool($section['visible'] ?? null) || !is_bool($section['hideable'] ?? null)
                    || !array_key_exists('group', $section) || !($section['group'] === null || (is_string($section['group']) && preg_match(self::NAME, $section['group'])))
                    || (!$section['hideable'] && !$section['visible'])) {
                    $fail($section_path, 'invalid');
                    continue;
                }
                if (isset($keys[$key])) {
                    $fail($section_path, 'duplicate');
                }
                $keys[$key] = true;

                if (array_key_exists('v1', $section)) {
                    if (!is_string($section['v1']) || !preg_match(self::V1_PATH, $section['v1'])) {
                        $fail("{$section_path}.v1", 'invalid');
                    } else if (isset($v1_paths[$section['v1']])) {
                        $fail("{$section_path}.v1", 'duplicate');
                    } else {
                        $v1_paths[$section['v1']] = true;
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * What is wrong with a theme's pages (`pages`, or a platform's), each
     * problem at its path: an unknown page or field, a footer that is not
     * a bool or null, sections that are not `[{ key, visible }]`, a section
     * the page does not have or repeated, or one hidden that cannot be.
     *
     * @param array<string, mixed> $pages
     * @param array<string, mixed> $schema
     * @return array<int, array{path: string, code: string}>
     */
    public static function validate(array $pages, array $schema, string $path): array
    {
        $errors = [];
        $fail = function (string $at, string $rule) use (&$errors) {
            $errors[] = [ 'path' => $at, 'code' => "{$at}.{$rule}" ];
        };

        foreach ($pages as $page => $value) {
            $page_path = "{$path}.{$page}";
            $spec = $schema['pages'][$page] ?? null;
            if (!is_array($spec) || !is_array($value) || ($value !== [] && array_is_list($value))) {
                $fail($page_path, 'unknown');
                continue;
            }

            foreach ($value as $field => $item) {
                if ($field === 'footer') {
                    if (!($item === null || is_bool($item))) {
                        $fail("{$page_path}.footer", 'invalid');
                    }
                } else if ($field === 'sections') {
                    if (!is_array($item) || !array_is_list($item)) {
                        $fail("{$page_path}.sections", 'invalid');
                        continue;
                    }
                    $defaults = self::byKey(self::defaultSections($spec));
                    $seen = [];
                    foreach ($item as $index => $section) {
                        $section_path = "{$page_path}.sections.{$index}";
                        if (!is_array($section) || count($section) !== 2 || !is_string($section['key'] ?? null) || !is_bool($section['visible'] ?? null)) {
                            $fail($section_path, 'invalid');
                        } else if (!isset($defaults[$section['key']])) {
                            $fail($section_path, 'unknown');
                        } else if (isset($seen[$section['key']])) {
                            $fail($section_path, 'duplicate');
                        } else if (!$section['visible'] && !self::hideable($defaults[$section['key']])) {
                            $fail($section_path, 'invalid');
                        }
                        if (is_array($section) && is_string($section['key'] ?? null)) {
                            $seen[$section['key']] = true;
                        }
                    }
                } else {
                    $fail("{$page_path}.{$field}", 'unknown');
                }
            }
        }

        return $errors;
    }

    /**
     * Every page with its defaults.
     *
     * @param array<string, mixed> $schema
     * @return array<string, array{footer: bool|null, sections: list<array{key: string, visible: bool}>}>
     */
    public static function defaults(array $schema): array
    {
        $pages = [];
        foreach (is_array($schema['pages'] ?? null) ? $schema['pages'] : [] as $page => $spec) {
            $pages[$page] = [
                'footer' => $spec['footer'][1] ?? null,
                'sections' => self::order(self::defaultSections($spec), []),
            ];
        }

        return $pages;
    }

    /**
     * Each page's sections in $pages, complete and in the order to paint
     * them (self::order()); the rest of each page as it is.
     *
     * @param array<string, mixed> $pages
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    public static function resolve(array $pages, array $schema): array
    {
        foreach ($pages as $page => $value) {
            $spec = $schema['pages'][$page] ?? null;
            if (is_array($spec) && is_array($value) && array_key_exists('sections', $value)) {
                $pages[$page]['sections'] = self::order(self::defaultSections($spec), is_array($value['sections']) ? $value['sections'] : []);
            }
        }

        return $pages;
    }

    /**
     * A theme's sections as the page paints them: each group fills the
     * places its sections have in the default list, in the theme's order,
     * and a section without a group keeps its place. An unknown or repeated
     * key is dropped (the first counts), and a missing one keeps its
     * default place in its group and its default visibility. A section that
     * cannot be hidden is always visible.
     *
     * @param list<array<string, mixed>> $defaults the schema's sections
     * @param array<int, mixed> $themed the theme's
     * @return list<array{key: string, visible: bool}>
     */
    public static function order(array $defaults, array $themed): array
    {
        $by_key = self::byKey($defaults);
        $order = [];
        $visible = [];
        foreach ($themed as $item) {
            $key = is_array($item) ? ($item['key'] ?? null) : null;
            if (!is_string($key) || !isset($by_key[$key]) || array_key_exists($key, $visible)) {
                continue;
            }
            $order[] = $key;
            $visible[$key] = is_bool($item['visible'] ?? null) ? $item['visible'] : null;
        }

        $queues = [];
        foreach ($defaults as $section) {
            $group = $section['group'] ?? null;
            if ($group === null || isset($queues[$group])) {
                continue;
            }
            $in_group = array_column(array_filter($defaults, fn ($other) => ($other['group'] ?? null) === $group), 'key');
            $ordered = array_values(array_filter($order, fn ($key) => ($by_key[$key]['group'] ?? null) === $group));
            foreach ($in_group as $index => $key) {
                if (!in_array($key, $ordered, true)) {
                    array_splice($ordered, $index, 0, [ $key ]);
                }
            }
            $queues[$group] = $ordered;
        }

        $sections = [];
        foreach ($defaults as $section) {
            $section = ($section['group'] ?? null) === null ? $section : $by_key[array_shift($queues[$section['group']])];
            $sections[] = [
                'key' => $section['key'],
                'visible' => !self::hideable($section) || ($visible[$section['key']] ?? $section['visible'] !== false),
            ];
        }

        return $sections;
    }

    /**
     * A page's sections in the schema, in their default order.
     *
     * @param array<string, mixed> $spec
     * @return list<array<string, mixed>>
     */
    public static function defaultSections(array $spec): array
    {
        $sections = $spec['sections'][1] ?? [];

        return is_array($sections) ? array_values(array_filter($sections, fn ($section) => is_array($section) && is_string($section['key'] ?? null))) : [];
    }

    /**
     * @param array<string, mixed> $section
     */
    private static function hideable(array $section): bool
    {
        return ($section['hideable'] ?? true) !== false;
    }

    /**
     * @param list<array<string, mixed>> $sections
     * @return array<string, array<string, mixed>>
     */
    private static function byKey(array $sections): array
    {
        return array_column($sections, null, 'key');
    }
}
