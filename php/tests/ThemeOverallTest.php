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
        $schema = ThemeSchemaFixture::compiled();
        $components = ThemeOverall::apply(ThemeSchemas::defaults($schema), $schema, [ 'shadow' => 'lg', 'radius' => 'xl' ])['components'];

        self::assertSame([ '{radius.xl}', '{shadow.lg}' ], [ $components['card']['base']['radius'], $components['card']['base']['shadow'] ]);
        self::assertSame([ '{radius.xl}', '{radius.xl}', '{radius.xl}', '{radius.xl}' ], [ $components['input']['base']['radius'], $components['modal']['base']['radius'], $components['business_card']['base']['radius'], $components['tabs']['variants']['pill']['radius'] ]);
        // A rounding whose options leave Identity's level out keeps its own.
        self::assertSame('{radius.md}', $components['button']['base']['radius']);
        self::assertSame('{shadow.lg}', $components['button']['appearances']['solid']['shadow']);
        self::assertSame([ 'width' => '{border.thick}', 'color' => '{color.border}', 'style' => 'solid' ], ThemeOverall::apply(ThemeSchemas::defaults($schema), $schema, [ 'border_width' => 'thick' ])['components']['card']['base']['border']);
    }

    /**
     * A followed border whose default is `"none"` takes Identity's width,
     * and a declared path the defaults do not have is not created.
     */
    public function testAFollowedBorderOfNoneAndAPathThatIsNotThere(): void
    {
        $schema = ThemeSchemaFixture::compiled();
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
        $schema = ThemeSchemaFixture::compiled();
        // A component whose default shadow is given as numbers: it does not follow Identity.
        $schema['components']['modal']['base']['shadow'] = [ 'shadow', [ 'x' => 0, 'y' => -1, 'blur' => 4, 'spread' => 0, 'color' => '#000000', 'opacity' => 0.1, 'elevation' => 4 ] ];
        $defaults = ThemeSchemas::defaults($schema);
        $components = ThemeOverall::apply($defaults, $schema, [ 'shadow' => 'xl', 'radius' => 'sm' ])['components'];

        // Nothing to cast a shadow from.
        self::assertNull($components['button']['appearances']['outline']['shadow']);
        // Pills.
        self::assertSame('{radius.full}', $components['button']['shapes']['pill']['radius']);
        // A state, a shadow given as numbers, a platform.
        self::assertSame($defaults['components']['card']['variants']['default']['states'], $components['card']['variants']['default']['states']);
        self::assertSame($defaults['components']['modal']['base']['shadow'], $components['modal']['base']['shadow']);
        self::assertSame($defaults['platforms'], ThemeOverall::apply($defaults, $schema, [ 'shadow' => 'xl', 'radius' => 'sm' ])['platforms']);
        // Neither set: the defaults as they are.
        self::assertSame($defaults, ThemeOverall::apply($defaults, $schema, [ 'shadow' => null, 'radius' => null ]));
    }

    public function testTheBorderWidthAndStyleGoWhereverABorderMayBe(): void
    {
        $schema = ThemeSchemaFixture::compiled();
        // A followed border whose default has a color of its own.
        $schema['components']['input']['base']['border'] = [ 'border', [ 'width' => '{border.thin}', 'color' => '#CCCCCC', 'style' => 'solid' ] ];
        $schema['follows']['components.input.base.border'] = 'border';
        $defaults = ThemeSchemas::defaults($schema);

        $thick = ThemeOverall::apply($defaults, $schema, [ 'border_width' => 'thick', 'border_style' => 'dashed' ])['components'];
        // Without a border, one of the border color; with one, its color.
        self::assertSame([ 'width' => '{border.thick}', 'color' => '{color.border}', 'style' => 'dashed' ], $thick['card']['base']['border']);
        self::assertSame([ 'width' => '{border.thick}', 'color' => '#CCCCCC', 'style' => 'dashed' ], $thick['input']['base']['border']);
        // By design: a button's appearance, a state.
        self::assertSame($defaults['components']['button']['appearances'], $thick['button']['appearances']);
        self::assertSame($defaults['components']['card']['variants'], $thick['card']['variants']);

        $none = ThemeOverall::apply($defaults, $schema, [ 'border_width' => 'none' ])['components'];
        self::assertSame([ null, null ], [ $none['input']['base']['border'], $none['card']['base']['border'] ]);
        // A style alone changes the borders there are.
        $dotted = ThemeOverall::apply($defaults, $schema, [ 'border_style' => 'dotted' ])['components'];
        self::assertSame([ 'dotted', null ], [ $dotted['input']['base']['border']['style'], $dotted['card']['base']['border'] ]);
    }
}
