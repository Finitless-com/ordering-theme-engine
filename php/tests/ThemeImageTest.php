<?php

declare(strict_types=1);

namespace Ordering\ThemeEngine\Tests;

use Ordering\ThemeEngine\ThemeImage;
use Ordering\ThemeEngine\ThemeSchemas;
use PHPUnit\Framework\TestCase;

/**
 * A theme image is one of the formats its key takes in the schema (JPG,
 * PNG, WebP and AVIF by default; the logos also SVG, the favicon also
 * ICO), with what the editor recommends for it. api-core inspects the
 * uploaded file against them.
 */
class ThemeImageTest extends TestCase
{
    public function testTheSchemaSaysWhatEachKeyTakes(): void
    {
        $schema = ThemeSchemaFixture::webMarketplace();
        self::assertSame([ 'jpg', 'png', 'webp', 'avif', 'svg' ], ThemeImage::formats($schema, 'logo'));
        self::assertSame([ 'jpg', 'png', 'webp', 'avif', 'svg' ], ThemeImage::formats($schema, 'logo_mobile'));
        self::assertSame([ 'jpg', 'png', 'webp', 'avif', 'ico' ], ThemeImage::formats($schema, 'favicon'));
        self::assertSame(ThemeImage::DEFAULT_FORMATS, ThemeImage::formats($schema, 'home_hero'));
        self::assertNull(ThemeImage::formats($schema, 'unknown'));

        self::assertFalse(ThemeImage::validFormats([]));
        self::assertFalse(ThemeImage::validFormats([ 'png', 'gif' ]));
        self::assertFalse(ThemeImage::validFormats([ 'png', 'png' ]));
        self::assertTrue(ThemeImage::validFormats([ 'svg' ]));

        self::assertSame([], ThemeSchemas::check($schema));
        $schema['images']['logo'][2] = [ 'png', 'gif' ];
        $schema['images']['favicon'][3] = 'extra';
        // Hints are a recommendation the schema may give each image; null
        // formats are the defaults.
        $schema['images']['home_hero'] = [ '', null, null, [ 'size' => [ 1920, 600 ], 'ratio' => '16:5', 'alpha' => false, 'max_kb' => 400 ] ];
        $schema['images']['logo_mobile'][3] = [ 'size' => [ 600, 180 ], 'ratio' => '10:3', 'alpha' => true, 'max_kb' => 100 ];
        self::assertSame(ThemeImage::DEFAULT_FORMATS, ThemeImage::formats($schema, 'home_hero'));
        $schema['images']['home_hero_mobile'] = [ '', null, null, [ 'size' => [ 0, 600 ], 'ratio' => '16/9', 'alpha' => 1, 'max_kb' => 6000, 'dpi' => 2 ] ];
        foreach ([ [ 'size' => [ 600 ] ], [ 'ratio' => '0:1' ], [ 'alpha' => 'yes' ], [ 'max_kb' => 0 ], [ 'dpi' => 2 ], [ 'size' => [ 600, 180 ], 'max_kb' => 5001 ] ] as $i => $hints) {
            self::assertFalse(ThemeImage::validHints($hints), (string) $i);
        }
        self::assertTrue(ThemeImage::validHints([]));

        // Only an image names formats: a visibility key's third is its platforms' defaults.
        $visibility = array_key_first($schema['visibility']);
        $schema['visibility'][$visibility][] = [ 'png' ];
        self::assertSame(
            [ "visibility.{$visibility}.platforms.invalid", 'images.logo.formats.invalid', 'images.favicon.hints.invalid', 'images.home_hero_mobile.hints.invalid' ],
            array_column(ThemeSchemas::check($schema), 'code')
        );
    }
}
