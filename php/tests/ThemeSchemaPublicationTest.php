<?php

declare(strict_types=1);

namespace Ordering\ThemeEngine\Tests;

use Ordering\ThemeEngine\Schema\NodeConverter;
use Ordering\ThemeEngine\Schema\SchemaDiff;
use Ordering\ThemeEngine\ThemeMigrations;
use Ordering\ThemeEngine\ThemeResolver;
use Ordering\ThemeEngine\ThemeSchemas;
use PHPUnit\Framework\TestCase;

/**
 * What a product's CI may publish as a new version of its schema, and the
 * renames and moves a theme's values follow (api-core's docs/THEMES_V2_PLAN.md §6.4.2).
 */
class ThemeSchemaPublicationTest extends TestCase
{
    public function testAMinorAddsAndMovesButDropsOnlyWhatWasDeprecated(): void
    {
        $old = self::schema('26.1.0');
        $old['components']['card']['base']['background']['$deprecated'] = true;

        $new = self::schema('26.2.0');
        unset($new['components']['card']['base']['background'], $new['components']['card']['base']['padding']);
        $new['components']['card']['base']['surface'] = [ '$value' => '{color.surface}', '$type' => 'color' ];
        $new['components']['card']['base']['inset'] = [ '$value' => '{space.lg}', '$type' => 'dimension', '$extensions' => [ 'co.ordering' => [ 'scale' => 'space' ] ] ];
        $new['$extensions'] = [ 'co.ordering.migrate' => [ [ 'from' => 'components.card.base.padding', 'to' => 'components.card.base.inset' ] ] ];
        $diff = SchemaDiff::compare($old, $new);

        self::assertSame([], $diff['errors']);
        self::assertSame([ 'components.card.base.surface', 'components.card.base.inset' ], $diff['changes']['added']);
        self::assertSame([ 'components.card.base.background' ], $diff['changes']['removed']);
        self::assertSame([ 'components.card.base.padding' ], $diff['changes']['migrated']);
    }

    public function testAMinorKeepsEveryKeyTypeOptionAndMigrationOfItsBase(): void
    {
        $old = self::schema('26.1.0');
        $old['$extensions'] = [ 'co.ordering.migrate' => [ [ 'from' => 'components.card.base.bg', 'to' => 'components.card.base.background' ] ] ];

        $new = self::schema('26.2.0');
        unset($new['components']['card']['base']['padding']);
        $new['components']['card']['base']['radius']['$type'] = 'color';
        $new['components']['link']['base']['underline']['$extensions']['co.ordering']['options'] = [ 'none', 'always' ];
        $codes = array_column(SchemaDiff::compare($old, $new)['errors'], 'code');

        self::assertSame([
            'components.link.base.underline.option_removed',
            'components.card.base.radius.type_changed',
            'components.card.base.padding.removed',
            'schema.migration_removed',
        ], $codes);
        // A new base starts over.
        self::assertSame([], SchemaDiff::compare($old, [ 'version' => '27.0.0' ] + $new)['errors']);
    }

    /**
     * Prior behavior: a dimension moved to another scale was published as
     * compatible, and the level each stored theme names was then read on the
     * new scale, or not found there.
     */
    public function testAMinorKeepsTheScaleOfEachDimension(): void
    {
        $old = self::schema('26.1.0');

        $new = self::schema('26.2.0');
        $new['components']['card']['base']['padding']['$value'] = '{size.lg}';
        $new['components']['card']['base']['padding']['$extensions']['co.ordering']['scale'] = 'size';
        $new['components']['button']['base']['icon_gap']['$value'] = '{icon.sm}';
        $new['components']['button']['base']['icon_gap']['$extensions']['co.ordering']['scale'] = 'icon';

        self::assertSame([
            [ 'path' => 'components.button.base.icon_gap', 'code' => 'components.button.base.icon_gap.scale_changed' ],
            [ 'path' => 'components.card.base.padding', 'code' => 'components.card.base.padding.scale_changed' ],
        ], SchemaDiff::compare($old, $new)['errors']);
        // Nor may a patch; a new base starts over.
        self::assertSame([
            'components.button.base.icon_gap.scale_changed',
            'components.card.base.padding.scale_changed',
            'schema.patch_changes_values',
        ], array_column(SchemaDiff::compare($old, [ 'version' => '26.1.1' ] + $new)['errors'], 'code'));
        self::assertSame([], SchemaDiff::compare($old, [ 'version' => '27.0.0' ] + $new)['errors']);
    }

    public function testAPatchChangesOnlyTextsAndTheEditorsMetadata(): void
    {
        $old = self::schema('26.1.0');
        $new = self::schema('26.1.1');
        $new['components']['card']['base']['background']['$description'] = 'The card behind the content';
        $new['components']['card']['$extensions']['co.ordering.editor']['label'] = [ 'key' => 'BUILDER_THEME_CARD', 'default' => 'Card' ];
        $new['editor'] = [ 'sections' => [] ];
        self::assertSame([], SchemaDiff::compare($old, $new)['errors']);

        $new['components']['card']['base']['background']['$value'] = '#FFFFFF';
        self::assertSame([ 'schema.patch_changes_values' ], array_column(SchemaDiff::compare($old, $new)['errors'], 'code'));
        // The frozen 26.0 is 26.0.0: a 26.0.1 is its patch, a 26.1.0 its minor.
        self::assertSame([ 'schema.patch_changes_values' ], array_column(SchemaDiff::compare(self::schema('26.0'), [ 'version' => '26.0.1' ] + $new)['errors'], 'code'));
        self::assertSame([], SchemaDiff::compare(self::schema('26.0'), [ 'version' => '26.1.0' ] + $new)['errors']);
    }

    /**
     * Prior behavior: a theme saved before a key moved lost its value.
     */
    public function testAThemeTakesWhatTheSchemaMovedWhereItWent(): void
    {
        $migrations = [
            [ 'from' => 'components.card.base.bg', 'to' => 'components.card.base.background' ],
            [ 'from' => 'visibility.business.info', 'to' => 'visibility.business.header.info' ],
            [ 'from' => 'components.chip.base.bg', 'to' => 'components.chip.base.background' ],
        ];
        $values = ThemeMigrations::apply([
            'components' => [ 'card' => [ 'base' => [ 'bg' => '#FFF8E1' ] ], 'chip' => [ 'base' => [ 'bg' => '#000000', 'background' => '#FDE68A' ] ] ],
            'visibility' => [ 'business.info' => false ],
        ], $migrations);

        // A map left empty goes; one recreated comes last.
        self::assertSame([
            'components' => [ 'chip' => [ 'base' => [ 'background' => '#FDE68A' ] ], 'card' => [ 'base' => [ 'background' => '#FFF8E1' ] ] ],
            'visibility' => [ 'business.header.info' => false ],
        ], $values);

        // Every reader goes through valid(): the moved value resolves.
        $schema = ThemeSchemaFixture::webMarketplace();
        $schema['migrate'] = [ $migrations[0] ];
        self::assertSame('#FFF8E1', ThemeResolver::valid([ 'components' => [ 'card' => [ 'base' => [ 'bg' => '#FFF8E1' ] ] ] ], $schema)['components']['card']['base']['background']);
    }

    public function testAMigrationGoesFromAKeyTheSchemaNoLongerHasToOneItHas(): void
    {
        $schema = ThemeSchemaFixture::webMarketplace();
        $schema['migrate'] = [
            [ 'from' => 'components.card.base.bg', 'to' => 'components.card.base.background' ],
            [ 'from' => 'components.card.base.padding', 'to' => 'components.card.base.background' ],
            [ 'from' => 'components.card.base.bg', 'to' => 'components.card.base.nope' ],
            [ 'from' => 'components.card.base.bg', 'to' => 'tokens.color.primary' ],
        ];
        self::assertSame([ 'migrate.1.invalid', 'migrate.2.invalid', 'migrate.3.invalid' ], array_column(ThemeSchemas::check($schema), 'code'));

        // In nodes it is the root's `co.ordering.migrate`, and it survives a round trip.
        $schema['migrate'] = [ $schema['migrate'][0] ];
        $nodes = NodeConverter::convert($schema)['schema'];
        self::assertSame($schema['migrate'], $nodes['$extensions']['co.ordering.migrate']);
        $compiled = ThemeSchemaFixture::tuples($nodes);
        self::assertSame([ [], $schema['migrate'] ], [ $compiled['errors'], $compiled['schema']['migrate'] ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function schema(string $version): array
    {
        return [ 'version' => $version ] + ThemeSchemaFixture::webMarketplaceNodes();
    }
}
