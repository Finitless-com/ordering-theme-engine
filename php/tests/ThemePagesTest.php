<?php

declare(strict_types=1);

namespace Ordering\ThemeEngine\Tests;

use Ordering\ThemeEngine\ThemePages;
use Ordering\ThemeEngine\ThemeResolver;
use Ordering\ThemeEngine\ThemeSchemas;
use Ordering\ThemeEngine\ThemeValidator;
use PHPUnit\Framework\TestCase;

/**
 * A page of the schema says whether it shows the footer and which sections
 * it paints, in what order: a section moves only among those of its group,
 * and one the page needs is never hidden. Prior behavior: a theme had no
 * pages; it could hide only the few sections `visibility` named, and never
 * reorder them.
 */
class ThemePagesTest extends TestCase
{
    public function testASchemasPagesSayWhatTheyGetWrong(): void
    {
        $schema = ThemeSchemaFixture::compiled();
        $section = fn (string $key, array $changes = []) => $changes + [ 'key' => $key, 'visible' => true, 'hideable' => true, 'group' => 'extras' ];
        $sections = &$schema['pages']['checkout']['sections'][1];
        $sections[0]['visible'] = false;
        $sections[1] = $sections[2];
        $sections[3] = $section('extra_a', [ 'group' => 'Main Column' ]);
        $sections[4] = $section('extra_b', [ 'v1' => 'checkout.components.map' ]);
        $sections[5] = $section('extra_c', [ 'v1' => $schema['visibility']['footer'][1] ]);
        unset($sections);
        $schema['pages']['home']['footer'] = [ 'bool', 'yes' ];
        $schema['pages']['home']['header'] = [ 'bool', true ];
        $schema['pages']['not_found']['sections'] = [ 'sections', [ 'faq' ] ];

        self::assertSame([
            'pages.home.header.unknown',
            'pages.home.footer.invalid',
            'pages.checkout.sections.summary.invalid',
            'pages.checkout.sections.notes.duplicate',
            'pages.checkout.sections.notes.v1.duplicate',
            'pages.checkout.sections.extra_a.invalid',
            'pages.checkout.sections.extra_b.v1.invalid',
            'pages.checkout.sections.extra_c.v1.duplicate',
            'pages.not_found.sections.0.invalid',
        ], ThemeValidator::codes(ThemePages::check($schema)));

        // What a version 1 theme without the section's path shows is a bool.
        $schema = ThemeSchemaFixture::compiled();
        self::assertFalse($schema['pages']['checkout']['sections'][1][2]['v1_default']);
        $schema['pages']['checkout']['sections'][1][2]['v1_default'] = 'no';
        self::assertSame([ 'pages.checkout.sections.notes.invalid' ], ThemeValidator::codes(ThemePages::check($schema)));
    }

    public function testAThemesPagesAreCheckedAgainstTheSchema(): void
    {
        $schema = ThemeSchemaFixture::compiled();

        self::assertSame([], ThemeValidator::validate([
            'pages' => [ 'checkout' => [ 'footer' => false, 'sections' => [ [ 'key' => 'notes', 'visible' => false ], [ 'key' => 'tips', 'visible' => true ] ] ] ],
            'platforms' => [ 'mobile_web' => [ 'pages' => [ 'checkout' => [ 'footer' => null ] ] ] ],
        ], $schema));
        self::assertSame([
            'pages.checkout.footer.invalid',
            'pages.checkout.sections.0.unknown',
            'pages.checkout.sections.2.duplicate',
            'pages.checkout.sections.3.invalid',
            'pages.checkout.sections.4.invalid',
            'pages.checkout.title.unknown',
            'pages.spaceship.unknown',
            'platforms.mobile_web.pages.home.sections.invalid',
        ], ThemeValidator::codes(ThemeValidator::validate([
            'pages' => [
                'checkout' => [
                    'footer' => 'no',
                    'sections' => [
                        [ 'key' => 'map', 'visible' => true ],
                        [ 'key' => 'tips', 'visible' => true ],
                        [ 'key' => 'tips', 'visible' => false ],
                        // The page cannot be paid without it.
                        [ 'key' => 'summary', 'visible' => false ],
                        [ 'key' => 'notes', 'visible' => true, 'group' => 'extras' ],
                    ],
                    'title' => 'Pay',
                ],
                'spaceship' => [ 'footer' => true ],
            ],
            'platforms' => [ 'mobile_web' => [ 'pages' => [ 'home' => [ 'sections' => [ 'key' => 'hero' ] ] ] ] ],
        ], $schema)));
    }

    /**
     * The same order the web paints (`orderedSections` of themePages.js).
     */
    public function testEachGroupReordersOnlyItsOwnPlaces(): void
    {
        $defaults = [
            [ 'key' => 'a', 'visible' => true, 'hideable' => true, 'group' => null ],
            [ 'key' => 'b', 'visible' => true, 'hideable' => true, 'group' => 'g' ],
            [ 'key' => 'c', 'visible' => true, 'hideable' => true, 'group' => 'g' ],
            [ 'key' => 'd', 'visible' => true, 'hideable' => true, 'group' => 'h' ],
            [ 'key' => 'e', 'visible' => false, 'hideable' => true, 'group' => 'g' ],
            [ 'key' => 'f', 'visible' => true, 'hideable' => false, 'group' => null ],
        ];

        self::assertSame([
            [ 'key' => 'a', 'visible' => true ],
            [ 'key' => 'b', 'visible' => true ],
            [ 'key' => 'e', 'visible' => true ],
            [ 'key' => 'd', 'visible' => false ],
            [ 'key' => 'c', 'visible' => false ],
            [ 'key' => 'f', 'visible' => true ],
        ], ThemePages::order($defaults, [
            [ 'key' => 'f', 'visible' => false ],
            [ 'key' => 'e', 'visible' => true ],
            [ 'key' => 'x', 'visible' => false ],
            [ 'key' => 'c', 'visible' => false ],
            [ 'key' => 'e', 'visible' => false ],
            [ 'key' => 'd', 'visible' => false ],
            'junk',
        ]));
        self::assertSame(ThemePages::order($defaults, []), array_map(fn ($section) => [ 'key' => $section['key'], 'visible' => $section['visible'] ], $defaults));
    }
}
