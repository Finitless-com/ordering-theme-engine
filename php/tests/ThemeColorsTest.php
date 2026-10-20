<?php

declare(strict_types=1);

namespace Ordering\ThemeEngine\Tests;

use Ordering\ThemeEngine\ThemeColors;
use PHPUnit\Framework\TestCase;

/**
 * A theme that changes its primary gets a pressed and a subtle primary, and
 * a focus color, that follow it. Prior behavior: they kept the schema's
 * defaults, made for the default blue, so an orange web's buttons turned
 * blue on hover and its active tab was light blue.
 */
class ThemeColorsTest extends TestCase
{
    public function testWhatTheThemeDoesNotSetFollowsWhatItDoes(): void
    {
        $defaults = [ 'primary' => '#2C7BE5', 'primary_pressed' => '#1F66C9', 'primary_subtle' => '#E9F2FE', 'focus' => '#2C7BE5', 'danger' => '#E63757', 'danger_subtle' => '#FEEDF0', 'secondary' => '#EFEFEF', 'secondary_pressed' => '#E2E2E2' ];
        $colors = ThemeColors::derive(
            [ 'primary' => '#FF5500', 'danger' => 'rgb(230, 55, 87)' ] + $defaults,
            [ 'primary' => '#FF5500', 'danger' => 'rgb(230, 55, 87)' ],
            $defaults
        );

        // darken(.07) and 12% over white, as the web painted them.
        self::assertSame('#DB4900', $colors['primary_pressed']);
        self::assertSame('#FFEBE0', $colors['primary_subtle']);
        self::assertSame('#FF5500', $colors['focus']);
        self::assertSame('#FCE7EB', $colors['danger_subtle']);
        // The secondary is not set: its derived colors stay.
        self::assertSame('#E2E2E2', $colors['secondary_pressed']);
    }

    /**
     * A color derived from a derived one sees it already derived, and
     * `when` says when a rule derives at all.
     */
    public function testADerivedColorChangesWhatDerivesFromIt(): void
    {
        $defaults = [ 'primary' => '#2C7BE5', 'primary_pressed' => '#1A69D3', 'focus' => '#1A69D3' ];
        $rules = [
            'primary_pressed' => [ 'op' => 'shade', 'from' => '{color.primary}', 'amount' => -0.07 ],
            'focus' => [ 'op' => 'same', 'from' => '{color.primary_pressed}' ],
        ];
        $colors = ThemeColors::derive([ 'primary' => '#FF5500' ] + $defaults, [ 'primary' => '#FF5500' ], $defaults, $rules);
        self::assertSame([ '#DB4900', '#DB4900' ], [ $colors['primary_pressed'], $colors['focus'] ]);

        $missing = [ 'focus' => [ 'op' => 'same', 'from' => '{color.primary}', 'when' => 'missing' ] ];
        self::assertSame('#1A69D3', ThemeColors::derive([ 'primary' => '#FF5500' ] + $defaults, [ 'primary' => '#FF5500' ], $defaults, $missing)['focus']);
        self::assertSame('#FF5500', ThemeColors::derive([ 'primary' => '#FF5500', 'focus' => null ] + $defaults, [ 'primary' => '#FF5500' ], $defaults, $missing)['focus']);
        $always = [ 'focus' => [ 'op' => 'same', 'from' => '{color.primary}', 'when' => 'always' ] ];
        self::assertSame('#2C7BE5', ThemeColors::derive($defaults, [], $defaults, $always)['focus']);
    }

    public function testAColorTheThemeSetsIsNeverDerived(): void
    {
        $colors = ThemeColors::derive(
            [ 'primary' => '#FF5500', 'primary_pressed' => '#000000', 'primary_subtle' => '#E9F2FE' ],
            [ 'primary' => '#FF5500', 'primary_pressed' => '#000000' ],
            [ 'primary' => '#2C7BE5', 'primary_pressed' => '#1F66C9', 'primary_subtle' => '#E9F2FE' ]
        );

        self::assertSame('#000000', $colors['primary_pressed']);
        self::assertSame('#FFEBE0', $colors['primary_subtle']);
    }

    /**
     * A subtle color is a tint over the page. Prior behavior: it was always
     * over white, so on a dark page a ghost button's hover was almost
     * white under its light text (contrast 1.18).
     */
    public function testASubtleColorIsATintOverTheBackground(): void
    {
        self::assertSame('#FFEBE0', ThemeColors::subtle('#FF5500'));
        self::assertSame('#FFEBE0', ThemeColors::subtle('#FF5500', '#FFFFFF'));
        self::assertSame('#FFEBE0', ThemeColors::subtle('#FF5500', 'not-a-color'));
        self::assertSame('#2D2D2D', ThemeColors::subtle('#F5F5F5', '#121212'));
    }

    /**
     * The schema's subtle colors were made for a white page: a theme that
     * makes it dark gets them over its background, though it keeps the
     * primary; one that makes it white keeps them.
     */
    public function testADarkBackgroundDerivesTheSubtleColors(): void
    {
        // A color the schema derives and does not have is derived (`when: changed` with nothing to keep).
        $defaults = [ 'background' => '#FFFFFF', 'primary' => '#2C7BE5', 'primary_pressed' => '#1F66C9', 'primary_subtle' => '#E9F2FE', 'primary_text' => '#1E72E3', 'focus' => '#2C7BE5', 'danger' => '#E63757', 'danger_subtle' => '#FEEDF0' ];

        $dark = ThemeColors::derive([ 'background' => '#121212' ] + $defaults, [ 'background' => '#121212', 'danger_subtle' => '#FEEDF0' ], $defaults);
        self::assertSame('#151F2B', $dark['primary_subtle']);
        self::assertSame('#1F66C9', $dark['primary_pressed']);
        self::assertSame('#FEEDF0', $dark['danger_subtle']);

        self::assertSame($defaults, ThemeColors::derive($defaults, [ 'background' => '#FFFFFF' ], $defaults));
        // A color set to its default changes nothing: the schema's subtle,
        // chosen by hand, stays. Prior behavior: setting the primary at all
        // derived its subtle again (#E6EFFC).
        self::assertSame('#E9F2FE', ThemeColors::derive($defaults, [ 'background' => '#FFFFFF', 'primary' => '#2C7BE5' ], $defaults)['primary_subtle']);
    }

    /**
     * The primary as text keeps 4.5:1 over the background. Prior behavior:
     * text in the primary was the primary itself, so a light primary's
     * active tab or points were unreadable (contrast 1.31 and 1.41).
     */
    public function testThePrimaryAsTextIsLegibleOverTheBackground(): void
    {
        // The schema's default: its primary, darkened to 4.5:1 over white.
        self::assertSame('#1E72E3', ThemeColors::legible('#2C7BE5', '#FFFFFF'));
        self::assertSame('#1E72E3', ThemeColors::legible('#2C7BE5'));
        // Legible already: the color itself, as written.
        self::assertSame('rgb(0, 0, 0)', ThemeColors::legible('rgb(0, 0, 0)', '#FFFFFF'));
        // Lighter over a dark background.
        self::assertSame('#3C7CDF', ThemeColors::legible('#1A4FA0', '#121212'));
        self::assertNull(ThemeColors::legible('not-a-color'));

        $defaults = [ 'background' => '#FFFFFF', 'primary' => '#2C7BE5', 'primary_text' => '#1E72E3', 'primary_subtle' => '#E9F2FE', 'primary_pressed' => '#1A69D3', 'focus' => '#2C7BE5' ];
        $yellow = ThemeColors::derive([ 'primary' => '#FFD600' ] + $defaults, [ 'primary' => '#FFD600' ], $defaults);
        self::assertSame('#8A7400', $yellow['primary_text']);
        // A background the theme sets, though it keeps the primary.
        self::assertSame('#3C7CDF', ThemeColors::derive([ 'background' => '#121212', 'primary' => '#1A4FA0' ] + $defaults, [ 'background' => '#121212' ], $defaults)['primary_text']);
        // The one the theme sets is kept.
        self::assertSame('#FFD600', ThemeColors::derive([ 'primary' => '#FFD600', 'primary_text' => '#FFD600' ] + $defaults, [ 'primary' => '#FFD600', 'primary_text' => '#FFD600' ], $defaults)['primary_text']);
        self::assertSame($defaults, ThemeColors::derive($defaults, [], $defaults));
    }
}
