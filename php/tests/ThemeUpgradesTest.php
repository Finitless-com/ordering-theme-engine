<?php

declare(strict_types=1);

namespace Ordering\ThemeEngine\Tests;

use Ordering\ThemeEngine\Schema\NodeCompiler;
use Ordering\ThemeEngine\ThemeSchemaPublisher;
use Ordering\ThemeEngine\ThemeSchemas;
use Ordering\ThemeEngine\ThemeUpgrades;
use PHPUnit\Framework\TestCase;

/**
 * How a theme of one base takes its values in the next one: the block a
 * base declares (`co.ordering.upgrade`), its checks and its steps. The
 * golden cases (fixtures/upgrade.json, fixtures/validate.json) cover the
 * rest, in both languages.
 */
class ThemeUpgradesTest extends TestCase
{
    /**
     * Prior behavior: PHP wrote an empty list as `{}` for the meta-schema,
     * so a block with no moves (or an empty list of migrations) failed it
     * here and passed it in TypeScript.
     */
    public function testTheMetaSchemaTakesTheBlockWithEmptyListsAndAMapByIndex(): void
    {
        $schema = self::schema('27.0.0');
        $schema['$extensions'] = [
            'co.ordering.migrate' => [],
            'co.ordering.upgrade' => [
                'from' => 26,
                'moves' => [ [ 'from' => 'components.card.base.columns', 'to' => 'components.card.base.columns', 'map' => [ 'none', 'two' ] ] ],
                'drop' => [],
                'rewrite' => [ 'id' => 'marketplace_website.27', 'paths' => [] ],
            ],
        ];
        self::assertSame([], ThemeSchemas::meta($schema));

        // A block of another shape does not.
        $schema['$extensions']['co.ordering.upgrade'] = [ 'from' => '26', 'moves' => [ [ 'from' => 'components', 'to' => 'x.y', 'extra' => 1 ] ] ];
        self::assertNotSame([], ThemeSchemas::meta($schema));
    }

    public function testTheBlockGoesToTheTuplesWithItsShapeChecked(): void
    {
        $block = [ 'from' => 26, 'moves' => [], 'drop' => [ 'visibility.footer' ] ];
        $schema = self::schema('27.0.0');
        $schema['$extensions'] = [ 'co.ordering.upgrade' => $block ];
        $compiled = NodeCompiler::compile($schema)['schema'];

        self::assertSame($block, $compiled['upgrade']);
        self::assertSame(5, ThemeSchemas::COMPILED);
        self::assertNotContains('upgrade.invalid', array_column(ThemeSchemas::check($compiled), 'code'));
        foreach ([ [], [ 'from' => '26', 'moves' => [], 'drop' => [] ], [ 'from' => 26, 'moves' => [ 'a' => 1 ], 'drop' => [] ], [ 'from' => 26, 'moves' => [] ] ] as $invalid) {
            $compiled['upgrade'] = $invalid;
            self::assertContains('upgrade.invalid', array_column(ThemeSchemas::check($compiled), 'code'));
        }
    }

    public function testWithoutAPreviousBaseABlockNamesNone(): void
    {
        $schema = self::schema('27.0.0');
        self::assertSame([], ThemeUpgrades::check($schema, null));

        $schema['$extensions'] = [ 'co.ordering.upgrade' => [ 'from' => 26, 'moves' => [], 'drop' => [] ] ];
        self::assertSame([ [ 'path' => 'upgrade.from', 'code' => 'upgrade.from.invalid' ] ], ThemeUpgrades::check($schema, null));
        // validate() checks the block only against the previous base it is given.
        self::assertNotContains('upgrade.from.invalid', array_column(ThemeSchemaPublisher::validate($schema)['errors'], 'code'));
    }

    public function testOneStepMovesMapsDropsAndRewritesThenKeepsWhatFits(): void
    {
        $previous = self::schema('26.4.0');
        $previous['components']['button']['base']['old_gap'] = [ '$type' => 'dimension', '$value' => '{space.sm}', '$extensions' => [ 'co.ordering' => [ 'scale' => 'space' ] ] ];
        $previous['visibility']['old_banner'] = [ '$value' => false ];
        $next = self::schema('27.0.0');
        $next['$extensions'] = [ 'co.ordering.upgrade' => [
            'from' => 26,
            'moves' => [
                [ 'from' => 'components.button.base.old_gap', 'to' => 'components.button.base.icon_gap' ],
                [ 'from' => 'components.link.base.underline', 'to' => 'components.link.base.underline', 'map' => [ 'always' => 'hover' ] ],
            ],
            'drop' => [ 'visibility.old_banner' ],
            'rewrite' => [ 'id' => 'product.27', 'paths' => [] ],
        ] ];
        $calls = [];
        $rewrite = function (string $id, array $values, array $before, array $after) use (&$calls) {
            $calls[] = [ $id, $before['version'], $after['version'] ];
            $values['components']['card']['base']['background'] = '#FFFFFF';

            return $values;
        };

        self::assertSame(
            [ 'components' => [ 'button' => [ 'base' => [ 'icon_gap' => '{space.md}' ] ], 'link' => [ 'base' => [ 'underline' => 'hover' ] ], 'card' => [ 'base' => [ 'background' => '#FFFFFF' ] ] ] ],
            ThemeUpgrades::apply(
                [ 'components' => [ 'button' => [ 'base' => [ 'old_gap' => '{space.md}' ] ], 'link' => [ 'base' => [ 'underline' => 'always' ] ] ], 'visibility' => [ 'old_banner' => true ], 'nope' => 1 ],
                NodeCompiler::compile($next)['schema'],
                NodeCompiler::compile($previous)['schema'],
                $rewrite,
            ),
        );
        self::assertSame([ [ 'product.27', '26.4.0', '27.0.0' ] ], $calls);
    }

    /**
     * @return array<string, mixed>
     */
    private static function schema(string $version): array
    {
        return [ 'version' => $version ] + ThemeSchemaFixture::webMarketplaceNodes();
    }
}
