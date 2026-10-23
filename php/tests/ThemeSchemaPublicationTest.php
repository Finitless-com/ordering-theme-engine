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
            'components.card.base.padding.removed',
            'components.card.base.radius.type_changed',
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
        $schema = ThemeSchemaFixture::compiled();
        $schema['migrate'] = [ $migrations[0] ];
        self::assertSame('#FFF8E1', ThemeResolver::valid([ 'components' => [ 'card' => [ 'base' => [ 'bg' => '#FFF8E1' ] ] ] ], $schema)['components']['card']['base']['background']);
    }

    public function testAMigrationGoesFromAKeyTheSchemaNoLongerHasToOneItHas(): void
    {
        $schema = ThemeSchemaFixture::compiled();
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
     * Prior behavior: kindAt() knew no visibility, image or page path, so
     * every migration of them was invalid.
     */
    public function testVisibilityImagesAndPagesHaveTheKindOfTheSchemasOwnKeys(): void
    {
        $schema = ThemeSchemaFixture::compiled();
        $kinds = fn (array $paths) => array_map(fn (string $path) => ThemeSchemas::kindAt($schema, $path), $paths);

        self::assertSame(
            [ 'bool', 'bool', 'image', 'image', 'page', 'bool', 'sections', 'section' ],
            $kinds([ 'visibility.footer', 'visibility.header.search', 'images.logotype', 'images.favicon', 'pages.home', 'pages.home.footer', 'pages.home.sections', 'pages.home.sections.hero' ]),
        );
        // What the schema does not have, or no theme stores.
        self::assertSame(
            array_fill(0, 9, null),
            $kinds([ 'visibility', 'visibility.header', 'images.nope', 'pages', 'pages.nope', 'pages.home.extra', 'pages.home.sections.nope', 'pages.home.sections.hero.visible', 'pages.checkout.sections.hero' ]),
        );

        // A version 1 path is a token's, a component's or a site setting's: the others carry theirs.
        $schema['v1'] = [ 'visibility.footer' => 'some.hidden', 'images.logotype' => 'some.image', 'pages.home.footer' => 'some.footer' ];
        self::assertSame([ 'v1.visibility.footer.invalid', 'v1.images.logotype.invalid', 'v1.pages.home.footer.invalid' ], array_column(ThemeSchemas::check($schema), 'code'));
    }

    public function testAMigrationOfVisibilityImagesOrPagesKeepsTheKindOfWhatItMoves(): void
    {
        $schema = ThemeSchemaFixture::compiled();
        $schema['migrate'] = [
            [ 'from' => 'visibility.header.brand', 'to' => 'visibility.header.search' ],
            [ 'from' => 'images.brand_logo', 'to' => 'images.logotype' ],
            [ 'from' => 'pages.shop', 'to' => 'pages.checkout' ],
            [ 'from' => 'pages.shop.footer', 'to' => 'pages.home.footer' ],
            [ 'from' => 'pages.shop.sections', 'to' => 'pages.home.sections' ],
            [ 'from' => 'pages.home.sections.banner', 'to' => 'pages.home.sections.featured' ],
            // Invalid from here: a key that is no key, across sections, and kinds that differ.
            [ 'from' => 'visibility.Header-Brand', 'to' => 'visibility.header.search' ],
            [ 'from' => 'images.', 'to' => 'images.logotype' ],
            [ 'from' => 'visibility.home.banner', 'to' => 'pages.home.sections.featured' ],
            [ 'from' => 'pages.shop.footer', 'to' => 'pages.home.sections.hero' ],
            [ 'from' => 'pages.shop', 'to' => 'pages.home.footer' ],
            [ 'from' => 'pages.shop.sections.hero', 'to' => 'pages.home' ],
            [ 'from' => 'pages.shop.sections.Hero', 'to' => 'pages.home.sections.hero' ],
            [ 'from' => 'pages.home.sections.hero.visible', 'to' => 'pages.home.sections.hero' ],
        ];

        self::assertSame(
            array_map(fn (int $index) => "migrate.{$index}.invalid", range(6, 13)),
            array_column(ThemeSchemas::check($schema), 'code'),
        );
    }

    /**
     * Prior behavior: a section's path named no value of the theme (its
     * sections are a list), and a platform's override was left where it was.
     */
    public function testASectionMovesAsAnItemOfItsListAndAPlatformFollows(): void
    {
        $section = fn (string $key, bool $visible) => [ 'key' => $key, 'visible' => $visible ];
        $apply = fn (array $values, string $from, string $to) => ThemeMigrations::apply($values, [ [ 'from' => $from, 'to' => $to ] ]);
        $home = [ 'pages' => [ 'home' => [ 'footer' => false, 'sections' => [ $section('hero', true), $section('banner', false), $section('content', true) ] ] ] ];

        // Within the page: renamed in its place, with its `visible`.
        self::assertSame(
            [ 'pages' => [ 'home' => [ 'footer' => false, 'sections' => [ $section('hero', true), $section('promo', false), $section('content', true) ] ] ] ],
            $apply($home, 'pages.home.sections.banner', 'pages.home.sections.promo'),
        );
        // The new key set already: the old one is dropped.
        self::assertSame(
            [ 'pages' => [ 'home' => [ 'footer' => false, 'sections' => [ $section('hero', true), $section('content', true) ] ] ] ],
            $apply($home, 'pages.home.sections.banner', 'pages.home.sections.content'),
        );
        // To another page: at the end of its list, created when it has none.
        self::assertSame(
            [ 'pages' => [
                'home' => [ 'footer' => false, 'sections' => [ $section('hero', true), $section('content', true) ] ],
                'business' => [ 'sections' => [ $section('offers', false) ] ],
            ] ],
            $apply($home, 'pages.home.sections.banner', 'pages.business.sections.offers'),
        );
        // A list left empty goes, with each map it leaves empty.
        self::assertSame(
            [ 'pages' => [ 'business' => [ 'sections' => [ $section('menu', true), $section('hero', true) ] ] ] ],
            $apply([ 'pages' => [ 'home' => [ 'sections' => [ $section('hero', true) ] ], 'business' => [ 'sections' => [ $section('menu', true) ] ] ] ], 'pages.home.sections.hero', 'pages.business.sections.hero'),
        );
        // Every item of the old key goes; the first one moves.
        self::assertSame(
            [ 'pages' => [ 'home' => [ 'sections' => [ $section('promo', false), $section('hero', true) ] ] ] ],
            $apply([ 'pages' => [ 'home' => [ 'sections' => [ $section('banner', false), $section('hero', true), $section('banner', true) ] ] ] ], 'pages.home.sections.banner', 'pages.home.sections.promo'),
        );
        // Onto itself, or between a section and anything else, nothing moves.
        self::assertSame($home, $apply($home, 'pages.home.sections.banner', 'pages.home.sections.banner'));
        self::assertSame($home, $apply($home, 'pages.home.sections.banner', 'pages.home.footer'));
        self::assertSame($home, $apply($home, 'pages.home.footer', 'pages.home.sections.banner'));

        // A platform's override moves by the same path: a visibility key, a page, a component property.
        // Each map left empty goes, and comes back last.
        self::assertSame(
            [
                'platforms' => [ 'mobile_web' => [
                    'visibility' => [ 'header.logo' => true ],
                    'pages' => [ 'business_listing' => [ 'footer' => true ] ],
                    'components' => [ 'card' => [ 'base' => [ 'radius' => '{radius.sm}' ] ] ],
                ] ],
                'visibility' => [ 'header.logo' => false ],
            ],
            ThemeMigrations::apply([
                'visibility' => [ 'header.brand' => false ],
                'platforms' => [ 'mobile_web' => [
                    'components' => [ 'card' => [ 'base' => [ 'corner' => '{radius.sm}' ] ] ],
                    'visibility' => [ 'header.brand' => true ],
                    'pages' => [ 'shop' => [ 'footer' => true ] ],
                ] ],
            ], [
                [ 'from' => 'visibility.header.brand', 'to' => 'visibility.header.logo' ],
                [ 'from' => 'pages.shop', 'to' => 'pages.business_listing' ],
                [ 'from' => 'components.card.base.corner', 'to' => 'components.card.base.radius' ],
            ]),
        );
    }

    /**
     * Prior behavior: only a color, a font role and the layout's mode had a
     * kind, so no other token could be migrated.
     */
    public function testEveryTokenHasTheKindOfItsGroupByTheSchemasOwnNames(): void
    {
        $schema = ThemeSchemaFixture::compiled();
        unset($schema['tokens']['typography']['label']);
        $kinds = fn (array $paths) => array_map(fn (string $path) => ThemeSchemas::kindAt($schema, $path), $paths);

        self::assertSame(
            [ 'color', 'font', 'layout_mode', 'layout', 'typography', 'opacity', 'radius', 'space', 'shadow_style', 'scrollbar' ],
            $kinds([ 'tokens.color.primary', 'tokens.font.body', 'tokens.layout.mode', 'tokens.layout.page_gutter', 'tokens.typography.h1', 'tokens.opacity.scrim', 'tokens.radius.md', 'tokens.space.xxxl', 'tokens.shadow_style.color', 'tokens.scrollbar.size' ]),
        );
        // A name the schema does not have (one it dropped, though the catalog has it), a group, or no token.
        self::assertSame(
            array_fill(0, 5, null),
            $kinds([ 'tokens.typography.label', 'tokens.typography.small', 'tokens.typography', 'tokens.nope.sm', 'tokens.radius.md.more' ]),
        );
    }

    /**
     * Prior behavior: a token other than a color had no kind (its
     * migrations were invalid), and a `from` the schema still has as a
     * group passed as one it no longer has.
     */
    public function testATokenMigratesByItsGroupAndAGroupStillThereIsNoFrom(): void
    {
        $schema = ThemeSchemaFixture::compiled();
        unset($schema['tokens']['typography']['label']);
        $schema['migrate'] = [
            [ 'from' => 'tokens.typography.label', 'to' => 'tokens.typography.body_sm' ],
            [ 'from' => 'tokens.opacity.dim', 'to' => 'tokens.opacity.scrim' ],
            [ 'from' => 'tokens.color.brand', 'to' => 'tokens.color.primary' ],
            // Invalid from here: groups the schema still has.
            [ 'from' => 'tokens.color', 'to' => 'tokens.color.primary' ],
            [ 'from' => 'components.button.base', 'to' => 'components.button.base.radius' ],
            [ 'from' => 'components.card.variants.default', 'to' => 'components.card.base.radius' ],
        ];

        self::assertSame([ 'migrate.3.invalid', 'migrate.4.invalid', 'migrate.5.invalid' ], array_column(ThemeSchemas::check($schema), 'code'));
    }

    /**
     * Prior behavior: a color's value moved, but not its value in each
     * mode, and every alias to it was left naming a color the schema no
     * longer has.
     */
    public function testATokenMovesWithItsModesAndEveryAliasToIt(): void
    {
        $values = [
            'tokens' => [ 'color' => [ 'brand' => '#E4002B', 'accent' => '#00AA55', 'secondary' => '#123456' ], 'typography' => [ 'overline' => [ 'size' => 11 ] ] ],
            'modes' => [ 'dark' => [ 'color' => [ 'brand' => '#FF5A5F', 'accent' => '#33CC77' ] ] ],
            'components' => [
                'button' => [ 'colors' => [ 'primary' => [ 'main' => '{color.brand}', 'on' => '{color.on_primary}' ] ] ],
                'card' => [ 'base' => [ 'border' => [ 'width' => 1, 'color' => '{color.accent}' ] ] ],
                'header' => [ 'base' => [ 'title' => '{typography.overline}' ] ],
            ],
            'platforms' => [ 'mobile_web' => [ 'components' => [ 'card' => [ 'base' => [ 'background' => '{color.brand}' ] ] ] ] ],
        ];

        self::assertSame(
            [
                'tokens' => [ 'color' => [ 'secondary' => '#123456', 'primary' => '#E4002B' ], 'typography' => [ 'caption' => [ 'size' => 11 ] ] ],
                'modes' => [ 'dark' => [ 'color' => [ 'primary' => '#FF5A5F', 'secondary' => '#33CC77' ] ] ],
                'components' => [
                    'button' => [ 'colors' => [ 'primary' => [ 'main' => '{color.primary}', 'on' => '{color.on_primary}' ] ] ],
                    'card' => [ 'base' => [ 'border' => [ 'width' => 1, 'color' => '{color.secondary}' ] ] ],
                    'header' => [ 'base' => [ 'title' => '{typography.caption}' ] ],
                ],
                'platforms' => [ 'mobile_web' => [ 'components' => [ 'card' => [ 'base' => [ 'background' => '{color.primary}' ] ] ] ] ],
            ],
            ThemeMigrations::apply($values, [
                [ 'from' => 'tokens.color.brand', 'to' => 'tokens.color.primary' ],
                // The theme sets the new color already: its value wins, the aliases go to it.
                [ 'from' => 'tokens.color.accent', 'to' => 'tokens.color.secondary' ],
                [ 'from' => 'tokens.typography.overline', 'to' => 'tokens.typography.caption' ],
            ]),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function schema(string $version): array
    {
        return [ 'version' => $version ] + ThemeSchemaFixture::nodes();
    }
}
