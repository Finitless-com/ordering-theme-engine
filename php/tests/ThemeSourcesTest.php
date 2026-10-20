<?php

declare(strict_types=1);

namespace Ordering\ThemeEngine\Tests;

use Ordering\ThemeEngine\ThemeResolver;
use Ordering\ThemeEngine\ThemeSources;
use PHPUnit\Framework\TestCase;

/**
 * Where each value of a theme comes from (ThemeSources), so the editor
 * says it without resolving anything itself.
 */
class ThemeSourcesTest extends TestCase
{
    public function testEachValueSaysWhetherTheThemeAParentIdentityOrADerivationGivesIt(): void
    {
        $schema = ThemeSchemaFixture::webMarketplace();
        $sources = ThemeSources::of([
            'tokens' => [ 'color' => [ 'primary' => '#FF5500' ], 'typography' => [ 'h1' => [ 'size' => 30, 'line' => 1.27, 'weight' => 700 ] ] ],
            'components' => [
                'card' => [ 'base' => [ 'background' => '#FFF8E1', 'border' => [ 'width' => 1, 'color' => '#000000', 'style' => 'solid' ] ] ],
                'header' => [ 'scope' => [ 'button' => [ 'base' => [ 'radius' => '{radius.none}' ] ] ] ],
            ],
            'visibility' => [ 'business.header.info' => false ],
            'platforms' => [ 'mobile_web' => [
                'components' => [ 'modal' => [ 'base' => [ 'radius' => '{radius.sm}' ] ] ],
                'visibility' => [ 'business.header.info' => true ],
            ] ],
            'nope' => true,
        ], $schema);

        // A composite value (a typography style, a border) is one value.
        foreach ([
            'tokens.color.primary', 'tokens.typography.h1', 'components.card.base.background', 'components.card.base.border',
            'components.header.scope.button.base.radius', 'visibility.business.header.info', 'platforms.mobile_web.components.modal.base.radius',
            'platforms.mobile_web.visibility.business.header.info',
        ] as $path) {
            self::assertSame('own', $sources[$path] ?? null, $path);
        }
        self::assertArrayNotHasKey('tokens.typography.h1.size', $sources);
        // What the schema refuses is no source.
        self::assertArrayNotHasKey('nope', $sources);
        self::assertSame('inherited', $sources['components.business_card.base.background']);
        self::assertSame('inherited', $sources['components.offer_card.base.border']);
        self::assertSame('identity', $sources['components.input.base.radius']);
        self::assertSame('derived', $sources['tokens.color.primary_pressed']);
        // The schema's defaults are not listed.
        self::assertArrayNotHasKey('tokens.color.secondary', $sources);
        self::assertArrayNotHasKey('components.card.base.padding', $sources);
    }

    /**
     * The alias each value resolves from, so the editor names a level
     * (High · 20) instead of guessing it from the number.
     */
    public function testEachValueSaysTheAliasItResolvedFrom(): void
    {
        $schema = ThemeSchemaFixture::webMarketplace();
        $resolved = ThemeResolver::resolve([ 'components' => [ 'card' => [ 'base' => [ 'radius' => '{radius.xl}' ] ] ] ], $schema, $unresolved);
        $aliases = ThemeSources::aliases($unresolved, $schema);

        self::assertSame([ 20, '{radius.xl}' ], [ $resolved['components']['card']['base']['radius'], $aliases['components.card.base.radius'] ]);
        // Inherited by a specific card, aliased the same.
        self::assertSame('{radius.xl}', $aliases['components.business_card.base.radius']);
        // A level that follows Identity, and a composite value's part.
        self::assertSame('{radius.md}', $aliases['components.input.base.radius']);
        self::assertSame('{typography.button}', $aliases['components.button.sizes.md.text']);
        self::assertArrayNotHasKey('tokens.color.primary', $aliases);
    }
}
