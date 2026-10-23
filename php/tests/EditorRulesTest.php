<?php

declare(strict_types=1);

namespace Ordering\ThemeEngine\Tests;

use Ordering\ThemeEngine\Schema\EditorRules;
use Ordering\ThemeEngine\ThemeSchemaPublisher;
use Ordering\ThemeEngine\ThemeSchemas;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The editor checks (EditorRules, the TypeScript engine's checkEditor();
 * the validate() cases in fixtures/validate.json cover each rule). The same
 * cases as test/editor.test.ts.
 */
class EditorRulesTest extends TestCase
{
    private const EDITOR = 'co.ordering.editor';

    /**
     * @return array<string, array{string, bool}>
     */
    public static function colors(): array
    {
        $cases = [
            [ 'transparent', true ],
            [ ' Transparent ', true ],
            [ '#0008', true ],
            [ '#000F', false ],
            [ '#00000080', true ],
            [ '#000000FF', false ],
            [ '#000000', false ],
            [ 'rgba(0,0,0,0.4)', true ],
            [ 'rgba(0, 0, 0, 1)', false ],
            [ 'rgb(0 0 0 / 50%)', true ],
            [ 'hsla(0,0%,0%,.5)', true ],
            [ 'rgba(0,0,0,abc)', false ],
            [ 'rgba(0,0,0)', false ],
            [ '{color.overlay}', false ],
        ];

        return array_combine(array_column($cases, 0), $cases);
    }

    #[DataProvider('colors')]
    public function testWhichColorsAreTranslucent(string $value, bool $expected): void
    {
        self::assertSame($expected, EditorRules::translucent($value));
    }

    public function testCheckReadsTheEditorOnlyGivenTheNodesAfterItsOwnRules(): void
    {
        // A visibility key in no group of the editor: an error of its metadata alone.
        $original = ThemeSchemaFixture::nodes();
        $original['visibility']['header.glow'] = [ '$value' => true ];
        $compiled = ThemeSchemaFixture::tuples($original)['schema'];

        self::assertSame([], ThemeSchemas::check($compiled));
        self::assertSame(EditorRules::check($original), ThemeSchemas::check($compiled, $original));
        self::assertNotSame([], EditorRules::check($original));
    }

    public function testEachErrorOnce(): void
    {
        $nodes = self::schema([
            'pages' => [],
            'editor' => [ 'sections' => [ [ 'key' => 'visibility', 'groups' => [ [ 'key' => 'one', 'pages' => [ 'nowhere', 'elsewhere' ] ] ] ] ] ],
        ]);

        self::assertSame([ 'editor.sections.0.groups.0.page_unknown' ], self::codes($nodes));
    }

    public function testAVisibilityKeyIsAPathOfItsOwn(): void
    {
        $nodes = self::schema([
            'visibility' => [ '$type' => 'boolean', 'a.b' => [ '$value' => true ] ],
            'components' => [
                'bar' => [
                    '$extensions' => [ self::EDITOR => [ 'groups' => [ [ 'key' => 'shown', 'paths' => [ 'visibility.a.b', 'visibility.a.c' ] ] ] ] ],
                    'base' => [ 'height' => [ '$type' => 'number', '$value' => 4, '$extensions' => [ self::EDITOR => [ 'order' => 1 ] ] ] ],
                ],
            ],
            'editor' => [ 'sections' => [ [ 'key' => 'visibility', 'groups' => [ [ 'key' => 'a', 'paths' => [ 'visibility.a.b' ] ] ] ] ] ],
        ]);

        self::assertSame([ 'components.bar.$extensions.' . self::EDITOR . '.groups.0.path_unknown' ], self::codes($nodes));
    }

    public function testThePropertysRulesAreCheckAndItsDefaultOutOfRange(): void
    {
        $nodes = self::schema([
            'tokens' => [ 'color' => [ '$type' => 'color', 'primary' => [ '$value' => '#000000', '$extensions' => [ self::EDITOR => [ 'custom' => true ], 'co.ordering' => [ 'max_length' => 3 ] ] ] ] ],
            'components' => [
                'card' => [
                    'base' => [
                        'gap' => [ '$type' => 'number', '$value' => 20, '$extensions' => [ self::EDITOR => [ 'custom' => true, 'control' => 'range', 'min' => 0, 'max' => 10 ], 'co.ordering' => [ 'max_length' => 3 ] ] ],
                    ],
                ],
            ],
        ]);

        self::assertSame([
            'tokens.color.primary.custom_not_level',
            'tokens.color.primary.rules_not_string',
        ], self::codes($nodes));
        self::assertSame([ 'components.card.base.gap.default_out_of_range' ], array_column(ThemeSchemaPublisher::outOfRange($nodes), 'code'));
    }

    public function testARangeSaysItsLimitsAndTheTypographyGroupIsAsWideAsItsLevels(): void
    {
        $level = fn (int $min, int $max) => [ '$value' => [ 'size' => 16, 'line' => 1.5, 'weight' => 400 ], '$extensions' => [ self::EDITOR => [ 'fields' => [ 'size' => [ 'min' => $min, 'max' => $max ] ] ] ] ];
        $nodes = self::schema([
            'tokens' => [
                'typography' => [
                    '$type' => 'typography',
                    '$extensions' => [ self::EDITOR => [ 'fields' => [ 'size' => [ 'min' => 10, 'max' => 40 ] ] ] ],
                    'body' => $level(12, 30),
                    'display' => $level(8, 96),
                ],
            ],
            'site' => [ 'width' => [ '$type' => 'number', '$value' => 5, '$extensions' => [ self::EDITOR => [ 'control' => 'range', 'min' => 10, 'max' => 1 ] ] ] ],
        ]);

        self::assertSame([
            'site.width.min_over_max',
            'tokens.typography.fields.size.min_not_widest',
            'tokens.typography.fields.size.max_not_widest',
        ], self::codes($nodes));
    }

    /**
     * @param array<string, mixed> $blocks
     * @return array<string, mixed>
     */
    private static function schema(array $blocks): array
    {
        return [ '$schema' => 'urn:ordering:theme-schema:1', 'product_type' => 'call_center', 'version' => '26.0.0', ...$blocks ];
    }

    /**
     * @param array<string, mixed> $nodes
     * @return array<int, string>
     */
    private static function codes(array $nodes): array
    {
        return array_column(EditorRules::check($nodes), 'code');
    }
}
