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
                    'base' => [ 'radius' => '{radius.full}', 'mobile_full_width' => true ],
                    'colors' => [ 'primary' => [ 'main' => '#00AA00' ], 'danger' => [ 'ink' => '{color.text}' ] ],
                    'appearances' => [ 'solid' => [ 'shadow' => '{shadow.md}', 'radius' => '{radius.none}', 'states' => [ 'pressed' => [ 'background' => '{subtle}' ], 'hover' => [ 'background' => '{pressed}', 'color' => '#FFFFFF' ] ] ],
                                       'outline' => [ 'border' => [ 'width' => '{border.thick}', 'color' => '{ink}' ] ] ],
                    'sizes' => [ 'md' => [ 'height' => 28, 'icon_size' => 18, 'text' => '{typography.label}' ] ],
                    'shapes' => [ 'icon' => [ 'radius' => '{radius.full}' ] ],
                ],
                'header' => [ 'base' => [ 'logo_position' => 'center', 'border_bottom' => [ 'width' => 1, 'color' => '{color.border}' ] ] ],
                'product_card' => [ 'base' => [ 'font_role' => 'heading', 'image_position' => 'left' ] ],
            ],
            'visibility' => [ 'footer' => false ],
            'images' => [ 'logo' => 'https://cdn.example.invalid/logo.png', 'favicon' => '' ],
            'modes' => [ 'dark' => [ 'color' => [ 'background' => '#0B1220' ] ] ],
            'platforms' => [ 'mobile_web' => [ 'components' => [ 'header' => [ 'base' => [ 'height' => 56 ] ] ] ] ],
        ], ThemeSchemaFixture::webMarketplace()));
    }

    /**
     * Prior behavior: a theme could refer to, and set, a color the engine's
     * catalog has but its schema dropped; it resolved to nothing.
     */
    public function testAThemeRefersOnlyToTheColorsItsSchemaNames(): void
    {
        $schema = ThemeSchemaFixture::webMarketplace();
        unset($schema['tokens']['color']['link'], $schema['tokens']['typography']['overline']);

        self::assertSame(
            [ 'tokens.color.link.unknown', 'components.header.base.border_bottom.color.alias', 'components.button.sizes.md.text.alias' ],
            ThemeValidator::codes(ThemeValidator::validate([
                'tokens' => [ 'color' => [ 'link' => '#0000FF' ] ],
                'components' => [
                    'header' => [ 'base' => [ 'border_bottom' => [ 'width' => 1, 'color' => '{color.link}' ] ] ],
                    'button' => [ 'sizes' => [ 'md' => [ 'text' => '{typography.overline}' ] ] ],
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
        $nodes = ThemeSchemaFixture::webMarketplaceNodes();
        $nodes['tokens']['color']['overlay']['$extensions']['co.ordering.editor']['alpha'] = true;
        $nodes['components']['modal']['base']['overlay']['$extensions']['co.ordering.editor']['alpha'] = true;
        $schema = ThemeSchemaFixture::tuples($nodes)['schema'];
        self::assertSame([ 'tokens.color.overlay', 'components.modal.base.overlay' ], $schema['alpha']);

        self::assertSame(
            [ 'tokens.color.primary.alpha', 'modes.dark.color.surface.alpha', 'components.card.base.background.alpha', 'components.header.base.border_bottom.color.alpha', 'components.button.colors.primary.main.alpha', 'components.chip.base.background.alpha' ],
            ThemeValidator::codes(ThemeValidator::validate([
                'tokens' => [ 'color' => [ 'primary' => 'rgba(255,0,0,.5)', 'overlay' => 'rgba(0,0,0,.6)', 'text' => '#000000FF' ] ],
                'modes' => [ 'dark' => [ 'color' => [ 'surface' => 'transparent' ] ] ],
                'components' => [
                    'card' => [ 'base' => [ 'background' => '#FFFFFF80' ] ],
                    'modal' => [ 'base' => [ 'overlay' => '{color.overlay}' ] ],
                    'header' => [ 'base' => [ 'border_bottom' => [ 'width' => 1, 'color' => 'transparent' ] ] ],
                    'button' => [ 'colors' => [ 'primary' => [ 'main' => '#F008' ] ] ],
                    // An alias to a translucent color is one too.
                    'chip' => [ 'base' => [ 'background' => '{color.overlay}' ] ],
                    // A border with no width shows no color.
                    'stepper' => [ 'base' => [ 'border' => [ 'width' => 0, 'style' => 'solid', 'color' => 'transparent' ] ] ],
                ],
            ], $schema))
        );

        // A schema from before the mark is not checked.
        self::assertSame([], ThemeValidator::validate([ 'tokens' => [ 'color' => [ 'primary' => 'rgba(255,0,0,.5)' ] ] ], ThemeSchemaFixture::webMarketplace()));
    }

    public function testEachProblemNamesItsPathAndRule(): void
    {
        $codes = ThemeValidator::codes(ThemeValidator::validate([
            'layouts' => [],
            'tokens' => [ 'color' => [ 'primary' => 'blue', 'brand' => '#000000' ], 'radius' => [ 'md' => '8px' ], 'font' => [ 'body' => 'comic_sans' ] ],
            'components' => [
                'button' => [
                    'base' => [ 'radius' => 8, 'text' => '{typography.label}', 'color' => '#000' ],
                    'colors' => [ 'brand' => [], 'primary' => [ 'main' => '{main}' ] ],
                    'appearances' => [ 'link' => [], 'solid' => [ 'background' => '{radius.md}', 'states' => [ 'hover' => [ 'color' => 'white' ], 'visited' => [] ] ] ],
                    'sizes' => [ 'md' => [ 'text' => '{typography.huge}' ] ],
                ],
                'header' => [ 'base' => [ 'logo_position' => 'neon' ] ],
                'carousel' => [],
            ],
            'visibility' => [ 'footer' => 'no', 'nope' => true ],
            'images' => [ 'logo' => 'javascript:alert(1)' ],
            'modes' => [ 'dark' => [ 'color' => [ 'primary' => '{color.text}' ] ], 'sepia' => [] ],
            'platforms' => [ 'tv' => [] ],
        ], ThemeSchemaFixture::webMarketplace()));

        self::assertSame([
            'layouts.unknown',
            'tokens.color.primary.invalid',
            'tokens.color.brand.unknown',
            'tokens.radius.md.invalid',
            'tokens.font.body.invalid',
            'components.button.base.radius.invalid',
            'components.button.base.text.unknown',
            'components.button.base.color.unknown',
            'components.button.colors.brand.unknown',
            'components.button.colors.primary.main.invalid',
            'components.button.appearances.link.unknown',
            'components.button.appearances.solid.background.alias',
            'components.button.appearances.solid.states.hover.color.invalid',
            'components.button.appearances.solid.states.visited.unknown',
            'components.button.sizes.md.text.alias',
            'components.header.base.logo_position.invalid',
            'components.carousel.unknown',
            'visibility.footer.invalid',
            'visibility.nope.unknown',
            'images.logo.invalid',
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
        $schema = ThemeSchemaFixture::webMarketplace();
        $values = [
            'platforms' => [ 'mobile_web' => [ 'visibility' => [ 'business.header.info' => false, 'footer' => 'no', 'nope' => true ] ] ],
        ];

        self::assertSame(
            [ 'platforms.mobile_web.visibility.footer.invalid', 'platforms.mobile_web.visibility.nope.unknown' ],
            ThemeValidator::codes(ThemeValidator::validate($values, $schema))
        );
        // What the schema refuses goes; the rest stays the platform's own.
        self::assertSame(
            [ 'platforms' => [ 'mobile_web' => [ 'visibility' => [ 'business.header.info' => false ] ] ] ],
            ThemeResolver::valid($values, $schema)
        );
    }

    /**
     * A theme has no border by setting it to `none` (a saved null removes
     * the key); `none` is no shadow nor color.
     */
    public function testABorderMayBeSetToNone(): void
    {
        $schema = ThemeSchemaFixture::webMarketplace();

        self::assertSame([], ThemeValidator::validate([ 'components' => [ 'tabs' => [ 'variants' => [ 'pill' => [ 'tab_border' => 'none' ] ] ] ] ], $schema));
        self::assertSame([ 'components.tabs.variants.pill.shadow.invalid', 'components.tabs.variants.pill.background.invalid' ], array_column(ThemeValidator::validate([ 'components' => [ 'tabs' => [ 'variants' => [ 'pill' => [ 'shadow' => 'none', 'background' => 'none' ] ] ] ] ], $schema), 'code'));
    }
}
