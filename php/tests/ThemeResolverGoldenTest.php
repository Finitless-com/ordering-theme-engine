<?php

declare(strict_types=1);

namespace Ordering\ThemeEngine\Tests;

use Ordering\ThemeEngine\Schema\NodeConverter;
use Ordering\ThemeEngine\ThemeMigrations;
use Ordering\ThemeEngine\ThemeResolver;
use Ordering\ThemeEngine\ThemeSchemas;
use Ordering\ThemeEngine\ThemeValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What ThemeResolver::resolve() answers today, pinned case by case
 * (ThemeResolverGolden), so the interpreter that replaces it (api-core's
 * docs/THEMES_V2_PLAN.md §6.3.4) has to answer exactly the same: key order
 * and types included. Each case isolates one rule of today's
 * resolver, or one combination of the themes the web tested.
 *
 * A case's values must be valid: ThemeResolver::valid() drops what is not,
 * and a case that silently resolved as the empty theme would pin nothing.
 * A case that declares migrations must be valid once they move its values,
 * and they must move something.
 *
 * When a change of behavior is deliberate, regenerate the fixtures with
 * `php scripts/golden-fixtures.php` and review the diff.
 */
class ThemeResolverGoldenTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function cases(): array
    {
        return array_map(fn (string $path) => [ $path ], ThemeResolverGolden::cases());
    }

    #[DataProvider('cases')]
    public function testResolveAnswersTheGoldenFixture(string $path): void
    {
        $case = ThemeResolverGolden::read($path);
        $schema = ThemeResolverGolden::schemaFor($case);
        $values = ThemeResolverGolden::values($case);
        $migrated = ThemeMigrations::apply($values, $schema['migrate'] ?? []);

        self::assertSame([], ThemeValidator::validate($migrated, $schema), 'The case sets something the schema rejects.');
        self::assertSame($migrated, ThemeResolver::valid($values, $schema));
        if (isset($schema['migrate'])) {
            self::assertNotSame($values, $migrated, 'The case stores nothing its migrations move.');
        }

        $expected = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR)['resolved'] ?? null;
        self::assertIsArray($expected, 'The case has no resolved values: run scripts/golden-fixtures.php.');
        self::assertSame($expected, ThemeResolverGolden::resolve($case, $schema));
    }

    /**
     * The same schema written in nodes (docs/schema-format.md),
     * declaring what follows Identity instead of leaving it to
     * ThemeOverall's rule, resolves every case the same.
     */
    #[DataProvider('cases')]
    public function testTheSchemaInNodesResolvesTheGoldenFixtureTheSame(string $path): void
    {
        $case = ThemeResolverGolden::read($path);
        $nodes = json_decode(NodeConverter::json(NodeConverter::convert(ThemeResolverGolden::schemaFor($case))['schema']), true);
        $compiled = ThemeSchemaFixture::tuples($nodes);
        self::assertSame([], $compiled['errors']);
        self::assertSame([], ThemeSchemas::check($compiled['schema']));

        $expected = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR)['resolved'] ?? null;
        self::assertSame($expected, ThemeResolverGolden::resolve($case, $compiled['schema']));
    }

    public function testTheEmptyThemeIsAmongTheCases(): void
    {
        // The first fixture §6.3.4 asks for.
        self::assertArrayHasKey('empty', ThemeResolverGolden::cases());
    }
}
