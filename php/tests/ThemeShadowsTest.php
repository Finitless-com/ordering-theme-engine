<?php

declare(strict_types=1);

namespace Ordering\ThemeEngine\Tests;

use Ordering\ThemeEngine\ThemeShadows;
use PHPUnit\Framework\TestCase;

/**
 * The shadow styles the editor offers instead of each level's numbers.
 */
class ThemeShadowsTest extends TestCase
{
    public function testEachStyleIsAWholeSetOfLevels(): void
    {
        $levels = [
            'none' => null,
            'lg' => [ 'x' => 0, 'y' => 4, 'blur' => 12, 'spread' => 0, 'color' => '#000000', 'opacity' => 0.15, 'elevation' => 6 ],
        ];

        $styles = ThemeShadows::styles($levels);

        self::assertSame([ 'none' => null, 'lg' => null ], $styles['none']);
        self::assertSame($levels, $styles['normal']);
        self::assertSame([ 'x' => 0, 'y' => 3, 'blur' => 16, 'spread' => 0, 'color' => '#000000', 'opacity' => 0.09, 'elevation' => 5 ], $styles['soft']['lg']);
        self::assertSame([ 'x' => 0, 'y' => 6, 'blur' => 15, 'spread' => 0, 'color' => '#000000', 'opacity' => 0.24, 'elevation' => 9 ], $styles['strong']['lg']);
        self::assertNull($styles['soft']['none']);
        // Solid: no blur and the color at full strength.
        self::assertSame([ 'x' => 0, 'y' => 6, 'blur' => 0, 'spread' => 0, 'color' => '#000000', 'opacity' => 1, 'elevation' => 9 ], $styles['solid']['lg']);
        self::assertNull($styles['solid']['none']);
    }

    public function testADirectionCastsEveryLevelAsFarAsItFalls(): void
    {
        $levels = [
            'none' => null,
            'lg' => [ 'x' => 0, 'y' => 4, 'blur' => 12, 'spread' => 0, 'color' => '#000000', 'opacity' => 0.15, 'elevation' => 6 ],
        ];

        self::assertSame($levels, ThemeShadows::apply($levels, 'normal', null, 'down'));
        self::assertSame([ 4, 4 ], [ ThemeShadows::apply($levels, 'normal', null, 'down_right')['lg']['x'], ThemeShadows::apply($levels, 'normal', null, 'down_right')['lg']['y'] ]);
        self::assertSame([ -4, 4 ], [ ThemeShadows::apply($levels, 'normal', null, 'down_left')['lg']['x'], ThemeShadows::apply($levels, 'normal', null, 'down_left')['lg']['y'] ]);
        self::assertSame([ 'x' => 0, 'y' => 0, 'blur' => 12, 'spread' => 0, 'color' => '#000000', 'opacity' => 0.15, 'elevation' => 6 ], ThemeShadows::apply($levels, 'normal', null, 'around')['lg']);
        // Around, a shadow without blur grows instead, or it would not show.
        self::assertSame([ 'x' => 0, 'y' => 0, 'blur' => 0, 'spread' => 6, 'color' => '#FF5500', 'opacity' => 1, 'elevation' => 9 ], ThemeShadows::apply($levels, 'solid', '#FF5500', 'around')['lg']);
        self::assertSame([ 'x' => 6, 'y' => 6, 'blur' => 0, 'spread' => 0, 'color' => '#FF5500', 'opacity' => 1, 'elevation' => 9 ], ThemeShadows::apply($levels, 'solid', '#FF5500', 'down_right')['lg']);
        self::assertNull(ThemeShadows::apply($levels, 'solid', '#FF5500', 'down_right')['none']);
    }

    public function testAStyleAndAColorRemakeEveryLevel(): void
    {
        $levels = [
            'none' => null,
            'sm' => [ 'x' => 0, 'y' => 1, 'blur' => 4, 'spread' => 0, 'color' => '#000000', 'opacity' => 0.1, 'elevation' => 1 ],
        ];

        self::assertSame([ 'none' => null, 'sm' => [ 'x' => 0, 'y' => 1, 'blur' => 4, 'spread' => 0, 'color' => '#FF5500', 'opacity' => 0.1, 'elevation' => 1 ] ], ThemeShadows::apply($levels, 'normal', '#FF5500'));
        self::assertSame([ 'none' => null, 'sm' => null ], ThemeShadows::apply($levels, 'none', '#FF5500'));
        self::assertSame($levels, ThemeShadows::apply($levels, 'normal', null));
    }
}
