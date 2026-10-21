<?php

namespace Ordering\ThemeEngine\Schema;

/**
 * What a new version of a theme schema changes from the last of its base,
 * and whether it may (api-core's docs/THEMES_V2_PLAN.md §6.4.2). Both are in nodes.
 *
 * - A patch changes no key, type, option, default or rule: only texts and
 *   editor metadata.
 * - A minor may add keys and options and change defaults. A key it drops
 *   must have been `$deprecated` in the last version, or moved by a
 *   migration (of the key, or of the page that holds it); a key keeps
 *   its type, its scale and every option it had; and the migrations of
 *   the base are never dropped.
 * - A new base is not compared.
 */
final class SchemaDiff
{
    private const BLOCKS = [ 'tokens', 'components', 'visibility', 'images', 'pages', 'site' ];

    /**
     * @param array<string, mixed> $old
     * @param array<string, mixed> $new
     * @return array{errors: array<int, array{path: string, code: string}>, changes: array{added: array<int, string>, removed: array<int, string>, migrated: array<int, string>, deprecated: array<int, string>}}
     */
    public static function compare(array $old, array $new): array
    {
        $errors = [];
        $before = self::leaves($old);
        $after = self::leaves($new);
        $migrations = self::migrations($new);
        $moved = array_column($migrations, 'to', 'from');

        $changes = [
            'added' => array_values(array_diff(array_keys($after), array_keys($before))),
            'removed' => [],
            'migrated' => [],
            'deprecated' => array_values(array_keys(array_filter($after, fn ($leaf, $path) => $leaf['deprecated'] && !($before[$path]['deprecated'] ?? false), ARRAY_FILTER_USE_BOTH))),
        ];

        [ $old_base, $old_minor ] = self::parts((string) ($old['version'] ?? ''));
        [ $new_base, $new_minor ] = self::parts((string) ($new['version'] ?? ''));
        if ($old_base !== $new_base) {
            return [ 'errors' => [], 'changes' => $changes ];
        }

        foreach ($before as $path => $leaf) {
            if (!isset($after[$path])) {
                if (self::isMoved($path, $moved)) {
                    $changes['migrated'][] = $path;
                } else {
                    $changes['removed'][] = $path;
                    if (!$leaf['deprecated']) {
                        $errors[] = [ 'path' => $path, 'code' => "{$path}.removed" ];
                    }
                }
                continue;
            }
            if ($leaf['type'] !== $after[$path]['type']) {
                $errors[] = [ 'path' => $path, 'code' => "{$path}.type_changed" ];
            }
            // A stored value names a level of its scale: another scale reads it elsewhere.
            if ($leaf['scale'] !== $after[$path]['scale']) {
                $errors[] = [ 'path' => $path, 'code' => "{$path}.scale_changed" ];
            }
            if (array_diff($leaf['options'], $after[$path]['options']) !== []) {
                $errors[] = [ 'path' => $path, 'code' => "{$path}.option_removed" ];
            }
        }

        foreach (self::migrations($old) as $migration) {
            if (!in_array($migration, $migrations, true)) {
                $errors[] = [ 'path' => 'migrate', 'code' => 'schema.migration_removed' ];
                break;
            }
        }

        if ($old_minor === $new_minor && self::values($old) !== self::values($new)) {
            $errors[] = [ 'path' => 'version', 'code' => 'schema.patch_changes_values' ];
        }

        return [ 'errors' => $errors, 'changes' => $changes ];
    }

    /**
     * Whether a migration moves a leaf: the leaf itself, or the page that
     * holds it (`pages.<page>` moves its footer and its sections). A key of
     * visibility or images holds dots of its own: only itself.
     *
     * @param array<string, mixed> $moved `to` by `from`
     */
    private static function isMoved(string $path, array $moved): bool
    {
        $segments = explode('.', $path);

        return isset($moved[$path]) || ($segments[0] === 'pages' && count($segments) > 2 && isset($moved["pages.{$segments[1]}"]));
    }

    /**
     * Each value of a schema by the path a theme stores it at, with its
     * type, its own scale, its options and whether it is deprecated.
     *
     * @param array<string, mixed> $schema
     * @return array<string, array{type: ?string, scale: mixed, options: array<int, string>, deprecated: bool}>
     */
    public static function leaves(array $schema): array
    {
        $leaves = [];
        $walk = function (array $group, string $path, ?string $type, bool $deprecated) use (&$walk, &$leaves) {
            $type = is_string($group['$type'] ?? null) ? $group['$type'] : $type;
            $deprecated = $deprecated || !empty($group['$deprecated']);
            if (array_key_exists('$value', $group)) {
                $options = $group['$extensions'][NodeFormat::EXTENSION]['options'] ?? [];
                $leaves[$path] = [
                    'type' => $type,
                    'scale' => $group['$extensions'][NodeFormat::EXTENSION]['scale'] ?? null,
                    'options' => is_array($options) ? array_values(array_filter(array_map(fn ($option) => is_array($option) ? ($option['value'] ?? null) : $option, $options), 'is_scalar')) : [],
                    'deprecated' => $deprecated,
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
     * What a schema says that a theme resolves with: everything but its
     * version, its texts and what only the editor reads.
     *
     * @param array<string, mixed> $schema
     */
    private static function values(array $schema): string
    {
        $strip = function ($value) use (&$strip) {
            if (!is_array($value)) {
                return $value;
            }
            unset($value['$description'], $value['$extensions'][NodeFormat::EDITOR]);
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
