<?php

declare(strict_types=1);

namespace Ordering\ThemeEngine\Tests;

use Ordering\ThemeEngine\ThemeColors;
use Ordering\ThemeEngine\ThemeDerivations;
use PHPUnit\Framework\TestCase;

/**
 * What a component's values derive from its other values when the theme
 * does not set them, by the rules its schema declares (ThemeDerivations).
 */
class ThemeDerivationsTest extends TestCase
{
    /**
     * The rules the engine applied before the schema declared them.
     */
    public function testTheEngineRulesAreTheSchemasOwn(): void
    {
        $rules = ThemeDerivations::rules(ThemeSchemaFixture::webMarketplace());

        self::assertSame([ 'op' => 'step', 'from' => 'shadow', 'offset' => 0, 'when' => 'always' ], $rules['derive']['card']['variants.default.states.hover.shadow']);
        self::assertSame([ 'op' => 'shade', 'from' => 'background', 'amount' => -0.07 ], $rules['derive']['chip']['variants.default.states.pressed.background']);
        self::assertSame([ 'op' => 'inset', 'from' => 'radius', 'by' => 'inset', 'when' => 'always' ], $rules['derive']['tabs']['variants.pill.tab_radius']);
        self::assertSame([ 'button' ], array_keys($rules['matrix']));
        self::assertSame(ThemeDerivations::ROLES, $rules['matrix']['button']['roles']);
    }

    /**
     * Without the schema's levels, a hover shadow the theme does not set is
     * one level above the resting one; the one it sets is kept.
     */
    public function testAHoverShadowIsOneLevelAboveTheRestingOne(): void
    {
        $card = fn ($resting, $hover = '{shadow.md}') => [
            'base' => [ 'shadow' => $resting ],
            'variants' => [ 'default' => [ 'states' => [ 'hover' => [ 'shadow' => $hover ] ] ] ],
        ];
        $hover = fn (array $components, string $name) => $components[$name]['variants']['default']['states']['hover']['shadow'];
        $step = [ 'variants.default.states.hover.shadow' => [ 'op' => 'step', 'from' => 'shadow', 'offset' => 1, 'when' => 'always' ] ];
        $names = [ 'sm', 'md', 'lg', 'xl', 'none', 'null', 'numeric', 'set', 'variant' ];

        $components = ThemeDerivations::steps([
            'sm' => $card('{shadow.sm}'),
            'md' => $card('{shadow.md}'),
            'lg' => $card('{shadow.lg}'),
            'xl' => $card('{shadow.xl}'),
            'none' => $card('{shadow.none}'),
            'null' => $card(null),
            'numeric' => $card([ 'x' => 0, 'y' => 2, 'blur' => 4, 'spread' => 0, 'color' => '#000000', 'opacity' => 0.1 ]),
            'set' => $card('{shadow.xl}', '{shadow.sm}'),
            'variant' => [ 'base' => [ 'shadow' => '{shadow.sm}' ], 'variants' => [ 'default' => [ 'shadow' => '{shadow.lg}', 'states' => [ 'hover' => [ 'shadow' => '{shadow.md}' ] ] ] ] ],
            'button' => [ 'base' => [ 'shadow' => '{shadow.sm}' ], 'variants' => [ 'primary' => [ 'states' => [ 'pressed' => [ 'opacity' => 0.8 ] ] ] ] ],
        ], [ 'set' => [ 'variants' => [ 'default' => [ 'states' => [ 'hover' => [ 'shadow' => '{shadow.sm}' ] ] ] ] ] ], array_fill_keys($names, $step));

        self::assertSame([ '{shadow.md}', '{shadow.lg}', '{shadow.xl}', '{shadow.xl}', '{shadow.sm}', '{shadow.sm}' ], array_map(fn ($name) => $hover($components, $name), [ 'sm', 'md', 'lg', 'xl', 'none', 'null' ]));
        // A resting shadow that is not a level keeps the schema's hover.
        self::assertSame('{shadow.md}', $hover($components, 'numeric'));
        self::assertSame('{shadow.sm}', $hover($components, 'set'));
        // The variant's own resting shadow goes over the base's.
        self::assertSame('{shadow.xl}', $hover($components, 'variant'));
        self::assertArrayNotHasKey('hover', $components['button']['variants']['primary']['states']);
    }

    /**
     * A state keeps the schema's distance from the resting shadow: a card's
     * hover and focus, at its resting level in the schema, stay there when
     * Identity raises it. Prior behavior: the card's hover rose one level
     * and its focus stayed at `sm`, under a resting `lg`.
     */
    public function testAStateKeepsTheSchemasDistanceFromTheRestingShadow(): void
    {
        $spec = fn ($rest, array $states) => [
            'base' => [ 'shadow' => [ 'shadow', $rest ] ],
            'variants' => [ 'default' => [ 'states' => array_map(fn ($level) => [ 'shadow' => [ 'shadow', $level ] ], $states) ] ],
        ];
        $served = fn ($rest, array $states) => [
            'base' => [ 'shadow' => $rest ],
            'variants' => [ 'default' => [ 'states' => array_map(fn ($level) => [ 'shadow' => $level ], $states) ] ],
        ];
        $rules = ThemeDerivations::rules([ 'components' => [
            'card' => $spec('{shadow.sm}', [ 'hover' => '{shadow.sm}', 'focus' => '{shadow.sm}' ]),
            'business_card' => $spec('{shadow.sm}', [ 'hover' => '{shadow.md}' ]),
            'raised' => $spec('{shadow.md}', [ 'pressed' => '{shadow.sm}' ]),
            // Its states' levels are not the schema's: a hover is one above, another state keeps its own.
            'unknown' => [ 'variants' => [ 'default' => [ 'states' => [ 'focus' => [ 'shadow' => [ 'shadow', null ] ], 'hover' => [ 'shadow' => [ 'shadow', [ 'x' => 0 ] ] ] ] ] ] ],
        ] ])['derive'];

        $components = ThemeDerivations::steps([
            'card' => $served('{shadow.lg}', [ 'hover' => '{shadow.sm}', 'focus' => '{shadow.sm}' ]),
            'business_card' => $served('{shadow.lg}', [ 'hover' => '{shadow.md}' ]),
            'raised' => $served('{shadow.none}', [ 'pressed' => '{shadow.sm}' ]),
            'unknown' => $served('{shadow.lg}', [ 'focus' => '{shadow.sm}', 'hover' => '{shadow.sm}' ]),
        ], [], $rules);
        $states = fn (string $name) => array_map(fn ($props) => $props['shadow'], $components[$name]['variants']['default']['states']);

        self::assertSame([ 'hover' => '{shadow.lg}', 'focus' => '{shadow.lg}' ], $states('card'));
        self::assertSame([ 'hover' => '{shadow.xl}' ], $states('business_card'));
        // Never below none.
        self::assertSame([ 'pressed' => '{shadow.none}' ], $states('raised'));
        self::assertSame([ 'focus' => '{shadow.sm}', 'hover' => '{shadow.xl}' ], $states('unknown'));
    }

    /**
     * A variant whose background the theme changes presses darker than
     * it, not in the color its pressed state had for the old background;
     * one it sets to its default changes nothing.
     */
    public function testAVariantPressesDarkerThanTheBackgroundTheThemeGivesIt(): void
    {
        $resolved = [ 'button' => [ 'variants' => [
            'primary' => [ 'background' => '#00AA00', 'states' => [ 'pressed' => [ 'background' => '#1F66C9' ] ] ],
            'outline' => [ 'background' => 'transparent', 'states' => [ 'pressed' => [ 'background' => '#2C7BE5' ] ] ],
            'danger' => [ 'background' => '#E63757', 'states' => [ 'pressed' => [ 'background' => '#000000' ] ] ],
            'neutral' => [ 'background' => '#EFEFEF', 'states' => [ 'pressed' => [ 'background' => '#DDDDDD' ] ] ],
        ] ] ];
        $set = [ 'button' => [ 'variants' => [
            'primary' => [ 'background' => '#00AA00' ],
            'outline' => [ 'color' => '#FF0000' ],
            'danger' => [ 'background' => '#E63757', 'states' => [ 'pressed' => [ 'background' => '#000000' ] ] ],
            'neutral' => [ 'background' => '{color.secondary}' ],
        ] ] ];
        $specs = [ 'button' => [ 'variants' => array_map(fn ($default) => [ 'background' => [ 'color', $default ], 'states' => [ 'pressed' => [ 'background' => [ 'color', null ] ] ] ], [
            'primary' => '{color.primary}', 'outline' => 'transparent', 'danger' => '{color.danger}', 'neutral' => '{color.secondary}',
        ]) ] ];

        $components = ThemeDerivations::shades($resolved, $set, ThemeDerivations::rules([ 'components' => $specs ])['derive'], $specs);

        self::assertSame('#008600', $components['button']['variants']['primary']['states']['pressed']['background']);
        self::assertSame('#2C7BE5', $components['button']['variants']['outline']['states']['pressed']['background']);
        self::assertSame('#000000', $components['button']['variants']['danger']['states']['pressed']['background']);
        self::assertSame('#DDDDDD', $components['button']['variants']['neutral']['states']['pressed']['background']);
    }

    /**
     * A pill tab's rounding is its container's less its inset; a full one
     * stays full, and the one the theme sets is kept.
     */
    public function testATabsRoundingIsItsContainersLessItsInset(): void
    {
        $rules = [ 'tabs' => [ 'variants.pill.tab_radius' => [ 'op' => 'inset', 'from' => 'radius', 'by' => 'inset', 'when' => 'always' ] ] ];
        $tabs = fn ($radius, $tab = 8) => [ 'variants' => [ 'pill' => [ 'radius' => $radius, 'inset' => 4, 'tab_radius' => $tab ] ] ];

        self::assertSame(16, ThemeDerivations::insets([ 'tabs' => $tabs(20) ], [], $rules, 9999)['tabs']['variants']['pill']['tab_radius']);
        self::assertSame(9999, ThemeDerivations::insets([ 'tabs' => $tabs(9999) ], [], $rules, 9999)['tabs']['variants']['pill']['tab_radius']);
        self::assertSame(0, ThemeDerivations::insets([ 'tabs' => $tabs(2) ], [], $rules, 9999)['tabs']['variants']['pill']['tab_radius']);
        self::assertSame(3, ThemeDerivations::insets([ 'tabs' => $tabs(20, 3) ], [ 'tabs' => [ 'variants' => [ 'pill' => [ 'tab_radius' => 3 ] ] ] ], $rules, 9999)['tabs']['variants']['pill']['tab_radius']);
        // A value the variant does not have is its base's.
        $based = [ 'base' => [ 'inset' => 6 ], 'variants' => [ 'pill' => [ 'radius' => 20, 'tab_radius' => 8 ] ] ];
        self::assertSame(14, ThemeDerivations::insets([ 'tabs' => $based ], [], $rules, 9999)['tabs']['variants']['pill']['tab_radius']);
    }

    /**
     * Every color in every appearance, each role derived when the color has
     * none, and again when the theme sets what it derives from.
     */
    public function testAMatrixPaintsEachColorInEachAppearance(): void
    {
        $button = [
            'colors' => [
                'primary' => [ 'main' => '#00AA55', 'on' => '#FFFFFF', 'subtle' => '#E9F2FE', 'pressed' => '#1A69D3' ],
                'neutral' => [ 'main' => '#F8F9FA', 'on' => '#000000', 'ink' => '#000000' ],
            ],
            'appearances' => [ 'solid' => [ 'background' => '{main}', 'color' => '{on}', 'states' => [ 'pressed' => [ 'background' => '{pressed}' ] ] ], 'ghost' => [ 'color' => '{ink}', 'states' => [ 'hover' => [ 'background' => '{subtle}' ] ] ] ],
        ];
        $matrix = [ 'button' => [ 'into' => 'variants', 'roles' => ThemeDerivations::ROLES ] ];

        $painted = ThemeDerivations::matrices([ 'button' => $button ], [ 'background' => '#FFFFFF' ], [ 'button' => [ 'colors' => [ 'primary' => [ 'main' => '#00AA55' ] ] ] ], $matrix)['button'];

        // The theme sets the primary's main: its subtle and pressed follow it, not the schema's.
        self::assertSame([ '#00AA55', '#008643', '#E0F5EB' ], [ $painted['variants']['primary']['solid']['background'], $painted['variants']['primary']['solid']['states']['pressed']['background'], $painted['variants']['primary']['ghost']['states']['hover']['background'] ]);
        // The neutral keeps its own ink, and derives the rest.
        self::assertSame('#000000', $painted['variants']['neutral']['ghost']['color']);
        self::assertSame([ 'main', 'on', 'ink', 'subtle', 'pressed' ], array_keys($painted['colors']['neutral']));
        // Without the theme, the schema's roles stay.
        $unset = ThemeDerivations::matrices([ 'button' => $button ], [ 'background' => '#FFFFFF' ], [], $matrix)['button'];
        self::assertSame([ '#1A69D3', '#E9F2FE' ], [ $unset['colors']['primary']['pressed'], $unset['colors']['primary']['subtle'] ]);
    }

    /**
     * Prior behavior: a matrix role took only `same`, `shade` and `mix`, so
     * an ink legible over the page could not be declared; it stayed null.
     */
    public function testAMatrixRoleIsLegibleOverAColor(): void
    {
        $button = [
            'colors' => [ 'warning' => [ 'main' => '#F5C400' ], 'primary' => [ 'main' => '#1E72E3', 'ink' => '#000000' ] ],
            'appearances' => [ 'ghost' => [ 'color' => '{ink}' ] ],
        ];
        $matrix = [ 'button' => [ 'into' => 'variants', 'roles' => [ 'ink' => [ 'op' => 'legible', 'from' => 'main', 'over' => '{color.background}', 'contrast' => 5.5, 'step' => 0.01, 'when' => 'missing' ] ] ] ];

        $painted = ThemeDerivations::matrices([ 'button' => $button ], [ 'background' => '#FFFFFF' ], [], $matrix)['button'];

        // The warning's yellow is too light on white: its ink is that yellow, darkened.
        $ink = $painted['variants']['warning']['ghost']['color'];
        self::assertNotSame('#F5C400', $ink);
        self::assertSame(ThemeColors::legible('#F5C400', '#FFFFFF', 5.5, 0.01), $ink);
        // `when: missing`: a color with its own ink keeps it.
        self::assertSame('#000000', $painted['variants']['primary']['ghost']['color']);
    }
}
