<?php

declare(strict_types=1);

namespace Ordering\ThemeEngine\Tests;

use Ordering\ThemeEngine\Schema\EditorMetadata;
use Ordering\ThemeEngine\Schema\NodeCompiler;
use Ordering\ThemeEngine\Schema\NodeConverter;
use Ordering\ThemeEngine\ThemeColors;
use Ordering\ThemeEngine\ThemeOverall;
use Ordering\ThemeEngine\ThemeSchemas;
use Ordering\ThemeEngine\ThemeValidator;
use PHPUnit\Framework\TestCase;

/**
 * A theme schema in the node format (docs/schema-format.md): it
 * says the same as the tuples it replaces, so a product can switch to it
 * without anything a client receives changing, and the format checks itself
 * against its meta-schema.
 */
class ThemeSchemaNodesTest extends TestCase
{
    public function testTheWebMarketplaceSchemaConvertsAndCompilesBackToTheSameTuples(): void
    {
        // The same once it declares what follows Identity (ThemeOverall::declare()).
        $schema = ThemeSchemas::declare(ThemeSchemaFixture::webMarketplace());
        $converted = NodeConverter::convert($schema);
        $compiled = NodeCompiler::compile($converted['schema'], $converted['v1']);

        self::assertSame([], $compiled['errors']);
        // Once compiled, `inherits` follows the order of the components; its order means nothing.
        ksort($schema['inherits']);
        ksort($compiled['schema']['inherits']);
        self::assertSame($schema, $compiled['schema']);
    }

    public function testTheConvertedSchemaFitsTheMetaSchemaAndRegistersAsTheSameTuples(): void
    {
        $schema = ThemeSchemaFixture::webMarketplace();
        $nodes = json_decode(NodeConverter::json(NodeConverter::convert($schema)['schema']), true);

        self::assertSame([], ThemeSchemas::meta($nodes));
        $tuples = ThemeSchemaFixture::tuples($nodes);
        self::assertSame([], $tuples['errors']);
        self::assertSame([], ThemeSchemas::check($tuples['schema']));
        self::assertSame(ThemeSchemas::defaults(ThemeSchemas::declare($schema)), ThemeSchemas::defaults($tuples['schema']));
    }

    /**
     * The version 1 paths come from api-core's map: those of what the schema
     * has, the site's settings under `site.`, and none of what it removed
     * (the clients' layouts).
     */
    public function testTheVersionOnePathsComeFromTheMapForWhatTheSchemaHas(): void
    {
        $map = ThemeSchemaFixture::v1Map();
        $schema = ThemeSchemaFixture::webMarketplace();

        $expected = $map['v1'];
        foreach ($map['site'] as $key => $path) {
            $expected["site.{$key}"] = $path;
        }
        foreach ([ 'header.base.layout', 'business_header.base.layout', 'product_search.base.layout', 'product_card.base.layout', 'signup_page.variants.business.layout' ] as $removed) {
            self::assertArrayHasKey("components.{$removed}", $expected);
            unset($expected["components.{$removed}"]);
        }
        self::assertSame($expected, $schema['v1']);
        self::assertSame(array_map(fn (string $key) => $map['visibility'][$key] ?? null, array_keys($schema['visibility'])), array_column($schema['visibility'], 1));
        self::assertSame(array_map(fn (string $key) => $map['images'][$key] ?? null, array_keys($schema['images'])), array_column($schema['images'], 1));
    }

    public function testTheMetaSchemaSaysWhereANodeIsMalformed(): void
    {
        $nodes = NodeConverter::convert(ThemeSchemaFixture::webMarketplace())['schema'];
        $nodes['components']['button']['base']['radius']['glow'] = true;
        unset($nodes['tokens']['layout']['mode']['$extensions']);
        $nodes['components']['button']['base']['animated']['$value'] = 'yes';
        $nodes['visibility']['Footer Links'] = [ '$value' => true ];

        self::assertSame([
            'tokens.layout.mode.schema',
            'components.button.base.radius.schema',
            'components.button.base.animated.$value.schema',
            'visibility.schema',
        ], array_column(ThemeSchemas::meta($nodes), 'code'));

        // A key the format does not have, at the root.
        $nodes = NodeConverter::convert(ThemeSchemaFixture::webMarketplace())['schema'];
        $nodes['extra'] = 1;
        self::assertSame([ [ 'path' => '', 'code' => 'schema' ] ], ThemeSchemas::meta($nodes));
    }

    public function testATokenMustDeclareTheTypeTheEngineValidatesItWith(): void
    {
        $nodes = NodeConverter::convert(ThemeSchemaFixture::webMarketplace())['schema'];
        $nodes['tokens']['shadow_style']['style']['$extensions']['co.ordering']['options'] = [ 'normal', 'neon' ];
        $nodes['tokens']['color']['primary']['$type'] = 'dimension';
        $nodes['components']['card']['base']['radius']['$extensions']['co.ordering']['scale'] = 'motion';

        self::assertSame([
            'tokens.color.primary.type',
            'tokens.shadow_style.style.type',
            'components.card.base.radius.type',
        ], array_column(NodeCompiler::compile($nodes)['errors'], 'code'));
    }

    public function testTheEditorMetadataGoesIntoTheNodesAndChangesNothingTheEngineReads(): void
    {
        $schema = ThemeSchemaFixture::webMarketplace();
        $nodes = NodeConverter::convert($schema)['schema'];
        $label = [ 'key' => 'BUILDER_THEME_COLOR_PRIMARY', 'default' => 'Primary' ];
        $injected = EditorMetadata::inject($nodes, [
            'nodes' => [
                'tokens.color.primary' => [ 'label' => $label, 'group' => 'brand', 'order' => 1 ],
                'visibility.business.header.info' => [ 'label' => null, 'related' => [ 'components.business_header' ] ],
                'components.nope' => [ 'group' => 'controls' ],
            ],
            'props' => [ 'radius' => [ 'label' => [ 'key' => 'BUILDER_THEME_PROP_RADIUS', 'default' => 'Rounding' ] ] ],
            'editor' => [
                'sections' => [ [ 'key' => 'colors', 'groups' => [ [ 'key' => 'brand', 'paths' => [ 'tokens.color.primary' ] ] ] ] ],
                'section_groups' => [ [ 'key' => 'identity' ] ],
            ],
        ], [
            'nodes' => [ 'tokens.color.on_primary' => [ 'pair' => 'tokens.color.primary', 'disabled_if' => [ 'path' => 'tokens.color.primary', 'op' => 'falsy' ] ] ],
            'editor' => [ 'section_parents' => [ 'colors' => 'identity' ], 'group_info' => [ 'colors.brand' => [ 'open' => true ] ] ],
        ]);

        self::assertSame([ 'components.nope' ], $injected['missing']);
        $editor = $injected['schema']['tokens']['color']['primary']['$extensions']['co.ordering.editor'];
        self::assertSame([ 'label' => $label, 'group' => 'brand', 'order' => 1 ], $editor);
        // Without a text of its own, the label is left out: the editor humanizes the key.
        self::assertSame([ 'related' => [ 'components.business_header' ] ], $injected['schema']['visibility']['business.header.info']['$extensions']['co.ordering.editor']);
        self::assertSame([ 'key' => 'colors', 'groups' => [ [ 'key' => 'brand', 'paths' => [ 'tokens.color.primary' ], 'open' => true ] ], 'parent' => 'identity' ], $injected['schema']['editor']['sections'][0]);
        // What the export already writes in the format's names is kept as it is.
        self::assertSame([ [ 'key' => 'identity' ] ], $injected['schema']['editor']['section_groups']);
        self::assertSame([ 'radius' ], array_keys($injected['schema']['editor']['props']));

        $written = json_decode(NodeConverter::json($injected['schema']), true);
        self::assertSame([], ThemeSchemas::meta($written));
        $tuples = ThemeSchemaFixture::tuples($written);
        $schema = ThemeSchemas::declare($schema);
        ksort($schema['inherits']);
        ksort($tuples['schema']['inherits']);
        self::assertSame($schema, $tuples['schema']);
    }

    /**
     * An export of a block the schema adds later (the site's settings) is
     * merged into the editor block the schema already has.
     */
    public function testAPartialEditorExportMergesIntoTheSchemasOwn(): void
    {
        $nodes = ThemeSchemaFixture::webMarketplaceNodes();
        $before = $nodes['editor']['sections'];
        $label = [ 'key' => 'BUILDER_THEME_SECTION_SITE', 'default' => 'Website settings' ];
        $merged = EditorMetadata::inject($nodes, [
            'nodes' => [ 'site.name' => [ 'control' => 'text' ] ],
            'editor' => [ 'sections' => [
                [ 'key' => 'extra', 'parent' => 'website', 'label' => $label ],
                [ 'key' => 'images', 'parent' => 'website', 'label' => $label ],
            ], 'props' => [ 'radius' => [ 'label' => $label ] ] ],
        ])['schema'];
        $sections = $merged['editor']['sections'];

        // A new section goes first among its parent's; one of the same key replaces its own.
        $first = array_search('website', array_column($before, 'parent'), true);
        self::assertSame('extra', $sections[$first]['key']);
        self::assertSame(count($before) + 1, count($sections));
        self::assertSame($label, $sections[array_search('images', array_column($sections, 'key'), true)]['label']);
        self::assertSame($label, $merged['editor']['props']['radius']['label']);
        self::assertSame(count($nodes['editor']['props']), count($merged['editor']['props']));
        self::assertSame([ 'control' => 'text' ], $merged['site']['name']['$extensions']['co.ordering.editor']);
    }

    public function testWhatFollowsIdentityIsDeclaredAndShowsWhatItAlwaysShowed(): void
    {
        // The schema as it was before it declared what follows Identity.
        $schema = ThemeResolverGolden::schema();
        $declared = ThemeSchemas::declare($schema);

        self::assertSame(ThemeOverall::follows($schema), $declared['follows']);
        self::assertSame('shadow', $declared['follows']['components.tabs.variants.pill.shadow']);
        // A default that Identity always replaced is now the one that shows.
        self::assertSame('{shadow.none}', $schema['components']['tabs']['variants']['pill']['shadow'][1]);
        self::assertSame('{shadow.sm}', $declared['components']['tabs']['variants']['pill']['shadow'][1]);
        // A rounding with options keeps its own: it shows when the theme's level is not one of them.
        self::assertSame($schema['components']['tabs']['variants']['pill']['radius'], $declared['components']['tabs']['variants']['pill']['radius']);
        // A border keeps its own: its color is the one a theme-wide width draws.
        self::assertSame($schema['components']['alert']['variants'], $declared['components']['alert']['variants']);
        self::assertArrayNotHasKey('components.button.appearances.outline.border', $declared['follows']);
        self::assertSame([], ThemeSchemas::check($declared));

        $declared['follows']['components.button.base.radius'] = 'shadow';
        $declared['follows']['components.card.variants.default.states.hover.shadow'] = 'shadow';
        self::assertSame([
            'follows.components.button.base.radius.invalid',
            'follows.components.card.variants.default.states.hover.shadow.invalid',
        ], array_column(ThemeSchemas::check($declared), 'code'));
    }

    /**
     * The schema as the web writes it once it no longer reads version 1:
     * no version 1 paths (api-core keeps them in its own map) and a `site`
     * block with the site's settings.
     */
    public function testASchemaWithoutVersionOnePathsTakesThemFromTheMapAndKeepsItsSite(): void
    {
        // The schema as it was with its version 1 paths, then without them.
        $schema = ThemeResolverGolden::schema();
        $map = ThemeSchemaFixture::v1Map();
        $site = [
            'mode' => [ 'option', 'marketplace', [ 'marketplace', 'single_store', 'franchise' ] ],
            'business_slug' => [ 'string', '' ],
            'franchise_id' => [ 'id', null ],
            'home_html' => [ 'html', '' ],
            'third_party_code' => [ 'head' => [ 'html', '' ], 'body' => [ 'html', '' ] ],
            'tracking_only' => [ 'bool', false ],
        ];

        $written = $schema;
        unset($written['v1']);
        $written['visibility'] = array_map(fn (array $entry) => $entry[0], $schema['visibility']);
        $written['images'] = array_map(fn (array $entry) => array_merge([ $entry[0] ], array_slice($entry, 2)), $schema['images']);
        foreach ($written['pages'] as $page => $spec) {
            $written['pages'][$page]['sections'][1] = array_map(fn (array $section) => array_diff_key($section, [ 'v1' => true, 'v1_default' => true ]), $spec['sections'][1]);
        }
        $written['site'] = $site;

        $converted = NodeConverter::convert($written, $map);
        self::assertSame($map, $converted['v1']);
        $nodes = json_decode(NodeConverter::json($converted['schema']), true);
        self::assertSame([], ThemeSchemas::meta($nodes));
        $tuples = ThemeSchemaFixture::tuples($nodes);
        self::assertSame([], $tuples['errors']);
        self::assertSame([], ThemeSchemas::check($tuples['schema']));

        $expected = ThemeSchemas::declare($schema);
        $expected['site'] = $site;
        // Only the settings the schema has: the map knows more.
        foreach ([ 'mode', 'business_slug', 'franchise_id', 'home_html', 'third_party_code.head', 'third_party_code.body', 'tracking_only' ] as $key) {
            $expected['v1']["site.{$key}"] = $map['site'][$key];
        }
        foreach ([ &$expected, &$tuples['schema'] ] as &$side) {
            ksort($side);
            ksort($side['inherits']);
        }
        unset($side);
        self::assertSame($expected, $tuples['schema']);

        self::assertSame([ 'mode' => 'marketplace', 'business_slug' => '', 'franchise_id' => null, 'home_html' => '', 'third_party_code' => [ 'head' => '', 'body' => '' ], 'tracking_only' => false ], ThemeSchemas::siteDefaults($tuples['schema']));
    }

    /**
     * siteDefaults() reads the tuples: the nodes, or tuples()'s whole
     * answer, would give the raw node tree or nothing without a word.
     */
    public function testSiteDefaultsRefusesTheNodesAndWhatTuplesAnswers(): void
    {
        $nodes = ThemeSchemaFixture::webMarketplaceNodes();
        $compiled = ThemeSchemaFixture::tuples($nodes);
        self::assertSame([], $compiled['errors']);
        self::assertNotSame([], ThemeSchemas::siteDefaults($compiled['schema']));

        foreach ([ 'nodes' => $nodes, 'tuples()' => $compiled ] as $given => $schema) {
            try {
                ThemeSchemas::siteDefaults($schema);
                self::fail("siteDefaults() took {$given}");
            } catch (\TypeError $error) {
                self::assertStringContainsString("pass tuples(\$nodes)['schema']", $error->getMessage());
            }
        }
    }

    /**
     * The colors' derivations, declared on each color, instead of a table
     * of the engine: a schema that declares them resolves as one that
     * does not.
     */
    public function testColorDerivationsAreDeclaredOnTheirColors(): void
    {
        $nodes = ThemeSchemaFixture::webMarketplaceNodes();
        self::assertSame([ 'op' => 'shade', 'from' => '{color.primary}', 'amount' => -0.07 ], $nodes['tokens']['color']['primary_pressed']['$extensions']['co.ordering']['derive']);
        self::assertSame([ 'op' => 'mix', 'from' => '{color.danger}', 'over' => '{color.background}', 'amount' => 0.12 ], $nodes['tokens']['color']['danger_subtle']['$extensions']['co.ordering']['derive']);
        // Declaring what is declared changes nothing.
        self::assertSame($nodes, json_decode(NodeConverter::json(NodeConverter::declare($nodes)), true));

        $undeclared = $nodes;
        foreach ($undeclared['tokens']['color'] as $color => $node) {
            if (is_array($node)) {
                unset($undeclared['tokens']['color'][$color]['$extensions']['co.ordering']['derive']);
            }
        }
        $with = ThemeSchemaFixture::tuples($nodes)['schema'];
        $without = ThemeSchemaFixture::tuples($undeclared)['schema'];
        self::assertArrayNotHasKey('derive', $without);
        // The same rules, in the order of the schema's colors.
        self::assertEquals(ThemeColors::rules(), $with['derive']);
        self::assertSame($nodes, json_decode(NodeConverter::json(NodeConverter::declare($undeclared)), true));

        $with['derive']['primary_pressed']['from'] = '{color.nope}';
        $with['derive']['nope'] = [ 'op' => 'same', 'from' => '{color.primary}' ];
        self::assertSame([ 'derive.primary_pressed.invalid', 'derive.nope.invalid' ], array_column(ThemeSchemas::check($with), 'code'));
    }

    /**
     * A group's `$type` is its nodes' unless they declare their own, and a
     * schema that declares nothing following Identity gets the engine's
     * rule, as one that declares no derivation does.
     */
    public function testWhatAGroupOrTheEngineSaysForANodeThatDoesNot(): void
    {
        $nodes = ThemeSchemaFixture::webMarketplaceNodes();
        $declared = ThemeSchemaFixture::tuples($nodes)['schema'];

        $typed = $nodes;
        $typed['components']['button']['colors']['primary']['$type'] = 'color';
        foreach ($typed['components']['button']['colors']['primary'] as $role => $node) {
            if (!str_starts_with($role, '$')) {
                unset($typed['components']['button']['colors']['primary'][$role]['$type']);
            }
        }
        self::assertSame($declared['components']['button']['colors']['primary'], ThemeSchemaFixture::tuples($typed)['schema']['components']['button']['colors']['primary']);

        $undeclared = json_decode((string) preg_replace('/,?\s*"follows":\s*"(shadow|radius|border)"/', '', (string) json_encode($nodes)), true);
        $compiled = ThemeSchemaFixture::tuples($undeclared)['schema'];
        self::assertArrayNotHasKey('follows', $compiled);
        self::assertSame($declared['follows'], ThemeOverall::follows($compiled));
    }

    public function testASchemaInTuplesIsReadAsItIs(): void
    {
        $schema = ThemeSchemaFixture::webMarketplace();

        self::assertSame([ 'schema' => $schema, 'errors' => [] ], ThemeSchemas::tuples($schema));
    }

    /**
     * Prior behavior: a phone-only default was a visibility key of its own
     * (`business.header.logo_mobile`); a key now says its default on a
     * platform that shows it otherwise, served with the platform's.
     */
    public function testAVisibilityKeyMayShowOtherwiseOnAPlatform(): void
    {
        $nodes = ThemeSchemaFixture::webMarketplaceNodes();
        $nodes['visibility']['business.header.logo']['$extensions']['co.ordering']['platforms'] = [ 'mobile_web' => false ];

        self::assertSame([], ThemeSchemas::meta($nodes));
        $schema = ThemeSchemaFixture::tuples($nodes)['schema'];
        self::assertSame([ true, 'business_view.components.header.components.business.components.logo.hidden', [ 'mobile_web' => false ] ], $schema['visibility']['business.header.logo']);
        self::assertSame([], ThemeSchemas::check($schema));
        $defaults = ThemeSchemas::defaults($schema);
        self::assertTrue($defaults['visibility']['business.header.logo']);
        self::assertSame([ 'business.header.logo' => false ], $defaults['platforms']['mobile_web']['visibility']);
        // The tuples convert back to the same node.
        self::assertSame([ 'platforms' => [ 'mobile_web' => false ] ], NodeConverter::convert($schema)['schema']['visibility']['business.header.logo']['$extensions']['co.ordering']);

        $schema['visibility']['business.header.logo'][2] = [ 'tv' => false ];
        $schema['visibility']['business.header.info'][2] = [ 'mobile_web' => 'no' ];
        self::assertSame(
            [ 'visibility.business.header.logo.platforms.invalid', 'visibility.business.header.info.platforms.invalid' ],
            array_column(ThemeSchemas::check($schema), 'code')
        );
    }

    public function testATokenMayBeNarrowedAndAPropertyCarryRules(): void
    {
        $nodes = ThemeSchemaFixture::webMarketplaceNodes();
        $nodes['tokens']['focus_ring']['color']['$value'] = 'primary';
        $nodes['tokens']['focus_ring']['color']['$extensions']['co.ordering']['options'] = [ 'primary', 'danger' ];
        $nodes['tokens']['focus_ring']['offset']['$extensions']['co.ordering']['options'] = [ 'none', 'xxs', 'xs', 'sm' ];
        $nodes['tokens']['scrollbar']['$type'] = 'dimension';
        $nodes['components']['link']['sizes']['sm']['text']['$extensions']['co.ordering.editor'] = [ 'custom' => true ];
        $nodes['site']['name']['$extensions']['co.ordering'] = [ 'max_length' => 5 ];
        $nodes['site']['smart_banner']['apple_id']['$extensions']['co.ordering'] = [ 'pattern' => '^\\d{6,12}$' ];
        $nodes['images']['business_listing_hero']['$extensions']['co.ordering']['hints']['safe'] = '4:1';

        self::assertSame([], ThemeSchemas::meta($nodes));
        $compiled = ThemeSchemaFixture::tuples($nodes);
        self::assertSame([], $compiled['errors']);
        $schema = $compiled['schema'];
        self::assertSame([ 'focus_ring.offset' => [ 'none', 'xxs', 'xs', 'sm' ], 'focus_ring.color' => [ 'primary', 'danger' ] ], $schema['token_options']);
        self::assertSame([ 'text', '{typography.caption}', null, [ 'custom' => true ] ], $schema['components']['link']['sizes']['sm']['text']);
        self::assertSame([ 'string', '', null, [ 'max_length' => 5 ] ], $schema['site']['name']);
        self::assertSame([], ThemeSchemas::check($schema));
        // The tuples convert back to nodes that compile to them.
        self::assertSame($schema, ThemeSchemaFixture::tuples(NodeConverter::convert($schema)['schema'])['schema']);

        $typography = [ 'size' => 18, 'line' => 1.33, 'weight' => 600 ];
        self::assertSame([], ThemeValidator::validate([
            'tokens' => [ 'focus_ring' => [ 'color' => 'danger', 'offset' => 'sm' ] ],
            'components' => [ 'link' => [ 'sizes' => [ 'sm' => [ 'text' => $typography ] ] ] ],
        ], $schema));
        self::assertSame([
            'tokens.focus_ring.offset.invalid',
            'tokens.focus_ring.color.invalid',
            'components.link.sizes.md.text.invalid',
        ], ThemeValidator::codes(ThemeValidator::validate([
            'tokens' => [ 'focus_ring' => [ 'color' => 'secondary', 'offset' => 'md' ] ],
            'components' => [ 'link' => [ 'sizes' => [ 'md' => [ 'text' => $typography ] ] ] ],
        ], $schema)));
        // An empty string is unset, whatever its pattern.
        self::assertSame([], ThemeValidator::settings([ 'name' => 'Acme', 'smart_banner' => [ 'apple_id' => '' ] ], $schema));
        self::assertSame(
            [ 'site.name.invalid', 'site.smart_banner.apple_id.invalid' ],
            ThemeValidator::codes(ThemeValidator::settings([ 'name' => 'Acme Foods', 'smart_banner' => [ 'apple_id' => '12a' ] ], $schema))
        );

        // Of the catalog only, the default among them.
        $schema['token_options']['focus_ring.offset'] = [ 'none', 'xs' ];
        $schema['token_options']['focus_ring.color'] = [ 'primary', 'nope' ];
        $schema['token_options']['scrollbar.size'] = [ 6 ];
        $schema['site']['name'][3] = [ 'custom' => true ];
        $schema['site']['description'] = [ 'string', '', null, [ 'pattern' => '(' ] ];
        $schema['components']['link']['sizes']['md']['text'] = [ 'text', '{typography.body}', null, [ 'max_length' => 3 ] ];
        self::assertSame([
            'token_options.focus_ring.offset.invalid',
            'token_options.focus_ring.color.invalid',
            'token_options.scrollbar.size.invalid',
            'components.link.sizes.md.text.rules.invalid',
            'site.name.rules.invalid',
            'site.description.rules.invalid',
        ], array_column(ThemeSchemas::check($schema), 'code'));
    }

    /**
     * Prior behavior: `preview` ({ page, path }) passed the meta-schema.
     */
    public function testTheMetaSchemaRefusesAPreviewAsAnyKeyTheEditorDoesNotRead(): void
    {
        $nodes = ThemeSchemaFixture::webMarketplaceNodes();
        self::assertSame([], ThemeSchemas::meta($nodes));

        $nodes['pages']['home']['$extensions']['co.ordering.editor']['preview'] = [ 'page' => 'home', 'path' => '/' ];
        self::assertSame([ 'pages.home.$extensions.co.ordering.editor.schema' ], array_column(ThemeSchemas::meta($nodes), 'code'));
    }
}
