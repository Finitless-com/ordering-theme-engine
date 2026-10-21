<?php

/*
 * The golden cases of validate(): named mutations of the product's current
 * schema, each with what ThemeSchemaPublisher::validate() answers for it,
 * as JSON on stdout. scripts/sync-fixtures.sh runs it in PHP 8.3:
 *
 *   php scripts/validate-cases.php fixtures/schema-26.0.nodes.json fixtures/v1-map.json
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

use Ordering\ThemeEngine\Schema\EditorRules;
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

/**
 * The operations that make right what a schema gets wrong of its editor
 * metadata (EditorRules), so that the base is valid: the colors it may make
 * translucent, the typographies offered custom, the fields of its line
 * heights and the blocks of its pages are declared; a number control
 * without limits loses its control, a component whose props have no order
 * loses its cards (and its props the card and order they name), and a
 * group leaves out a path another group already lists or that breaks its
 * rows. Each
 * comes from an error the schema still has, so a schema that has none gets
 * none; an error none of them makes right fails the script.
 *
 * @param array<string, mixed> $schema
 * @return array<int, array>
 */
function editor_fixes(array $schema): array
{
    $editor = fn (array $segments) => [ ...$segments, '$extensions', 'co.ordering.editor' ];
    $ops = [];
    for ($round = 0; $round < 5; $round++) {
        $added = [];
        $add = function (array $op) use (&$added) {
            $added[json_encode($op)] = $op;
        };
        foreach (EditorRules::check($schema) as [ 'path' => $path, 'code' => $code ]) {
            $segments = explode('.', $path);
            switch (substr($code, strlen($path) + 1)) {
                case 'alpha_required':
                    $add(set([ ...$editor($segments), 'alpha' ], true));
                    break;
                case 'custom_required':
                    $add(set([ ...$editor($segments), 'custom' ], true));
                    break;
                case 'limits_required':
                    $add(del([ ...$editor($segments), 'control' ]));
                    break;
                case 'unit_not_x':
                case 'limits_not_ratio':
                case 'default_out_of_range':
                    $node = array_slice($segments, 0, -2);
                    $field = $schema;
                    foreach ([ ...$node, '$extensions', 'co.ordering.editor', 'fields', 'line' ] as $segment) {
                        $field = $field[$segment] ?? [];
                    }
                    $add(set([ ...$editor($node), 'fields', 'line' ], [ 'unit' => 'x', 'min' => 0.8, 'max' => 3, 'step' => 0.05 ] + $field));
                    break;
                case 'section_groups_required':
                    $used = [];
                    foreach ($schema['pages'][$segments[1]]['sections']['$value'] as $section) {
                        if ($section['group'] !== null && !in_array($section['group'], $used, true)) {
                            $used[] = $section['group'];
                        }
                    }
                    $add(set([ ...$editor($segments), 'section_groups' ], array_map(fn (string $group) => [
                        'key' => $group,
                        'label' => [ 'key' => 'BUILDER_THEME_SECTION_GROUP_' . strtoupper($group), 'default' => ucfirst(str_replace('_', ' ', $group)) ],
                    ], $used)));
                    break;
                case 'order_missing':
                    $component = $segments[1];
                    $add(del([ 'components', $component, '$extensions', 'co.ordering.editor', 'groups' ]));
                    foreach ($schema['components'][$component]['base'] ?? [] as $key => $node) {
                        $own = $node['$extensions']['co.ordering.editor'] ?? [];
                        if (isset($own['group'])) {
                            unset($own['group'], $own['order']);
                            $add(set([ 'components', $component, 'base', (string) $key, '$extensions', 'co.ordering.editor' ], $own));
                        }
                    }
                    break;
                case 'in_several_editor_groups':
                    // It stays in the first group that lists it as a row (its order is that row's), else in the first switch.
                    $listings = [];
                    foreach ($schema['editor']['sections'] as $s => $section) {
                        foreach ($section['groups'] ?? [] as $g => $group) {
                            if (in_array($path, $group['paths'] ?? [], true)) {
                                $listings[] = [ $s, $g, 'paths', $group['paths'] ];
                            }
                            if (($group['toggle']['path'] ?? null) === $path) {
                                $listings[] = [ $s, $g, 'toggle', null ];
                            }
                        }
                    }
                    $rows = array_values(array_filter($listings, fn (array $listing) => $listing[2] === 'paths'));
                    $kept = $rows[0] ?? $listings[0];
                    foreach ($listings as $listing) {
                        [ $s, $g, $where, $paths ] = $listing;
                        if ($listing !== $kept) {
                            $add($where === 'toggle'
                                ? del([ 'editor', 'sections', $s, 'groups', $g, 'toggle' ])
                                : set([ 'editor', 'sections', $s, 'groups', $g, 'paths' ], array_values(array_diff($paths, [ $path ]))));
                        }
                    }
                    break;
                case 'order_not_consecutive':
                    $kept = [];
                    $last = 0;
                    foreach ($schema['editor']['sections'][$segments[2]]['groups'][$segments[4]]['paths'] as $item) {
                        $node = $schema;
                        foreach (explode('.', $item) as $segment) {
                            $node = $node[$segment] ?? null;
                        }
                        $order = $node['$extensions']['co.ordering.editor']['order'] ?? null;
                        if (is_int($order) && $order !== $last && $order !== $last + 1) {
                            continue;
                        }
                        $kept[] = $item;
                        $last = is_int($order) ? max($last, $order) : $last;
                    }
                    $add(set([ 'editor', 'sections', $segments[2], 'groups', $segments[4], 'paths' ], $kept));
                    break;
                case 'group_mismatch':
                    // A path out of its row says the group of the row it is in: leaving it out of the group makes both right.
                    break;
                default:
                    fwrite(STDERR, "The base schema has an editor error no fix makes right: {$code}\n");
                    exit(1);
            }
        }
        if ($added === []) {
            break;
        }
        $schema = apply($schema, array_values($added));
        $ops = [ ...$ops, ...array_values($added) ];
    }
    if (($left = EditorRules::check($schema)) !== []) {
        fwrite(STDERR, "The base schema keeps editor errors: " . json_encode(array_column($left, 'code')) . "\n");
        exit(1);
    }

    return $ops;
}

// The base every case starts from unless it says `original`: the product's
// schema with a semver version, its two defaults within their control and
// its editor metadata made right (editor_fixes()).
$base = [
    version('26.0.0'),
    set('components.signup_page.base.padding_y.$value', 96),
    set('components.signup_page.base.padding_x.$value', 96),
];
$base = [ ...$base, ...editor_fixes(apply($original, $base)) ];

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
$pattern = fn (string $key, string $pattern, ?string $default = null) => [
    set([ 'site', ...explode('.', $key), '$extensions', 'co.ordering' ], [ 'pattern' => $pattern ]),
    ...($default === null ? [] : [ set([ 'site', ...explode('.', $key), '$value' ], $default) ]),
];
$case('rules-patterns', 'Patterns of the subset PCRE and ECMAScript read alike, each with a default it takes: valid.', [
    ...$pattern('smart_banner.apple_id', '^\\d{6,12}$', '123456'),
    ...$pattern('smart_banner.android_id', '^[a-zA-Z][\\w]*(\\.[a-zA-Z][\\w]*)+$', 'com.ordering.app'),
    ...$pattern('business_slug', '^(?:[a-z0-9]+-)*[a-z0-9]+$', 'my-shop'),
    ...$pattern('name', '^(?!-)[^<>\\n]{1,60}?$', 'Acme'),
    ...$pattern('description', '^(?:https?://)?[\\w.\\-/]+|[{}()|$^*+?.]\\.{2,}\\[\\]$', 'https://ordering.co/a-b'),
]);
$case('rules-patterns-escapes', 'Escapes outside the subset: whitespace, a PCRE anchor, a property, a character code and a backreference.', [
    ...$pattern('smart_banner.apple_id', '^\\s+$'),
    ...$pattern('smart_banner.android_id', '\\Aabc'),
    ...$pattern('business_slug', '^\\p{L}+$'),
    ...$pattern('name', '\\x41'),
    ...$pattern('description', '(a)\\1'),
]);
$case('rules-patterns-groups', 'Groups outside the subset: a lookbehind, a named group, an inline flag, an atomic group and an unclosed one.', [
    ...$pattern('smart_banner.apple_id', '(?<=a)b'),
    ...$pattern('smart_banner.android_id', '(?<id>a)'),
    ...$pattern('business_slug', '(?i)abc'),
    ...$pattern('name', '(?>a+)b'),
    ...$pattern('description', '(ab'),
]);
$case('rules-patterns-quantifiers', 'Quantifiers outside the subset: possessive, with nothing to repeat, out of order, a brace that is no quantifier and over a lookahead.', [
    ...$pattern('smart_banner.apple_id', '^\\d++$'),
    ...$pattern('smart_banner.android_id', '*a'),
    ...$pattern('business_slug', 'a{3,2}'),
    ...$pattern('name', 'a{,3}'),
    ...$pattern('description', '(?=a)*b'),
]);
$case('rules-patterns-classes', 'Classes outside the subset: empty, empty and negated, a POSIX class, a word boundary inside and a `-` in the middle.', [
    ...$pattern('smart_banner.apple_id', 'a[]'),
    ...$pattern('smart_banner.android_id', '[^]'),
    ...$pattern('business_slug', '[[:alpha:]]'),
    ...$pattern('name', '[\\b]'),
    ...$pattern('description', '[a-z-0]'),
]);
$case('rules-patterns-literals', 'Literals and escapes outside the subset: a lone `]` and `}`, `\\-` outside a class, a word boundary and a control character.', [
    ...$pattern('smart_banner.apple_id', 'a]'),
    ...$pattern('smart_banner.android_id', 'a}'),
    ...$pattern('business_slug', 'a\\-b'),
    ...$pattern('name', '\\bword\\b'),
    ...$pattern('description', "a\tb"),
]);
$case('rules-patterns-values', 'Defaults a pattern of the subset refuses as ECMAScript does: before a final newline `$` does not match, `\\d` is ASCII and `.` matches no line break.', [
    ...$pattern('smart_banner.apple_id', '^\\d{6,12}$', "123456\n"),
    ...$pattern('smart_banner.android_id', '^\\d{6,12}$', '٣٣٣٣٣٣'),
    ...$pattern('name', '^.$', "\r"),
    ...$pattern('description', '^x.$', "x\u{2028}"),
    ...$pattern('business_slug', '^\\w+$', 'ñandú'),
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
        [ 'from' => 'visibility.header.brand', 'to' => 'visibility.header.logo' ],
        [ 'from' => 'images.brand_logo', 'to' => 'images.logo' ],
        [ 'from' => 'pages.shop', 'to' => 'pages.business_listing' ],
        [ 'from' => 'pages.shop.footer', 'to' => 'pages.home.footer' ],
        [ 'from' => 'pages.shop.sections', 'to' => 'pages.business_listing.sections' ],
        [ 'from' => 'pages.home.sections.banner', 'to' => 'pages.home.sections.promo_banners' ],
        [ 'from' => 'pages.shop.sections.hero', 'to' => 'pages.home.sections.hero' ],
    ]),
]);
$case('migrate-sections-invalid', "Migrations of visibility, images and pages it rejects: from a key the schema has, to an image it lacks, a visibility key into a page's section, a footer into a section, a section into a page, a page field it has not, a page's sections or a section the schema still has, and a path deeper than a section.", [
    set([ '$extensions', 'co.ordering.migrate' ], [
        [ 'from' => 'visibility.footer', 'to' => 'visibility.pwa_prompt' ],
        [ 'from' => 'images.brand_logo', 'to' => 'images.brand' ],
        [ 'from' => 'visibility.home.banner', 'to' => 'pages.home.sections.promo_banners' ],
        [ 'from' => 'pages.shop.footer', 'to' => 'pages.home.sections.hero' ],
        [ 'from' => 'pages.shop.sections.hero', 'to' => 'pages.home' ],
        [ 'from' => 'pages.shop.extra', 'to' => 'pages.home.footer' ],
        [ 'from' => 'pages.home.sections', 'to' => 'pages.business_listing.sections' ],
        [ 'from' => 'pages.home.sections.hero', 'to' => 'pages.business_listing.sections.hero' ],
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
        [ 'from' => 'tokens.typography.small', 'to' => 'tokens.typography.caption' ],
        [ 'from' => 'tokens.opacity.dim', 'to' => 'tokens.opacity.scrim' ],
        [ 'from' => 'tokens.color', 'to' => 'tokens.color.primary' ],
        [ 'from' => 'components.button.base', 'to' => 'components.button.base.radius' ],
        [ 'from' => 'components.card.variants.default', 'to' => 'components.card.base.radius' ],
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
$case('v1-own-colors', "The version 1 map names a catalog color the schema does not name: it is left out, so its key does not collide with the primary's.", [], null, [
    set([ 'v1', 'tokens.color.danger_text' ], 'my_products.components.theme_settings.components.style.primary_btn_color'),
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

// --- The editor's metadata (EditorRules) ------------------------------------
// The groups by index: 0 colors, 4 sizes (0 control heights), 8 site, 10
// visibility (2 header, 6 bottom_nav, 7 home, 9 business_card); a group
// added to the visibility section goes after its last. bottom_nav is the
// component whose cards the base keeps.

$group = fn (int $section, int $index) => $original['editor']['sections'][$section]['groups'][$index];
$cards = $original['components']['bottom_nav']['$extensions']['co.ordering.editor']['groups'];
$editor = fn (string|array $path, string $key) => [ ...path($path), '$extensions', 'co.ordering.editor', $key ];
$fonts = $original['tokens']['font']['heading']['$extensions']['co.ordering']['options'];
$label = fn (string $key, string $text) => [ 'key' => $key, 'default' => $text ];

$case('editor-valid', 'Editor metadata the rules take: a visibility group shown in a page and one in a component, an ascending scale, a font control listing every font with its weights, a translucent color marked alpha, a typography group as wide as its levels and a text control with its placeholder.', [
    set([ 'editor', 'sections', 10, 'groups', 7, 'pages' ], [ 'home' ]),
    set([ 'editor', 'sections', 10, 'groups', 9, 'components' ], [ 'business_card' ]),
    set([ 'editor', 'sections', 4, 'groups', 0, 'ordered' ], 'ascending'),
    set($editor('tokens.font.heading', 'options'), array_map(fn (string $font) => [ 'value' => $font, 'weights' => [ 400, 700 ] ], $fonts)),
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
    set([ 'editor', 'sections', 10, 'groups', 7, 'pages' ], [ 'home', 'nowhere' ]),
    set([ 'editor', 'sections', 10, 'groups', count($original['editor']['sections'][10]['groups']) ], [ 'key' => 'home', 'pages' => [ 'home' ] ]),
    set([ 'visibility', 'header.glow' ], node('boolean', true)),
    set([ 'editor', 'sections', 8, 'groups', 0, 'toggle' ], [ 'path' => 'visibility.footer' ]),
    set([ 'editor', 'sections', 10, 'groups', 7, 'paths' ], [ ...$group(10, 7)['paths'], 'visibility.nope' ]),
]);
$case('editor-components', 'Components on a group of another section, pages and components on one group, a component the schema lacks, two groups of one key in a component, and a card that lists what a visibility group already shows in its component.', [
    set([ 'editor', 'sections', 0, 'groups', 1, 'components' ], [ 'button' ]),
    set([ 'editor', 'sections', 10, 'groups', 9, 'pages' ], [ 'home' ]),
    set([ 'editor', 'sections', 10, 'groups', 9, 'components' ], [ 'business_card', 'nope' ]),
    set([ 'editor', 'sections', 10, 'groups', 6, 'components' ], [ 'bottom_nav' ]),
    set([ 'editor', 'sections', 10, 'groups', count($original['editor']['sections'][10]['groups']) ], [ 'key' => 'bottom_nav', 'components' => [ 'bottom_nav' ] ]),
]);
$case('editor-card-order', "A component's props and cards out of order: a prop without order, two of one order in a card, a path the schema lacks, a card listing a prop before one of a lower order, a prop in a card it does not name, and one naming a card that does not list it.", [
    del($editor('components.bottom_nav.base.height', 'order')),
    set($editor('components.bottom_nav.base.active', 'order'), 3),
    set([ 'components', 'bottom_nav', '$extensions', 'co.ordering.editor', 'groups', 0, 'paths' ], [ ...$cards[0]['paths'], 'components.bottom_nav.base.nope' ]),
    set([ 'components', 'bottom_nav', '$extensions', 'co.ordering.editor', 'groups', 1, 'paths' ], array_reverse($cards[1]['paths'])),
    set($editor('components.bottom_nav.base.shadow', 'group'), 'padding'),
    set('components.bottom_nav.base.glow', node('color', '#ffffff', [], [ 'group' => 'bar', 'order' => 5 ])),
]);
$case('editor-rows', "A group of the editor whose rows go back to order 4 first, and a token listed by a group it does not name.", [
    set([ 'editor', 'sections', 4, 'groups', 0, 'paths' ], array_reverse($group(4, 0)['paths'])),
    set($editor('tokens.size.sm', 'group'), 'icon_sizes'),
]);
$case('editor-custom', 'custom on a token, on a group, and false on a typography chosen by level in a component; on a property of another type it is check()\'s rules.invalid alone.', [
    set($editor('tokens.typography.h1', 'custom'), true),
    set($editor('components.button.base', 'custom'), true),
    set($editor('components.link.sizes.sm.text', 'custom'), false),
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
    set([ 'tokens', 'typography', 'h3', '$extensions', 'co.ordering.editor', 'fields', 'line', 'max' ], 120),
    set($editor('tokens.typography', 'fields'), [ 'size' => [ 'min' => 10, 'max' => 60 ] ]),
]);
$case('editor-ordered', 'Scales out of order: control heights said descending, and cards that list a color and a group.', [
    set([ 'editor', 'sections', 4, 'groups', 0, 'ordered' ], 'descending'),
    set([ 'components', 'bottom_nav', '$extensions', 'co.ordering.editor', 'groups', 0, 'ordered' ], 'ascending'),
    set([ 'components', 'bottom_nav', '$extensions', 'co.ordering.editor', 'groups', 1, 'ordered' ], 'ascending'),
    set([ 'components', 'bottom_nav', '$extensions', 'co.ordering.editor', 'groups', 1, 'paths' ], [ ...$cards[1]['paths'], 'components.bottom_nav.base' ]),
]);
$case('editor-section-groups', "The blocks of the pages: on a footer, missing on a page whose sections move in blocks, repeated, and in another order than the sections'.", [
    set($editor('pages.home.footer', 'section_groups'), [ [ 'key' => 'main', 'label' => $label('BUILDER_THEME_SECTION_GROUP_MAIN', 'Main') ] ]),
    del($editor('pages.orders.sections', 'section_groups')),
    set($editor('pages.home.sections', 'section_groups'), [
        [ 'key' => 'main', 'label' => $label('BUILDER_THEME_SECTION_GROUP_MAIN', 'Main') ],
        [ 'key' => 'main', 'label' => $label('BUILDER_THEME_SECTION_GROUP_MAIN', 'Main') ],
    ]),
    set($editor('pages.checkout.sections', 'section_groups'), [
        [ 'key' => 'side', 'label' => $label('BUILDER_THEME_SECTION_GROUP_SIDE', 'Side') ],
        [ 'key' => 'main', 'label' => $label('BUILDER_THEME_SECTION_GROUP_MAIN', 'Main') ],
    ]),
]);
$case('editor-fonts', 'Weights on a select, and a font control that lists a font twice, one that is not its own, leaves out the rest, and gives weights that go down.', [
    set($editor('tokens.font.body', 'control'), 'select'),
    set($editor('tokens.font.body', 'options'), [ [ 'value' => 'archivo', 'weights' => [ 400 ] ] ]),
    set($editor('tokens.font.heading', 'options'), [ [ 'value' => 'archivo', 'weights' => [ 700, 400 ] ], [ 'value' => 'archivo' ], [ 'value' => 'comic_sans' ] ]),
]);
$case('editor-texts', "Rules of a string on a token and on a slot, a placeholder and multiline on controls that are no text; on another property they are check()'s rules.invalid.", [
    set([ 'tokens', 'color', 'primary', '$extensions', 'co.ordering', 'max_length' ], 10),
    set([ 'components', 'banner', 'slots', 'action', '$extensions', 'co.ordering', 'pattern' ], '^x$'),
    set($editor('components.button.base.radius', 'placeholder'), $label('BUILDER_THEME_EXAMPLE', 'Example')),
    set($editor('components.button.base.icon_gap', 'multiline'), true),
]);
$home_sections = $original['pages']['home']['sections']['$value'];
$home_sections[2]['group'] = 'aside';
$home_sections[4]['key'] = '_promo_banners';
$case('editor-page-sections', "A page's sections: one alone in its group, and a key that does not start with a letter.", [
    set('pages.home.sections.$value', $home_sections),
    set($editor('pages.home.sections', 'section_groups'), [
        [ 'key' => 'aside', 'label' => $label('BUILDER_THEME_SECTION_GROUP_ASIDE', 'Aside') ],
        [ 'key' => 'main', 'label' => $label('BUILDER_THEME_SECTION_GROUP_MAIN', 'Main') ],
    ]),
]);
$case('editor-visibility-type', 'A visibility key typed string: its default is still a bool, which check() asks.', [
    set([ 'visibility', 'footer', '$type' ], 'string'),
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
    set([ 'components', 'button', '$extensions', 'co.ordering.editor', 'advanced' ], true),
    del('components.auth_form.scope.button.$extensions'),
    set([ 'editor', 'sections', 0, 'label', 'default' ], 'Colours'),
    set('$description', 'Web Marketplace 26.'),
], [ 'ops' => [] ]);
$case('diff-minor-add', 'A minor that adds keys (the visibility key in a group of the editor) and changes a default.', [
    version('26.1.0'),
    set('components.button.base.glow', node('color', '#ffffff')),
    set([ 'visibility', 'header.glow' ], node('boolean', true)),
    set([ 'editor', 'sections', 10, 'groups', 2, 'paths' ], [ ...$group(10, 2)['paths'], 'visibility.header.glow' ]),
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
$case('diff-migrated-sections', "A minor that moves a visibility key, an image and a whole page with migrations: the page's footer and sections go with it.", [
    version('26.1.0'),
    set([ '$extensions', 'co.ordering.migrate' ], [
        [ 'from' => 'visibility.header.brand', 'to' => 'visibility.header.logo' ],
        [ 'from' => 'images.brand_logo', 'to' => 'images.logo' ],
        [ 'from' => 'pages.shop', 'to' => 'pages.business_listing' ],
    ]),
], [ 'ops' => [
    set([ 'visibility', 'header.brand' ], node(null, true)),
    set([ 'images', 'brand_logo' ], node(null, '')),
    set('pages.shop', $original['pages']['multi_cart']),
] ]);
$case('diff-page-partly-migrated', "A minor that drops a page but moves only its footer: its sections are removed.", [
    version('26.1.0'),
    set([ '$extensions', 'co.ordering.migrate' ], [ [ 'from' => 'pages.shop.footer', 'to' => 'pages.home.footer' ] ]),
], [ 'ops' => [ set('pages.shop', $original['pages']['multi_cart']) ] ]);
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
    fwrite(STDERR, "These cases do not pass the meta-schema, which the TypeScript validate() does not check:\n" . json_encode($meta_failures, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    exit(1);
}

echo json_encode([
    'description' => "What api-core's ThemeSchemaPublisher::validate() answers for each mutation of {$original['product_type']}@{$original['version']}.",
    'php' => PHP_VERSION,
    'base' => $base_ops,
    'cases' => $cases,
], JSON_PRETTY_PRINT | $flags) . "\n";
