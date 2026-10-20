<?php

declare(strict_types=1);

namespace Ordering\ThemeEngine\Tests;

use Ordering\ThemeEngine\ThemeResolver;

/**
 * The golden fixtures of ThemeResolver::resolve(): what the resolver
 * answers for a set of themes, over a frozen copy of the Web Marketplace 26
 * schema, so that both implementations (this one and the TypeScript one)
 * can be proven to answer the same (api-core's docs/THEMES_V2_PLAN.md
 * §6.3.4).
 *
 * Each case (`fixtures/cases/<name>.json`) is
 * `{ description, migrate?, values, resolved }`: `values` is what a theme
 * stores, as written by hand, and `resolved` is what resolve() answered for
 * it. `migrate`, when there, is the renames and moves the schema declares
 * for that case (ThemeMigrations): a theme saved before them.
 * ThemeResolverGoldenTest checks them; `scripts/golden-fixtures.php`
 * rewrites `resolved` when a change of behavior is deliberate.
 *
 * JSON cannot tell PHP's empty array from an empty map, so:
 * - `values` is read as objects, so `{}` stays `{}` when it is written
 *   back, and decoded to arrays only to resolve it, as api-core decodes
 *   what it stores;
 * - `resolved` is compared after decoding it to arrays, where `{}` and `[]`
 *   are both `[]`, which is what PHP returns. JSON_PRESERVE_ZERO_FRACTION
 *   keeps a float such as 1.0 a float on the way back.
 */
final class ThemeResolverGolden
{
    public const FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    public static function directory(): string
    {
        return dirname(__DIR__, 2) . '/fixtures';
    }

    /**
     * The frozen schema, in tuples (`fixtures/schema.tuples.json`): a copy
     * of Web Marketplace 26 as it was, so a later edit of the product's
     * schema does not move the fixtures.
     *
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return json_decode((string) file_get_contents(self::directory() . '/schema.tuples.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * The frozen schema with the migrations the case declares, if any.
     *
     * @return array<string, mixed>
     */
    public static function schemaFor(object $case): array
    {
        $schema = self::schema();
        if (property_exists($case, 'migrate')) {
            $schema['migrate'] = json_decode(json_encode($case->migrate, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        }

        return $schema;
    }

    /**
     * @return array<string, string> case name => path
     */
    public static function cases(): array
    {
        $cases = [];
        foreach (glob(self::directory() . '/cases/*.json') ?: [] as $path) {
            $cases[basename($path, '.json')] = $path;
        }
        ksort($cases);

        return $cases;
    }

    /**
     * A case as written: objects, so its `values` keep `{}` apart from `[]`.
     */
    public static function read(string $path): object
    {
        $case = json_decode((string) file_get_contents($path), false, 512, JSON_THROW_ON_ERROR);
        if (!$case instanceof \stdClass || !property_exists($case, 'values') || !$case->values instanceof \stdClass) {
            throw new \UnexpectedValueException("{$path}: a case is { description, values: {…}, resolved }.");
        }

        return $case;
    }

    /**
     * The case's values as arrays, the way ThemeValidator reads them.
     *
     * @return array<string, mixed>
     */
    public static function values(object $case): array
    {
        return json_decode(json_encode($case->values, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * What resolve() answers for the case's values on the frozen schema.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    public static function resolve(object $case, array $schema): array
    {
        return ThemeResolver::resolve(self::values($case), $schema);
    }
}
