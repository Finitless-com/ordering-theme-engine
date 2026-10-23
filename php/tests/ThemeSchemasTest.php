<?php

declare(strict_types=1);

namespace Ordering\ThemeEngine\Tests;

use Ordering\ThemeEngine\ThemeDerivations;
use Ordering\ThemeEngine\ThemeOverall;
use Ordering\ThemeEngine\ThemeResolver;
use Ordering\ThemeEngine\ThemeSchemas;
use Ordering\ThemeEngine\ThemeValidator;
use PHPUnit\Framework\TestCase;

/**
 * A product type's theme schema (api-core's docs/THEMES_V2_PLAN.md §2.2): what one may
 * hold, the complete theme its defaults make, and how a theme resolves over
 * them.
 */
class ThemeSchemasTest extends TestCase
{
    public function testTheWebMarketplaceSchemaIsValidAndItsDefaultsFitIt(): void
    {
        $schema = ThemeSchemaFixture::webMarketplace();
        self::assertSame([], ThemeSchemas::check($schema));

        $defaults = ThemeSchemas::defaults($schema);
        // The schema as it ships has two defaults above their control's max
        // (which publishing refuses, `default_out_of_range`): saved by a
        // theme, they are out of range too.
        self::assertSame([
            [ 'path' => 'components.signup_page.base.padding_y', 'code' => 'components.signup_page.base.padding_y.out_of_range' ],
            [ 'path' => 'components.signup_page.base.padding_x', 'code' => 'components.signup_page.base.padding_x.out_of_range' ],
        ], ThemeValidator::validate($defaults, $schema));
        self::assertSame('{radius.md}', $defaults['components']['button']['base']['radius']);
        self::assertSame('{color.primary}', $defaults['components']['button']['colors']['primary']['main']);
        self::assertSame('{ink}', $defaults['components']['button']['appearances']['outline']['color']);
        self::assertTrue($defaults['visibility']['footer']);
        self::assertTrue($defaults['visibility']['menu.wallet']);
        self::assertSame('original', $defaults['tokens']['layout']['mode']);
        self::assertSame([ 'mobile_web' ], array_keys($defaults['platforms']));
        self::assertSame([ 'components' ], array_keys($defaults['platforms']['mobile_web']));
        self::assertSame([ 'base' => [ 'radius' => '{radius.none}' ] ], $defaults['platforms']['mobile_web']['components']['modal']);
        self::assertSame([ 'base' => [ 'padding_y' => '{space.xxl}', 'padding_x' => '{space.lg}', 'min_height' => 0, 'image_size' => 160, 'image_gap' => '{space.lg}' ] ], $defaults['platforms']['mobile_web']['components']['empty_state']);
        self::assertSame(array_keys($schema['pages']), array_keys($defaults['pages']));
        self::assertNull($defaults['pages']['checkout']['footer']);
        self::assertSame([ 'key' => 'business', 'visible' => true ], $defaults['pages']['checkout']['sections'][2]);
        self::assertSame([ 'key' => 'near_business', 'visible' => false ], $defaults['pages']['business']['sections'][array_search('near_business', array_column($defaults['pages']['business']['sections'], 'key'), true)]);
    }

    public function testASchemaSaysWhatItGetsWrong(): void
    {
        $schema = ThemeSchemaFixture::webMarketplace();
        $schema['product_type'] = 'spaceship';
        unset($schema['tokens']['radius']['lg']);
        $schema['tokens']['radius']['md'] = '10px';
        $schema['components']['button']['base']['radius'] = [ 'radius', 15 ];
        $schema['components']['header']['base']['layout'] = [ 'layout', 'neon', [ 'original', 'starbucks' ] ];
        $schema['components']['card']['base']['glow'] = [ 'sparkle', true ];
        $schema['components']['modal']['platforms']['tv'] = [ 'radius' => [ 'radius', '{radius.none}' ] ];
        $schema['visibility']['Footer Links'] = [ true, 'footer.hidden' ];
        $schema['v1']['components.button.base.glow'] = 'general.components.buttons.glow';
        // A radius cannot read the primary color's version 1 key.
        $schema['v1']['components.modal.base.radius'] = $schema['v1']['tokens.color.primary'];
        $schema['inherits']['product_card'] = 'nope';
        $schema['inherits']['menu'] = 'menu';
        $schema['extra'] = [];

        self::assertSame([
            'product_type.invalid',
            'extra.unknown',
            'tokens.radius.lg.required',
            'tokens.radius.md.invalid',
            'components.button.base.radius.default.invalid',
            'components.modal.platforms.tv.unknown',
            'components.card.base.glow.invalid',
            'components.header.base.layout.default.invalid',
            'inherits.product_card.invalid',
            'inherits.menu.invalid',
            'visibility.Footer Links.invalid',
            'v1.components.modal.base.radius.duplicate',
            'v1.components.button.base.glow.invalid',
        ], ThemeValidator::codes(ThemeSchemas::check($schema)));
    }

    /**
     * Prior behavior: every schema had to have every color, text style and
     * opacity of the engine's catalog, so a product could neither drop one
     * nor name a new one. Now it names its own, and refers only to those.
     */
    public function testASchemaNamesItsOwnColorsTextStylesAndOpacities(): void
    {
        $schema = ThemeSchemaFixture::webMarketplace();
        unset($schema['tokens']['color']['favorite'], $schema['derive']['favorite']);
        $schema['tokens']['color']['danger_text'] = '#B02A3E';
        $own = fn (array $schema) => array_values(array_filter(ThemeValidator::codes(ThemeSchemas::check($schema)), fn ($code) => str_contains($code, 'favorite') || str_contains($code, 'danger_text')));

        // The components that painted the dropped color say so; the new one is no error.
        self::assertNotSame([], $own($schema));
        self::assertSame([], array_filter($own($schema), fn ($code) => !str_ends_with($code, '.alias')));
        self::assertContains('components.favorite.base.color.default.alias', $own($schema));

        // Without the references, the schema is valid with its own palette.
        $schema['components'] = json_decode(str_replace('{color.favorite}', '{color.danger_text}', json_encode($schema['components'])), true);
        self::assertSame([], ThemeSchemas::check($schema));
        // A group it names itself is never empty.
        $schema['tokens']['opacity'] = [];
        self::assertContains('tokens.opacity.required', ThemeValidator::codes(ThemeSchemas::check($schema)));
    }

    public function testAThemeResolvesOverItsSchemasDefaults(): void
    {
        $schema = ThemeSchemaFixture::webMarketplace();
        $values = ThemeResolver::resolveAliases(ThemeResolver::merge(ThemeSchemas::defaults($schema), [
            'tokens' => [ 'color' => [ 'primary' => '#FF0000' ], 'radius' => [ 'md' => 12 ], 'font' => [ 'heading' => 'playfair_display' ] ],
            'components' => [ 'button' => [ 'appearances' => [ 'outline' => [ 'border' => [ 'width' => '{border.thick}', 'color' => '{ink}', 'style' => 'dashed' ] ] ] ] ],
        ]));
        $values['components'] = ThemeDerivations::matrices($values['components'], $values['tokens']['color'], [], ThemeDerivations::rules($schema)['matrix']);
        $button = $values['components']['button'];

        self::assertSame(12, $button['base']['radius']);
        self::assertSame([ 'size' => 16, 'line' => 1.88, 'weight' => 400, 'family' => 'archivo' ], $button['sizes']['md']['text']);
        // Every color in every appearance.
        self::assertSame([ 'primary', 'secondary', 'danger', 'success', 'neutral', 'inverse' ], array_keys($button['variants']));
        self::assertSame([ 'solid', 'outline', 'tonal', 'ghost' ], array_keys($button['variants']['danger']));
        self::assertSame('#FF0000', $button['variants']['primary']['solid']['background']);
        self::assertSame([ 'width' => 2, 'color' => '#E63757', 'style' => 'dashed' ], $button['variants']['danger']['outline']['border']);
        self::assertSame([ 'width' => 2, 'color' => '#000000', 'style' => 'dashed' ], $button['variants']['neutral']['outline']['border']);
        // A color's subtle and pressed derive from it unless it sets them.
        self::assertSame('#FCE7EB', $button['variants']['danger']['tonal']['background']);
        self::assertSame('#E63757', $button['variants']['danger']['tonal']['color']);
        // A pressed tonal button fills like a pressed outline one.
        self::assertSame($button['variants']['danger']['outline']['states']['pressed'], $button['variants']['danger']['tonal']['states']['pressed']);
        self::assertSame('#E63757', $button['variants']['danger']['tonal']['states']['pressed']['background']);
        self::assertSame('playfair_display', $values['tokens']['typography']['h1']['family']);
        self::assertSame('archivo', $values['tokens']['typography']['body']['family']);
        self::assertSame([ 'x' => 0, 'y' => 1, 'blur' => 4, 'spread' => 0, 'color' => '#000000', 'opacity' => 0.1, 'elevation' => 1 ], $values['components']['menu']['base']['shadow']);
        self::assertSame(0, $values['platforms']['mobile_web']['components']['modal']['base']['radius']);
    }

    /**
     * What a theme sets on the generic card also goes to the specific
     * cards, unless it sets the same property on one of them.
     */
    public function testASpecificCardFollowsTheGenericOne(): void
    {
        $schema = ThemeSchemaFixture::webMarketplace();
        $border = [ 'width' => '{border.thick}', 'color' => '{color.border}', 'style' => 'solid' ];

        $values = ThemeResolver::inherit([ 'components' => [
            'card' => [ 'base' => [ 'radius' => '{radius.none}', 'border' => $border, 'font_role' => 'heading' ] ],
            'product_card' => [ 'base' => [ 'radius' => '{radius.xl}' ] ],
        ] ], $schema);

        self::assertSame([ 'radius' => '{radius.none}', 'border' => $border, 'font_role' => 'heading' ], $values['components']['business_card']['base']);
        self::assertSame([ 'radius' => '{radius.xl}', 'border' => $border, 'font_role' => 'heading' ], $values['components']['product_card']['base']);
        self::assertSame([ 'radius' => '{radius.none}', 'border' => $border, 'font_role' => 'heading' ], $values['components']['order_card']['base']);
        // Only components that inherit, and only what the theme sets.
        self::assertArrayNotHasKey('order_type_card', $values['components']);
        self::assertSame([], ThemeResolver::inherit([], $schema));
    }

    /**
     * The search bar follows the Field, its states included, unless the
     * theme sets the same property on the search bar.
     */
    public function testTheSearchBarFollowsTheField(): void
    {
        $schema = ThemeSchemaFixture::webMarketplace();
        $focus = [ 'width' => '{border.thick}', 'color' => '{color.focus}', 'style' => 'dashed' ];

        $values = ThemeResolver::inherit([ 'components' => [
            'input' => [ 'base' => [ 'radius' => '{radius.xl}', 'background' => '#F0F0F0' ], 'variants' => [ 'default' => [ 'states' => [ 'focus' => [ 'border' => $focus ] ] ] ] ],
            'search_bar' => [ 'base' => [ 'radius' => '{radius.full}' ] ],
        ] ], $schema);
        $sized = ThemeResolver::inherit([ 'components' => [ 'input' => [ 'sizes' => [ 'lg' => [ 'height' => 52 ] ] ] ] ], $schema);

        self::assertSame([ 'radius' => '{radius.full}', 'background' => '#F0F0F0' ], $values['components']['search_bar']['base']);
        self::assertSame([ 'default' => [ 'states' => [ 'focus' => [ 'border' => $focus ] ] ] ], $values['components']['search_bar']['variants']);
        self::assertArrayNotHasKey('underline', $schema['components']['input']['variants']);
        // Its sizes too.
        self::assertSame([ 'lg' => [ 'height' => 52 ] ], $sized['components']['search_bar']['sizes']);
    }

    public function testASelectedChipIsSecondary(): void
    {
        $selected = ThemeSchemas::defaults(ThemeSchemaFixture::webMarketplace())['components']['chip']['variants']['default']['states']['selected'];

        self::assertSame([ 'background' => '{color.secondary}', 'color' => '{color.on_secondary}', 'border' => null ], $selected);
    }

    /**
     * A platform changes a property of the component's base, of the same
     * kind. Prior behavior: a mobile override of another kind registered,
     * and its default failed only when served.
     */
    public function testAPlatformChangesABasePropertyOfTheSameKind(): void
    {
        $schema = ThemeSchemaFixture::webMarketplace();
        $schema['components']['modal']['platforms']['mobile_web'] = [ 'radius' => [ 'number', 0 ], 'margin' => [ 'number', 0 ] ];
        unset($schema['components']['empty_state']['platforms']);

        self::assertSame(
            [ 'components.modal.platforms.mobile_web.radius.invalid', 'components.modal.platforms.mobile_web.margin.invalid' ],
            ThemeValidator::codes(ThemeSchemas::check($schema))
        );
    }

    public function testButtonSizesAreTheSizeTokens(): void
    {
        $schema = ThemeSchemaFixture::webMarketplace();
        $sizes = ThemeOverall::apply(ThemeSchemas::defaults($schema), $schema, [ 'radius' => 'xl' ])['components']['button']['sizes'];

        // xs is as tall as a large icon (the small round buttons).
        self::assertSame([
            'xs' => [ 'height' => '{icon.lg}', 'icon_size' => '{icon.md}', 'padding_x' => '{space.sm}' ],
            'sm' => [ 'height' => '{size.sm}', 'icon_size' => '{icon.sm}', 'padding_x' => '{space.lg}', 'text' => '{typography.button}' ],
            'md' => [ 'height' => '{size.md}', 'icon_size' => '{icon.md}', 'padding_x' => '{space.lg}', 'text' => '{typography.button}' ],
            'lg' => [ 'height' => '{size.lg}', 'icon_size' => '{icon.lg}', 'padding_x' => '{space.lg}', 'text' => '{typography.button}' ],
        ], $sizes);
        // The height is only in the sizes (a button without one is `md`); the
        // rounding and text of every size are the base's.
        self::assertArrayNotHasKey('height', $schema['components']['button']['base']);
    }

    /**
     * An icon button is a button of the icon shape, in any color, appearance
     * and size: there is no icon_button component.
     */
    public function testAnIconButtonIsTheButtonsIconShape(): void
    {
        $schema = ThemeSchemaFixture::webMarketplace();

        self::assertSame([ 'icon' => [ 'radius' => [ 'radius', '{radius.md}' ] ] ], $schema['components']['button']['shapes']);
        self::assertArrayNotHasKey('icon_button', $schema['components']);
        self::assertSame([], ThemeSchemas::check($schema));
    }

    /**
     * The theme picks the tabs' variant; plain has no indicator and does
     * not replace underline. Each variant draws its own container: a pill
     * is a segmented control, an underline a baseline under the whole bar.
     */
    public function testTabsHaveADefaultVariant(): void
    {
        $schema = ThemeSchemaFixture::webMarketplace();
        $tabs = $schema['components']['tabs'];
        $defaults = ThemeSchemas::defaults($schema)['components']['tabs'];

        self::assertSame([ 'option', 'pill', [ 'pill', 'underline', 'plain' ] ], $tabs['base']['variant']);
        self::assertSame([ 'variant', 'text', 'icon_gap', 'animated', 'speed' ], array_keys($tabs['base']));
        self::assertSame([ 'pill', 'underline', 'plain' ], array_keys($tabs['variants']));
        self::assertSame([
            // The shadow and the tab's rounding follow Identity: their defaults are Identity's.
            'background' => '{color.surface_alt}', 'border' => null, 'radius' => '{radius.md}', 'shadow' => '{shadow.sm}',
            'inset' => '{space.xs}', 'gap' => '{space.xxs}', 'joined' => false, 'tab_radius' => '{radius.md}', 'tab_border' => null,
            'active_background' => '{color.primary_subtle}', 'active' => '{color.primary_text}', 'inactive' => '{color.text_muted}',
        ], $defaults['variants']['pill']);
        self::assertSame([ 'width' => 1, 'color' => '{color.border}', 'style' => 'solid' ], $defaults['variants']['underline']['baseline']);
        self::assertSame([ 'option', 'full', [ 'full', 'medium', 'small' ] ], $tabs['variants']['underline']['indicator_length']);
        self::assertSame([ 'background' => 'transparent', 'border' => null, 'shadow' => '{shadow.sm}', 'active_background' => 'transparent', 'gap' => '{space.sm}', 'active' => '{color.text}', 'inactive' => '{color.text_muted}' ], $defaults['variants']['plain']);
        // Every container casts Identity's shadow, a transparent one too; a transparent button does not.
        $lg = ThemeOverall::apply(ThemeSchemas::defaults($schema), $schema, [ 'shadow' => 'lg' ])['components'];
        self::assertSame([ '{shadow.lg}', '{shadow.lg}', '{shadow.lg}' ], [ $lg['tabs']['variants']['pill']['shadow'], $lg['tabs']['variants']['underline']['shadow'], $lg['tabs']['variants']['plain']['shadow'] ]);
        self::assertSame([ null, null ], [ $lg['button']['appearances']['outline']['shadow'], $lg['button']['appearances']['ghost']['shadow'] ]);
        // Every container's border follows Identity's; the baseline does not.
        $thin = ThemeOverall::apply(ThemeSchemas::defaults($schema), $schema, [ 'border_width' => 'thin' ])['components']['tabs']['variants'];
        self::assertSame([ '{border.thin}', '{border.thin}', '{border.thin}', 1 ], [ $thin['pill']['border']['width'], $thin['underline']['border']['width'], $thin['plain']['border']['width'], $thin['underline']['baseline']['width'] ]);
        self::assertSame('transparent', $defaults['variants']['underline']['active_background']);
        self::assertSame([ 'height' => '{size.md}', 'padding_x' => '{space.md}', 'icon_size' => '{icon.md}' ], $defaults['sizes']['md']);
        // The baseline is a number: Identity's border does not erase it.
        self::assertSame([ 'width' => 1, 'color' => '{color.border}', 'style' => 'solid' ], ThemeOverall::apply(ThemeSchemas::defaults($schema), $schema, [ 'border_width' => 'none' ])['components']['tabs']['variants']['underline']['baseline']);
        // Tabs with no space between them may join into one block.
        self::assertSame([], ThemeValidator::validate([ 'components' => [ 'tabs' => [ 'variants' => [ 'pill' => [ 'gap' => '{space.none}', 'joined' => true ] ] ] ] ], $schema));
        self::assertSame([ 'components.tabs.base.radius.unknown' ], array_column(ThemeValidator::validate([ 'components' => [ 'tabs' => [ 'base' => [ 'radius' => '{radius.md}' ] ] ] ], $schema), 'code'));
    }

    /**
     * Paddings, gaps and insets are of the space tokens.
     */
    public function testSpacesAreOfTheSpaceTokens(): void
    {
        $schema = ThemeSchemaFixture::webMarketplace();

        self::assertSame([ 'none' => 0, 'xxs' => 2, 'xs' => 4, 'sm' => 8, 'md' => 12, 'lg' => 16, 'xl' => 24, 'xxl' => 32, 'xxxl' => 48 ], $schema['tokens']['space']);
        // A space may also be a number off the scale (a field's 20).
        self::assertSame([ 'space', 20 ], $schema['components']['input']['base']['padding_x']);
        self::assertSame([], ThemeValidator::validate([
            'tokens' => [ 'space' => [ 'md' => 14 ] ],
            'components' => [ 'tabs' => [ 'variants' => [ 'pill' => [ 'inset' => 6, 'gap' => '{space.none}' ] ] ] ],
        ], $schema));
        self::assertSame([ 'tokens.space.huge.unknown', 'components.tabs.variants.plain.gap.alias' ], array_column(ThemeValidator::validate([
            'tokens' => [ 'space' => [ 'huge' => 40 ] ],
            'components' => [ 'tabs' => [ 'variants' => [ 'plain' => [ 'gap' => '{size.sm}' ] ] ] ],
        ], $schema), 'code'));
    }

    /**
     * The business header's banner is a card: the header has no box of its
     * own (background, border, rounding or shadow).
     */
    public function testTheBusinessHeadersBoxIsACard(): void
    {
        $schema = ThemeSchemaFixture::webMarketplace();

        self::assertSame([], array_values(array_intersect([ 'background', 'border', 'radius', 'shadow' ], array_keys($schema['components']['business_header']['base']))));
        self::assertSame([ 'components.business_header.base.shadow.unknown' ], array_column(ThemeValidator::validate([ 'components' => [ 'business_header' => [ 'base' => [ 'shadow' => '{shadow.sm}' ] ] ] ], $schema), 'code'));
    }

    /**
     * A badge's variants are status colors, each on its own on-color.
     */
    public function testABadgeIsOfAStatusColor(): void
    {
        $badge = ThemeSchemas::defaults(ThemeSchemaFixture::webMarketplace())['components']['badge']['variants'];

        self::assertSame([
            'primary' => [ 'background' => '{color.primary}', 'color' => '{color.on_primary}' ],
            'neutral' => [ 'background' => '{color.border}', 'color' => '{color.text}' ],
            'inverse' => [ 'background' => '{color.inverse_surface}', 'color' => '{color.on_inverse}' ],
            'warning' => [ 'background' => '{color.warning}', 'color' => '{color.on_warning}' ],
            'danger' => [ 'background' => '{color.danger}', 'color' => '{color.on_danger}' ],
            'success' => [ 'background' => '{color.success}', 'color' => '{color.on_success}' ],
        ], $badge);
    }

    /**
     * A divider is a line by purpose, of a number width: Identity's border
     * neither changes nor erases it, and a theme hides it with `none`.
     */
    public function testADividerIsAFixedLineByPurpose(): void
    {
        $schema = ThemeSchemaFixture::webMarketplace();
        $defaults = ThemeSchemas::defaults($schema);
        $divider = $defaults['components']['divider']['variants'];
        $line = static fn (int $width, string $color): array => [ 'width' => $width, 'color' => $color, 'style' => 'solid' ];

        self::assertSame('#E9ECEF', $defaults['tokens']['color']['divider']);
        self::assertSame([
            'item' => [ 'line' => $line(1, '{color.divider}'), 'spacing' => '{space.none}', 'indent' => '{space.none}' ],
            'section' => [ 'line' => $line(1, '{color.divider}'), 'spacing' => '{space.xl}', 'indent' => '{space.none}' ],
            'band' => [ 'line' => $line(8, '{color.surface_alt}'), 'spacing' => '{space.none}', 'indent' => '{space.none}' ],
            'vertical' => [ 'line' => $line(1, '{color.divider}'), 'spacing' => '{space.lg}', 'indent' => '{space.sm}' ],
        ], $divider);
        foreach ([ [ 'border_width' => 'none' ], [ 'border_width' => 'thick', 'border_style' => 'dashed' ] ] as $overall) {
            self::assertSame($divider, ThemeOverall::apply($defaults, $schema, $overall)['components']['divider']['variants']);
        }
        self::assertSame([], ThemeValidator::validate([ 'components' => [ 'divider' => [ 'variants' => [
            'item' => [ 'line' => 'none' ],
            'section' => [ 'line' => [ 'width' => '{border.thick}', 'color' => '{color.border_strong}', 'style' => 'dashed' ], 'indent' => '{space.md}' ],
        ] ] ] ], $schema));
        self::assertSame([ 'components.divider.variants.band.spacing.alias' ], array_column(ThemeValidator::validate([ 'components' => [ 'divider' => [ 'variants' => [
            'band' => [ 'spacing' => '{size.sm}' ],
        ] ] ] ], $schema), 'code'));
    }

    /**
     * One mode for the listing, the orders and the favorites, and version 1
     * may name several keys for one value. Prior behavior: a layout token
     * was a number, and a version 1 entry one path.
     */
    public function testTheLayoutModeIsOneOfItsModesAndItsVersion1KeysAreAList(): void
    {
        $schema = ThemeSchemaFixture::webMarketplace();
        self::assertSame([], ThemeValidator::validate([ 'tokens' => [ 'layout' => [ 'mode' => 'appointments', 'page_gutter' => 16 ] ] ], $schema));
        self::assertSame([ 'tokens.layout.mode.invalid', 'tokens.layout.page_gutter.invalid' ], array_column(ThemeValidator::validate([ 'tokens' => [ 'layout' => [ 'mode' => 2, 'page_gutter' => 'wide' ] ] ], $schema), 'code'));

        $broken = $schema;
        $broken['v1']['tokens.layout.mode'] = [];
        $broken['v1']['tokens.color.primary'] = [ 'orders.components.layout.type', 7 ];
        // A color now claims the button's rounding first: the radius claiming it after is of another kind.
        $broken['v1']['tokens.color.link'] = [ 'general.components.buttons.borderRadius' ];
        self::assertSame([ 'v1.tokens.color.primary.invalid', 'v1.tokens.layout.mode.invalid', 'v1.components.button.base.radius.duplicate' ], array_column(ThemeSchemas::check($broken), 'code'));
    }

    /**
     * Version 1 had one font for the whole site: its key feeds the text
     * and the heading font. Prior behavior: a version 1 path fed one key
     * only (`v1.tokens.font.heading.duplicate`).
     */
    public function testAVersion1PathFeedsSeveralKeysOfTheSameKind(): void
    {
        $schema = ThemeSchemaFixture::webMarketplace();
        $schema['v1']['tokens.font.heading'] = $schema['v1']['tokens.font.body'];
        self::assertSame([], ThemeSchemas::check($schema));

        $schema['v1']['tokens.color.secondary'] = [ $schema['v1']['tokens.font.body'] ];
        $schema['v1']['components.modal.base.radius'] = [ 'some.radius', 'some.radius' ];
        self::assertSame([ 'v1.components.modal.base.radius.duplicate', 'v1.tokens.color.secondary.duplicate' ], array_column(ThemeSchemas::check($schema), 'code'));
    }

    /**
     * The product modal shows a bar of its options' tabs over them, an
     * underline bar of the md size by default; whether it shows it is
     * `visibility`'s, not the modal's.
     */
    public function testTheProductModalNavigatesItsOptions(): void
    {
        $schema = ThemeSchemaFixture::webMarketplace();
        $defaults = ThemeSchemas::defaults($schema);
        $modal = $defaults['components']['product_modal'];

        self::assertTrue($defaults['visibility']['product.navigation']);
        self::assertSame([ 'component' => 'tabs', 'size' => 'md', 'variant' => 'underline' ], $modal['slots']['navigation']);
        self::assertSame([], ThemeValidator::validate([ 'visibility' => [ 'product.navigation' => false ], 'components' => [ 'product_modal' => [ 'slots' => [ 'navigation' => [ 'variant' => 'pill' ] ] ] ] ], $schema));
        self::assertSame([ 'components.product_modal.base.navigation.unknown' ], array_column(ThemeValidator::validate([ 'components' => [ 'product_modal' => [ 'base' => [ 'navigation' => false ] ] ] ], $schema), 'code'));
    }

    /**
     * A pill tab may be square, so a theme can be square throughout.
     * Prior behavior: its rounding left `none` out, the theme could not
     * set it and Identity's `none` left the pill's own.
     */
    public function testAPillTabMayBeSquare(): void
    {
        $schema = ThemeSchemaFixture::webMarketplace();
        $defaults = ThemeSchemas::defaults($schema);

        self::assertSame([], ThemeSchemas::check($schema));
        self::assertSame('{radius.none}', ThemeOverall::apply($defaults, $schema, [ 'radius' => 'none' ])['components']['tabs']['variants']['pill']['radius']);
        self::assertSame('{radius.xl}', ThemeOverall::apply($defaults, $schema, [ 'radius' => 'xl' ])['components']['tabs']['variants']['pill']['radius']);
        self::assertSame([], ThemeValidator::validate([ 'components' => [ 'tabs' => [ 'variants' => [ 'pill' => [ 'radius' => '{radius.none}' ] ] ] ] ], $schema));
        self::assertSame([], ThemeValidator::validate([ 'components' => [ 'tabs' => [ 'variants' => [ 'pill' => [ 'radius' => '{radius.full}' ] ] ] ] ], $schema));
        // A rounding whose options leave a level out: the theme cannot set
        // it, and Identity's leaves the property's own.
        $narrow = $schema;
        $narrow['components']['tabs']['variants']['pill']['radius'][2] = [ 'sm', 'md', 'lg', 'xl', 'xxl', 'full' ];
        self::assertSame([], ThemeSchemas::check($narrow));
        self::assertSame('{radius.md}', ThemeOverall::apply($defaults, $narrow, [ 'radius' => 'none' ])['components']['tabs']['variants']['pill']['radius']);
        self::assertSame([ 'components.tabs.variants.pill.radius.invalid' ], array_column(ThemeValidator::validate([ 'components' => [ 'tabs' => [ 'variants' => [ 'pill' => [ 'radius' => '{radius.none}' ] ] ] ] ], $narrow), 'code'));
        // Options on a radius name its levels, and only those.
        $schema['components']['tabs']['variants']['pill']['radius'][2] = [ 'round' ];
        self::assertNotSame([], ThemeSchemas::check($schema));
    }

    public function testTheSidebarMenuIsItsOwnComponent(): void
    {
        $schema = ThemeSchemaFixture::webMarketplace();
        $sidebar = ThemeSchemas::defaults($schema)['components']['sidebar_menu']['base'];

        self::assertSame([], ThemeSchemas::check($schema));
        self::assertSame([ '{typography.h3}', '{color.primary_text}', '{typography.body_lg}', true ], [ $sidebar['title'], $sidebar['title_color'], $sidebar['item_text'], $sidebar['show_icons'] ]);
        self::assertSame([], ThemeValidator::validate([ 'components' => [ 'sidebar_menu' => [ 'base' => [ 'show_icons' => false, 'item_text' => '{typography.body}' ] ] ] ], $schema));
        // The panel's header is not a title; options may have a border (Identity's); no dividers.
        self::assertSame([ '{typography.h2}', 'full' ], [ $sidebar['header_text'], $sidebar['item_style'] ]);
        self::assertSame([], array_intersect_key($sidebar, [ 'divider' => true, 'item_dividers' => true ]));
        self::assertSame([ 'width' => '{border.thin}', 'color' => '{color.border}', 'style' => 'solid' ], ThemeOverall::apply(ThemeSchemas::defaults($schema), $schema, [ 'border_width' => 'thin' ])['components']['sidebar_menu']['base']['item_border']);
    }

    public function testAChoiceIsSelectedWithThePrimaryColor(): void
    {
        $schema = ThemeSchemaFixture::webMarketplace();
        $choice = ThemeSchemas::defaults($schema)['components']['choice'];

        self::assertSame([], ThemeSchemas::check($schema));
        self::assertTrue($choice['base']['show_indicator']);
        self::assertSame([ '{color.primary_subtle}', '{color.primary}' ], [ $choice['variants']['default']['states']['selected']['background'], $choice['variants']['default']['states']['selected']['border']['color'] ]);
        self::assertSame(0.5, $choice['variants']['default']['states']['disabled']['opacity']);
        // The base border follows Identity's; the selected one stays primary.
        $overall = ThemeOverall::apply(ThemeSchemas::defaults($schema), $schema, [ 'border_width' => 'thick' ])['components']['choice'];
        self::assertSame([ 'width' => '{border.thick}', 'color' => '{color.border}', 'style' => 'solid' ], $overall['base']['border']);
        self::assertSame('{color.primary}', $overall['variants']['default']['states']['selected']['border']['color']);
        self::assertSame([], ThemeValidator::validate([ 'components' => [ 'choice' => [ 'base' => [ 'show_indicator' => false ] ] ] ], $schema));
    }

    /**
     * A group changes the components it holds in their own shape, and its
     * slots choose only among what their component has.
     */
    public function testAGroupChangesItsComponentsAndChoosesForItsSlots(): void
    {
        $schema = ThemeSchemaFixture::webMarketplace();
        $codes = fn (array $components) => array_column(ThemeValidator::validate([ 'components' => $components ], $schema), 'code');

        self::assertSame([], ThemeSchemas::check($schema));
        self::assertSame([], $codes([
            'product_option' => [ 'scope' => [ 'badge' => [ 'base' => [ 'radius' => '{radius.full}' ] ], 'stepper' => [ 'sizes' => [ 'sm' => [ 'height' => '{size.sm}' ] ] ] ] ],
            'header' => [ 'slots' => [ 'cart' => [ 'size' => 'lg', 'shape' => null ], 'order_type' => [ 'variant' => 'underline' ] ] ],
        ]));
        self::assertSame([
            'components.product_option.scope.button.unknown',
            'components.product_option.scope.badge.base.height.unknown',
            'components.header.slots.cart.size.invalid',
            'components.header.slots.cart.component.unknown',
            'components.header.slots.language.color.unknown',
            'components.header.slots.door.unknown',
        ], $codes([
            'product_option' => [ 'scope' => [ 'button' => [], 'badge' => [ 'base' => [ 'height' => 20 ] ] ] ],
            'header' => [ 'slots' => [ 'cart' => [ 'size' => 'xl', 'component' => 'chip' ], 'language' => [ 'color' => 'primary' ], 'door' => [] ] ],
        ]));
        // Only a component's own platform override, never a group's scope or slots.
        self::assertSame([ 'platforms.mobile_web.components.header.slots.unknown' ], array_column(ThemeValidator::validate([ 'platforms' => [ 'mobile_web' => [ 'components' => [ 'header' => [ 'slots' => [] ] ] ] ] ], $schema), 'code'));

        // A group is never scoped nor a slot; a scope's change keeps its property's kind.
        $broken = $schema;
        $broken['components']['header']['scope']['product_option'] = [];
        $broken['components']['header']['slots']['cart']['size'] = 'xl';
        $broken['components']['product_option']['scope']['badge'] = [ 'base' => [ 'radius' => [ 'color', '#000000' ] ] ];
        self::assertSame([ 'components.header.scope.product_option.invalid', 'components.header.slots.cart.size.invalid', 'components.product_option.scope.badge.base.radius.invalid' ], array_column(ThemeSchemas::check($broken), 'code'));
        // Identity's border (none by default) never hides the nesting line.
        $product_option = ThemeOverall::apply(ThemeSchemas::defaults($schema), $schema, [ 'border_width' => 'none' ])['components']['product_option'];
        self::assertSame([ 'width' => 1, 'color' => '{color.border}', 'style' => 'solid' ], $product_option['base']['nesting_line']);
        self::assertSame([ 'component' => 'tabs', 'size' => 'sm', 'variant' => 'pill' ], $product_option['slots']['halves']);
        self::assertSame([ 'line', 'none' ], [ $product_option['base']['nesting'], $product_option['base']['collapsed'] ]);
        self::assertArrayNotHasKey('image_visible', $product_option['base']);
        // The required mark moved from the product modal to the product option's slot.
        self::assertArrayNotHasKey('required', $schema['components']['product_modal']['base']);
    }

    /**
     * Animation is Identity's (durations, easing, on or off) and each
     * component says whether it animates and at what speed.
     */
    public function testMotionIsIdentitysAndEachComponentsChoice(): void
    {
        $schema = ThemeSchemaFixture::webMarketplace();
        $components = ThemeSchemas::defaults($schema)['components'];

        self::assertSame([ 'fast' => 120, 'base' => 200, 'slow' => 300 ], $schema['tokens']['motion']);
        self::assertSame([ 'easing' => 'ease_out', 'enabled' => true ], $schema['tokens']['motion_style']);
        self::assertSame([ true, '{motion.slow}' ], [ $components['sidebar_menu']['base']['animated'], $components['sidebar_menu']['base']['speed'] ]);
        self::assertSame([ false, '{motion.fast}' ], [ $components['button']['base']['animated'], $components['button']['base']['speed'] ]);
        self::assertSame([], ThemeValidator::validate([
            'tokens' => [ 'motion' => [ 'slow' => 400 ], 'motion_style' => [ 'easing' => 'linear', 'enabled' => false ] ],
            'components' => [ 'tabs' => [ 'base' => [ 'animated' => true, 'speed' => 150 ] ] ],
        ], $schema));
        self::assertSame([ 'tokens.motion_style.easing.invalid', 'components.tabs.base.speed.alias' ], array_column(ThemeValidator::validate([
            'tokens' => [ 'motion_style' => [ 'easing' => 'bounce' ] ],
            'components' => [ 'tabs' => [ 'base' => [ 'speed' => '{size.md}' ] ] ],
        ], $schema), 'code'));
    }

    /**
     * Every card a customer taps to open something raises its shadow a level
     * on hover, and fades into it when it animates; whether it animates is
     * each card's own. The card itself only holds content: its hover and
     * focus keep its resting shadow, and neither they nor its padding reach
     * the cards that follow it.
     */
    public function testEveryCardShadowsOnHover(): void
    {
        $schema = ThemeSchemaFixture::webMarketplace();
        $defaults = ThemeSchemas::defaults($schema)['components'];

        self::assertSame([ 'hover' => [ 'shadow' => '{shadow.sm}' ], 'focus' => [ 'shadow' => '{shadow.sm}' ] ], $defaults['card']['variants']['default']['states']);
        self::assertSame([ 'background', 'radius', 'border', 'shadow', 'font_role', 'padding' ], array_keys($defaults['card']['base']));
        self::assertSame([ null, '{shadow.sm}' ], [ $defaults['order_type_card']['base']['border'], $defaults['order_type_card']['base']['shadow'] ]);
        foreach ([ 'business_card', 'product_card', 'order_card', 'order_type_card' ] as $card) {
            self::assertSame('{shadow.md}', $defaults[$card]['variants']['default']['states']['hover']['shadow'], $card);
            self::assertSame([ false, '{motion.base}' ], [ $defaults[$card]['base']['animated'], $defaults[$card]['base']['speed'] ], $card);
        }
        // A card's animation stored before it was each card's own is left out, and reaches no card.
        $stored = [ 'components' => [ 'card' => [
            'base' => [ 'animated' => true, 'speed' => '{motion.slow}', 'radius' => '{radius.lg}', 'padding' => '{space.xl}' ],
            'variants' => [ 'default' => [ 'states' => [ 'focus' => [ 'shadow' => '{shadow.xl}' ] ] ] ],
        ] ] ];
        self::assertSame([ 'components.card.base.animated.unknown', 'components.card.base.speed.unknown' ], array_column(ThemeValidator::validate($stored, $schema), 'code'));
        $values = ThemeResolver::inherit(ThemeResolver::valid($stored, $schema), $schema)['components'];
        self::assertSame([ 'radius' => '{radius.lg}', 'padding' => '{space.xl}' ], $values['card']['base']);
        self::assertSame([ 'radius' => '{radius.lg}' ], $values['order_card']['base']);
        self::assertArrayNotHasKey('variants', $values['order_card']);
    }

    /**
     * A link goes inside a sentence: its size is a typography style, not a
     * height.
     */
    public function testALinkIsInline(): void
    {
        $schema = ThemeSchemaFixture::webMarketplace();
        $link = ThemeSchemas::defaults($schema)['components']['link'];

        self::assertSame([ 'color' => '{color.link}', 'underline' => 'hover' ], $link['base']);
        self::assertSame('{typography.body}', $link['sizes']['md']['text']);
        self::assertSame([ 'default', 'muted', 'danger' ], array_keys($link['variants']));
        self::assertSame('{opacity.pressed}', $link['variants']['danger']['states']['pressed']['opacity']);
        self::assertSame([], ThemeValidator::validate([ 'components' => [ 'link' => [ 'variants' => [ 'muted' => [ 'states' => [ 'pressed' => [ 'opacity' => 0.6 ] ] ] ] ] ] ], $schema));
    }

    /**
     * A progress bar is the bar alone: Identity's shadow and border, any
     * rounding, and as thick as its size.
     */
    public function testAProgressBarIsOfAnyRounding(): void
    {
        $schema = ThemeSchemaFixture::webMarketplace();
        $progress = ThemeOverall::apply(ThemeSchemas::defaults($schema), $schema, [ 'shadow' => 'md', 'radius' => 'sm', 'border_width' => 'thin' ])['components']['progress'];

        self::assertSame([], ThemeSchemas::check($schema));
        // Identity's shadow and border apply; its rounding doesn't round a full bar.
        self::assertSame([ '{shadow.md}', '{radius.full}' ], [ $progress['base']['shadow'], $progress['base']['radius'] ]);
        // Its default is Identity's: it follows it.
        self::assertSame('{shadow.sm}', ThemeSchemas::defaults($schema)['components']['progress']['base']['shadow']);
        self::assertSame([ 'width' => '{border.thin}', 'color' => '{color.border}', 'style' => 'solid' ], $progress['base']['border']);
        self::assertSame([ 'sm' => [ 'height' => 6 ], 'md' => [ 'height' => 12 ], 'lg' => [ 'height' => 20 ] ], $progress['sizes']);
        self::assertSame([ 'active', 'complete', 'pending', 'canceled' ], array_keys($progress['variants']['default']['states']));
        self::assertSame([ '{color.success}', '{color.success_subtle}' ], [ $progress['variants']['default']['states']['complete']['fill'], $progress['variants']['default']['states']['complete']['track'] ]);
        self::assertSame([], array_diff_key($progress['base'], array_flip([ 'track', 'fill', 'radius', 'border', 'shadow', 'animated', 'speed' ])));
        // Square is allowed.
        self::assertSame([], ThemeValidator::validate([ 'components' => [ 'progress' => [ 'base' => [ 'radius' => '{radius.none}', 'shadow' => '{shadow.sm}' ] ] ] ], $schema));
    }

    /**
     * No control has a size of its own: the ones that sit in a row take
     * their height from the same size tokens, in the same three sizes.
     */
    public function testEveryControlIsOfTheSizeTokens(): void
    {
        $components = ThemeSchemaFixture::webMarketplace()['components'];

        // Besides the three: the button's xs (a large icon's height) and the
        // field's code (one box of a verification code).
        $extra = [ 'button' => [ 'xs' => [ 'icon', '{icon.lg}' ] ], 'input' => [ 'code' => [ 'size', '{size.xl}' ] ] ];
        foreach ([ 'button', 'input', 'search_bar', 'tabs', 'chip', 'choice', 'switch' ] as $component) {
            $sizes = array_diff_key($components[$component]['sizes'], $extra[$component] ?? []);
            self::assertSame([ 'sm', 'md', 'lg' ], array_keys($sizes), $component);
            foreach ($sizes as $size => $props) {
                self::assertSame([ 'size', "{size.{$size}}" ], $props['height'], "{$component}.{$size}");
            }
            foreach ($extra[$component] ?? [] as $size => $height) {
                self::assertSame($height, $components[$component]['sizes'][$size]['height'], "{$component}.{$size}");
            }
            self::assertArrayNotHasKey('height', $components[$component]['base'], $component);
        }
        self::assertArrayNotHasKey('width', $components['switch']['base']);
        self::assertSame([ [ 'size', '{size.md}' ], [ 'size', '{size.lg}' ] ], [ $components['menu']['base']['item_height'], $components['sidebar_menu']['base']['item_height'] ]);
    }

    /**
     * Icons, and the controls drawn like one, take the icon scale.
     */
    public function testIconsAreOfTheIconTokens(): void
    {
        $schema = ThemeSchemaFixture::webMarketplace();
        $components = $schema['components'];

        self::assertSame([ 'sm' => 16, 'md' => 20, 'lg' => 24 ], $schema['tokens']['icon']);
        self::assertSame([ [ 'icon', '{icon.md}' ], [ 'icon', '{icon.md}' ], [ 'icon', '{icon.md}' ] ], [ $components['radio']['base']['size'], $components['checkbox']['base']['size'], $components['sidebar_menu']['base']['icon_size'] ]);
        self::assertSame([], ThemeValidator::validate([ 'tokens' => [ 'icon' => [ 'md' => 22 ] ], 'components' => [ 'radio' => [ 'base' => [ 'size' => '{icon.lg}' ] ] ] ], $schema));
        self::assertSame([ 'components.radio.base.size.alias' ], array_column(ThemeValidator::validate([ 'components' => [ 'radio' => [ 'base' => [ 'size' => '{size.lg}' ] ] ] ], $schema), 'code'));
    }

    /**
     * A stored theme with bad values (written outside the editor, or
     * before its schema changed) resolves with its defaults instead.
     */
    public function testBadStoredValuesFallBackToTheDefaults(): void
    {
        $schema = ThemeSchemaFixture::webMarketplace();
        $stored = [
            'tokens' => [ 'color' => [ 'primary' => '#00AA00', 'text' => 'azul', 'nope' => '#000000' ], 'radius' => 'big', 'font' => [ 'body' => 'comic_sans' ] ],
            'components' => [
                'button' => [ 'colors' => [ 'primary' => null, 'danger' => [ 'main' => '{color.nope}' ] ], 'appearances' => [ 'solid' => 'flat' ], 'base' => [ 'radius' => 8 ] ],
                'carousel' => [ 'base' => [] ],
            ],
            'visibility' => [ 'menu.wallet' => 'yes', 'footer' => false ],
            'images' => [ 'logo' => 123 ],
            'layouts' => [ 'header' => 'neon' ],
        ];

        $valid = ThemeResolver::valid($stored, $schema);
        self::assertSame([
            'tokens' => [ 'color' => [ 'primary' => '#00AA00' ], 'font' => [] ],
            'components' => [ 'button' => [ 'colors' => [ 'danger' => [] ], 'appearances' => [], 'base' => [] ] ],
            'visibility' => [ 'footer' => false ],
            'images' => [],
        ], $valid);

        $values = ThemeResolver::merge(ThemeSchemas::defaults($schema), $valid);
        $values = ThemeResolver::resolveAliases($values);
        $values['components'] = ThemeDerivations::matrices($values['components'], $values['tokens']['color'], [], ThemeDerivations::rules($schema)['matrix']);
        self::assertSame('#00AA00', $values['components']['button']['variants']['primary']['solid']['background']);
        self::assertSame('#000000', $values['tokens']['color']['text']);
        self::assertSame(10, $values['components']['button']['base']['radius']);
        self::assertTrue($values['visibility']['menu.wallet']);
        self::assertSame('archivo', $values['tokens']['font']['body']);

        // Without a schema, whatever has no theme's shape is dropped and
        // nothing breaks.
        self::assertSame([ 'tokens' => [ 'color' => 'red' ] ], ThemeResolver::valid([ 'tokens' => [ 'color' => 'red' ], 'components' => 'x', 'junk' => [] ], null));
        self::assertSame([], ThemeResolver::valid([ 1, 2 ], null));
        $garbage = ThemeResolver::resolveAliases([ 'tokens' => [ 'typography' => 'x', 'font' => 'y' ], 'components' => [ 'a' => [ 'b' => '{color.primary}' ] ], 'platforms' => 'z' ]);
        self::assertNull($garbage['components']['a']['b']);
        self::assertSame([ 'a' => 'b' ], ThemeDerivations::matrices([ 'a' => 'b' ], [], [], [ 'a' => [ 'roles' => ThemeDerivations::ROLES ] ]));
    }

    /**
     * Prior behavior: a token color had a kind when the catalog had it, so a
     * color the schema dropped still crossed from version 1 and migrations
     * could not move it.
     */
    public function testATokenColorIsOneOfTheSchemasOwn(): void
    {
        $schema = ThemeSchemaFixture::webMarketplace();
        self::assertSame('color', ThemeSchemas::kindAt($schema, 'tokens.color.primary'));
        self::assertArrayNotHasKey('danger_text', $schema['tokens']['color']);
        self::assertNull(ThemeSchemas::kindAt($schema, 'tokens.color.danger_text'));

        // A schema that names no colors has the catalog's.
        $schema['tokens']['color'] = [];
        self::assertSame('color', ThemeSchemas::kindAt($schema, 'tokens.color.danger_text'));
        self::assertNull(ThemeSchemas::kindAt($schema, 'tokens.color.brand'));
    }
}
