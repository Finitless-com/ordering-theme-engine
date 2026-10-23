<?php

declare(strict_types=1);

namespace Ordering\ThemeEngine\Tests;

use Ordering\ThemeEngine\ThemeSchemas;

/**
 * The reference schema (meta/reference/): no product's, it uses every shape
 * of the format; its 1.0.0 is published with its version 1 map.
 */
final class ThemeSchemaFixture
{
    /**
     * The reference's 1.0.0 in the tuples the engine reads, compiled with
     * its version 1 map.
     *
     * @return array<string, mixed>
     */
    public static function compiled(): array
    {
        return self::tuples(self::nodes())['schema'];
    }

    /**
     * The reference at $version, in nodes.
     *
     * @return array<string, mixed>
     */
    public static function nodes(string $version = '1.0.0'): array
    {
        return self::json("reference-{$version}.json");
    }

    /**
     * @return array<string, mixed>
     */
    public static function v1Map(): array
    {
        return self::json('reference-1.0.0.v1-map.json');
    }

    /**
     * ThemeSchemas::tuples() as api-core compiles a schema: without a map of
     * its own, with the reference's for its product type.
     *
     * @param array<string, mixed> $schema
     * @return array{schema: array<string, mixed>, errors: array<int, array{path: string, code: string}>}
     */
    public static function tuples(array $schema, ?array $v1_map = null): array
    {
        return ThemeSchemas::tuples($schema, $v1_map ?? (($schema['product_type'] ?? null) === 'marketplace_website' ? self::v1Map() : null));
    }

    /**
     * @return array<string, mixed>
     */
    private static function json(string $file): array
    {
        return json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/meta/reference/' . $file), true, 512, JSON_THROW_ON_ERROR);
    }
}
