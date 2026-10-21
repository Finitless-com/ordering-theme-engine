<?php

namespace Ordering\ThemeEngine\Schema;

/**
 * What a new version of a theme schema changes from the last of its base,
 * and whether it may (docs/schema-format.md §13). Both are in nodes.
 *
 * - A patch changes no key, type, option, default or rule: only texts and
 *   the editor metadata that validates no value. What of the editor's
 *   metadata decides how a value is validated (a color's `alpha`, a
 *   typography's `custom`, the numeric `min` and `max` of a control and of
 *   its `fields`) is a rule like any other.
 * - A minor may add keys, options and sections and change defaults and the
 *   rules a product tunes (a string's `max_length` and `pattern`, the
 *   limits, a section's `hideable` and `group`). A key it drops (a page's
 *   section is one) must have been `$deprecated` in the last version, or
 *   moved by a migration (of the key, or of the page or the list that
 *   holds it; a platform's default goes with its property); a key keeps
 *   its type, its scale, every option it had and the `alpha` and `custom`
 *   it allowed; the migrations of the base are never dropped; and its
 *   upgrade block only grows: it keeps its previous base, its rewrite,
 *   each move and each path it drops.
 * - A new base is not compared.
 */
final class SchemaDiff
{
    private const BLOCKS = [ 'tokens', 'components', 'visibility', 'images', 'pages', 'site' ];

    /**
     * @param array<string, mixed> $old
     * @param array<string, mixed> $new
     * @return array{errors: array<int, array{path: string, code: string}>, changes: array<string, array<int, string>>}
     */
    public static function compare(array $old, array $new): array
    {
        $errors = [];
        $before = self::leaves($old);
        $after = self::leaves($new);
        $migrations = self::migrations($new);
        $moved = array_column($migrations, 'to', 'from');

        $changes = [
            'added' => array_values(array_map('strval', array_diff(array_keys($after), array_keys($before)))),
            'removed' => [],
            'migrated' => [],
            'deprecated' => array_values(array_map('strval', array_keys(array_filter($after, fn ($leaf, $path) => $leaf['deprecated'] && !($before[$path]['deprecated'] ?? false), ARRAY_FILTER_USE_BOTH)))),
            'options_added' => [],
            'defaults_changed' => [],
            'rules_changed' => [],
            'sections_changed' => [],
        ];

        [ $old_base, $old_minor ] = self::parts((string) ($old['version'] ?? ''));
        [ $new_base, $new_minor ] = self::parts((string) ($new['version'] ?? ''));
        if ($old_base !== $new_base) {
            return [ 'errors' => [], 'changes' => $changes ];
        }

        foreach ($before as $path => $leaf) {
            $path = (string) $path;
            if (!isset($after[$path])) {
                $property = self::platformBase($path);
                if (self::isMoved($path, $moved)) {
                    $changes['migrated'][] = $path;
                } else {
                    $changes['removed'][] = $path;
                    // A platform's default goes with its property's `$deprecated`.
                    if (!$leaf['deprecated'] && !($property !== null && ($before[$property]['deprecated'] ?? false))) {
                        $errors[] = [ 'path' => $path, 'code' => "{$path}.removed" ];
                    }
                }
                continue;
            }
            $now = $after[$path];
            if ($leaf['type'] !== $now['type']) {
                $errors[] = [ 'path' => $path, 'code' => "{$path}.type_changed" ];
            }
            // A stored value names a level of its scale: another scale reads it elsewhere.
            if ($leaf['scale'] !== $now['scale']) {
                $errors[] = [ 'path' => $path, 'code' => "{$path}.scale_changed" ];
            }
            if (array_diff($leaf['options'], $now['options']) !== []) {
                $errors[] = [ 'path' => $path, 'code' => "{$path}.option_removed" ];
            }
            // A translucent color, or a typography's own style, the key took and no longer takes.
            foreach ([ 'alpha', 'custom' ] as $rule) {
                if (($leaf['rules'][$rule] ?? false) === true && ($now['rules'][$rule] ?? false) !== true) {
                    $errors[] = [ 'path' => $path, 'code' => "{$path}.{$rule}_removed" ];
                }
            }
            if (array_diff($now['options'], $leaf['options']) !== []) {
                $changes['options_added'][] = $path;
            }
            if (self::canonical($leaf['default']) !== self::canonical($now['default'])) {
                $changes['defaults_changed'][] = $path;
            }
            if (self::canonical($leaf['rules']) !== self::canonical($now['rules'])) {
                $changes['rules_changed'][] = $path;
            }
            if (isset($leaf['section'], $now['section'])
                && ($leaf['section']['hideable'] !== $now['section']['hideable'] || $leaf['section']['group'] !== $now['section']['group'])) {
                $changes['sections_changed'][] = $path;
            }
        }
        // A page whose sections, those both versions have, come in another order.
        $orders = self::sectionOrders($after);
        foreach (self::sectionOrders($before) as $list => $order) {
            $now = array_values(array_intersect($orders[$list] ?? [], $order));
            if ($now !== array_values(array_intersect($order, $now))) {
                $changes['sections_changed'][] = $list;
            }
        }

        foreach (self::migrations($old) as $migration) {
            if (!in_array($migration, $migrations, true)) {
                $errors[] = [ 'path' => 'migrate', 'code' => 'schema.migration_removed' ];
                break;
            }
        }

        if (self::upgradeRemoved($old, $new)) {
            $errors[] = [ 'path' => 'upgrade', 'code' => 'schema.upgrade_removed' ];
        }

        if ($old_minor === $new_minor && self::values($old) !== self::values($new)) {
            $errors[] = [ 'path' => 'version', 'code' => 'schema.patch_changes_values' ];
        }

        return [ 'errors' => $errors, 'changes' => $changes ];
    }

    /**
     * The path of the property a platform's default
     * (`components.<c>.platforms.<p>.<prop>`) is the default of
     * (`components.<c>.base.<prop>`), or null for any other path.
     */
    public static function platformBase(string $path): ?string
    {
        $segments = explode('.', $path);

        return count($segments) === 5 && $segments[0] === 'components' && $segments[2] === 'platforms'
            ? "components.{$segments[1]}.base.{$segments[4]}"
            : null;
    }

    /**
     * Whether a migration moves a leaf: the leaf itself, the page that holds
     * it (`pages.<page>` moves its footer and its sections), the list that
     * holds a section (`pages.<page>.sections`) and, for a platform's
     * default, its property. A key of visibility or images holds dots of its
     * own: only itself.
     *
     * @param array<string, mixed> $moved `to` by `from`
     */
    private static function isMoved(string $path, array $moved): bool
    {
        $segments = explode('.', $path);
        $property = self::platformBase($path);

        return isset($moved[$path])
            || ($segments[0] === 'pages' && count($segments) > 2 && isset($moved["pages.{$segments[1]}"]))
            || ($segments[0] === 'pages' && count($segments) === 4 && isset($moved["pages.{$segments[1]}.sections"]))
            || ($property !== null && isset($moved[$property]));
    }

    /**
     * Each value of a schema by the path a theme stores it at, with its
     * type, its own scale, its options, whether it is deprecated, its
     * default and its rules: `max_length` and `pattern`, and what of its
     * editor validates a value (self::editorRules()). A page's sections are
     * a leaf each (`pages.<page>.sections.<key>`, of type `section`), whose
     * default is its `visible`, with its `hideable`, its `group` and its
     * place in the list.
     *
     * @param array<string, mixed> $schema
     * @return array<string, array{type: ?string, scale: mixed, options: array<int, mixed>, deprecated: bool, default: mixed, rules: array<string, mixed>, section?: array{hideable: mixed, group: mixed, position: int}}>
     */
    public static function leaves(array $schema): array
    {
        $leaves = [];
        $walk = function (array $group, string $path, ?string $type, bool $deprecated) use (&$walk, &$leaves) {
            $type = is_string($group['$type'] ?? null) ? $group['$type'] : $type;
            $deprecated = $deprecated || !empty($group['$deprecated']);
            if (array_key_exists('$value', $group)) {
                if ($type === 'sections' && is_array($group['$value']) && array_is_list($group['$value'])) {
                    foreach ($group['$value'] as $position => $section) {
                        if (is_array($section) && is_string($section['key'] ?? null)) {
                            $leaves["{$path}.{$section['key']}"] = [
                                'type' => 'section',
                                'scale' => null,
                                'options' => [],
                                'deprecated' => $deprecated,
                                'default' => $section['visible'] ?? null,
                                'rules' => [],
                                'section' => [ 'hideable' => $section['hideable'] ?? null, 'group' => $section['group'] ?? null, 'position' => $position ],
                            ];
                        }
                    }

                    return;
                }
                $own = is_array($group['$extensions'][NodeFormat::EXTENSION] ?? null) ? $group['$extensions'][NodeFormat::EXTENSION] : [];
                $options = $own['options'] ?? [];
                $leaves[$path] = [
                    'type' => $type,
                    'scale' => $own['scale'] ?? null,
                    'options' => is_array($options) ? array_values(array_filter(array_map(fn ($option) => is_array($option) ? ($option['value'] ?? null) : $option, $options), 'is_scalar')) : [],
                    'deprecated' => $deprecated,
                    'default' => $group['$value'],
                    'rules' => array_filter([ 'max_length' => $own['max_length'] ?? null, 'pattern' => $own['pattern'] ?? null ], fn ($rule) => $rule !== null)
                        + self::editorRules($group['$extensions'][NodeFormat::EDITOR] ?? null),
                ];

                return;
            }
            foreach ($group as $key => $child) {
                if (is_array($child) && !str_starts_with((string) $key, '$')) {
                    $walk($child, "{$path}.{$key}", $type, $deprecated);
                }
            }
        };
        foreach (self::BLOCKS as $block) {
            if (is_array($schema[$block] ?? null)) {
                $walk($schema[$block], $block, null, false);
            }
        }

        return $leaves;
    }

    /**
     * What of a node's or group's editor metadata decides how a value is
     * validated: `alpha` and `custom` when they are true, and its numeric
     * limits (NodeFormat::limits()).
     *
     * @param mixed $editor
     * @return array<string, mixed>
     */
    public static function editorRules($editor): array
    {
        if (!is_array($editor)) {
            return [];
        }
        $marks = array_filter([
            'alpha' => ($editor['alpha'] ?? null) === true ? true : null,
            'custom' => ($editor['custom'] ?? null) === true ? true : null,
        ], fn ($mark) => $mark !== null);

        return $marks + NodeFormat::limits($editor);
    }

    /**
     * The keys of each page's sections in their default order, by the path
     * of their list.
     *
     * @param array<string, array<string, mixed>> $leaves
     * @return array<string, array<int, string>>
     */
    private static function sectionOrders(array $leaves): array
    {
        $orders = [];
        foreach ($leaves as $path => $leaf) {
            if (isset($leaf['section'])) {
                $segments = explode('.', (string) $path);
                $key = array_pop($segments);
                $orders[implode('.', $segments)][$leaf['section']['position']] = $key;
            }
        }

        return array_map(function (array $keys) {
            ksort($keys);

            return array_values($keys);
        }, $orders);
    }

    /**
     * Whether a version drops anything of its base's upgrade block: the
     * block itself, its previous base, its rewrite, a move or a path it
     * drops (a minor may add moves and paths).
     *
     * @param array<string, mixed> $old
     * @param array<string, mixed> $new
     */
    private static function upgradeRemoved(array $old, array $new): bool
    {
        $was = $old['$extensions'][NodeFormat::UPGRADE] ?? null;
        if (!is_array($was)) {
            return false;
        }
        $now = $new['$extensions'][NodeFormat::UPGRADE] ?? null;
        if (!is_array($now) || ($was['from'] ?? null) !== ($now['from'] ?? null) || ($was['rewrite'] ?? null) !== ($now['rewrite'] ?? null)) {
            return true;
        }
        foreach ([ 'moves', 'drop' ] as $list) {
            $kept = is_array($now[$list] ?? null) ? $now[$list] : [];
            foreach (is_array($was[$list] ?? null) ? $was[$list] : [] as $item) {
                if (!in_array($item, $kept, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<int, array{from: mixed, to: mixed}>
     */
    private static function migrations(array $schema): array
    {
        $migrations = $schema['$extensions'][NodeFormat::MIGRATE] ?? [];

        return is_array($migrations)
            ? array_values(array_map(fn ($migration) => [ 'from' => $migration['from'] ?? null, 'to' => $migration['to'] ?? null ], array_filter($migrations, 'is_array')))
            : [];
    }

    /**
     * A version's base and minor: `26.0` is `26.0.0`.
     *
     * @return array{0: string, 1: string}
     */
    private static function parts(string $version): array
    {
        $parts = explode('.', $version);

        return [ $parts[0], $parts[1] ?? '0' ];
    }

    /**
     * A value as a string two values share only when they are the same,
     * each map's keys sorted.
     *
     * @param mixed $value
     */
    public static function canonical($value): string
    {
        $sort = function ($value) use (&$sort) {
            if (!is_array($value)) {
                return $value;
            }
            if (!array_is_list($value)) {
                ksort($value, SORT_STRING);
            }

            return array_map($sort, $value);
        };

        return (string) json_encode($sort($value));
    }

    /**
     * What a schema says that a theme resolves with: everything but its
     * version, its texts and the editor metadata that validates no value
     * (self::editorRules() is what stays of it).
     *
     * @param array<string, mixed> $schema
     */
    private static function values(array $schema): string
    {
        $strip = function ($value) use (&$strip) {
            if (!is_array($value)) {
                return $value;
            }
            unset($value['$description']);
            if (is_array($value['$extensions'] ?? null) && array_key_exists(NodeFormat::EDITOR, $value['$extensions'])) {
                $rules = self::editorRules($value['$extensions'][NodeFormat::EDITOR]);
                if ($rules === []) {
                    unset($value['$extensions'][NodeFormat::EDITOR]);
                } else {
                    $value['$extensions'][NodeFormat::EDITOR] = $rules;
                }
            }
            if (($value['$extensions'] ?? null) === []) {
                unset($value['$extensions']);
            }
            if (!array_is_list($value)) {
                ksort($value, SORT_STRING);
            }

            return array_map($strip, $value);
        };
        unset($schema['version'], $schema['editor'], $schema['$schema']);

        return json_encode($strip($schema));
    }
}
