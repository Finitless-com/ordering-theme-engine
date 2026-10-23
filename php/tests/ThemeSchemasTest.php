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
 * them, over the reference schema.
 */
class ThemeSchemasTest extends TestCase
{
    public function testTheReferenceSchemaIsValidAndItsDefaultsFitIt(): void
    {
        $schema = ThemeSchemaFixture::compiled();
        self::assertSame([], ThemeSchemas::check($schema));

        $defaults = ThemeSchemas::defaults($schema);
        self::assertSame([], ThemeValidator::validate($defaults, $schema));
        self::assertSame('{radius.md}', $defaults['components']['button']['base']['radius']);
        self::assertSame('{color.primary}', $defaults['components']['button']['colors']['primary']['main']);
        self::assertSame('{ink}', $defaults['components']['button']['appearances']['outline']['color']);
        self::assertTrue($defaults['visibility']['footer']);
        self::assertTrue($defaults['visibility']['menu.wallet']);
        self::assertSame('original', $defaults['tokens']['layout']['mode']);
        self::assertSame([ 'mobile_web' ], array_keys($defaults['platforms']));
        self::assertSame([ 'components', 'visibility' ], array_keys($defaults['platforms']['mobile_web']));
        self::assertSame([ 'base' => [ 'padding' => '{space.sm}' ] ], $defaults['platforms']['mobile_web']['components']['card']);
        self::assertSame([ 'header.cart' => false ], $defaults['platforms']['mobile_web']['visibility']);
        self::assertSame(array_keys($schema['pages']), array_keys($defaults['pages']));
        self::assertSame([ null, false ], [ $defaults['pages']['home']['footer'], $defaults['pages']['checkout']['footer'] ]);
        self::assertSame([ 'key' => 'recent', 'visible' => false ], $defaults['pages']['home']['sections'][2]);
        self::assertSame([], $defaults['pages']['not_found']['sections']);
    }

    public function testASchemaSaysWhatItGetsWrong(): void
    {
        $schema = ThemeSchemaFixture::compiled();
        $schema['product_type'] = 'spaceship';
        unset($schema['tokens']['radius']['lg']);
        $schema['tokens']['radius']['md'] = '10px';
        $schema['components']['button']['base']['icon_gap'] = [ 'space', 'big' ];
        $schema['components']['header']['base']['layout'] = [ 'layout', 'neon', [ 'original', 'starbucks' ] ];
        $schema['components']['card']['base']['glow'] = [ 'sparkle', true ];
        $schema['components']['modal']['platforms']['tv'] = [ 'radius' => [ 'radius', '{radius.none}' ] ];
        $schema['visibility']['Footer Links'] = [ true, 'footer.hidden' ];
        $schema['v1']['components.button.base.glow'] = 'general.components.buttons.glow';
        // A radius cannot read the primary color's version 1 key.
        $schema['v1']['components.modal.base.radius'] = $schema['v1']['tokens.color.primary'];
        $schema['inherits']['banner'] = 'nope';
        $schema['inherits']['modal'] = 'modal';
        $schema['extra'] = [];

        self::assertSame([
            'product_type.invalid',
            'extra.unknown',
            'tokens.radius.lg.required',
            'tokens.radius.md.invalid',
            'components.button.base.icon_gap.default.invalid',
            'components.modal.platforms.tv.unknown',
            'components.card.base.glow.invalid',
            'components.header.base.layout.default.invalid',
            'inherits.banner.invalid',
            'inherits.modal.invalid',
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
        $schema = ThemeSchemaFixture::compiled();
        unset($schema['tokens']['color']['surface_alt']);
        $schema['tokens']['color']['danger_text'] = '#B02A3E';
        $own = fn (array $schema) => array_values(array_filter(ThemeValidator::codes(ThemeSchemas::check($schema)), fn ($code) => str_contains($code, 'surface_alt') || str_contains($code, 'danger_text') || str_ends_with($code, '.alias')));

        // The components that painted the dropped color say so; the new one is no error.
        self::assertNotSame([], $own($schema));
        self::assertSame([], array_filter($own($schema), fn ($code) => !str_ends_with($code, '.alias')));
        self::assertContains('components.chip.variants.default.background.default.alias', $own($schema));

        // Without the references, the schema is valid with its own palette.
        $schema['components'] = json_decode(str_replace('{color.surface_alt}', '{color.danger_text}', json_encode($schema['components'])), true);
        self::assertSame([], ThemeSchemas::check($schema));
        // A group it names itself is never empty.
        $schema['tokens']['opacity'] = [];
        self::assertContains('tokens.opacity.required', ThemeValidator::codes(ThemeSchemas::check($schema)));
    }

    public function testAThemeResolvesOverItsSchemasDefaults(): void
    {
        $schema = ThemeSchemaFixture::compiled();
        $values = ThemeResolver::resolveAliases(ThemeResolver::merge(ThemeSchemas::defaults($schema), [
            'tokens' => [ 'color' => [ 'primary' => '#FF0000' ], 'radius' => [ 'md' => 12 ], 'font' => [ 'heading' => 'playfair_display' ] ],
            'components' => [ 'button' => [ 'appearances' => [ 'outline' => [ 'border' => [ 'width' => '{border.thick}', 'color' => '{ink}', 'style' => 'dashed' ] ] ] ] ],
        ]));
        $values['components'] = ThemeDerivations::matrices($values['components'], $values['tokens']['color'], [], ThemeDerivations::rules($schema)['matrix']);
        $button = $values['components']['button'];

        self::assertSame(12, $button['base']['radius']);
        self::assertSame([ 'size' => 15, 'line' => 1.2, 'weight' => 600, 'letter_spacing' => 0.2, 'family' => 'inter' ], $button['base']['text']);
        // Every color in every appearance.
        self::assertSame([ 'primary', 'neutral' ], array_keys($button['variants']));
        self::assertSame([ 'solid', 'outline' ], array_keys($button['variants']['neutral']));
        self::assertSame('#FF0000', $button['variants']['primary']['solid']['background']);
        self::assertSame([ 'width' => 2, 'color' => '#1B1B1B', 'style' => 'dashed' ], $button['variants']['neutral']['outline']['border']);
        // A color's subtle and pressed derive from it unless it sets them.
        self::assertSame($button['colors']['neutral']['subtle'], $button['variants']['neutral']['outline']['states']['pressed']['background']);
        self::assertNotNull($button['colors']['neutral']['subtle']);
        self::assertSame('playfair_display', $values['tokens']['typography']['h1']['family']);
        self::assertSame('inter', $values['tokens']['typography']['body']['family']);
        self::assertSame($values['tokens']['shadow']['sm'], $values['components']['card']['base']['shadow']);
        self::assertSame(8, $values['platforms']['mobile_web']['components']['card']['base']['padding']);
    }

    /**
     * What a theme sets on the generic card also goes to the specific
     * cards, unless it sets the same property on one of them; a specific
     * card takes only the properties it declares.
     */
    public function testASpecificCardFollowsTheGenericOne(): void
    {
        $schema = ThemeSchemaFixture::compiled();
        $border = [ 'width' => '{border.thick}', 'color' => '{color.border}', 'style' => 'solid' ];

        $values = ThemeResolver::inherit([ 'components' => [
            'card' => [ 'base' => [ 'radius' => '{radius.none}', 'border' => $border, 'background' => '#FFF8E1' ] ],
        ] ], $schema);
        self::assertSame([ 'radius' => '{radius.none}', 'background' => '#FFF8E1' ], $values['components']['business_card']['base']);

        $own = ThemeResolver::inherit([ 'components' => [
            'card' => [ 'base' => [ 'radius' => '{radius.none}' ] ],
            'business_card' => [ 'base' => [ 'radius' => '{radius.xl}' ] ],
        ] ], $schema);
        self::assertSame([ 'radius' => '{radius.xl}' ], $own['components']['business_card']['base']);
        // Only components that inherit, and only what the theme sets.
        self::assertArrayNotHasKey('modal', $values['components']);
        self::assertSame([], ThemeResolver::inherit([], $schema));
    }

    /**
     * A component that inherits takes its parent's variants, their states,
     * and its sizes too, unless the theme sets the same property on it.
     */
    public function testAComponentFollowsItsParentsVariantsAndSizes(): void
    {
        $schema = ThemeSchemaFixture::compiled();
        $schema['components']['category_tabs'] = $schema['components']['tabs'];
        $schema['inherits']['category_tabs'] = 'tabs';
        self::assertSame([], ThemeSchemas::check($schema));

        $values = ThemeResolver::inherit([ 'components' => [
            'tabs' => [ 'variants' => [ 'pill' => [ 'active' => '#112233', 'radius' => '{radius.xl}' ] ], 'sizes' => [ 'md' => [ 'height' => 52 ] ] ],
            'category_tabs' => [ 'variants' => [ 'pill' => [ 'radius' => '{radius.full}' ] ] ],
        ] ], $schema);

        self::assertSame([ 'pill' => [ 'radius' => '{radius.full}', 'active' => '#112233' ] ], $values['components']['category_tabs']['variants']);
        self::assertSame([ 'md' => [ 'height' => 52 ] ], $values['components']['category_tabs']['sizes']);
    }

    /**
     * A platform changes a property of the component's base, of the same
     * kind. Prior behavior: a mobile override of another kind registered,
     * and its default failed only when served.
     */
    public function testAPlatformChangesABasePropertyOfTheSameKind(): void
    {
        $schema = ThemeSchemaFixture::compiled();
        $schema['components']['modal']['platforms']['mobile_web'] = [ 'radius' => [ 'number', 0 ], 'margin' => [ 'number', 0 ] ];

        self::assertSame(
            [ 'components.modal.platforms.mobile_web.radius.invalid', 'components.modal.platforms.mobile_web.margin.invalid' ],
            ThemeValidator::codes(ThemeSchemas::check($schema))
        );
    }

    /**
     * A property of a scale takes a level of it or a number off it, never a
     * level of another scale.
     */
    public function testAScalesPropertyTakesItsLevelsOrANumber(): void
    {
        $schema = ThemeSchemaFixture::compiled();

        self::assertSame([], ThemeValidator::validate([
            'tokens' => [ 'space' => [ 'md' => 14 ], 'icon' => [ 'md' => 22 ] ],
            'components' => [ 'tabs' => [ 'variants' => [ 'pill' => [ 'inset' => 6 ] ] ], 'header' => [ 'base' => [ 'icon_size' => '{icon.lg}' ] ] ],
        ], $schema));
        self::assertSame([ 'tokens.space.huge.unknown', 'components.tabs.variants.pill.inset.alias', 'components.header.base.icon_size.alias' ], array_column(ThemeValidator::validate([
            'tokens' => [ 'space' => [ 'huge' => 40 ] ],
            'components' => [ 'tabs' => [ 'variants' => [ 'pill' => [ 'inset' => '{size.sm}' ] ] ], 'header' => [ 'base' => [ 'icon_size' => '{size.lg}' ] ] ],
        ], $schema), 'code'));
    }

    /**
     * One mode for the listing, the orders and the favorites, and version 1
     * may name several keys for one value. Prior behavior: a layout token
     * was a number, and a version 1 entry one path.
     */
    public function testTheLayoutModeIsOneOfItsModesAndItsVersion1KeysAreAList(): void
    {
        $schema = ThemeSchemaFixture::compiled();
        self::assertSame([], ThemeValidator::validate([ 'tokens' => [ 'layout' => [ 'mode' => 'appointments', 'page_gutter' => 16 ] ] ], $schema));
        self::assertSame([ 'tokens.layout.mode.invalid', 'tokens.layout.page_gutter.invalid' ], array_column(ThemeValidator::validate([ 'tokens' => [ 'layout' => [ 'mode' => 2, 'page_gutter' => 'wide' ] ] ], $schema), 'code'));

        $broken = $schema;
        $broken['v1']['tokens.layout.mode'] = [];
        $broken['v1']['tokens.color.primary'] = [ 'orders.components.layout.type', 7 ];
        // A color now claims the button's rounding first: the radius claiming it after is of another kind.
        $broken['v1']['tokens.color.background'] = [ 'general.components.buttons.borderRadius' ];
        self::assertSame([ 'v1.tokens.color.primary.invalid', 'v1.tokens.layout.mode.invalid', 'v1.components.button.base.radius.duplicate' ], array_column(ThemeSchemas::check($broken), 'code'));
    }

    /**
     * Version 1 had one font for the whole site: its key feeds the text
     * and the heading font. Prior behavior: a version 1 path fed one key
     * only (`v1.tokens.font.heading.duplicate`).
     */
    public function testAVersion1PathFeedsSeveralKeysOfTheSameKind(): void
    {
        $schema = ThemeSchemaFixture::compiled();
        $schema['v1']['tokens.font.heading'] = $schema['v1']['tokens.font.body'];
        self::assertSame([], ThemeSchemas::check($schema));

        $schema['v1']['tokens.color.secondary'] = [ $schema['v1']['tokens.font.body'] ];
        $schema['v1']['components.modal.base.radius'] = [ 'some.radius', 'some.radius' ];
        self::assertSame([ 'v1.components.modal.base.radius.duplicate', 'v1.tokens.color.secondary.duplicate' ], array_column(ThemeSchemas::check($schema), 'code'));
    }

    /**
     * A rounding that follows Identity may be square, so a theme can be
     * square throughout. Prior behavior: a pill tab's rounding left `none`
     * out, the theme could not set it and Identity's `none` left the pill's
     * own.
     */
    public function testARoundingThatFollowsIdentityMayBeSquare(): void
    {
        $schema = ThemeSchemaFixture::compiled();
        $defaults = ThemeSchemas::defaults($schema);

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

    /**
     * A group changes the components it holds in their own shape, and its
     * slots choose only among what their component has.
     */
    public function testAGroupChangesItsComponentsAndChoosesForItsSlots(): void
    {
        $schema = ThemeSchemaFixture::compiled();
        $codes = fn (array $components) => array_column(ThemeValidator::validate([ 'components' => $components ], $schema), 'code');

        self::assertSame([], $codes([
            'header' => [
                'scope' => [ 'button' => [ 'base' => [ 'radius' => '{radius.sm}' ] ], 'input' => [ 'sizes' => [ 'md' => [ 'height' => '{size.sm}' ] ] ] ],
                'slots' => [ 'cta' => [ 'size' => 'sm', 'shape' => 'pill' ], 'order_type' => [ 'variant' => 'underline' ] ],
            ],
        ]));
        self::assertSame([
            'components.header.scope.tabs.unknown',
            'components.header.scope.button.base.height.unknown',
            'components.header.slots.cta.size.invalid',
            'components.header.slots.cta.component.unknown',
            'components.header.slots.search.color.unknown',
            'components.header.slots.door.unknown',
        ], $codes([
            'header' => [
                'scope' => [ 'tabs' => [], 'button' => [ 'base' => [ 'height' => 20 ] ] ],
                'slots' => [ 'cta' => [ 'size' => 'xl', 'component' => 'chip' ], 'search' => [ 'color' => 'primary' ], 'door' => [] ],
            ],
        ]));
        // Only a component's own platform override, never a group's scope or slots.
        self::assertSame([ 'platforms.mobile_web.components.header.slots.unknown' ], array_column(ThemeValidator::validate([ 'platforms' => [ 'mobile_web' => [ 'components' => [ 'header' => [ 'slots' => [] ] ] ] ] ], $schema), 'code'));

        // A group is never scoped nor a slot; a scope's change keeps its property's kind.
        $broken = $schema;
        $broken['components']['header']['scope']['header'] = [];
        $broken['components']['header']['slots']['cta']['size'] = 'xl';
        $broken['components']['header']['scope']['button'] = [ 'base' => [ 'radius' => [ 'color', '#000000' ] ] ];
        self::assertSame([ 'components.header.slots.cta.size.invalid', 'components.header.scope.button.base.radius.invalid', 'components.header.scope.header.invalid' ], array_column(ThemeSchemas::check($broken), 'code'));
    }

    /**
     * Animation is Identity's (durations, easing, on or off) and each
     * component says whether it animates and at what speed.
     */
    public function testMotionIsIdentitysAndEachComponentsChoice(): void
    {
        $schema = ThemeSchemaFixture::compiled();
        $components = ThemeSchemas::defaults($schema)['components'];

        self::assertSame([ 'fast' => 120, 'base' => 200, 'slow' => 320 ], $schema['tokens']['motion']);
        self::assertSame([ 'easing' => 'ease', 'enabled' => true ], $schema['tokens']['motion_style']);
        self::assertSame([ false, '{motion.fast}' ], [ $components['button']['base']['animated'], $components['button']['base']['speed'] ]);
        self::assertSame([], ThemeValidator::validate([
            'tokens' => [ 'motion' => [ 'slow' => 400 ], 'motion_style' => [ 'easing' => 'linear', 'enabled' => false ] ],
            'components' => [ 'button' => [ 'base' => [ 'animated' => true, 'speed' => 150 ] ] ],
        ], $schema));
        self::assertSame([ 'tokens.motion_style.easing.invalid', 'components.button.base.speed.alias' ], array_column(ThemeValidator::validate([
            'tokens' => [ 'motion_style' => [ 'easing' => 'bounce' ] ],
            'components' => [ 'button' => [ 'base' => [ 'speed' => '{size.md}' ] ] ],
        ], $schema), 'code'));
    }

    /**
     * A stored theme with bad values (written outside the editor, or
     * before its schema changed) resolves with its defaults instead.
     */
    public function testBadStoredValuesFallBackToTheDefaults(): void
    {
        $schema = ThemeSchemaFixture::compiled();
        $stored = [
            'tokens' => [ 'color' => [ 'primary' => '#00AA00', 'text' => 'azul', 'nope' => '#000000' ], 'radius' => 'big', 'font' => [ 'body' => 'comic_sans' ] ],
            'components' => [
                'button' => [ 'colors' => [ 'primary' => null, 'neutral' => [ 'main' => '{color.nope}' ] ], 'appearances' => [ 'solid' => 'flat' ], 'base' => [ 'radius' => 8 ] ],
                'carousel' => [ 'base' => [] ],
            ],
            'visibility' => [ 'menu.wallet' => 'yes', 'footer' => false ],
            'images' => [ 'logotype' => 123 ],
            'layouts' => [ 'header' => 'neon' ],
        ];

        $valid = ThemeResolver::valid($stored, $schema);
        self::assertSame([
            'tokens' => [ 'color' => [ 'primary' => '#00AA00' ], 'font' => [] ],
            'components' => [ 'button' => [ 'colors' => [ 'neutral' => [] ], 'appearances' => [], 'base' => [] ] ],
            'visibility' => [ 'footer' => false ],
            'images' => [],
        ], $valid);

        $values = ThemeResolver::merge(ThemeSchemas::defaults($schema), $valid);
        $values = ThemeResolver::resolveAliases($values);
        $values['components'] = ThemeDerivations::matrices($values['components'], $values['tokens']['color'], [], ThemeDerivations::rules($schema)['matrix']);
        self::assertSame('#00AA00', $values['components']['button']['variants']['primary']['solid']['background']);
        self::assertSame('#1B1B1B', $values['tokens']['color']['text']);
        self::assertSame(8, $values['components']['button']['base']['radius']);
        self::assertTrue($values['visibility']['menu.wallet']);
        self::assertSame('inter', $values['tokens']['font']['body']);

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
        $schema = ThemeSchemaFixture::compiled();
        self::assertSame('color', ThemeSchemas::kindAt($schema, 'tokens.color.primary'));
        self::assertArrayNotHasKey('danger_text', $schema['tokens']['color']);
        self::assertNull(ThemeSchemas::kindAt($schema, 'tokens.color.danger_text'));

        // A schema that names no colors has the catalog's.
        $schema['tokens']['color'] = [];
        self::assertSame('color', ThemeSchemas::kindAt($schema, 'tokens.color.danger_text'));
        self::assertNull(ThemeSchemas::kindAt($schema, 'tokens.color.brand'));
    }
}
