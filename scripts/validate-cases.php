<?php

/*
 * The golden cases of validate(): named mutations of the reference schema
 * (meta/reference/reference-1.0.0.json), each with what
 * ThemeSchemaPublisher::validate() answers for it, as JSON on stdout.
 * scripts/sync-fixtures.sh runs it in PHP 8.3:
 *
 *   php scripts/validate-cases.php meta/reference/reference-1.0.0.json meta/reference/reference-1.0.0.v1-map.json
 *
 * Each case stores its mutation, not the schema it produces: a list of
 * operations over the schema (`["set", path, value]`, `["delete", path]`, a
 * path being a list of keys) that the TypeScript test applies the same way
 * to the same schema and version 1 map.
 * The answer is computed over the case as it was written and read back, so
 * PHP validates exactly what the fixture says.
 *
 * A case with `development: true` runs in api-core's development mode
 * (validate()'s last argument).
 *
 * Every case's schema passes the meta-schema (ThemeSchemas::meta()), which
 * the TypeScript validate() leaves to validateFull(): a case that does not
 * fails the script.
 */

declare(strict_types=1);

use Ordering\ThemeEngine\Schema\NodeCompiler;
use Ordering\ThemeEngine\ThemeSchemaPublisher;
use Ordering\ThemeEngine\ThemeSchemas;

require dirname(__DIR__) . '/vendor/autoload.php';

// A warning (an array cast to a string…) is a broken case, not a quiet one.
set_error_handler(function (int $severity, string $message, string $file, int $line) {
    throw new \ErrorException($message, 0, $severity, $file, $line);
});

[ , $schema_path, $map_path ] = $argv + [ null, null, null ];
if (!is_string($schema_path) || !is_file($schema_path) || !is_string($map_path) || !is_file($map_path)) {
    fwrite(STDERR, "Usage: php scripts/validate-cases.php <schema in nodes> <version 1 map>\n");
    exit(1);
}
$read = fn (string $path) => json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
$reference = $read($schema_path);
$map = $read($map_path);

/** @param string|array<int, string|int> $path */
function path(string|array $path): array
{
    return is_array($path) ? $path : explode('.', $path);
}

function set(string|array $path, mixed $value): array
{
    return [ 'set', path($path), $value ];
}

function del(string|array $path): array
{
    return [ 'delete', path($path) ];
}

/** Applies the operations, in order, to a copy of $document. */
function apply(array $document, array $ops): array
{
    foreach ($ops as $op) {
        $segments = $op[1];
        $last = array_pop($segments);
        $node = &$document;
        foreach ($segments as $segment) {
            if (!is_array($node[$segment] ?? null)) {
                $node[$segment] = [];
            }
            $node = &$node[$segment];
        }
        if ($op[0] === 'set') {
            $node[$last] = $op[2];
        } else {
            unset($node[$last]);
        }
        unset($node);
    }

    return $document;
}

function node(?string $type, mixed $value, array $engine = [], array $editor = []): array
{
    $node = $type === null ? [] : [ '$type' => $type ];
    $node['$value'] = $value;
    $extensions = [];
    if ($engine !== []) {
        $extensions['co.ordering'] = $engine;
    }
    if ($editor !== []) {
        $extensions['co.ordering.editor'] = $editor;
    }
    if ($extensions !== []) {
        $node['$extensions'] = $extensions;
    }

    return $node;
}

/** A version of the base: the reference is `1.0.0`. */
function version(string $version): array
{
    return set('version', $version);
}

$cases = [];
/**
 * @param array<int, array> $ops over the reference
 * @param array<int, array>|null $previous over the reference, the previous version
 * @param array<int, array>|null $v1 operations over the reference's version 1 map; null passes none
 * @param array<int, array>|null $previous_base over the reference, the last version of the previous base
 */
$case = function (string $name, string $description, array $ops, ?array $previous = null, ?array $v1 = null, ?array $previous_base = null) use (&$cases) {
    $entry = [ 'name' => $name, 'description' => $description, 'schema' => $ops ];
    if ($previous !== null) {
        $entry['previous'] = $previous;
    }
    if ($previous_base !== null) {
        $entry['previousBase'] = $previous_base;
    }
    if ($v1 !== null) {
        $entry['v1Map'] = $v1;
    }
    $cases[] = $entry;
};

// --- The schema alone ------------------------------------------------------

$case('valid', 'The reference: valid, without a version 1 map.', []);
$case('valid-v1-map', 'The reference with its version 1 map: valid.', [], null, []);
$case('v1-map-stale', 'A version 1 map that still names properties the schema no longer has: `v1_map.v1.<path>.unknown`.', [], null, [
    set([ 'v1', 'components.header.base.logo_position' ], 'header.components.logo.components.layout.position'),
    set([ 'v1', 'components.product_card.base.layout' ], 'business_view.components.products.components.product.components.image.position'),
]);
$case('v1-map-unknown', 'A version 1 map that names, besides, a visibility key, an image, a page, a section of a page and a setting the schema does not have, and a version 2 path of a section the `v1` block does not take.', [], null, [
    set([ 'visibility', 'header.nope' ], 'header.components.nope.hidden'),
    set([ 'images', 'nope' ], null),
    set([ 'pages', 'nowhere' ], [ 'hero' => [ 'v1' => 'nowhere.hidden' ] ]),
    set([ 'pages', 'home', 'nope' ], [ 'v1' => 'home.nope.hidden' ]),
    set([ 'site', 'nope' ], 'my_products.components.website_theme.components.nope'),
    set([ 'v1', 'visibility.footer' ], 'footer.hidden'),
]);
$case('format', 'A schema that is not in nodes stops at the format.', [ set('$schema', 'urn:ordering:theme-schema:0') ]);
$case('semver', 'A version of the base that is not major.minor.patch.', [ version('1.1') ]);
$case('product-type', 'A product type that is not one of the Builder\'s.', [ set('product_type', 'tv_app') ]);

$case('compile-not-a-node', "Values that are not nodes: the compiler's errors, without check(), and the version's.", [
    set('tokens.color.primary', [ '$description' => 'No value.' ]),
    set('components.button.base.radius', [ '$description' => 'No value.' ]),
    set('tokens.color.secondary.$value', 'red'),
    version('1.0'),
]);
$case('compile-token-type', 'Tokens that do not declare the type or the options the engine validates them with.', [
    set([ 'tokens', 'font', 'body', '$extensions', 'co.ordering', 'options' ], [ 'roboto', 'raleway', 'poppins', 'playfair_display', 'open_sans', 'nunito', 'montserrat', 'lato', 'inter', 'archivo' ]),
    set('tokens.color.text.$type', 'string'),
]);
$case('compile-kind', 'A node whose type and scale are no property kind.', [
    set('components.button.base.radius.$type', 'font'),
]);
$case('own-colors-dropped', 'A schema names its own colors: dropping one its components still paint is an alias error at each of them.', [
    del('tokens.color.surface_alt'),
    set([ 'editor', 'sections', 0, 'groups', 1, 'paths' ], [ 'tokens.color.background', 'tokens.color.surface', 'tokens.color.overlay' ]),
]);
$case('own-colors-added', 'A color of the catalog the schema adds, offered to the focus ring too: valid.', [
    set('tokens.color.danger_text', [ '$value' => '#B02A3E' ]),
    set([ 'tokens', 'focus_ring', 'color', '$extensions', 'co.ordering', 'options' ], [ 'primary', 'text', 'danger', 'danger_text' ]),
]);

$case('tokens-required-unknown', 'A token missing, one the format does not have, and a group it does not have.', [
    del('tokens.radius.xl'),
    set('tokens.color.brand', node('color', '#123456')),
    set('tokens.extra', [ 'one' => node('number', 1) ]),
]);
$case('token-narrowed', "The focus ring offers some of its colors and distances, its default among them, and the scrollbar's tokens are dimensions: valid.", [
    set('tokens.focus_ring.color.$value', 'secondary'),
    set([ 'tokens', 'focus_ring', 'color', '$extensions', 'co.ordering', 'options' ], [ 'primary', 'secondary', 'text', 'border', 'success', 'danger' ]),
    set([ 'tokens', 'focus_ring', 'offset', '$extensions', 'co.ordering', 'options' ], [ 'none', 'xxs', 'xs', 'sm', 'md' ]),
    set('tokens.scrollbar.$type', 'dimension'),
]);
$case('token-narrowed-default', 'A narrowed token whose default is not among its options.', [
    set([ 'tokens', 'focus_ring', 'offset', '$extensions', 'co.ordering', 'options' ], [ 'none', 'xs' ]),
]);
$case('token-not-narrowable', "A token that may not be narrowed, and the focus ring's color narrowed to one of no catalog: the compiler's type errors.", [
    set([ 'tokens', 'focus_ring', 'width', '$extensions', 'co.ordering', 'options' ], [ 'thin' ]),
    set([ 'tokens', 'focus_ring', 'color', '$extensions', 'co.ordering', 'options' ], [ 'focus', 'brand' ]),
]);
$case('rules', 'A custom typography, a string with a longest value and one with a pattern, and an image with a safe area: valid.', [
    set('components.link.base.text', node('typography', '{typography.body}', [], [ 'control' => 'level', 'custom' => true ])),
    set([ 'site', 'name', '$extensions', 'co.ordering' ], [ 'max_length' => 50 ]),
    set([ 'site', 'smart_banner', 'apple_id', '$extensions', 'co.ordering' ], [ 'pattern' => '^\\d{6,10}$' ]),
    set([ 'images', 'isotype', '$extensions', 'co.ordering', 'hints', 'safe' ], '1:1'),
]);
$case('alpha', "Colors the editor may make translucent, a token, a component's base and a variant's state: valid (the compiled schema lists them in `alpha`).", [
    set([ 'tokens', 'color', 'overlay', '$extensions', 'co.ordering.editor', 'alpha' ], true),
    set([ 'components', 'modal', 'base', 'background', '$extensions', 'co.ordering.editor', 'alpha' ], true),
    set([ 'components', 'chip', 'variants', 'default', 'states', 'pressed', 'background', '$extensions', 'co.ordering.editor', 'alpha' ], true),
]);
$case('rules-invalid', 'Rules of another kind, a pattern no engine reads, and a default its rule refuses.', [
    set([ 'site', 'description', '$extensions', 'co.ordering.editor', 'custom' ], true),
    set([ 'site', 'name', '$extensions', 'co.ordering' ], [ 'pattern' => '(' ]),
    set([ 'components', 'link', 'base', 'color', '$extensions', 'co.ordering', 'max_length' ], 3),
    set([ 'site', 'business_slug', '$value' ], 'abcdef'),
    set([ 'site', 'business_slug', '$extensions', 'co.ordering' ], [ 'max_length' => 3 ]),
]);
$pattern = fn (string $key, string $pattern, ?string $default = null) => [
    set([ 'site', ...explode('.', $key), '$extensions', 'co.ordering' ], [ 'pattern' => $pattern ]),
    ...($default === null ? [] : [ set([ 'site', ...explode('.', $key), '$value' ], $default) ]),
];
$case('rules-patterns', 'Patterns of the subset PCRE and ECMAScript read alike, each with a default it takes: valid.', [
    ...$pattern('smart_banner.apple_id', '^\\d{6,12}$', '123456'),
    ...$pattern('contact_email', '^[a-zA-Z][\\w]*(\\.[a-zA-Z][\\w]*)+$', 'com.ordering.app'),
    ...$pattern('business_slug', '^(?:[a-z0-9]+-)*[a-z0-9]+$', 'my-shop'),
    ...$pattern('name', '^(?!-)[^<>\\n]{1,60}?$', 'Acme'),
    ...$pattern('description', '^(?:https?://)?[\\w.\\-/]+|[{}()|$^*+?.]\\.{2,}\\[\\]$', 'https://ordering.co/a-b'),
]);
$case('rules-patterns-escapes', 'Escapes outside the subset: whitespace, a PCRE anchor, a property, a character code and a backreference.', [
    ...$pattern('smart_banner.apple_id', '^\\s+$'),
    ...$pattern('contact_email', '\\Aabc'),
    ...$pattern('business_slug', '^\\p{L}+$'),
    ...$pattern('name', '\\x41'),
    ...$pattern('description', '(a)\\1'),
]);
$case('rules-patterns-groups', 'Groups outside the subset: a lookbehind, a named group, an inline flag, an atomic group and an unclosed one.', [
    ...$pattern('smart_banner.apple_id', '(?<=a)b'),
    ...$pattern('contact_email', '(?<id>a)'),
    ...$pattern('business_slug', '(?i)abc'),
    ...$pattern('name', '(?>a+)b'),
    ...$pattern('description', '(ab'),
]);
$case('rules-patterns-quantifiers', 'Quantifiers outside the subset: possessive, with nothing to repeat, out of order, a brace that is no quantifier and over a lookahead.', [
    ...$pattern('smart_banner.apple_id', '^\\d++$'),
    ...$pattern('contact_email', '*a'),
    ...$pattern('business_slug', 'a{3,2}'),
    ...$pattern('name', 'a{,3}'),
    ...$pattern('description', '(?=a)*b'),
]);
$case('rules-patterns-classes', 'Classes outside the subset: empty, empty and negated, a POSIX class, a word boundary inside and a `-` in the middle.', [
    ...$pattern('smart_banner.apple_id', 'a[]'),
    ...$pattern('contact_email', '[^]'),
    ...$pattern('business_slug', '[[:alpha:]]'),
    ...$pattern('name', '[\\b]'),
    ...$pattern('description', '[a-z-0]'),
]);
$case('rules-patterns-literals', 'Literals and escapes outside the subset: a lone `]` and `}`, `\\-` outside a class, a word boundary and a control character.', [
    ...$pattern('smart_banner.apple_id', 'a]'),
    ...$pattern('contact_email', 'a}'),
    ...$pattern('business_slug', 'a\\-b'),
    ...$pattern('name', '\\bword\\b'),
    ...$pattern('description', "a\tb"),
]);
$case('rules-patterns-values', 'Defaults a pattern of the subset refuses as ECMAScript does: before a final newline `$` does not match, `\\d` is ASCII and `.` matches no line break.', [
    ...$pattern('smart_banner.apple_id', '^\\d{6,12}$', "123456\n"),
    ...$pattern('contact_email', '^\\d{6,12}$', '٣٣٣٣٣٣'),
    ...$pattern('name', '^.$', "\r"),
    ...$pattern('description', '^x.$', "x\u{2028}"),
    ...$pattern('business_slug', '^\\w+$', 'ñandú'),
]);
$case('typography-line', 'A line height in px, and one above the ratio the format takes: invalid typography defaults.', [
    set([ 'tokens', 'typography', 'h1', '$value', 'line' ], 40),
    set([ 'tokens', 'typography', 'body_sm', '$value', 'line' ], 3.2),
    set([ 'tokens', 'typography', 'body', '$value', 'line' ], 0.8),
]);
$case('tokens-values', "Token defaults that are not literals of their group's kind.", [
    set('tokens.color.primary.$value', 'red'),
    set('tokens.space.md.$value', -5),
    set('tokens.shadow_style.style.$value', 'fuzzy'),
    set('tokens.typography.h1.$value.weight', 450),
]);
$case('prop-defaults', "Property defaults that do not fit their kind (one also above its control's max).", [
    set('components.button.base.full_width.$value', null),
    set('components.button.base.icon_gap.$value', '{color.primary}'),
    set('components.button.base.speed.$value', 99999),
    set('site.mode.$value', 'nope'),
]);
$case('prop-options', 'Options a kind does not take, and a level the options leave out.', [
    set([ 'components', 'button', 'base', 'radius', '$extensions', 'co.ordering', 'options' ], [ 'huge' ]),
    set('components.card.base.tint', node('color', '#ffffff', [ 'options' => [ '#ffffff' ] ])),
    set([ 'components', 'card', 'base', 'radius', '$extensions', 'co.ordering', 'options' ], [ 'sm', 'md' ]),
    set('components.card.base.radius.$value', '{radius.lg}'),
]);
$case('prop-shape', 'Nodes without a type: a value is no tuple (unless it looks like one), and an empty group of the site.', [
    set('components.button.base.extra', node(null, '#ffffff')),
    set('components.button.base.looks_like_a_tuple', node(null, [ 'color', '#ffffff' ])),
    set('site.extra', node(null, 5)),
    set('site.empty_group', [ '$description' => 'Nothing in it.' ]),
]);
$case('unknown-section', 'A section of a component that is none of the known ones.', [
    set('components.button.extras', [ 'one' => [ 'tint' => node('color', '#ffffff') ] ]),
]);
$case('platforms', 'A platform the schema does not declare, and platform properties that are not the base\'s of the same kind.', [
    set('components.modal.platforms.desktop', [ 'radius' => node('dimension', '{radius.none}', [ 'scale' => 'radius' ]) ]),
    set('components.modal.platforms.mobile_web.radius', node('color', '#ffffff')),
    set('components.modal.platforms.mobile_web.ghost', node('boolean', false)),
]);
$case('scope', "A group's scope: what is no component, a group, itself, a property the component lacks, of another kind, a default that does not fit.", [
    set('components.card.scope', [ 'input' => [ 'base' => [ 'radius' => node('dimension', '{radius.md}', [ 'scale' => 'radius', 'follows' => 'radius' ]) ] ] ]),
    set('components.header.scope.nothing', [ 'base' => [ 'radius' => node('dimension', '{radius.md}', [ 'scale' => 'radius' ]) ] ]),
    set('components.header.scope.card', [ '$description' => 'A group.' ]),
    set('components.header.scope.header', [ '$description' => 'Itself.' ]),
    set('components.header.scope.button.base', [
        'glow' => node('color', '#ffffff'),
        'radius' => node('color', '#ffffff'),
    ]),
    set('components.header.scope.input.base', [ 'radius' => node('dimension', '{space.sm}', [ 'scale' => 'radius' ]) ]),
]);
$case('slots', "Slots: an unknown component, an axis its component lacks, a choice it does not have, a group, and a component whose slots nest too deep.", [
    set('components.header.slots.cta.$value.component', 'nonexistent'),
    set('components.header.slots.search.$value.variant', 'primary'),
    set('components.input.slots.trailing_action.$value.size', 'huge'),
    set('components.input.slots.trailing_action.$value.tone', 'loud'),
    set('components.header.slots.order_type.$value.component', 'header'),
    set('components.trailing', [ 'slots' => [ 'inner' => node('slot', [ 'component' => 'input', 'size' => 'md' ]) ] ]),
    set('components.banner.slots', [ 'store' => node('slot', [ 'component' => 'trailing' ]) ]),
]);
$case('inherits', 'Parents that do not exist, that inherit themselves, or that are the component.', [
    set([ 'components', 'business_card', '$extensions', 'co.ordering', 'inherits' ], 'nonexistent'),
    set([ 'components', 'banner', '$extensions', 'co.ordering', 'inherits' ], 'business_card'),
    set([ 'components', 'modal', '$extensions', 'co.ordering', 'inherits' ], 'modal'),
]);
$case('visibility-images', 'Visibility and image defaults that do not fit, image formats and hints that are not valid.', [
    set('visibility.footer.$value', null),
    set('images.logotype.$value', 'ftp://example.com/logo.png'),
    set([ 'images', 'logotype', '$extensions', 'co.ordering', 'formats' ], [ 'gif' ]),
    set([ 'images', 'favicon', '$extensions', 'co.ordering', 'hints' ], [ 'size' => [ 20000, 10 ] ]),
    set([ 'images', 'isotype', '$extensions', 'co.ordering', 'hints' ], [ 'max_kb' => 9000 ]),
]);
$case('visibility-v1', 'Version 1 paths of visibility and images that are not a string or null.', [], null, [
    set('visibility.footer', 5),
    set('images.logotype', [ 'a' ]),
]);
$case('follows', 'What follows Identity with a group that is not the property\'s kind.', [
    set([ 'components', 'button', 'base', 'radius', '$extensions', 'co.ordering', 'follows' ], 'border'),
    set([ 'components', 'card', 'base', 'shadow', '$extensions', 'co.ordering', 'follows' ], 'radius'),
]);
$case('derive', 'Derived colors from a color the schema lacks, by an operation of the components, from a name that is no alias.', [
    set([ 'tokens', 'color', 'focus', '$extensions', 'co.ordering', 'derive' ], [ 'op' => 'same', 'from' => '{color.nope}' ]),
    set([ 'tokens', 'color', 'primary_pressed', '$extensions', 'co.ordering', 'derive' ], [ 'op' => 'step', 'from' => '{color.primary}', 'offset' => 1 ]),
    set([ 'tokens', 'color', 'primary_subtle', '$extensions', 'co.ordering', 'derive' ], [ 'op' => 'mix', 'from' => 'primary', 'over' => '{color.background}', 'amount' => 0.12 ]),
]);
$case('component-derive', "Components' derivations by an operation of the colors, from a value the variant lacks, inset by one it lacks.", [
    set([ 'components', 'tabs', 'variants', 'pill', 'tab_radius', '$extensions', 'co.ordering', 'derive' ], [ 'op' => 'same', 'from' => 'radius' ]),
    set([ 'components', 'card', 'variants', 'default', 'states', 'hover', 'shadow', '$extensions', 'co.ordering', 'derive' ], [ 'op' => 'step', 'from' => 'nope', 'offset' => 0 ]),
    set([ 'components', 'chip', 'variants', 'default', 'states', 'pressed', 'background', '$extensions', 'co.ordering', 'derive' ], [ 'op' => 'inset', 'from' => 'background', 'by' => 'nope' ]),
]);
$case('matrix', 'A matrix on a component without colors, and one into a section the component has.', [
    set([ 'components', 'link', '$extensions', 'co.ordering', 'matrix' ], [ 'into' => 'variants', 'roles' => [ 'ink' => [ 'op' => 'same', 'from' => 'main' ] ] ]),
    set([ 'components', 'button', '$extensions', 'co.ordering', 'matrix', 'into' ], 'base'),
]);
$case('migrate-valid', 'Migrations from keys the schema no longer has to keys it has.', [
    set([ '$extensions', 'co.ordering.migrate' ], [
        [ 'from' => 'components.button.base.old_gap', 'to' => 'components.button.base.icon_gap' ],
        [ 'from' => 'tokens.color.brand', 'to' => 'tokens.color.primary' ],
        [ 'from' => 'site.title', 'to' => 'site.name' ],
    ]),
]);
$case('migrate-invalid', "Migrations from a key the schema has, to one it lacks, across sections and onto themselves.", [
    set([ '$extensions', 'co.ordering.migrate' ], [
        [ 'from' => 'components.button.base.radius', 'to' => 'components.button.base.icon_gap' ],
        [ 'from' => 'components.button.base.old_gap', 'to' => 'components.button.base.new_gap' ],
        [ 'from' => 'components.button.base.old_gap', 'to' => 'tokens.color.primary' ],
        [ 'from' => 'components.button.base.gone', 'to' => 'components.button.base.gone' ],
    ]),
]);
$case('migrate-sections-valid', "Migrations of visibility, images and pages: a key whose own dots name no group, an image, a whole page, a page's footer, its list of sections, and one section within a page and to another.", [
    set([ '$extensions', 'co.ordering.migrate' ], [
        [ 'from' => 'visibility.header.brand', 'to' => 'visibility.header.search' ],
        [ 'from' => 'images.brand_logo', 'to' => 'images.logotype' ],
        [ 'from' => 'pages.shop', 'to' => 'pages.checkout' ],
        [ 'from' => 'pages.shop.footer', 'to' => 'pages.home.footer' ],
        [ 'from' => 'pages.shop.sections', 'to' => 'pages.checkout.sections' ],
        [ 'from' => 'pages.home.sections.banner', 'to' => 'pages.home.sections.featured' ],
        [ 'from' => 'pages.shop.sections.hero', 'to' => 'pages.home.sections.hero' ],
    ]),
]);
$case('migrate-sections-invalid', "Migrations of visibility, images and pages it rejects: from a key the schema has, to an image it lacks, a visibility key into a page's section, a footer into a section, a section into a page, a page field it has not, a page's sections or a section the schema still has, and a path deeper than a section.", [
    set([ '$extensions', 'co.ordering.migrate' ], [
        [ 'from' => 'visibility.footer', 'to' => 'visibility.nope' ],
        [ 'from' => 'images.brand_logo', 'to' => 'images.brand' ],
        [ 'from' => 'visibility.home.banner', 'to' => 'pages.home.sections.featured' ],
        [ 'from' => 'pages.shop.footer', 'to' => 'pages.home.sections.hero' ],
        [ 'from' => 'pages.shop.sections.hero', 'to' => 'pages.home' ],
        [ 'from' => 'pages.shop.extra', 'to' => 'pages.home.footer' ],
        [ 'from' => 'pages.home.sections', 'to' => 'pages.checkout.sections' ],
        [ 'from' => 'pages.home.sections.hero', 'to' => 'pages.checkout.sections.hero' ],
        [ 'from' => 'pages.shop.sections.hero.more', 'to' => 'pages.home.sections.hero' ],
    ]),
]);
$case('migrate-own-colors', "Migrations read the schema's own colors, not the catalog: from a catalog color the schema does not name (as one it dropped) passes, to one fails.", [
    set([ '$extensions', 'co.ordering.migrate' ], [
        [ 'from' => 'tokens.color.danger_text', 'to' => 'tokens.color.danger' ],
        [ 'from' => 'components.button.base.old_gap', 'to' => 'tokens.color.success_text' ],
    ]),
]);
$case('migrate-tokens', "Migrations of tokens by the schema's own names, of every group: a typography style and an opacity it does not name pass; from a group of tokens, a component's base or a variant the schema still has, it rejects.", [
    set([ '$extensions', 'co.ordering.migrate' ], [
        [ 'from' => 'tokens.typography.small', 'to' => 'tokens.typography.label' ],
        [ 'from' => 'tokens.opacity.dim', 'to' => 'tokens.opacity.scrim' ],
        [ 'from' => 'tokens.color', 'to' => 'tokens.color.primary' ],
        [ 'from' => 'components.button.base', 'to' => 'components.button.base.radius' ],
        [ 'from' => 'components.card.variants.default', 'to' => 'components.card.base.radius' ],
    ]),
]);

$home = $reference['pages']['home']['sections']['$value'];
$hidden_hero = $home;
$hidden_hero[0]['visible'] = false;
$case('pages', 'Pages: a field they do not have, a footer that is no bool, a section hidden that cannot be, a section twice, sections of another type, an empty page.', [
    set('pages.home.extra', node('boolean', true)),
    set('pages.home.footer.$type', 'string'),
    set('pages.home.footer.$value', ''),
    set('pages.home.sections.$value', [ ...$hidden_hero, $home[1] ]),
    set('pages.checkout.sections.$type', 'string'),
    set('pages.empty', [ '$description' => 'No footer, no sections.' ]),
]);
$case('pages-v1', "Sections' version 1 paths: one that is visibility's, one that is no `.hidden` path, a `v1_default` that is no bool, one that another section has.", [], null, [
    set('pages.home.featured.v1', 'footer.hidden'),
    set('pages.home.recent', [ 'v1' => 'content' ]),
    set('pages.checkout.tips', [ 'v1' => 'checkout.components.customer.hidden', 'v1_default' => 'yes' ]),
    set('pages.checkout.notes.v1', 'checkout.components.customer.hidden'),
]);
$case('v1', 'The version 1 map: paths that are not strings, an empty list, two controls on one key (in two entries, in one list, a site setting), and a version 2 path the schema lacks.', [], null, [
    set([ 'v1', 'tokens.color.primary' ], 5),
    set([ 'v1', 'tokens.color.link' ], []),
    set([ 'v1', 'tokens.color.text' ], 'general.components.fonts.primary.name'),
    set([ 'v1', 'tokens.color.border' ], [ 'a.b', 'a.b' ]),
    set([ 'v1', 'components.nonexistent.base.x' ], 'a.b.c'),
    set('site.name', 'my_products.components.website_theme.components.type'),
]);
$case('v1-own-colors', "The version 1 map names a catalog color the schema does not name: refused, and left out of the tuples, so its key does not collide with the primary's.", [], null, [
    set([ 'v1', 'tokens.color.danger_text' ], 'my_products.components.theme_settings.components.style.primary_btn_color'),
]);
$case('out-of-range', "Defaults outside their control's min and max, and nothing else.", [
    set('components.button.base.icon_gap.$value', 100),
    set('components.button.base.speed.$value', 2000),
]);
$case('check-and-range', "A default that neither fits its kind nor its control: check()'s error, then outOfRange()'s.", [
    set('components.button.base.icon_gap.$value', -1),
]);
$case('check-and-semver', "check()'s errors and the version's together.", [
    set('tokens.color.primary.$value', 'red'),
    version('1'),
]);

// --- The editor's metadata (EditorRules) ------------------------------------
// The reference's sections by index: 0 colors (0 brand, 1 surfaces), 1
// typography, 2 style (3 durations), 4 site (0 type), 6 visibility (0
// general, 1 header, 2 address); a group added to the visibility section
// goes after its last. header is the component with cards (0 bar, 1 logo).

$group = fn (int $section, int $index) => $reference['editor']['sections'][$section]['groups'][$index];
$cards = $reference['components']['header']['$extensions']['co.ordering.editor']['groups'];
$editor = fn (string|array $path, string $key) => [ ...path($path), '$extensions', 'co.ordering.editor', $key ];
$fonts = $reference['tokens']['font']['heading']['$extensions']['co.ordering']['options'];
$label = fn (string $key, string $text) => [ 'key' => $key, 'default' => $text ];
$visibility_groups = count($reference['editor']['sections'][6]['groups']);

$case('editor-valid', 'Editor metadata the rules take: a visibility group shown in a page too, an ascending scale, a font control listing every font with its weights, a translucent color marked alpha, a typography group as wide as its levels and a text control with its placeholder.', [
    set([ 'editor', 'sections', 6, 'groups', 0, 'pages' ], [ 'home' ]),
    set([ 'editor', 'sections', 2, 'groups', 3, 'ordered' ], 'ascending'),
    set($editor('tokens.font.body', 'options'), array_map(fn (string $font) => [ 'value' => $font, 'weights' => [ 400, 700 ] ], $fonts)),
    set('components.modal.base.background.$value', 'rgba(0,0,0,0.5)'),
    set($editor('components.modal.base.background', 'alpha'), true),
    set($editor('tokens.typography', 'fields'), [ 'size' => [ 'min' => 8, 'max' => 200 ], 'line' => [ 'unit' => 'x', 'min' => 0.8, 'max' => 3 ] ]),
    set($editor('site.name', 'control'), 'text'),
    set($editor('site.name', 'placeholder'), $label('BUILDER_THEME_SITE_NAME_EXAMPLE', 'My shop')),
]);
$case('editor-alpha', 'Colors the editor would show opaque (a translucent default, an alias to a token marked alpha), and alpha on a number and on a group.', [
    set('components.modal.base.background.$value', '#00000080'),
    set('components.card.base.background.$value', '{color.overlay}'),
    set($editor('components.button.base.icon_gap', 'alpha'), true),
    set($editor('tokens.color', 'alpha'), true),
]);
$case('editor-platforms', 'Platform defaults on a component property and on the visibility block, not on a key: visibility.<key>.platforms.invalid is check()\'s.', [
    set([ 'components', 'modal', 'base', 'radius', '$extensions', 'co.ordering', 'platforms' ], [ 'mobile_web' => false ]),
    set([ 'visibility', '$extensions', 'co.ordering', 'platforms' ], [ 'mobile_web' => true ]),
]);
$case('editor-pages', "Pages on a group of another section, a page the schema lacks, two groups of one key in a page, a visibility key in no group, one in two (a row and a card's switch), and a path that is no visibility key.", [
    set([ 'editor', 'sections', 0, 'groups', 0, 'pages' ], [ 'home' ]),
    set([ 'editor', 'sections', 6, 'groups', 2, 'pages' ], [ 'checkout', 'nowhere' ]),
    set([ 'editor', 'sections', 6, 'groups', $visibility_groups ], [ 'key' => 'address', 'pages' => [ 'checkout' ] ]),
    set([ 'visibility', 'header.glow' ], node('boolean', true)),
    set([ 'editor', 'sections', 4, 'groups', 0, 'toggle' ], [ 'path' => 'visibility.footer' ]),
    set([ 'editor', 'sections', 6, 'groups', 2, 'paths' ], [ ...$group(6, 2)['paths'], 'visibility.nope' ]),
]);
$case('editor-components', 'Components on a group of another section, pages and components on one group, a component the schema lacks, two groups of one key in a component, and a card that lists what a visibility group already shows in its component.', [
    set([ 'editor', 'sections', 0, 'groups', 1, 'components' ], [ 'button' ]),
    set([ 'editor', 'sections', 6, 'groups', 1, 'pages' ], [ 'home' ]),
    set([ 'editor', 'sections', 6, 'groups', 1, 'components' ], [ 'header', 'nope' ]),
    set([ 'editor', 'sections', 6, 'groups', $visibility_groups ], [ 'key' => 'header', 'components' => [ 'header' ] ]),
    set([ 'components', 'header', '$extensions', 'co.ordering.editor', 'groups', 1, 'paths' ], [ ...$cards[1]['paths'], 'visibility.header.cart' ]),
]);
$case('editor-card-order', "A component's props and cards out of order: a prop without order, two of one order in a card, a path the schema lacks, a card listing a prop before one of a lower order, a prop in a card it does not name, and one naming a card that does not list it.", [
    del($editor('components.header.base.height', 'order')),
    set($editor('components.header.base.layout', 'order'), 2),
    set([ 'components', 'header', '$extensions', 'co.ordering.editor', 'groups', 0, 'paths' ], [ ...$cards[0]['paths'], 'components.header.base.nope' ]),
    set([ 'components', 'header', '$extensions', 'co.ordering.editor', 'groups', 1, 'paths' ], array_reverse($cards[1]['paths'])),
    set($editor('components.header.base.background', 'group'), 'logo'),
    set('components.header.base.glow', node('color', '#ffffff', [], [ 'group' => 'bar', 'order' => 5 ])),
]);
$case('editor-rows', "A group of the editor whose rows go back to order 2 first, and a token listed by a group it does not name.", [
    set([ 'editor', 'sections', 0, 'groups', 0, 'paths' ], array_reverse($group(0, 0)['paths'])),
    set($editor('tokens.color.on_primary', 'group'), 'surfaces'),
]);
$case('editor-custom', 'custom on a token, on a group, and false on a typography chosen by level in a component; on a property of another type it is check()\'s rules.invalid alone.', [
    set($editor('tokens.typography.h1', 'custom'), true),
    set($editor('components.button.base', 'custom'), true),
    set($editor('components.input.base.text', 'custom'), false),
    set($editor('components.button.base.icon_gap', 'custom'), true),
]);
$case('editor-limits', "Limits of the number controls: a typography field whose min is over its max and one under its default, a level with a unit and no max, and a min over the max (the default within them is outOfRange()'s).", [
    set([ 'tokens', 'typography', 'h1', '$extensions', 'co.ordering.editor', 'fields', 'size', 'min' ], 100),
    set([ 'tokens', 'typography', 'body', '$extensions', 'co.ordering.editor', 'fields', 'size', 'max' ], 10),
    del($editor('components.button.base.icon_gap', 'max')),
    set($editor('components.button.base.speed', 'min'), 2000),
]);
$case('editor-lines', "Line height fields that are no ratio of the size (a unit of px, a max of 120), and a typography group narrower than its levels.", [
    set([ 'tokens', 'typography', 'h2', '$extensions', 'co.ordering.editor', 'fields', 'line', 'unit' ], 'px'),
    set([ 'tokens', 'typography', 'body_sm', '$extensions', 'co.ordering.editor', 'fields', 'line', 'max' ], 120),
    set($editor('tokens.typography', 'fields'), [ 'size' => [ 'min' => 10, 'max' => 50 ] ]),
]);
$case('editor-ordered', 'Scales out of order: durations said descending, and cards that list a color and a group.', [
    set([ 'editor', 'sections', 2, 'groups', 3, 'ordered' ], 'descending'),
    set([ 'components', 'header', '$extensions', 'co.ordering.editor', 'groups', 0, 'ordered' ], 'ascending'),
    set([ 'components', 'header', '$extensions', 'co.ordering.editor', 'groups', 1, 'ordered' ], 'ascending'),
    set([ 'components', 'header', '$extensions', 'co.ordering.editor', 'groups', 1, 'paths' ], [ ...$cards[1]['paths'], 'components.header.base' ]),
]);
$case('editor-section-groups', "The blocks of the pages: on a footer, missing on a page whose sections move in blocks, repeated, and in another order than the sections'.", [
    set($editor('pages.home.footer', 'section_groups'), [ [ 'key' => 'content', 'label' => $label('BUILDER_THEME_PAGE_CONTENT', 'Content') ] ]),
    del($editor('pages.checkout.sections', 'section_groups')),
    set($editor('pages.home.sections', 'section_groups'), [
        [ 'key' => 'content', 'label' => $label('BUILDER_THEME_PAGE_CONTENT', 'Content') ],
        [ 'key' => 'content', 'label' => $label('BUILDER_THEME_PAGE_CONTENT', 'Content') ],
    ]),
    set('pages.offers', [
        'footer' => node('boolean', null),
        'sections' => node('sections', [
            [ 'key' => 'main_a', 'visible' => true, 'hideable' => true, 'group' => 'main' ],
            [ 'key' => 'main_b', 'visible' => true, 'hideable' => true, 'group' => 'main' ],
            [ 'key' => 'side_a', 'visible' => true, 'hideable' => true, 'group' => 'side' ],
            [ 'key' => 'side_b', 'visible' => true, 'hideable' => true, 'group' => 'side' ],
        ], [], [ 'section_groups' => [
            [ 'key' => 'side', 'label' => $label('BUILDER_THEME_SECTION_GROUP_SIDE', 'Side') ],
            [ 'key' => 'main', 'label' => $label('BUILDER_THEME_SECTION_GROUP_MAIN', 'Main') ],
        ] ]),
    ]),
]);
$case('editor-fonts', 'Weights on a select, and a font control that lists a font twice, one that is not its own, leaves out the rest, and gives weights that go down.', [
    set($editor('tokens.font.body', 'control'), 'select'),
    set($editor('tokens.font.body', 'options'), [ [ 'value' => 'archivo', 'weights' => [ 400 ] ] ]),
    set($editor('tokens.font.heading', 'options'), [ [ 'value' => 'archivo', 'weights' => [ 700, 400 ] ], [ 'value' => 'archivo' ], [ 'value' => 'comic_sans' ] ]),
]);
$case('editor-texts', "Rules of a string on a token and on a slot, a placeholder and multiline on controls that are no text; on another property they are check()'s rules.invalid.", [
    set([ 'tokens', 'color', 'primary', '$extensions', 'co.ordering', 'max_length' ], 10),
    set([ 'components', 'header', 'slots', 'cta', '$extensions', 'co.ordering', 'pattern' ], '^x$'),
    set($editor('components.button.base.radius', 'placeholder'), $label('BUILDER_THEME_EXAMPLE', 'Example')),
    set($editor('components.button.base.icon_gap', 'multiline'), true),
]);
$home_sections = $home;
$home_sections[1]['group'] = 'aside';
$home_sections[2]['key'] = '_recent';
$case('editor-page-sections', "A page's sections: one alone in its group, and a key that does not start with a letter.", [
    set('pages.home.sections.$value', $home_sections),
    set($editor('pages.home.sections', 'section_groups'), [
        [ 'key' => 'aside', 'label' => $label('BUILDER_THEME_SECTION_GROUP_ASIDE', 'Aside') ],
        [ 'key' => 'content', 'label' => $label('BUILDER_THEME_PAGE_CONTENT', 'Content') ],
    ]),
]);
$case('editor-visibility-type', 'A visibility key typed string: its default is still a bool, which check() asks.', [
    set([ 'visibility', 'footer', '$type' ], 'string'),
]);

// --- Against the previous version ---------------------------------------------

$case('version-equal', 'The same version as the previous one, with the same content: unchanged, as api-core answers a deploy that publishes it again.', [], []);
$case('version-equal-reordered', 'The same version with the same content, its keys in another order: unchanged.', [], [ del('format'), set('format', $reference['format']) ]);
$case('version-exists', 'The same version as the previous one, with another content.', [ set('tokens.color.primary.$value', '#123456') ], []);
$case('version-lower', 'A version lower than the previous one of its base.', [ version('1.0.5') ], [ version('1.1.0') ]);
$case('version-previous-short', 'A previous version of two parts (`1.0` is `1.0.0`): a patch over it.', [ version('1.0.1') ], [ version('1.0') ]);
$case('version-previous-prerelease', "A previous pre-release: PHP's version_compare() puts 1.1.0 after 1.1.0-beta.", [ version('1.1.0') ], [ version('1.1.0-beta') ]);
$case('new-base', 'A new base is not compared: it may remove anything; what it adds and deprecates is still reported.', [
    version('2.0.0'),
    del('components.button.base.full_width'),
    set('components.button.base.glow', node('color', '#ffffff')),
    set('components.button.base.speed.$deprecated', true),
], []);
$case('diff-patch-default', 'A patch that changes a default.', [
    version('1.0.1'),
    set('tokens.color.primary.$value', '#123456'),
], []);
$case('diff-patch-texts', 'A patch that changes only texts and editor metadata.', [
    version('1.0.1'),
    set('components.button.base.radius.$description', 'The rounding.'),
    set([ 'components', 'button', 'base', 'icon_gap', '$extensions', 'co.ordering.editor', 'step' ], 4),
    set([ 'components', 'button', 'base', 'icon_gap', '$extensions', 'co.ordering.editor', 'unit' ], 'px'),
    set([ 'components', 'button', '$extensions', 'co.ordering.editor', 'advanced' ], true),
    del('components.header.scope.$extensions'),
    set([ 'editor', 'sections', 0, 'label', 'default' ], 'Colours'),
    set('$description', 'The reference, version 1.'),
], []);
$case('diff-minor-add', 'A minor that adds keys (the visibility key in a group of the editor) and changes a default.', [
    version('1.1.0'),
    set('components.button.base.glow', node('color', '#ffffff')),
    set([ 'visibility', 'header.glow' ], node('boolean', true)),
    set([ 'editor', 'sections', 6, 'groups', 1, 'paths' ], [ ...$group(6, 1)['paths'], 'visibility.header.glow' ]),
    set('tokens.color.primary.$value', '#123456'),
], []);
$case('diff-removed', 'A minor that removes a key that was not deprecated.', [
    version('1.1.0'),
    del('components.button.base.full_width'),
], []);
$case('diff-removed-deprecated', 'A minor that removes a deprecated key and a component deprecated as a whole.', [
    version('1.1.0'),
    del('components.button.base.full_width'),
    del('components.chip'),
    set([ 'editor', 'sections', 3, 'groups', 0, 'paths' ], array_values(array_diff($group(3, 0)['paths'], [ 'components.chip' ]))),
], [
    set('components.button.base.full_width.$deprecated', true),
    set('components.chip.$deprecated', 'No longer painted.'),
]);
$case('diff-deprecated-zero', 'A key deprecated as the string "0" is not deprecated (PHP\'s empty()): removing it breaks.', [
    version('1.1.0'),
    del('components.button.base.speed'),
], [ set('components.button.base.speed.$deprecated', '0') ]);
$case('diff-deprecating', 'A minor that deprecates keys: only the newly deprecated are reported.', [
    version('1.1.0'),
    set('components.button.base.speed.$deprecated', 'Use the motion tokens.'),
    set('components.button.base.full_width.$deprecated', false),
    set('components.button.base.radius.$deprecated', true),
], [ set('components.button.base.radius.$deprecated', true) ]);
$case('diff-migrated', 'A minor that moves a key with a migration.', [
    version('1.1.0'),
    set([ '$extensions', 'co.ordering.migrate' ], [ [ 'from' => 'components.button.base.old_gap', 'to' => 'components.button.base.icon_gap' ] ]),
], [ set('components.button.base.old_gap', node('dimension', '{space.sm}', [ 'scale' => 'space' ])) ]);
$case('diff-migrated-sections', "A minor that moves a visibility key, an image and a whole page with migrations: the page's footer and sections go with it.", [
    version('1.1.0'),
    set([ '$extensions', 'co.ordering.migrate' ], [
        [ 'from' => 'visibility.header.brand', 'to' => 'visibility.header.search' ],
        [ 'from' => 'images.brand_logo', 'to' => 'images.logotype' ],
        [ 'from' => 'pages.shop', 'to' => 'pages.checkout' ],
    ]),
], [
    set([ 'visibility', 'header.brand' ], node(null, true)),
    set([ 'images', 'brand_logo' ], node(null, '')),
    set('pages.shop', $reference['pages']['checkout']),
]);
$case('diff-page-partly-migrated', "A minor that drops a page but moves only its footer: its sections are removed.", [
    version('1.1.0'),
    set([ '$extensions', 'co.ordering.migrate' ], [ [ 'from' => 'pages.shop.footer', 'to' => 'pages.home.footer' ] ]),
], [ set('pages.shop', $reference['pages']['checkout']) ]);
$case('diff-type-changed', 'A minor that changes the type of a key.', [
    version('1.1.0'),
    set('site.business_slug.$type', 'html'),
], []);
$case('diff-scale-changed', 'A minor that moves dimensions to another scale: the levels stored themes name are of the old one.', [
    version('1.1.0'),
    set('components.modal.base.padding.$value', '{size.lg}'),
    set([ 'components', 'modal', 'base', 'padding', '$extensions', 'co.ordering', 'scale' ], 'size'),
    set('components.button.base.icon_gap.$value', '{icon.sm}'),
    set([ 'components', 'button', 'base', 'icon_gap', '$extensions', 'co.ordering', 'scale' ], 'icon'),
], []);
$case('diff-option-removed', 'A minor that removes an option (and adds another).', [
    version('1.1.0'),
    set([ 'site', 'mode', '$extensions', 'co.ordering', 'options' ], [ 'marketplace', 'single_store', 'kiosk' ]),
], []);
$case('diff-migration-removed', 'A minor that drops a migration of its base.', [
    version('1.1.0'),
], [ set([ '$extensions', 'co.ordering.migrate' ], [ [ 'from' => 'components.button.base.old_gap', 'to' => 'components.button.base.icon_gap' ] ]) ]);
$case('diff-errors', "Every incompatibility at once, in SchemaDiff's order: each key's, the migrations', and a patch's values.", [
    version('1.0.1'),
    del('components.button.base.full_width'),
    set('site.business_slug.$type', 'html'),
    set([ 'site', 'mode', '$extensions', 'co.ordering', 'options' ], [ 'marketplace', 'single_store' ]),
], [ set([ '$extensions', 'co.ordering.migrate' ], [ [ 'from' => 'components.button.base.old_gap', 'to' => 'components.button.base.icon_gap' ] ]) ]);
$case('diff-valid-changes', 'A valid minor with every kind of change: added, removed (deprecated), migrated and deprecated.', [
    version('1.2.0'),
    set('components.button.base.glow', node('color', '#ffffff')),
    del('components.button.base.full_width'),
    set([ '$extensions', 'co.ordering.migrate' ], [ [ 'from' => 'components.button.base.old_gap', 'to' => 'components.button.base.icon_gap' ] ]),
    set('components.button.base.speed.$deprecated', true),
], [
    version('1.1.0'),
    set('components.button.base.full_width.$deprecated', true),
    set('components.button.base.old_gap', node('dimension', '{space.sm}', [ 'scale' => 'space' ])),
]);

$case('diff-patch-limits', "A patch that changes what of the editor validates a value: a control's max, a typography field's min and a color's `alpha`.", [
    version('1.0.1'),
    set([ 'components', 'button', 'base', 'icon_gap', '$extensions', 'co.ordering.editor', 'max' ], 64),
    set([ 'tokens', 'typography', 'h1', '$extensions', 'co.ordering.editor', 'fields', 'size', 'min' ], 10),
    set([ 'components', 'card', 'base', 'background', '$extensions', 'co.ordering.editor', 'alpha' ], true),
], []);
$case('diff-alpha-removed', "A minor that takes `alpha` from a color that had it: its translucent values no longer fit (alpha_removed), while tightening a control's limits and a string's length is the minor's to do.", [
    version('1.1.0'),
    set([ 'components', 'button', 'base', 'icon_gap', '$extensions', 'co.ordering.editor', 'max' ], 24),
    set([ 'site', 'name', '$extensions', 'co.ordering' ], [ 'max_length' => 40 ]),
], [ set([ 'components', 'card', 'base', 'background', '$extensions', 'co.ordering.editor', 'alpha' ], true) ]);
$case('diff-changes', "A valid minor that adds an option, changes defaults (a color's and a section's `visible`), tightens rules (a control's max, a string's length), adds `alpha` and changes a section's `hideable` and `group` and the order of a page's sections.", [
    version('1.1.0'),
    set([ 'site', 'mode', '$extensions', 'co.ordering', 'options' ], [ 'marketplace', 'single_store', 'franchise', 'kiosk' ]),
    set('tokens.color.primary.$value', '#123456'),
    set([ 'components', 'button', 'base', 'icon_gap', '$extensions', 'co.ordering.editor', 'max' ], 24),
    set([ 'site', 'name', '$extensions', 'co.ordering' ], [ 'max_length' => 40 ]),
    set([ 'components', 'card', 'base', 'background', '$extensions', 'co.ordering.editor', 'alpha' ], true),
    set('pages.checkout.sections.$value', [
        [ 'key' => 'summary', 'visible' => true, 'hideable' => false, 'group' => null ],
        [ 'key' => 'notes', 'visible' => false, 'hideable' => true, 'group' => 'extras' ],
        [ 'key' => 'tips', 'visible' => true, 'hideable' => false, 'group' => 'extras' ],
    ]),
], []);
$case('diff-sections', "A minor that removes a page's section (not deprecated), renames another with a migration and adds one: each section is a key.", [
    version('1.1.0'),
    set('pages.home.sections.$value', [
        [ 'key' => 'hero', 'visible' => true, 'hideable' => false, 'group' => null ],
        [ 'key' => 'main_content', 'visible' => true, 'hideable' => true, 'group' => 'content' ],
        [ 'key' => 'news', 'visible' => true, 'hideable' => true, 'group' => 'content' ],
    ]),
    set([ '$extensions', 'co.ordering.migrate' ], [ [ 'from' => 'pages.home.sections.featured', 'to' => 'pages.home.sections.main_content' ] ]),
], []);
$case('diff-sections-deprecated', "A minor that removes a section of a page whose sections were deprecated: valid.", [
    version('1.1.0'),
    set('pages.checkout.sections.$value', array_slice($reference['pages']['checkout']['sections']['$value'], 0, 1)),
    del($editor('pages.checkout.sections', 'section_groups')),
], [ set('pages.checkout.sections.$deprecated', true) ]);
$columns_moved = [
    del('components.banner.base.columns'),
    del('components.banner.platforms.mobile_web.columns'),
    set('components.banner.base.per_row', $reference['components']['banner']['base']['columns']),
    set('components.banner.platforms.mobile_web.per_row', $reference['components']['banner']['platforms']['mobile_web']['columns']),
];
$case('diff-platform-migrated', "A minor that renames a property with a default of its own on a platform: the platform's default goes with it.", [
    version('1.1.0'),
    ...$columns_moved,
    set([ '$extensions', 'co.ordering.migrate' ], [ [ 'from' => 'components.banner.base.columns', 'to' => 'components.banner.base.per_row' ] ]),
], []);
$case('diff-platform-deprecated', "A minor that removes a deprecated property and its platform's default: valid.", [
    version('1.1.0'),
    del('components.banner.base.columns'),
    del('components.banner.platforms.mobile_web.columns'),
], [ set('components.banner.base.columns.$deprecated', true) ]);

// --- Against the previous base (the upgrade block) -------------------------
// The schema is the reference as 2.0.0 with its block; the previous base,
// the reference as 1.4.0 with what the block takes from it: a property, a
// color, a visibility key, a typography style, a page and site settings it
// named otherwise, a property whose scale and one whose type changed, an
// option renamed, and keys the new base drops.

$previous_1 = [
    version('1.4.0'),
    set('components.button.base.old_gap', node('dimension', '{space.sm}', [ 'scale' => 'space' ])),
    set([ 'components', 'link', 'base', 'underline', '$extensions', 'co.ordering', 'options' ], [ 'none', 'hover', 'underlined' ]),
    set('components.modal.base.padding', node('dimension', '{size.lg}', [ 'scale' => 'size' ])),
    set('components.button.base.speed', node('number', 120)),
    set('tokens.color.brand', [ '$value' => '#E4002B' ]),
    set([ 'tokens', 'typography', 'small' ], [ '$value' => [ 'size' => 11, 'line' => 1.4, 'weight' => 400 ] ]),
    set([ 'visibility', 'header.brand' ], node(null, true)),
    set([ 'visibility', 'old_banner' ], node(null, false)),
    set('pages.shop', $reference['pages']['checkout']),
    set('site.slug', node('string', '')),
    set('site.legacy_flag', node('boolean', false)),
];
$moves = [
    [ 'from' => 'components.button.base.old_gap', 'to' => 'components.button.base.icon_gap' ],
    [ 'from' => 'components.link.base.underline', 'to' => 'components.link.base.underline', 'map' => [ 'none' => 'none', 'hover' => 'hover', 'underlined' => 'always' ] ],
    [ 'from' => 'components.modal.base.padding', 'to' => 'components.modal.base.padding', 'map' => [ '{size.lg}' => '{space.lg}', '{size.md}' => '{space.md}' ] ],
    [ 'from' => 'tokens.color.brand', 'to' => 'tokens.color.primary' ],
    [ 'from' => 'visibility.header.brand', 'to' => 'visibility.header.search' ],
    [ 'from' => 'pages.shop', 'to' => 'pages.checkout' ],
    [ 'from' => 'site.slug', 'to' => 'site.business_slug' ],
];
$block = fn (array $changes = []) => set([ '$extensions', 'co.ordering.upgrade' ], $changes + [
    'from' => 1,
    'moves' => $moves,
    'drop' => [ 'components.button.base.speed', 'visibility.old_banner', 'site.legacy_flag' ],
    'rewrite' => [ 'id' => 'reference.2', 'paths' => [ 'tokens.typography.small' ] ],
]);

$case('upgrade-valid', 'A new base whose block accounts for every key of the previous one: moves (a renamed property, a color, a visibility key, a whole page, a site setting, and two in place with a map, an option renamed and a scale changed), drops (a property whose type changed, a visibility key, a setting) and a rewrite.', [
    version('2.0.0'),
    $block(),
], null, null, $previous_1);
$case('upgrade-to-migrated', "A move to a key the new base's own migrations move on: it counts where they take it.", [
    version('2.1.0'),
    $block([ 'moves' => [
        [ 'from' => 'components.button.base.old_gap', 'to' => 'components.button.base.gap_inline' ],
        [ 'from' => 'components.link.base.underline', 'to' => 'components.link.base.underline', 'map' => [ 'none' => 'none', 'hover' => 'hover', 'underlined' => 'always' ] ],
        [ 'from' => 'components.modal.base.padding', 'to' => 'components.modal.base.padding', 'map' => [ '{size.lg}' => '{space.lg}' ] ],
        [ 'from' => 'tokens.color.brand', 'to' => 'tokens.color.primary' ],
        [ 'from' => 'visibility.header.brand', 'to' => 'visibility.header.search' ],
        [ 'from' => 'pages.shop', 'to' => 'pages.checkout' ],
        [ 'from' => 'site.slug', 'to' => 'site.business_slug' ],
    ] ]),
    set([ '$extensions', 'co.ordering.migrate' ], [ [ 'from' => 'components.button.base.gap_inline', 'to' => 'components.button.base.icon_gap' ] ]),
], null, null, $previous_1);
$case('upgrade-required', 'A new base without a block, though there is a previous one.', [ version('2.0.0') ], null, null, $previous_1);
$case('upgrade-from-not-previous', 'A block whose `from` is not the previous base: base 3 from base 2, when the last published base is 1.', [ version('3.0.0'), $block([ 'from' => 2 ]) ], null, null, $previous_1);
$case('upgrade-from-not-lower', 'A block whose `from` is not below its own base: nothing else is checked.', [ version('1.5.0'), $block([ 'from' => 1 ]) ], null, null, $previous_1);
$case('upgrade-errors', "Every error of a block at once, in order: a move from a key the previous base has not, to one the new base has not, across sections, with a map to what is no option or level, from a key an earlier move has; drops of what the previous base has not; and the keys nothing accounts for (a property whose type changed, a setting, a typography style, a visibility key).", [
    version('2.0.0'),
    set([ '$extensions', 'co.ordering.upgrade' ], [
        'from' => 1,
        'moves' => [
            [ 'from' => 'components.button.base.nope', 'to' => 'components.button.base.icon_gap' ],
            [ 'from' => 'components.button.base.old_gap', 'to' => 'components.button.base.nope' ],
            [ 'from' => 'visibility.header.brand', 'to' => 'pages.home.footer' ],
            [ 'from' => 'components.modal.base.padding', 'to' => 'components.modal.base.padding', 'map' => [ '{size.lg}' => '{size.lg}' ] ],
            [ 'from' => 'components.link.base.underline', 'to' => 'components.link.base.underline', 'map' => [ 'underlined' => 'dotted' ] ],
            [ 'from' => 'tokens.color.brand', 'to' => 'tokens.typography.h1' ],
            [ 'from' => 'visibility.header.brand', 'to' => 'visibility.header.search' ],
            [ 'from' => 'pages.shop', 'to' => 'pages.checkout' ],
        ],
        'drop' => [ 'visibility.nope', 'components.nope', 'visibility.header' ],
    ]),
], null, null, $previous_1);
$case('upgrade-minor-adds', 'A minor of the new base that adds a move and a path to drop: valid.', [
    version('2.1.0'),
    $block([ 'drop' => [ 'components.button.base.speed', 'visibility.old_banner', 'site.legacy_flag', 'tokens.typography.small' ] ]),
], [ version('2.0.0'), $block() ]);
$case('upgrade-platform-default', "A move of a property with a platform's default: the platform's default is accounted for with it.", [
    version('2.0.0'),
    ...$columns_moved,
    $block([ 'moves' => [ ...$moves, [ 'from' => 'components.banner.base.columns', 'to' => 'components.banner.base.per_row' ] ] ]),
], null, null, $previous_1);
$case('upgrade-map-boolean', "Maps of booleans: one turned around (valid), one whose keys are not `true` and `false`, and one to a boolean that is no boolean.", [
    version('2.0.0'),
    set([ 'visibility', 'show_prices' ], node(null, true)),
    set([ 'visibility', 'show_stock' ], node(null, true)),
    set([ 'editor', 'sections', 6, 'groups', 1, 'paths' ], [ ...$group(6, 1)['paths'], 'visibility.show_prices', 'visibility.show_stock' ]),
    $block([
        'moves' => [
            ...$moves,
            [ 'from' => 'visibility.hide_prices', 'to' => 'visibility.show_prices', 'map' => [ 'true' => false, 'false' => true ] ],
            [ 'from' => 'visibility.hide_stock', 'to' => 'visibility.show_stock', 'map' => [ '1' => false, '0' => true ] ],
            [ 'from' => 'visibility.old_banner', 'to' => 'visibility.footer', 'map' => [ 'true' => 'yes' ] ],
        ],
        'drop' => [ 'components.button.base.speed', 'site.legacy_flag' ],
    ]),
], null, null, [
    ...$previous_1,
    set([ 'visibility', 'hide_prices' ], node(null, false)),
    set([ 'visibility', 'hide_stock' ], node(null, false)),
]);
$case('upgrade-removed', "A minor of the new base that drops a move of its block, and one that changes its rewrite: schema.upgrade_removed.", [
    version('2.1.0'),
    $block([ 'moves' => [ [ 'from' => 'components.button.base.old_gap', 'to' => 'components.button.base.icon_gap' ] ], 'rewrite' => [ 'id' => 'reference.2b', 'paths' => [] ] ]),
], [ version('2.0.0'), $block() ]);

// --- In development ---------------------------------------------------------
// The same cases with development = true: the version's order and the diff's
// incompatibilities refuse nothing, the changes are still answered, and the
// schema alone is checked as always.

$development = [
    'version-equal' => 'In development too, the same version with the same content is unchanged.',
    'version-lower' => 'In development, a version lower than the previous one is no refusal.',
    'diff-removed' => 'In development, removing a key that was not deprecated is no refusal: it is reported as removed.',
    'diff-errors' => 'In development, every incompatibility at once is no refusal: the changes are answered.',
    'diff-valid-changes' => 'In development, a valid minor answers the same changes.',
    'version-exists' => 'In development, the same version with another content is no refusal: production would answer schema.version_exists, a warning here.',
    'diff-patch-limits' => 'In development, what production refuses as incompatible is answered in warnings.',
    'prop-defaults' => 'In development, the schema itself is still checked.',
    'semver' => 'In development, the version is still semver.',
];
foreach ($cases as $entry) {
    if (isset($development[$entry['name']])) {
        $cases[] = [ ...$entry, 'name' => "development-{$entry['name']}", 'description' => $development[$entry['name']], 'development' => true ];
    }
}

// --- Answers ---------------------------------------------------------------

$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;
$cases = json_decode(json_encode($cases, $flags), true, 512, JSON_THROW_ON_ERROR);

$meta_failures = [];
foreach ($cases as $index => $entry) {
    $schema = apply($reference, $entry['schema']);
    $previous = isset($entry['previous']) ? apply($reference, $entry['previous']) : null;
    $previous_base = isset($entry['previousBase']) ? apply($reference, $entry['previousBase']) : null;
    $v1_map = isset($entry['v1Map']) ? apply($map, $entry['v1Map']) : [];
    if (NodeCompiler::isNodes($schema) && ($errors = ThemeSchemas::meta($schema)) !== []) {
        $meta_failures[$entry['name']] = $errors;
    }
    $cases[$index]['expected'] = ThemeSchemaPublisher::validate($schema, $v1_map, $previous, $entry['development'] ?? false, $previous_base);
}
if ($meta_failures !== []) {
    fwrite(STDERR, "These cases do not pass the meta-schema, which the TypeScript validate() does not check:\n" . json_encode($meta_failures, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    exit(1);
}

echo json_encode([
    'description' => "What api-core's ThemeSchemaPublisher::validate() answers for each mutation of the reference schema, {$reference['product_type']}@{$reference['version']}.",
    'php' => PHP_VERSION,
    'cases' => $cases,
], JSON_PRETTY_PRINT | $flags) . "\n";
