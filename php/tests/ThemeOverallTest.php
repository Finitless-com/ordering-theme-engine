<?php

declare(strict_types=1);

namespace Ordering\ThemeEngine\Tests;

use Ordering\ThemeEngine\ThemeOverall;
use Ordering\ThemeEngine\ThemeSchemas;
use PHPUnit\Framework\TestCase;

/**
 * The theme-wide shadow size, rounding and border of Identity.
 */
class ThemeOverallTest extends TestCase
{
    public function testTheyBecomeEveryComponentsDefault(): void
    {
        $schema = ThemeSchemaFixture::webMarketplace();
        $components = ThemeOverall::apply(ThemeSchemas::defaults($schema), $schema, [ 'shadow' => 'lg', 'radius' => 'xl' ])['components'];

        self::assertSame([ '{radius.xl}', '{shadow.lg}' ], [ $components['card']['base']['radius'], $components['card']['base']['shadow'] ]);
        self::assertSame([ '{radius.xl}', '{shadow.lg}' ], [ $components['input']['base']['radius'], $components['input']['base']['shadow'] ]);
        // An icon button is the button's icon shape: the rounding of the
        // theme, the shadow of its appearance.
        self::assertSame('{radius.xl}', $components['button']['shapes']['icon']['radius']);
        self::assertSame('{shadow.lg}', $components['button']['appearances']['solid']['shadow']);
        self::assertSame('{shadow.lg}', $components['header']['base']['shadow']);
        self::assertSame('{shadow.lg}', $components['cart']['base']['shadow']);
        self::assertSame('{shadow.lg}', $components['bottom_nav']['base']['shadow']);
        self::assertSame([ 'width' => '{border.thick}', 'color' => '{color.border}', 'style' => 'solid' ], ThemeOverall::apply(ThemeSchemas::defaults($schema), $schema, [ 'border_width' => 'thick' ])['components']['bottom_nav']['base']['border_top']);
    }

    /**
     * A followed border whose default is `"none"` takes Identity's width,
     * and a declared path the defaults do not have is not created.
     */
    public function testAFollowedBorderOfNoneAndAPathThatIsNotThere(): void
    {
        $schema = ThemeSchemaFixture::webMarketplace();
        $schema['components']['card']['base']['border'] = [ 'border', 'none' ];
        $schema['follows']['components.card.base.border'] = 'border';
        $schema['follows']['components.card.variants.ghost.shadow'] = 'shadow';
        $defaults = ThemeSchemas::defaults($schema);

        $applied = ThemeOverall::apply($defaults, $schema, [ 'border_width' => 'thin', 'shadow' => 'lg' ]);
        self::assertSame([ 'width' => '{border.thin}', 'color' => '{color.border}', 'style' => 'solid' ], $applied['components']['card']['base']['border']);
        self::assertArrayNotHasKey('ghost', $applied['components']['card']['variants']);
    }

    public function testWhatIsSoByDesignKeepsItsDefault(): void
    {
        $schema = ThemeSchemaFixture::webMarketplace();
        // A component whose default shadow is given as numbers: it does not follow Identity.
        $schema['components']['bottom_nav']['base']['shadow'] = [ 'shadow', [ 'x' => 0, 'y' => -1, 'blur' => 4, 'spread' => 0, 'color' => '#000000', 'opacity' => 0.1, 'elevation' => 4 ] ];
        unset($schema['follows']['components.bottom_nav.base.shadow']);
        $defaults = ThemeSchemas::defaults($schema);
        $components = ThemeOverall::apply($defaults, $schema, [ 'shadow' => 'xl', 'radius' => 'sm' ])['components'];

        // Nothing to cast a shadow from.
        self::assertNull($components['button']['appearances']['outline']['shadow']);
        self::assertNull($components['button']['appearances']['ghost']['shadow']);
        // Pills.
        self::assertSame('{radius.full}', $components['chip']['base']['radius']);
        // A state, a shadow given as numbers, a platform.
        self::assertSame($defaults['components']['business_card']['variants']['default']['states'], $components['business_card']['variants']['default']['states']);
        self::assertSame($defaults['components']['bottom_nav']['base']['shadow'], $components['bottom_nav']['base']['shadow']);
        self::assertSame($defaults['platforms'], ThemeOverall::apply($defaults, $schema, [ 'shadow' => 'xl', 'radius' => 'sm' ])['platforms']);
        // Neither set: the defaults as they are.
        self::assertSame($defaults, ThemeOverall::apply($defaults, $schema, [ 'shadow' => null, 'radius' => null ]));
    }

    public function testTheBorderWidthAndStyleGoWhereverABorderMayBe(): void
    {
        $schema = ThemeSchemaFixture::webMarketplace();
        // A component whose default has a border of its own.
        $schema['components']['menu']['base']['border'] = [ 'border', [ 'width' => '{border.thin}', 'color' => '#CCCCCC', 'style' => 'solid' ] ];
        $defaults = ThemeSchemas::defaults($schema);

        $thick = ThemeOverall::apply($defaults, $schema, [ 'border_width' => 'thick', 'border_style' => 'dashed' ])['components'];
        // Without a border, one of the border color; with one, its color.
        self::assertSame([ 'width' => '{border.thick}', 'color' => '{color.border}', 'style' => 'dashed' ], $thick['card']['base']['border']);
        self::assertSame([ 'width' => '{border.thick}', 'color' => '{color.border}', 'style' => 'dashed' ], $thick['header']['base']['border_bottom']);
        self::assertSame([ 'width' => '{border.thick}', 'color' => '#CCCCCC', 'style' => 'dashed' ], $thick['menu']['base']['border']);
        // By design: a checkbox's box, a button's appearance, a state.
        self::assertSame($defaults['components']['checkbox']['base']['border'], $thick['checkbox']['base']['border']);
        self::assertSame($defaults['components']['button']['appearances'], $thick['button']['appearances']);
        self::assertNull($thick['input']['variants']['default']['states']['focus']['border']);

        $none = ThemeOverall::apply($defaults, $schema, [ 'border_width' => 'none' ])['components'];
        self::assertSame([ null, null ], [ $none['menu']['base']['border'], $none['card']['base']['border'] ]);
        // A style alone changes the borders there are.
        $dotted = ThemeOverall::apply($defaults, $schema, [ 'border_style' => 'dotted' ])['components'];
        self::assertSame([ 'dotted', null ], [ $dotted['menu']['base']['border']['style'], $dotted['card']['base']['border'] ]);
    }
}
