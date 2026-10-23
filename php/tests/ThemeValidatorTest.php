<?php

declare(strict_types=1);

namespace Ordering\ThemeEngine\Tests;

use Ordering\ThemeEngine\ThemeResolver;
use Ordering\ThemeEngine\ThemeValidator;
use PHPUnit\Framework\TestCase;

/**
 * What a product's theme may set of its schema, and each problem by its
 * path, so the editor marks the field.
 */
class ThemeValidatorTest extends TestCase
{
    public function testAThemeMaySetAnyPartOfItsSchema(): void
    {
        self::assertSame([], ThemeValidator::validate([
            'tokens' => [ 'color' => [ 'primary' => '#ff0000', 'overlay' => 'rgba(0,0,0,.5)' ], 'radius' => [ 'md' => 12 ], 'font' => [ 'body' => 'inter' ] ],
            'components' => [
                'button' => [
                    'base' => [ 'radius' => '{radius.full}', 'full_width' => true, 'text' => '{typography.label}', 'font' => 'heading' ],
                    'colors' => [ 'primary' => [ 'main' => '#00AA00' ], 'neutral' => [ 'ink' => '{color.text}' ] ],
                    'appearances' => [ 'solid' => [ 'shadow' => '{shadow.md}', 'states' => [ 'pressed' => [ 'background' => '{subtle}' ], 'disabled' => [ 'background' => '#EEEEEE', 'color' => '#FFFFFF' ] ] ],
                                       'outline' => [ 'border' => [ 'width' => '{border.thick}', 'color' => '{ink}' ] ] ],
                    'sizes' => [ 'md' => [ 'height' => 28, 'icon_size' => 18, 'padding_x' => '{space.sm}' ] ],
                    'shapes' => [ 'icon' => [ 'radius' => '{radius.full}' ] ],
                ],
                'header' => [ 'base' => [ 'layout' => 'centered', 'logo_width' => 200 ] ],
                'card' => [ 'base' => [ 'border' => [ 'width' => 1, 'color' => '{color.border}' ] ] ],
            ],
            'visibility' => [ 'footer' => false ],
            'images' => [ 'logotype' => 'https://cdn.example.invalid/logo.png', 'favicon' => '' ],
            'modes' => [ 'dark' => [ 'color' => [ 'background' => '#0B1220' ] ] ],
            'platforms' => [ 'mobile_web' => [ 'components' => [ 'header' => [ 'base' => [ 'height' => 56 ] ] ] ] ],
        ], ThemeSchemaFixture::compiled()));
    }

    /**
     * Prior behavior: a theme could refer to, and set, a color the engine's
     * catalog has but its schema dropped; it resolved to nothing.
     */
    public function testAThemeRefersOnlyToTheColorsItsSchemaNames(): void
    {
        $schema = ThemeSchemaFixture::compiled();
        unset($schema['tokens']['color']['link'], $schema['tokens']['typography']['label']);

        self::assertSame(
            [ 'tokens.color.link.unknown', 'components.card.base.border.color.alias', 'components.input.base.text.alias' ],
            ThemeValidator::codes(ThemeValidator::validate([
                'tokens' => [ 'color' => [ 'link' => '#0000FF' ] ],
                'components' => [
                    'card' => [ 'base' => [ 'border' => [ 'width' => 1, 'color' => '{color.link}' ] ] ],
                    'input' => [ 'base' => [ 'text' => '{typography.label}' ] ],
                ],
            ], $schema))
        );
    }

    /**
     * Prior behavior: any color took a translucent value, though the
     * editor only offers opacity on the colors the schema marks `alpha`.
     */
    public function testOnlyAColorMarkedAlphaIsTranslucent(): void
    {
        $schema = ThemeSchemaFixture::compiled();
        self::assertSame([ 'tokens.color.overlay', 'components.button.appearances.outline.background', 'components.tabs.variants.underline.background', 'components.modal.base.overlay' ], $schema['alpha']);

        self::assertSame(
            [ 'tokens.color.primary.alpha', 'modes.dark.color.surface.alpha', 'components.card.base.background.alpha', 'components.card.base.border.color.alpha', 'components.button.colors.primary.main.alpha', 'components.chip.variants.default.background.alpha' ],
            ThemeValidator::codes(ThemeValidator::validate([
                'tokens' => [ 'color' => [ 'primary' => 'rgba(255,0,0,.5)', 'overlay' => 'rgba(0,0,0,.6)', 'text' => '#000000FF' ] ],
                'modes' => [ 'dark' => [ 'color' => [ 'surface' => 'transparent' ] ] ],
                'components' => [
                    'card' => [ 'base' => [ 'background' => '#FFFFFF80', 'border' => [ 'width' => 1, 'color' => 'transparent' ] ] ],
                    'modal' => [ 'base' => [ 'overlay' => '{color.overlay}' ] ],
                    'button' => [ 'colors' => [ 'primary' => [ 'main' => '#F008' ] ] ],
                    // An alias to a translucent color is one too.
                    'chip' => [ 'variants' => [ 'default' => [ 'background' => '{color.overlay}' ] ] ],
                    // A border with no width shows no color.
                    'input' => [ 'base' => [ 'border' => [ 'width' => 0, 'style' => 'solid', 'color' => 'transparent' ] ] ],
                ],
            ], $schema))
        );

        // A schema that marks no color is not checked.
        unset($schema['alpha']);
        self::assertSame([], ThemeValidator::validate([ 'tokens' => [ 'color' => [ 'primary' => 'rgba(255,0,0,.5)' ] ] ], $schema));
    }

    public function testEachProblemNamesItsPathAndRule(): void
    {
        $codes = ThemeValidator::codes(ThemeValidator::validate([
            'layouts' => [],
            'tokens' => [ 'color' => [ 'primary' => 'blue', 'brand' => '#000000' ], 'radius' => [ 'md' => '8px' ], 'font' => [ 'body' => 'comic_sans' ] ],
            'components' => [
                'button' => [
                    'base' => [ 'radius' => 8, 'glow' => '#000', 'color' => '#000' ],
                    'colors' => [ 'brand' => [], 'primary' => [ 'main' => '{main}' ] ],
                    'appearances' => [ 'link' => [], 'solid' => [ 'background' => '{radius.md}', 'states' => [ 'disabled' => [ 'color' => 'white' ], 'visited' => [] ] ] ],
                    'sizes' => [ 'md' => [ 'height' => '{typography.huge}' ] ],
                ],
                'header' => [ 'base' => [ 'layout' => 'neon' ] ],
                'carousel' => [],
            ],
            'visibility' => [ 'footer' => 'no', 'nope' => true ],
            'images' => [ 'logotype' => 'javascript:alert(1)' ],
            'modes' => [ 'dark' => [ 'color' => [ 'primary' => '{color.text}' ] ], 'sepia' => [] ],
            'platforms' => [ 'tv' => [] ],
        ], ThemeSchemaFixture::compiled()));

        self::assertSame([
            'layouts.unknown',
            'tokens.color.primary.invalid',
            'tokens.color.brand.unknown',
            'tokens.radius.md.invalid',
            'tokens.font.body.invalid',
            'components.button.base.radius.invalid',
            'components.button.base.glow.unknown',
            'components.button.base.color.unknown',
            'components.button.colors.brand.unknown',
            'components.button.colors.primary.main.invalid',
            'components.button.appearances.link.unknown',
            'components.button.appearances.solid.background.alias',
            'components.button.appearances.solid.states.disabled.color.invalid',
            'components.button.appearances.solid.states.visited.unknown',
            'components.button.sizes.md.height.alias',
            'components.header.base.layout.invalid',
            'components.carousel.unknown',
            'visibility.footer.invalid',
            'visibility.nope.unknown',
            'images.logotype.invalid',
            'modes.dark.color.primary.invalid',
            'modes.sepia.unknown',
            'platforms.tv.unknown',
        ], $codes);
    }

    /**
     * Prior behavior: a platform overrode only components and pages, so a
     * phone-only variant of what a theme shows was a key of its own.
     */
    public function testAPlatformShowsOrHidesWhatTheSchemaCan(): void
    {
        $schema = ThemeSchemaFixture::compiled();
        $values = [
            'platforms' => [ 'mobile_web' => [ 'visibility' => [ 'header.search' => false, 'footer' => 'no', 'nope' => true ] ] ],
        ];

        self::assertSame(
            [ 'platforms.mobile_web.visibility.footer.invalid', 'platforms.mobile_web.visibility.nope.unknown' ],
            ThemeValidator::codes(ThemeValidator::validate($values, $schema))
        );
        // What the schema refuses goes; the rest stays the platform's own.
        self::assertSame(
            [ 'platforms' => [ 'mobile_web' => [ 'visibility' => [ 'header.search' => false ] ] ] ],
            ThemeResolver::valid($values, $schema)
        );
    }

    /**
     * A theme has no border by setting it to `none` (a saved null removes
     * the key); `none` is no shadow nor color.
     */
    public function testABorderMayBeSetToNone(): void
    {
        $schema = ThemeSchemaFixture::compiled();

        self::assertSame([], ThemeValidator::validate([ 'components' => [ 'card' => [ 'base' => [ 'border' => 'none' ] ] ] ], $schema));
        self::assertSame([ 'components.card.base.shadow.invalid', 'components.card.base.background.invalid' ], array_column(ThemeValidator::validate([ 'components' => [ 'card' => [ 'base' => [ 'shadow' => 'none', 'background' => 'none' ] ] ] ], $schema), 'code'));
    }
}
