<?php

namespace Ordering\ThemeEngine;

use Ordering\ThemeEngine\Schema\NodeCompiler;
use Ordering\ThemeEngine\Schema\NodeFormat;
use Ordering\ThemeEngine\Schema\SchemaDiff;

/**
 * What publishing a theme schema checks before the registry (api-core's
 * docs/THEMES_V2_PLAN.md §6.4.2): the schema itself, that each default fits
 * its editor's control, that its version 1 map names only what it has,
 * that versions only go forward within a base, that
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
     * metadata, each default within its control, that every path of the
     * version 1 map is one of the schema (self::v1MapErrors()), the semver
     * version and, against the previous version, its order within the base
     * and SchemaDiff. The first stage that fails answers its code:
     * `schema.format`, `schema.invalid`, `schema.version_exists`,
     * `schema.version_order` or `schema.incompatible`.
     *
     * Against a previous version that is the same version: with the same
     * content (every key, the editor's included, in any order) the answer
     * is valid and `unchanged`, as api-core answers a deploy that publishes
     * it again; with another content, `schema.version_exists`.
     *
     * In development the previous version refuses nothing: what production
     * would refuse for the version (`schema.version_exists`,
     * `schema.version_order`) and for compatibility (SchemaDiff's errors) is
     * answered in `warnings`, each `{ path, code }`, with the changes.
     *
     * Given the last version of the previous base, the schema's upgrade
     * block is also checked against it (ThemeUpgrades::check()), and its
     * errors are `schema.invalid` like the rest of the schema's own. Without
     * it the block is not checked: api-core, which looks it up, calls
     * ThemeUpgrades::check() itself when the product type has no previous
     * base.
     *
     * @param array<string, mixed> $schema in nodes
     * @param array<string, mixed>|null $v1_map null compiles no version 1 paths, and checks none
     * @param array<string, mixed>|null $previous in nodes, the last version of its base (or the same version, published)
     * @param array<string, mixed>|null $previous_base in nodes, the last version of the previous base
     * @return array{code: ?string, errors: array<int, array{path: string, code: string}>, warnings: array<int, array{path: string, code: string}>, changes: ?array<string, array<int, string>>, unchanged: bool}
     */
    public static function validate(array $schema, ?array $v1_map = null, ?array $previous = null, bool $development = false, ?array $previous_base = null): array
    {
        if (!NodeCompiler::isNodes($schema)) {
            return self::answer('schema.format', [ [ 'path' => '$schema', 'code' => 'schema.format' ] ]);
        }
        $compiled = ThemeSchemas::tuples($schema, $v1_map);
        $errors = $compiled['errors'] !== [] ? $compiled['errors'] : [
            ...ThemeSchemas::check($compiled['schema'], $schema),
            ...self::outOfRange($schema),
            ...($v1_map === null ? [] : self::v1MapErrors($compiled['schema'], $v1_map)),
            ...($previous_base === null ? [] : ThemeUpgrades::check($schema, $previous_base)),
        ];
        if (!preg_match(self::SEMVER, (string) ($schema['version'] ?? ''))) {
            $errors[] = [ 'path' => 'version', 'code' => 'version.semver' ];
        }
        if ($errors !== []) {
            return self::answer('schema.invalid', $errors);
        }
        if ($previous === null) {
            return self::answer(null);
        }

        $version = (string) $schema['version'];
        $previous_version = (string) ($previous['version'] ?? '');
        $warnings = [];
        if (explode('.', $version)[0] === explode('.', $previous_version)[0]) {
            $order = version_compare(self::full($version), self::full($previous_version));
            if ($order === 0 && SchemaDiff::canonical($schema) === SchemaDiff::canonical($previous)) {
                return self::answer(null, [], [], null, true);
            }
            $refusal = $order === 0 ? 'schema.version_exists' : ($order < 0 ? 'schema.version_order' : null);
            if ($refusal !== null) {
                if (!$development) {
                    return self::answer($refusal, [ [ 'path' => 'version', 'code' => $refusal ] ]);
                }
                $warnings[] = [ 'path' => 'version', 'code' => $refusal ];
            }
        }
        $diff = SchemaDiff::compare($previous, $schema);
        if ($diff['errors'] !== []) {
            if (!$development) {
                return self::answer('schema.incompatible', $diff['errors']);
            }
            array_push($warnings, ...$diff['errors']);
        }

        return self::answer(null, [], $warnings, $diff['changes']);
    }

    /**
     * What validate() answers: the code of the stage that failed (null
     * when none did) with its errors, what production would refuse in
     * development, the changes from the previous version and whether it is
     * that version again.
     *
     * @param array<int, array{path: string, code: string}> $errors
     * @param array<int, array{path: string, code: string}> $warnings
     * @param array<string, array<int, string>>|null $changes
     * @return array{code: ?string, errors: array<int, array{path: string, code: string}>, warnings: array<int, array{path: string, code: string}>, changes: ?array<string, array<int, string>>, unchanged: bool}
     */
    private static function answer(?string $code, array $errors = [], array $warnings = [], ?array $changes = null, bool $unchanged = false): array
    {
        return [ 'code' => $code, 'errors' => $errors, 'warnings' => $warnings, 'changes' => $changes, 'unchanged' => $unchanged ];
    }

    /**
     * The paths of a version 1 map the schema (compiled) does not have,
     * each `v1_map.<section>.<path>.unknown`: a version 2 path of `v1` that
     * is no token, component property or setting of it, a visibility key or
     * an image it has not, a page or a page's section it has not
     * (`v1_map.pages.<page>.<section>`) and a setting it has not. A map that
     * outlives a key would convert a version 1 site without it, silently.
     * Its other keys (`description`, `source`) say nothing.
     *
     * @param array<string, mixed> $schema compiled
     * @param array<string, mixed> $v1_map
     * @return array<int, array{path: string, code: string}>
     */
    public static function v1MapErrors(array $schema, array $v1_map): array
    {
        $errors = [];
        $unknown = function (string $path) use (&$errors) {
            $errors[] = [ 'path' => "v1_map.{$path}", 'code' => "v1_map.{$path}.unknown" ];
        };
        foreach (is_array($v1_map['v1'] ?? null) ? $v1_map['v1'] : [] as $path => $from) {
            $path = (string) $path;
            if (!in_array(explode('.', $path)[0], [ 'tokens', 'components', 'site' ], true) || ThemeSchemas::kindAt($schema, $path) === null) {
                $unknown("v1.{$path}");
            }
        }
        foreach ([ 'visibility', 'images' ] as $section) {
            foreach (is_array($v1_map[$section] ?? null) ? $v1_map[$section] : [] as $key => $from) {
                if (!is_array($schema[$section][$key] ?? null)) {
                    $unknown("{$section}.{$key}");
                }
            }
        }
        foreach (is_array($v1_map['pages'] ?? null) ? $v1_map['pages'] : [] as $page => $sections) {
            $spec = $schema['pages'][$page] ?? null;
            if (!is_array($spec)) {
                $unknown("pages.{$page}");
                continue;
            }
            $keys = array_column(ThemePages::defaultSections($spec), 'key');
            foreach (is_array($sections) ? $sections : [] as $key => $from) {
                if (!in_array((string) $key, $keys, true)) {
                    $unknown("pages.{$page}.{$key}");
                }
            }
        }
        foreach (is_array($v1_map['site'] ?? null) ? $v1_map['site'] : [] as $key => $from) {
            if (ThemeSchemas::kindAt($schema, "site.{$key}") === null) {
                $unknown("site.{$key}");
            }
        }

        return $errors;
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
