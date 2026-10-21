<?php

namespace Ordering\ThemeEngine;

use Ordering\ThemeEngine\Schema\NodeCompiler;
use Ordering\ThemeEngine\Schema\NodeFormat;
use Ordering\ThemeEngine\Schema\SchemaDiff;

/**
 * What publishing a theme schema checks before the registry (api-core's
 * docs/THEMES_V2_PLAN.md §6.4.2): the schema itself, that each default fits
 * its editor's control, that versions only go forward within a base, that
 * nothing breaks the themes made with an earlier one (SchemaDiff), and how
 * a theme of the previous base takes its values (ThemeUpgrades).
 * api-core publishes (its own App\Themes\ThemeSchemaPublisher): it looks up
 * the previous version, keeps each version immutable and stores it.
 */
final class ThemeSchemaPublisher
{
    private const SEMVER = '/^\d{1,4}\.\d{1,4}\.\d{1,4}$/';

    /**
     * What publishing a schema checks without the registry, so that a
     * product's repository runs the same before it publishes (the
     * TypeScript engine's validateFull() mirrors it): the format, the
     * meta-schema and the compiler, ThemeSchemas::check() with the editor
     * metadata, each default within its control, the semver version and,
     * against the previous version, its order within the base and
     * SchemaDiff. The first stage that fails answers its code:
     * `schema.format`, `schema.invalid`, `schema.version_order` or
     * `schema.incompatible`. In development the previous version is only
     * compared for its changes: neither its order nor its compatibility
     * refuses anything.
     *
     * Given the last version of the previous base, the schema's upgrade
     * block is also checked against it (ThemeUpgrades::check()), and its
     * errors are `schema.invalid` like the rest of the schema's own. Without
     * it the block is not checked: api-core, which looks it up, calls
     * ThemeUpgrades::check() itself when the product type has no previous
     * base.
     *
     * @param array<string, mixed> $schema in nodes
     * @param array<string, mixed>|null $v1_map null compiles no version 1 paths
     * @param array<string, mixed>|null $previous in nodes, the last version of its base
     * @param array<string, mixed>|null $previous_base in nodes, the last version of the previous base
     * @return array{code: ?string, errors: array<int, array{path: string, code: string}>, changes: ?array<string, array<int, string>>}
     */
    public static function validate(array $schema, ?array $v1_map = null, ?array $previous = null, bool $development = false, ?array $previous_base = null): array
    {
        if (!NodeCompiler::isNodes($schema)) {
            return [ 'code' => 'schema.format', 'errors' => [ [ 'path' => '$schema', 'code' => 'schema.format' ] ], 'changes' => null ];
        }
        $compiled = ThemeSchemas::tuples($schema, $v1_map);
        $errors = $compiled['errors'] !== [] ? $compiled['errors'] : [
            ...ThemeSchemas::check($compiled['schema'], $schema),
            ...self::outOfRange($schema),
            ...($previous_base === null ? [] : ThemeUpgrades::check($schema, $previous_base)),
        ];
        if (!preg_match(self::SEMVER, (string) ($schema['version'] ?? ''))) {
            $errors[] = [ 'path' => 'version', 'code' => 'version.semver' ];
        }
        if ($errors !== []) {
            return [ 'code' => 'schema.invalid', 'errors' => $errors, 'changes' => null ];
        }
        if ($previous === null) {
            return [ 'code' => null, 'errors' => [], 'changes' => null ];
        }

        $version = (string) $schema['version'];
        $previous_version = (string) ($previous['version'] ?? '');
        if (!$development && explode('.', $version)[0] === explode('.', $previous_version)[0] && version_compare(self::full($version), self::full($previous_version), '<=')) {
            return [ 'code' => 'schema.version_order', 'errors' => [ [ 'path' => 'version', 'code' => 'schema.version_order' ] ], 'changes' => null ];
        }
        $diff = SchemaDiff::compare($previous, $schema);
        if (!$development && $diff['errors'] !== []) {
            return [ 'code' => 'schema.incompatible', 'errors' => $diff['errors'], 'changes' => null ];
        }

        return [ 'code' => null, 'errors' => [], 'changes' => $diff['changes'] ];
    }

    /**
     * The defaults the editor's own control could not show: a number below
     * its `min` or above its `max`.
     *
     * @param array<string, mixed> $schema in nodes
     * @return array<int, array{path: string, code: string}>
     */
    public static function outOfRange(array $schema): array
    {
        $errors = [];
        $walk = function (array $group, string $path) use (&$walk, &$errors) {
            if (array_key_exists('$value', $group)) {
                $editor = $group['$extensions'][NodeFormat::EDITOR] ?? [];
                $value = $group['$value'];
                if ((is_int($value) || is_float($value)) && is_array($editor)
                    && ((is_numeric($editor['min'] ?? null) && $value < $editor['min']) || (is_numeric($editor['max'] ?? null) && $value > $editor['max']))) {
                    $errors[] = [ 'path' => $path, 'code' => "{$path}.default_out_of_range" ];
                }

                return;
            }
            foreach ($group as $key => $child) {
                if (is_array($child) && !str_starts_with((string) $key, '$')) {
                    $walk($child, $path === '' ? (string) $key : "{$path}.{$key}");
                }
            }
        };
        foreach ([ 'tokens', 'components', 'pages', 'site' ] as $block) {
            if (is_array($schema[$block] ?? null)) {
                $walk($schema[$block], $block);
            }
        }

        return $errors;
    }

    /**
     * `26.0` compares as `26.0.0`.
     */
    private static function full(string $version): string
    {
        return implode('.', array_pad(explode('.', $version), 3, '0'));
    }
}
