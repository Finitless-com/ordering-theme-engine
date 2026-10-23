<?php

declare(strict_types=1);

namespace Ordering\ThemeEngine\Tests;

use Ordering\ThemeEngine\Schema\NodeCompiler;
use Ordering\ThemeEngine\Schema\SchemaDiff;
use Ordering\ThemeEngine\ThemeMigrations;
use Ordering\ThemeEngine\ThemeResolver;
use Ordering\ThemeEngine\ThemeSchemaPublisher;
use Ordering\ThemeEngine\ThemeUpgrades;
use Ordering\ThemeEngine\ThemeValidator;
use PHPUnit\Framework\TestCase;

/**
 * What a change of a theme schema does to the themes and settings saved
 * with an earlier version, and what publishing it says, case by case: the
 * regressions of a campaign of schema changes, over the reference schema.
 * The golden cases (fixtures/valid.json, upgrade.json, validate.json)
 * carry the same cases to the TypeScript engine.
 */
class ThemeSchemaChangesTest extends TestCase
{
    /**
     * Prior behavior: leaving out a section that was not the last one
     * turned the page's list into a map (`{"1": …}`), which the next
     * validation refused whole and the editor could not save.
     */
    public function testASectionLeftOutKeepsItsPagesListAList(): void
    {
        $schema = ThemeSchemaFixture::compiled();
        $values = [ 'pages' => [ 'home' => [ 'sections' => [ [ 'key' => 'nope', 'visible' => false ], [ 'key' => 'featured', 'visible' => false ] ] ] ] ];

        $valid = ThemeResolver::valid($values, $schema);
        self::assertSame([ [ 'key' => 'featured', 'visible' => false ] ], $valid['pages']['home']['sections']);
        self::assertSame([], ThemeValidator::validate($valid, $schema));
        self::assertSame($valid, ThemeUpgrades::cutDown($values, [ $schema ]));
    }

    /**
     * Prior behavior: a migration of a site setting within a base was
     * accepted and reported `migrated`, but the setting kept its old name
     * and was left out as unknown: its value was lost.
     */
    public function testAMigrationOfASettingWithinABaseMovesIt(): void
    {
        $schema = ThemeSchemaFixture::compiled();
        $schema['site']['analytics_only'] = $schema['site']['tracking_only'];
        unset($schema['site']['tracking_only']);
        $schema['migrate'] = [ [ 'from' => 'site.tracking_only', 'to' => 'site.analytics_only' ] ];

        self::assertSame([ 'name' => 'Shop', 'analytics_only' => true ], ThemeResolver::validSettings([ 'tracking_only' => true, 'name' => 'Shop' ], $schema));
        self::assertSame([ 'analytics_only' => true ], ThemeUpgrades::settingsChain([ 'tracking_only' => true ], [ $schema ]));
    }

    /**
     * Prior behavior: SchemaDiff ignored every key of the editor, so a
     * patch could take a color's `alpha` away (and the translucent values
     * saved with it), or change the limits a value is validated with.
     */
    public function testWhatTheEditorValidatesIsARule(): void
    {
        $old = self::nodes('1.1.0');
        $old['components']['card']['base']['background']['$extensions']['co.ordering.editor']['alpha'] = true;

        $patch = self::nodes('1.1.1');
        $patch['components']['card']['base']['background']['$extensions']['co.ordering.editor']['alpha'] = true;
        $patch['components']['button']['base']['icon_gap']['$extensions']['co.ordering.editor']['max'] = 64;
        self::assertSame([ 'schema.patch_changes_values' ], array_column(SchemaDiff::compare($old, $patch)['errors'], 'code'));
        // Texts, the step and the unit validate nothing.
        $patch['components']['button']['base']['icon_gap']['$extensions']['co.ordering.editor'] = [ 'max' => 32, 'step' => 4, 'unit' => 'px' ] + $old['components']['button']['base']['icon_gap']['$extensions']['co.ordering.editor'];
        $patch['components']['button']['base']['icon_gap']['$description'] = 'The gap.';
        self::assertSame([], SchemaDiff::compare($old, $patch)['errors']);

        $minor = self::nodes('1.2.0');
        $minor['components']['button']['base']['icon_gap']['$extensions']['co.ordering.editor']['max'] = 24;
        unset($minor['components']['input']['base']['text']['$extensions']['co.ordering.editor']['custom']);
        $diff = SchemaDiff::compare($old, $minor);
        self::assertSame([ 'components.input.base.text.custom_removed', 'components.card.base.background.alpha_removed' ], array_column($diff['errors'], 'code'));
        self::assertSame([ 'components.button.base.icon_gap', 'components.input.base.text', 'components.card.base.background' ], $diff['changes']['rules_changed']);
    }

    /**
     * Prior behavior: a property with a default of its own on a platform
     * could not be migrated nor moved: the platform's default was
     * `.removed` in a minor and `.unaccounted` in a new base.
     */
    public function testAPlatformsDefaultGoesWithItsProperty(): void
    {
        $old = self::nodes('1.1.0');
        $moved = function (array $schema): array {
            $schema['components']['banner']['base']['per_row'] = $schema['components']['banner']['base']['columns'];
            $schema['components']['banner']['platforms']['mobile_web']['per_row'] = $schema['components']['banner']['platforms']['mobile_web']['columns'];
            unset($schema['components']['banner']['base']['columns'], $schema['components']['banner']['platforms']['mobile_web']['columns']);

            return $schema;
        };
        $minor = $moved(self::nodes('1.2.0'));
        $minor['$extensions'] = [ 'co.ordering.migrate' => [ [ 'from' => 'components.banner.base.columns', 'to' => 'components.banner.base.per_row' ] ] ];
        $diff = SchemaDiff::compare($old, $minor);
        self::assertSame([], $diff['errors']);
        self::assertSame([ 'components.banner.base.columns', 'components.banner.platforms.mobile_web.columns' ], $diff['changes']['migrated']);

        $base = $moved(self::nodes('2.0.0'));
        $base['$extensions'] = [ 'co.ordering.upgrade' => [ 'from' => 1, 'moves' => [ [ 'from' => 'components.banner.base.columns', 'to' => 'components.banner.base.per_row' ] ], 'drop' => [] ] ];
        self::assertSame([], ThemeUpgrades::check($base, $old));
        // And the theme's override moves with the value.
        self::assertSame(
            [ 'components' => [ 'banner' => [ 'base' => [ 'per_row' => 4 ] ] ], 'platforms' => [ 'mobile_web' => [ 'components' => [ 'banner' => [ 'base' => [ 'per_row' => 2 ] ] ] ] ] ],
            ThemeUpgrades::apply(
                [ 'components' => [ 'banner' => [ 'base' => [ 'columns' => 4 ] ] ], 'platforms' => [ 'mobile_web' => [ 'components' => [ 'banner' => [ 'base' => [ 'columns' => 2 ] ] ] ] ] ],
                NodeCompiler::compile($base)['schema'],
                NodeCompiler::compile($old)['schema'],
            ),
        );
    }

    /**
     * Prior behavior: a map over a boolean passed the checks and dropped
     * every value, since a map translated only strings and integers.
     */
    public function testAMapTranslatesABooleanByTrueAndFalse(): void
    {
        $map = [ 'true' => 'on', 'false' => 'off' ];
        self::assertSame([ 'site' => [ 'kiosk' => 'on' ] ], ThemeMigrations::apply([ 'site' => [ 'kiosk_only' => true ] ], [ [ 'from' => 'site.kiosk_only', 'to' => 'site.kiosk', 'map' => $map ] ]));
        self::assertSame([ 'visibility' => [ 'prices' => true ] ], ThemeMigrations::apply([ 'visibility' => [ 'hide_prices' => false ] ], [ [ 'from' => 'visibility.hide_prices', 'to' => 'visibility.prices', 'map' => [ 'true' => false, 'false' => true ] ] ]));
    }

    /**
     * Prior behavior: a mode's colors were checked against the engine's
     * catalog, not the schema's own colors: a color the schema does not
     * name was saved, served and left on a client cut down to it.
     */
    public function testAModesColorsAreTheSchemasOwn(): void
    {
        $schema = ThemeSchemaFixture::compiled();
        $values = [ 'modes' => [ 'dark' => [ 'color' => [ 'primary' => '#111111', 'danger_text' => '#B02A3E' ] ] ] ];

        self::assertSame([ 'modes.dark.color.danger_text.unknown' ], ThemeValidator::codes(ThemeValidator::validate($values, $schema)));
        self::assertSame([ 'modes' => [ 'dark' => [ 'color' => [ 'primary' => '#111111' ] ] ] ], ThemeResolver::valid($values, $schema));
    }

    /**
     * Prior behavior: a client of an older version got the default of every
     * key a newer version renamed, though the theme had a value for it.
     */
    public function testACutDownUndoesWhatANewerVersionRenamed(): void
    {
        $older = self::nodes('1.1.0');
        $newer = self::nodes('1.2.0');
        $newer['visibility']['header.badge'] = $newer['visibility']['header.search'];
        unset($newer['visibility']['header.search']);
        $newer['$extensions'] = [ 'co.ordering.migrate' => [ [ 'from' => 'visibility.header.search', 'to' => 'visibility.header.badge' ] ] ];

        self::assertSame(
            [ 'visibility' => [ 'header.search' => false ], 'platforms' => [ 'mobile_web' => [ 'visibility' => [ 'header.search' => true ] ] ] ],
            ThemeUpgrades::cutDown(
                [ 'visibility' => [ 'header.badge' => false ], 'platforms' => [ 'mobile_web' => [ 'visibility' => [ 'header.badge' => true ] ] ] ],
                [ NodeCompiler::compile($newer)['schema'], NodeCompiler::compile($older)['schema'] ],
            ),
        );
    }

    /**
     * Prior behavior: the editor's limits and an image's formats were only
     * the editor's and the upload's: a theme saved a number out of range or
     * an image of a format its key does not take, and the clients got it;
     * then a stored number out of range was read as its default, so a
     * tightened limit lost the value instead of keeping the closest one.
     */
    public function testANumberOutOfItsLimitsOrAnImageOfAnotherFormatIsRefusedAndTheNumberIsReadClamped(): void
    {
        $schema = ThemeSchemaFixture::compiled();
        $values = [
            'tokens' => [ 'size' => [ 'lg' => 90 ], 'typography' => [ 'h1' => [ 'size' => 30, 'line' => 2.5, 'weight' => 600 ] ] ],
            'components' => [ 'header' => [ 'base' => [ 'logo_width' => 400, 'icon_size' => '{icon.sm}' ] ] ],
            'images' => [ 'favicon' => 'https://cdn.example.com/favicon.svg', 'logotype' => 'https://cdn.example.com/logo.svg' ],
        ];

        self::assertSame([
            'images.favicon.format',
            'tokens.size.lg.out_of_range',
            'tokens.typography.h1.line.out_of_range',
            'components.header.base.logo_width.out_of_range',
        ], ThemeValidator::codes(ThemeValidator::validate($values, $schema)));
        self::assertSame(
            [
                'tokens' => [ 'size' => [ 'lg' => 64 ], 'typography' => [ 'h1' => [ 'size' => 30, 'line' => 1.6, 'weight' => 600 ] ] ],
                'components' => [ 'header' => [ 'base' => [ 'logo_width' => 320, 'icon_size' => '{icon.sm}' ] ] ],
                'images' => [ 'logotype' => 'https://cdn.example.com/logo.svg' ],
            ],
            array_filter(ThemeResolver::valid($values, $schema), fn ($section) => $section !== []),
        );
    }

    /**
     * Prior behavior: a page's sections were one value for SchemaDiff, so a
     * minor could drop a section or change its `hideable` unnoticed.
     */
    public function testEachSectionOfAPageIsAKey(): void
    {
        $old = self::nodes('1.1.0');
        $new = self::nodes('1.2.0');
        $sections = $old['pages']['checkout']['sections']['$value'];
        $new['pages']['checkout']['sections']['$value'] = [ [ 'hideable' => false ] + $sections[1], $sections[0] ];

        $diff = SchemaDiff::compare($old, $new);
        self::assertSame([ 'pages.checkout.sections.notes.removed' ], array_column($diff['errors'], 'code'));
        self::assertSame([ 'pages.checkout.sections.tips', 'pages.checkout.sections' ], $diff['changes']['sections_changed']);
    }

    /**
     * Prior behavior: a version 1 map could name what the schema does not
     * have: the conversion of a version 1 site lost it without a word.
     */
    public function testAVersionOneMapNamesOnlyWhatTheSchemaHas(): void
    {
        $schema = ThemeSchemaFixture::compiled();
        self::assertSame([
            [ 'path' => 'v1_map.v1.tokens.color.nope', 'code' => 'v1_map.v1.tokens.color.nope.unknown' ],
            [ 'path' => 'v1_map.images.nope', 'code' => 'v1_map.images.nope.unknown' ],
            [ 'path' => 'v1_map.pages.home.nope', 'code' => 'v1_map.pages.home.nope.unknown' ],
            [ 'path' => 'v1_map.site.nope', 'code' => 'v1_map.site.nope.unknown' ],
        ], ThemeSchemaPublisher::v1MapErrors($schema, [
            'description' => 'Not a path.',
            'v1' => [ 'tokens.color.primary' => 'a.b', 'tokens.color.nope' => 'a.c' ],
            'visibility' => [ 'footer' => 'footer.hidden' ],
            'images' => [ 'logotype' => null, 'nope' => null ],
            'pages' => [ 'home' => [ 'featured' => [ 'v1' => 'x.hidden' ], 'nope' => [ 'v1' => 'y.hidden' ] ] ],
            'site' => [ 'name' => 'a.d', 'nope' => 'a.e' ],
        ]));
    }

    /**
     * Prior behavior: in development the previous version refused nothing
     * and said nothing; and the same version with the same content was
     * `schema.version_order` where the API answers `unchanged`.
     */
    public function testDevelopmentWarnsOfWhatProductionRefusesAndTheSameContentIsUnchanged(): void
    {
        $base = self::nodes('1.0.0');
        $changed = $base;
        $changed['tokens']['color']['primary']['$value'] = '#123456';

        self::assertSame([ 'code' => null, 'errors' => [], 'warnings' => [], 'changes' => null, 'unchanged' => true ], ThemeSchemaPublisher::validate($base, null, $base));
        self::assertSame('schema.version_exists', ThemeSchemaPublisher::validate($changed, null, $base)['code']);
        $development = ThemeSchemaPublisher::validate($changed, null, $base, true);
        self::assertNull($development['code']);
        self::assertSame([ 'schema.version_exists', 'schema.patch_changes_values' ], array_column($development['warnings'], 'code'));
        self::assertSame([ 'tokens.color.primary' ], $development['changes']['defaults_changed']);
    }

    /**
     * @return array<string, mixed>
     */
    private static function nodes(string $version): array
    {
        return [ 'version' => $version ] + ThemeSchemaFixture::nodes();
    }
}
