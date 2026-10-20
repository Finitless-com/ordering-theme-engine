<?php

declare(strict_types=1);

namespace Ordering\ThemeEngine\Tests;

use Ordering\ThemeEngine\ThemeSchemas;

/**
 * The theme schema of Web Marketplace 26 as api-core registers it
 * (fixtures/schema-26.0.nodes.json), and the version 1 map api-core keeps
 * for its product type (fixtures/v1-map.json).
 */
final class ThemeSchemaFixture
{
    /**
     * The schema in the tuples the engine reads: the product keeps it in
     * the node format (docs/schema-format.md).
     *
     * @return array<string, mixed>
     */
    public static function webMarketplace(): array
    {
        return self::tuples(self::webMarketplaceNodes())['schema'];
    }

    /**
     * @return array<string, mixed>
     */
    public static function webMarketplaceNodes(): array
    {
        return self::json('schema-26.0.nodes.json');
    }

    /**
     * @return array<string, mixed>
     */
    public static function v1Map(): array
    {
        return self::json('v1-map.json');
    }

    /**
     * ThemeSchemas::tuples() as api-core compiles a schema: without a map of
     * its own, with the one api-core keeps for its product type (only Web
     * Marketplace has one).
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
        return json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/' . $file), true, 512, JSON_THROW_ON_ERROR);
    }
}
