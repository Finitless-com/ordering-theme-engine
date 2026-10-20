<?php

/*
 * The golden cases of validate(): named mutations of the product's current
 * schema, each with what api-core's ThemeSchemaPublisher::validate()
 * answers for it, as JSON on stdout. It runs inside api-core's dev
 * container, read from stdin (the container does not see this repository):
 *
 *   docker compose -f docker-compose.dev.yml exec -T api \
 *     php -- resources/themes/marketplace_website/26.0.json \
 *            resources/themes/marketplace_website/v1-map.json < scripts/validate-cases.php
 *
 * The paths are relative to api-core's root. Each case stores its mutation,
 * not the schema it produces: a list of operations over the schema
 * (`["set", path, value]`, `["delete", path]`, a path being a list of keys)
 * that the package's test applies the same way to its copies of the schema
 * and the version 1 map (fixtures/schema-26.0.nodes.json, fixtures/v1-map.json).
 * The answer is computed over the case as it was written and read back, so
 * PHP validates exactly what the fixture says.
 *
 * A case with `development: true` runs in api-core's development mode
 * (validate()'s last argument).
 *
 * Every case's schema passes the meta-schema (ThemeSchemas::meta()), which
 * the package leaves to its consumer: a case that does not fails the script.
 */

declare(strict_types=1);

use App\Themes\Schema\NodeCompiler;
use App\Themes\ThemeSchemaPublisher;
use App\Themes\ThemeSchemas;

require getcwd() . '/vendor/autoload.php';

// A warning (an array cast to a string…) is a broken case, not a quiet one.
set_error_handler(function (int $severity, string $message, string $file, int $line) {
    throw new \ErrorException($message, 0, $severity, $file, $line);
});

[ , $schema_path, $map_path ] = $argv + [ null, null, null ];
if (!is_string($schema_path) || !is_file($schema_path) || !is_string($map_path) || !is_file($map_path)) {
    fwrite(STDERR, "Usage: php -- <schema in nodes> <version 1 map> < validate-cases.php\n");
    exit(1);
}
$read = fn (string $path) => json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
$original = $read($schema_path);
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

/** A version of the base: `26.0.0` by default. */
function version(string $version): array
{
    return set('version', $version);
}

// The base every case starts from unless it says `original`: the product's
// schema with a semver version and its two defaults within their control.
$base = [
    version('26.0.0'),
    set('components.signup_page.base.padding_y.$value', 96),
    set('components.signup_page.base.padding_x.$value', 96),
];

$cases = [];
/**
 * @param array<int, array> $ops over the base (or the original)
 * @param array{from?: string, ops?: array}|null $previous
 * @param array<int, array>|null $v1 operations over the product's version 1 map; null passes none
 */
$case = function (string $name, string $description, array $ops, ?array $previous = null, ?array $v1 = null, string $from = 'base') use (&$cases) {
    $entry = [ 'name' => $name, 'description' => $description, 'schema' => [ 'from' => $from, 'ops' => $ops ] ];
    if ($previous !== null) {
        $entry['previous'] = [ 'from' => $previous['from'] ?? 'base', 'ops' => $previous['ops'] ?? [] ];
    }
    if ($v1 !== null) {
        $entry['v1Map'] = [ 'from' => 'product', 'ops' => $v1 ];
    }
    $cases[] = $entry;
};

// --- The schema alone ------------------------------------------------------

$case('original', "The product's schema as it ships: two defaults above their control's max (signup_page's padding, 120 and 130 over 96) and a version that is not semver.", [], null, [], 'original');
$case('valid', 'The base: valid, without a version 1 map.', []);
$case('valid-v1-map', "The base with the product's version 1 map: valid.", [], null, []);
$case('format', 'A schema that is not in nodes stops at the format.', [ set('$schema', 'urn:ordering:theme-schema:0') ]);
$case('semver', 'A version of the base that is not major.minor.patch.', [ version('26.1') ]);
$case('product-type', 'A product type that is not one of the Builder\'s.', [ set('product_type', 'tv_app') ]);

$case('compile-not-a-node', "Values that are not nodes: the compiler's errors, without check(), and the version's.", [
    set('tokens.color.primary', [ '$description' => 'No value.' ]),
    set('components.button.base.radius', [ '$description' => 'No value.' ]),
    set('tokens.color.secondary.$value', 'red'),
    version('26.0'),
]);
$case('compile-token-type', 'Tokens that do not declare the type or the options the engine validates them with.', [
    set([ 'tokens', 'font', 'body', '$extensions', 'co.ordering', 'options' ], [ 'roboto', 'raleway', 'poppins', 'playfair_display', 'open_sans', 'nunito', 'montserrat', 'lato', 'inter', 'archivo' ]),
    set('tokens.color.text.$type', 'string'),
]);
$case('compile-kind', 'A node whose type and scale are no property kind.', [
    set('components.button.base.radius.$type', 'font'),
]);
$case('own-colors-dropped', 'A schema names its own colors: dropping one its components still paint is an alias error at each of them.', [
    del('tokens.color.favorite'),
    set([ 'tokens', 'focus_ring', 'color', '$extensions', 'co.ordering', 'options' ], [ 'primary', 'on_primary', 'primary_subtle', 'primary_pressed', 'primary_text', 'secondary', 'on_secondary', 'secondary_subtle', 'secondary_pressed', 'background', 'surface', 'surface_alt', 'surface_raised', 'overlay', 'on_overlay', 'inverse_surface', 'on_inverse', 'text_heading', 'text', 'text_muted', 'text_disabled', 'link', 'border', 'border_strong', 'divider', 'focus', 'success', 'on_success', 'success_subtle', 'warning', 'on_warning', 'warning_subtle', 'danger', 'on_danger', 'danger_subtle', 'info', 'on_info', 'info_subtle', 'disabled', 'rating' ]),
]);
$case('own-colors-added', 'A color of the catalog the schema adds, offered to the focus ring too: valid.', [
    set('tokens.color.danger_text', [ '$value' => '#B02A3E' ]),
    set([ 'tokens', 'focus_ring', 'color', '$extensions', 'co.ordering', 'options' ], [ 'primary', 'on_primary', 'primary_subtle', 'primary_pressed', 'primary_text', 'secondary', 'on_secondary', 'secondary_subtle', 'secondary_pressed', 'background', 'surface', 'surface_alt', 'surface_raised', 'overlay', 'on_overlay', 'inverse_surface', 'on_inverse', 'text_heading', 'text', 'text_muted', 'text_disabled', 'link', 'border', 'border_strong', 'divider', 'focus', 'success', 'on_success', 'success_subtle', 'warning', 'on_warning', 'warning_subtle', 'danger', 'on_danger', 'danger_subtle', 'info', 'on_info', 'info_subtle', 'disabled', 'rating', 'favorite', 'danger_text' ]),
]);

$case('tokens-required-unknown', 'A token missing, one the format does not have, and a group it does not have.', [
    del('tokens.radius.xl'),
    set('tokens.color.brand', node('color', '#123456')),
    set('tokens.extra', [ 'one' => node('number', 1) ]),
]);
$case('token-narrowed', "The focus ring offers some of its colors and distances, its default among them, and the scrollbar's tokens are dimensions: valid.", [
    set('tokens.focus_ring.color.$value', 'primary'),
    set([ 'tokens', 'focus_ring', 'color', '$extensions', 'co.ordering', 'options' ], [ 'primary', 'secondary', 'text', 'border_strong', 'info', 'success', 'warning', 'danger' ]),
    set([ 'tokens', 'focus_ring', 'offset', '$extensions', 'co.ordering', 'options' ], [ 'none', 'xxs', 'xs', 'sm' ]),
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
    set([ 'components', 'link', 'sizes', 'sm', 'text', '$extensions', 'co.ordering.editor', 'custom' ], true),
    set([ 'site', 'name', '$extensions', 'co.ordering' ], [ 'max_length' => 60 ]),
    set([ 'site', 'smart_banner', 'apple_id', '$extensions', 'co.ordering' ], [ 'pattern' => '^\\d{6,12}$' ]),
    set([ 'images', 'business_listing_hero', '$extensions', 'co.ordering', 'hints', 'safe' ], '4:1'),
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
$case('typography-line', 'A line height in px, and one above the ratio the format takes: invalid typography defaults.', [
    set([ 'tokens', 'typography', 'h1', '$value', 'line' ], 40),
    set([ 'tokens', 'typography', 'caption', '$value', 'line' ], 3.2),
    set([ 'tokens', 'typography', 'body', '$value', 'line' ], 0.8),
]);
$case('tokens-values', "Token defaults that are not literals of their group's kind.", [
    set('tokens.color.primary.$value', 'red'),
    set('tokens.space.md.$value', -5),
    set('tokens.shadow_style.style.$value', 'fuzzy'),
    set('tokens.typography.h1.$value.weight', 450),
]);
$case('prop-defaults', "Property defaults that do not fit their kind (one also above its control's max).", [
    set('components.button.base.mobile_full_width.$value', null),
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
    set('components.auth_form.scope.nothing', [ 'base' => [ 'radius' => node('dimension', '{radius.md}', [ 'scale' => 'radius' ]) ] ]),
    set('components.auth_form.scope.header', [ '$description' => 'A group.' ]),
    set('components.auth_form.scope.auth_form', [ '$description' => 'Itself.' ]),
    set('components.auth_form.scope.button.base', [
        'glow' => node('color', '#ffffff'),
        'radius' => node('color', '#ffffff'),
    ]),
    set('components.auth_form.scope.input.base', [ 'radius' => node('dimension', '{space.sm}', [ 'scale' => 'radius' ]) ]),
]);
$case('slots', "Slots: an unknown component, an axis its component lacks, a choice it does not have, a group, and a component whose slots nest too deep.", [
    set('components.banner.slots.action.$value.component', 'nonexistent'),
    set('components.modal.slots.close.$value.variant', 'primary'),
    set('components.cart.slots.clear.$value.size', 'huge'),
    set('components.cart.slots.clear.$value.tone', 'loud'),
    set('components.promo_carousel.slots.arrow.$value.component', 'auth_form'),
    set('components.trailing', [ 'slots' => [ 'inner' => node('slot', [ 'component' => 'input', 'size' => 'md' ]) ] ]),
    set('components.cart.slots.store.$value', [ 'component' => 'trailing' ]),
]);
$case('inherits', 'Parents that do not exist, that inherit themselves, or that are the component.', [
    set([ 'components', 'business_card', '$extensions', 'co.ordering', 'inherits' ], 'nonexistent'),
    set([ 'components', 'product_card', '$extensions', 'co.ordering', 'inherits' ], 'business_card'),
    set([ 'components', 'wallet_balance', '$extensions', 'co.ordering', 'inherits' ], 'wallet_balance'),
]);
$case('visibility-images', 'Visibility and image defaults that do not fit, image formats and hints that are not valid.', [
    set('visibility.footer.$value', null),
    set('images.logo.$value', 'ftp://example.com/logo.png'),
    set([ 'images', 'logo', '$extensions', 'co.ordering', 'formats' ], [ 'gif' ]),
    set([ 'images', 'favicon', '$extensions', 'co.ordering', 'hints' ], [ 'size' => [ 20000, 10 ] ]),
    set([ 'images', 'app_icon', '$extensions', 'co.ordering', 'hints' ], [ 'max_kb' => 9000 ]),
]);
$case('visibility-v1', 'Version 1 paths of visibility and images that are not a string or null.', [], null, [
    set('visibility.footer', 5),
    set('images.logo', [ 'a' ]),
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
$case('migrate-invalid', "Migrations from a key the schema has, to one it lacks, across sections, onto themselves, and of visibility (kindAt() knows no visibility key).", [
    set([ '$extensions', 'co.ordering.migrate' ], [
        [ 'from' => 'components.button.base.radius', 'to' => 'components.button.base.icon_gap' ],
        [ 'from' => 'components.button.base.old_gap', 'to' => 'components.button.base.new_gap' ],
        [ 'from' => 'components.button.base.old_gap', 'to' => 'tokens.color.primary' ],
        [ 'from' => 'components.button.base.gone', 'to' => 'components.button.base.gone' ],
        [ 'from' => 'visibility.old_footer', 'to' => 'visibility.footer' ],
    ]),
]);

$home = $original['pages']['home']['sections']['$value'];
$hidden_hero = $home;
$hidden_hero[0]['visible'] = false;
$case('pages', 'Pages: a field they do not have, a footer that is no bool, a section hidden that cannot be, a section twice, sections of another type, an empty page.', [
    set('pages.home.extra', node('boolean', true)),
    set('pages.home.footer.$type', 'string'),
    set('pages.home.footer.$value', ''),
    set('pages.home.sections.$value', [ ...$hidden_hero, $home[3] ]),
    set('pages.orders.sections.$type', 'string'),
    set('pages.empty', [ '$description' => 'No footer, no sections.' ]),
]);
$case('pages-v1', "Sections' version 1 paths: one that is visibility's, one that is no `.hidden` path, a `v1_default` that is no bool, one that another section has.", [], null, [
    set('pages.home.login_account.v1', 'footer.hidden'),
    set('pages.home.content', [ 'v1' => 'content' ]),
    set('pages.home.promo_banners', [ 'v1' => 'mobile_view_web.components.home.components.promo_banners.hidden', 'v1_default' => 'yes' ]),
    set('pages.business_listing.filters.v1', 'business_listing_view.components.search.hidden'),
]);
$case('v1', 'The version 1 map: paths that are not strings, an empty list, two controls on one key (in two entries, in one list, a site setting), and a path the schema lacks (left out).', [], null, [
    set([ 'v1', 'tokens.color.primary' ], 5),
    set([ 'v1', 'tokens.color.link' ], []),
    set([ 'v1', 'tokens.color.text' ], 'general.components.fonts.primary.name'),
    set([ 'v1', 'tokens.color.border' ], [ 'a.b', 'a.b' ]),
    set([ 'v1', 'components.nonexistent.base.x' ], 'a.b.c'),
    set('site.name', 'my_products.components.website_theme.components.type'),
]);
$case('out-of-range', "Defaults outside their control's min and max, and nothing else.", [
    set('components.button.base.icon_gap.$value', 100),
    set('components.button.base.speed.$value', 20),
]);
$case('check-and-range', "A default that neither fits its kind nor its control: check()'s error, then outOfRange()'s.", [
    set('components.button.base.icon_gap.$value', -1),
]);
$case('check-and-semver', "check()'s errors and the version's together.", [
    set('tokens.color.primary.$value', 'red'),
    version('26'),
]);

// --- Against the previous version ---------------------------------------------

$case('version-equal', 'The same version as the previous one.', [], [ 'ops' => [] ]);
$case('version-lower', 'A version lower than the previous one of its base.', [ version('26.0.5') ], [ 'ops' => [ version('26.1.0') ] ]);
$case('version-previous-short', 'A previous version of two parts (`26.0` is `26.0.0`): a patch over it.', [ version('26.0.1') ], [ 'ops' => [ version('26.0') ] ]);
$case('version-previous-prerelease', "A previous pre-release: PHP's version_compare() puts 26.1.0 after 26.1.0-beta.", [ version('26.1.0') ], [ 'ops' => [ version('26.1.0-beta') ] ]);
$case('new-base', 'A new base is not compared: it may remove anything; what it adds and deprecates is still reported.', [
    version('27.0.0'),
    del('components.button.base.animated'),
    set('components.button.base.glow', node('color', '#ffffff')),
    set('components.button.base.speed.$deprecated', true),
], [ 'ops' => [] ]);
$case('diff-patch-default', 'A patch that changes a default.', [
    version('26.0.1'),
    set('tokens.color.primary.$value', '#123456'),
], [ 'ops' => [] ]);
$case('diff-patch-texts', 'A patch that changes only texts and editor metadata.', [
    version('26.0.1'),
    set('components.button.base.radius.$description', 'The rounding.'),
    set([ 'components', 'button', 'base', 'icon_gap', '$extensions', 'co.ordering.editor', 'max' ], 64),
    set([ 'components', 'button', '$extensions', 'co.ordering.editor', 'order' ], 99),
    del('components.auth_form.scope.button.$extensions'),
    set([ 'editor', 'sections', 0, 'label', 'default' ], 'Colours'),
    set('$description', 'Web Marketplace 26.'),
], [ 'ops' => [] ]);
$case('diff-minor-add', 'A minor that adds keys and changes a default.', [
    version('26.1.0'),
    set('components.button.base.glow', node('color', '#ffffff')),
    set([ 'visibility', 'header.glow' ], node('boolean', true)),
    set('tokens.color.primary.$value', '#123456'),
], [ 'ops' => [] ]);
$case('diff-removed', 'A minor that removes a key that was not deprecated.', [
    version('26.1.0'),
    del('components.button.base.animated'),
], [ 'ops' => [] ]);
$case('diff-removed-deprecated', 'A minor that removes a deprecated key and a component deprecated as a whole.', [
    version('26.1.0'),
    del('components.button.base.animated'),
    del('components.connection_status'),
], [ 'ops' => [
    set('components.button.base.animated.$deprecated', true),
    set('components.connection_status.$deprecated', 'No longer painted.'),
] ]);
$case('diff-deprecated-zero', 'A key deprecated as the string "0" is not deprecated (PHP\'s empty()): removing it breaks.', [
    version('26.1.0'),
    del('components.button.base.speed'),
], [ 'ops' => [ set('components.button.base.speed.$deprecated', '0') ] ]);
$case('diff-deprecating', 'A minor that deprecates keys: only the newly deprecated are reported.', [
    version('26.1.0'),
    set('components.button.base.speed.$deprecated', 'Use the motion tokens.'),
    set('components.button.base.animated.$deprecated', false),
    set('components.button.base.radius.$deprecated', true),
], [ 'ops' => [ set('components.button.base.radius.$deprecated', true) ] ]);
$case('diff-migrated', 'A minor that moves a key with a migration.', [
    version('26.1.0'),
    set([ '$extensions', 'co.ordering.migrate' ], [ [ 'from' => 'components.button.base.old_gap', 'to' => 'components.button.base.icon_gap' ] ]),
], [ 'ops' => [ set('components.button.base.old_gap', node('dimension', '{space.sm}', [ 'scale' => 'space' ])) ] ]);
$case('diff-type-changed', 'A minor that changes the type of a key.', [
    version('26.1.0'),
    set('site.business_slug.$type', 'html'),
], [ 'ops' => [] ]);
$case('diff-scale-changed', 'A minor that moves dimensions to another scale: the levels stored themes name are of the old one.', [
    version('26.1.0'),
    set('components.card.base.padding.$value', '{size.lg}'),
    set([ 'components', 'card', 'base', 'padding', '$extensions', 'co.ordering', 'scale' ], 'size'),
    set('components.button.base.icon_gap.$value', '{icon.sm}'),
    set([ 'components', 'button', 'base', 'icon_gap', '$extensions', 'co.ordering', 'scale' ], 'icon'),
], [ 'ops' => [] ]);
$case('diff-option-removed', 'A minor that removes an option (and adds another).', [
    version('26.1.0'),
    set([ 'site', 'mode', '$extensions', 'co.ordering', 'options' ], [ 'marketplace', 'single_store', 'kiosk' ]),
], [ 'ops' => [] ]);
$case('diff-migration-removed', 'A minor that drops a migration of its base.', [
    version('26.1.0'),
], [ 'ops' => [ set([ '$extensions', 'co.ordering.migrate' ], [ [ 'from' => 'components.button.base.old_gap', 'to' => 'components.button.base.icon_gap' ] ]) ] ]);
$case('diff-errors', "Every incompatibility at once, in SchemaDiff's order: each key's, the migrations', and a patch's values.", [
    version('26.0.1'),
    del('components.button.base.animated'),
    set('site.business_slug.$type', 'html'),
    set([ 'site', 'mode', '$extensions', 'co.ordering', 'options' ], [ 'marketplace', 'single_store' ]),
], [ 'ops' => [ set([ '$extensions', 'co.ordering.migrate' ], [ [ 'from' => 'components.button.base.old_gap', 'to' => 'components.button.base.icon_gap' ] ]) ] ]);
$case('diff-valid-changes', 'A valid minor with every kind of change: added, removed (deprecated), migrated and deprecated.', [
    version('26.2.0'),
    set('components.button.base.glow', node('color', '#ffffff')),
    del('components.button.base.animated'),
    set([ '$extensions', 'co.ordering.migrate' ], [ [ 'from' => 'components.button.base.old_gap', 'to' => 'components.button.base.icon_gap' ] ]),
    set('components.button.base.speed.$deprecated', true),
], [ 'ops' => [
    version('26.1.0'),
    set('components.button.base.animated.$deprecated', true),
    set('components.button.base.old_gap', node('dimension', '{space.sm}', [ 'scale' => 'space' ])),
] ]);

// --- In development ---------------------------------------------------------
// The same cases with development = true: the version's order and the diff's
// incompatibilities refuse nothing, the changes are still answered, and the
// schema alone is checked as always.

$development = [
    'version-equal' => 'In development, the same version as the previous one is no refusal: its changes are answered.',
    'version-lower' => 'In development, a version lower than the previous one is no refusal.',
    'diff-removed' => 'In development, removing a key that was not deprecated is no refusal: it is reported as removed.',
    'diff-errors' => 'In development, every incompatibility at once is no refusal: the changes are answered.',
    'diff-valid-changes' => 'In development, a valid minor answers the same changes.',
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
$base_ops = json_decode(json_encode($base, $flags), true, 512, JSON_THROW_ON_ERROR);
$start = fn (string $from) => $from === 'original' ? $original : apply($original, $base_ops);

$meta_failures = [];
foreach ($cases as $index => $entry) {
    $schema = apply($start($entry['schema']['from']), $entry['schema']['ops']);
    $previous = isset($entry['previous']) ? apply($start($entry['previous']['from']), $entry['previous']['ops']) : null;
    $v1_map = isset($entry['v1Map']) ? apply($map, $entry['v1Map']['ops']) : [];
    if (NodeCompiler::isNodes($schema) && ($errors = ThemeSchemas::meta($schema)) !== []) {
        $meta_failures[$entry['name']] = $errors;
    }
    $cases[$index]['expected'] = ThemeSchemaPublisher::validate($schema, $v1_map, $previous, $entry['development'] ?? false);
}
if ($meta_failures !== []) {
    fwrite(STDERR, "These cases do not pass the meta-schema, which the package does not check:\n" . json_encode($meta_failures, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    exit(1);
}

echo json_encode([
    'description' => "What api-core's ThemeSchemaPublisher::validate() answers for each mutation of {$original['product_type']}@{$original['version']}.",
    'php' => PHP_VERSION,
    'base' => $base_ops,
    'cases' => $cases,
], JSON_PRETTY_PRINT | $flags) . "\n";
