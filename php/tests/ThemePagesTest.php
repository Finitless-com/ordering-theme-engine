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
        $schema = ThemeSchemaFixture::webMarketplace();
        $sections = &$schema['pages']['checkout']['sections'][1];
        $sections[0]['visible'] = false;
        $sections[1] = $sections[2];
        $sections[3]['group'] = 'Main Column';
        $sections[4]['v1'] = 'checkout.components.map';
        $sections[5]['v1'] = $schema['visibility']['footer'][1];
        unset($sections);
        $schema['pages']['home']['footer'] = [ 'bool', 'yes' ];
        $schema['pages']['home']['header'] = [ 'bool', true ];
        $schema['pages']['help']['sections'] = [ 'sections', [ 'faq' ] ];

        self::assertSame([
            'pages.home.header.unknown',
            'pages.home.footer.invalid',
            'pages.checkout.sections.order_context.invalid',
            'pages.checkout.sections.business.duplicate',
            'pages.checkout.sections.business.v1.duplicate',
            'pages.checkout.sections.customer.invalid',
            'pages.checkout.sections.address.v1.invalid',
            'pages.checkout.sections.delivery_instructions.v1.duplicate',
            'pages.help.sections.0.invalid',
        ], ThemeValidator::codes(ThemePages::check($schema)));

        // What a version 1 theme without the section's path shows is a bool.
        $schema = ThemeSchemaFixture::webMarketplace();
        $near = array_search('near_business', array_column($schema['pages']['business']['sections'][1], 'key'), true);
        self::assertFalse($schema['pages']['business']['sections'][1][$near]['v1_default']);
        $schema['pages']['business']['sections'][1][$near]['v1_default'] = 'no';
        self::assertSame([ 'pages.business.sections.near_business.invalid' ], ThemeValidator::codes(ThemePages::check($schema)));
    }

    public function testAThemesPagesAreCheckedAgainstTheSchema(): void
    {
        $schema = ThemeSchemaFixture::webMarketplace();

        self::assertSame([], ThemeValidator::validate([
            'pages' => [ 'checkout' => [ 'footer' => false, 'sections' => [ [ 'key' => 'customer', 'visible' => false ], [ 'key' => 'business', 'visible' => true ] ] ] ],
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
                        [ 'key' => 'business', 'visible' => true ],
                        [ 'key' => 'business', 'visible' => false ],
                        // The page cannot be paid without it.
                        [ 'key' => 'place_order', 'visible' => false ],
                        [ 'key' => 'wallet', 'visible' => true, 'group' => 'main' ],
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
