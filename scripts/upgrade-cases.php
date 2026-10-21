<?php

/*
 * The golden cases of a theme's way from one base to the next
 * (ThemeUpgrades): three bases made from the product's current schema (26,
 * 27 and 28, each a list of operations over it, as in validate-cases.php),
 * and for each case what ThemeUpgrades answers: a theme or a site's
 * settings through the steps between two or three of them (chain(),
 * settingsChain()), or cut down to an older one (cutDown(),
 * cutDownSettings()). JSON on stdout; scripts/sync-fixtures.sh runs it in
 * PHP 8.3:
 *
 *   php scripts/upgrade-cases.php fixtures/schema-26.0.nodes.json
 *
 * A case with `rewrite: true` passes the rewrite every test runs (the
 * TypeScript test has its own copy): for `test.bolder`, a typography style
 * `small` the theme sets becomes its `caption` unless it sets that one,
 * and every style's weight goes one step up. Without it the rewrite is
 * skipped. The bases are compiled with NodeCompiler, their errors aside:
 * the steps read the tuples whatever the editor would say of them.
 */

declare(strict_types=1);

use Ordering\ThemeEngine\Schema\NodeCompiler;
use Ordering\ThemeEngine\ThemeUpgrades;

require dirname(__DIR__) . '/vendor/autoload.php';

set_error_handler(function (int $severity, string $message, string $file, int $line) {
    throw new \ErrorException($message, 0, $severity, $file, $line);
});

[ , $schema_path ] = $argv + [ null, null ];
if (!is_string($schema_path) || !is_file($schema_path)) {
    fwrite(STDERR, "Usage: php scripts/upgrade-cases.php <schema in nodes>\n");
    exit(1);
}
$original = json_decode((string) file_get_contents($schema_path), true, 512, JSON_THROW_ON_ERROR);

$set = fn (string|array $path, mixed $value) => [ 'set', is_array($path) ? $path : explode('.', $path), $value ];
$del = fn (string|array $path) => [ 'delete', is_array($path) ? $path : explode('.', $path) ];
$node = function (?string $type, mixed $value, array $engine = []) {
    $node = $type === null ? [] : [ '$type' => $type ];
    $node['$value'] = $value;
    if ($engine !== []) {
        $node['$extensions'] = [ 'co.ordering' => $engine ];
    }

    return $node;
};
$apply = function (array $document, array $ops) {
    foreach ($ops as $op) {
        $segments = $op[1];
        $last = array_pop($segments);
        $at = &$document;
        foreach ($segments as $segment) {
            if (!is_array($at[$segment] ?? null)) {
                $at[$segment] = [];
            }
            $at = &$at[$segment];
        }
        if ($op[0] === 'set') {
            $at[$last] = $op[2];
        } else {
            unset($at[$last]);
        }
        unset($at);
    }

    return $document;
};

// 26.4: what 27 takes otherwise (a property, a color, a visibility key, a
// typography style, a page and a setting named otherwise; a property whose
// scale, and one whose type, 27 changes; an option 27 renames; a boolean 27
// turns around and one it turns into an option; options 27 merges; keys 27
// drops), and a migration of its own base.
// 26.5: a later minor of 26 that renames, by migrations, a property, a
// color, a visibility key, a home section and a setting.
// 27.0: the product's schema, with its block from 26 (it leaves the
// changed type of the modal's padding to the step that drops what changed
// kind). 28.0: renames the button's icon gap.
$base_26 = [
    $set('version', '26.4.0'),
    $set('components.button.base.old_gap', $node('dimension', '{space.sm}', [ 'scale' => 'space' ])),
    $set([ 'components', 'link', 'base', 'underline', '$extensions', 'co.ordering', 'options' ], [ 'none', 'hover', 'underlined' ]),
    $set('components.card.base.padding', $node('dimension', '{size.lg}', [ 'scale' => 'size' ])),
    $set('components.modal.base.padding', $node('dimension', '{space.lg}', [ 'scale' => 'space' ])),
    $set('tokens.color.brand', [ '$value' => '#E4002B' ]),
    $set('tokens.typography.small', [ '$value' => [ 'size' => 11, 'line' => 1.4, 'weight' => 400 ] ]),
    $set([ 'visibility', 'header.brand' ], $node(null, true)),
    $set([ 'visibility', 'old_banner' ], $node(null, false)),
    $set('pages.shop', $original['pages']['multi_cart']),
    $set('site.slug', $node('string', '')),
    $set('site.legacy_flag', $node('boolean', false)),
    $set('site.kiosk_only', $node('boolean', false)),
    $set([ 'visibility', 'hide_prices' ], $node(null, false)),
    $set('components.card.base.tone', $node('option', 'soft', [ 'options' => [ 'soft', 'loud', 'neutral' ] ])),
    $del('site.description'),
    $set([ '$extensions', 'co.ordering.migrate' ], [ [ 'from' => 'components.card.base.corner', 'to' => 'components.card.base.radius' ] ]),
];
$home = $original['pages']['home']['sections']['$value'];
$schemas = [
    '26' => $base_26,
    '26.5' => [
        ...$base_26,
        $set('version', '26.5.0'),
        $del('components.button.base.old_gap'),
        $set('components.button.base.spacer', $node('dimension', '{space.sm}', [ 'scale' => 'space' ])),
        $del('tokens.color.favorite'),
        $set('tokens.color.danger_text', [ '$value' => '#B02A3E' ]),
        $del([ 'visibility', 'header.brand' ]),
        $set([ 'visibility', 'header.badge' ], $node(null, true)),
        $set('pages.home.sections.$value', array_map(fn (array $section) => $section['key'] === 'content' ? [ 'key' => 'main_content' ] + $section : $section, $home)),
        $del('site.slug'),
        $set('site.shop_slug', $node('string', '')),
        $set([ '$extensions', 'co.ordering.migrate' ], [
            [ 'from' => 'components.card.base.corner', 'to' => 'components.card.base.radius' ],
            [ 'from' => 'components.button.base.old_gap', 'to' => 'components.button.base.spacer' ],
            [ 'from' => 'tokens.color.favorite', 'to' => 'tokens.color.danger_text' ],
            [ 'from' => 'visibility.header.brand', 'to' => 'visibility.header.badge' ],
            [ 'from' => 'pages.home.sections.content', 'to' => 'pages.home.sections.main_content' ],
            [ 'from' => 'site.slug', 'to' => 'site.shop_slug' ],
        ]),
    ],
    '27' => [
        $set('version', '27.0.0'),
        $set([ '$extensions', 'co.ordering.upgrade' ], [
            'from' => 26,
            'moves' => [
                [ 'from' => 'components.button.base.old_gap', 'to' => 'components.button.base.icon_gap' ],
                [ 'from' => 'components.link.base.underline', 'to' => 'components.link.base.underline', 'map' => [ 'none' => 'none', 'hover' => 'hover', 'underlined' => 'always' ] ],
                [ 'from' => 'components.card.base.padding', 'to' => 'components.card.base.padding', 'map' => [ '{size.lg}' => '{space.lg}', '{size.md}' => '{space.md}' ] ],
                [ 'from' => 'tokens.color.brand', 'to' => 'tokens.color.primary' ],
                [ 'from' => 'visibility.header.brand', 'to' => 'visibility.header.logo' ],
                [ 'from' => 'pages.shop', 'to' => 'pages.multi_cart' ],
                [ 'from' => 'site.slug', 'to' => 'site.business_slug' ],
                [ 'from' => 'visibility.hide_prices', 'to' => 'visibility.show_prices', 'map' => [ 'true' => false, 'false' => true ] ],
                [ 'from' => 'site.kiosk_only', 'to' => 'site.kiosk_mode', 'map' => [ 'true' => 'on', 'false' => 'off' ] ],
                [ 'from' => 'components.card.base.tone', 'to' => 'components.card.base.tone', 'map' => [ 'soft' => 'soft', 'loud' => 'loud', 'neutral' => 'soft' ] ],
            ],
            'drop' => [ 'visibility.old_banner', 'site.legacy_flag' ],
            'rewrite' => [ 'id' => 'test.bolder', 'paths' => [ 'tokens.typography.small' ] ],
        ]),
        $set([ 'visibility', 'show_prices' ], $node(null, true)),
        $set('site.kiosk_mode', $node('option', 'off', [ 'options' => [ 'on', 'off' ] ])),
        $set('components.card.base.tone', $node('option', 'soft', [ 'options' => [ 'soft', 'loud' ] ])),
    ],
    '28' => [
        $set('version', '28.0.0'),
        $del('components.button.base.icon_gap'),
        $set('components.button.base.icon_space', $node('dimension', '{space.sm}', [ 'scale' => 'space' ])),
        $set([ '$extensions', 'co.ordering.upgrade' ], [
            'from' => 27,
            'moves' => [ [ 'from' => 'components.button.base.icon_gap', 'to' => 'components.button.base.icon_space' ] ],
            'drop' => [],
        ]),
    ],
];
$compiled = array_map(fn (array $ops) => NodeCompiler::compile($apply($original, json_decode(json_encode($ops), true)))['schema'], $schemas);

$rewrite = function (string $id, array $values, array $previous, array $next): array {
    if ($id !== 'test.bolder' || ($next['upgrade']['rewrite']['id'] ?? null) !== $id) {
        throw new \LogicException("Unexpected rewrite {$id}.");
    }
    $styles = $values['tokens']['typography'] ?? null;
    if (!is_array($styles)) {
        return $values;
    }
    if (is_array($styles['small'] ?? null)) {
        $styles['caption'] ??= $styles['small'];
        unset($styles['small']);
    }
    foreach ($styles as $style => $typography) {
        if (is_int($typography['weight'] ?? null) && $typography['weight'] < 900) {
            $styles[$style]['weight'] += 100;
        }
    }
    $values['tokens']['typography'] = $styles;

    return $values;
};

$cases = [
    [ 'upgrade', 'identity', 'What keeps its path and kind keeps its value: a color, a component property, a footer, a platform\'s override, a visibility key, an image.', [ '26', '27' ], false, <<<'JSON'
        {
            "tokens": { "color": { "primary": "#2C7BE5" } },
            "components": { "card": { "base": { "background": "{color.surface}" } } },
            "pages": { "home": { "footer": false } },
            "visibility": { "footer": false },
            "images": { "logo": "https://example.com/logo.png" },
            "platforms": { "mobile_web": { "components": { "modal": { "base": { "radius": "{radius.xl}" } } } } }
        }
        JSON ],
    [ 'upgrade', 'moves', "Moves: a property (and a platform's override of it), a visibility key (and a platform's), a whole page and a key the previous base's own migration moved first (the card's `corner`, its radius since 26.4).", [ '26', '27' ], false, <<<'JSON'
        {
            "components": { "button": { "base": { "old_gap": "{space.md}" } }, "card": { "base": { "corner": "{radius.xl}" } } },
            "visibility": { "header.brand": false },
            "pages": { "shop": { "footer": false, "sections": [ { "key": "items", "visible": true } ] } },
            "platforms": { "mobile_web": { "components": { "button": { "base": { "old_gap": "{space.xs}" } } }, "visibility": { "header.brand": true } } }
        }
        JSON ],
    [ 'upgrade', 'moves-to-set', "A move to a key the theme already sets in the new base's name does not overwrite it; `from` goes either way.", [ '26', '27' ], false, <<<'JSON'
        { "visibility": { "header.brand": false, "header.logo": true }, "tokens": { "color": { "brand": "#E4002B", "primary": "#000000" } } }
        JSON ],
    [ 'upgrade', 'map', "Maps: an option renamed (`underlined` is `always`) and a scale changed (`{size.lg}` is `{space.lg}`), also on a platform; a level the map does not name, and a number, are dropped.", [ '26', '27' ], false, <<<'JSON'
        {
            "components": { "link": { "base": { "underline": "underlined" } }, "card": { "base": { "padding": "{size.lg}" } } },
            "platforms": { "mobile_web": { "components": { "card": { "base": { "padding": "{size.sm}" } }, "link": { "base": { "underline": "none" } } } } }
        }
        JSON ],
    [ 'upgrade', 'map-number', 'A number where a map translates levels is dropped: the map names only what it translates.', [ '26', '27' ], false, <<<'JSON'
        { "components": { "card": { "base": { "padding": 12 } } } }
        JSON ],
    [ 'upgrade', 'token-aliases', "A color moved: its value, its value in dark mode and every alias to it (a property, a border's color, a platform's override) name the new one.", [ '26', '27' ], false, <<<'JSON'
        {
            "tokens": { "color": { "brand": "#E4002B" } },
            "modes": { "dark": { "color": { "brand": "#FF5A5F" } } },
            "components": { "card": { "base": { "background": "{color.brand}", "border": { "width": 1, "color": "{color.brand}" } } } },
            "platforms": { "mobile_web": { "components": { "modal": { "base": { "background": "{color.brand}" } } } } }
        }
        JSON ],
    [ 'upgrade', 'type-changed', "A property whose type changed at the same path (the modal's padding, a level of space in 26, a number in 27) and no move takes: dropped, though 24 would fit both, also on a platform.", [ '26', '27' ], false, <<<'JSON'
        { "components": { "modal": { "base": { "padding": 24, "radius": "{radius.sm}" } } }, "platforms": { "mobile_web": { "components": { "modal": { "base": { "padding": 16 } } } } } }
        JSON ],
    [ 'upgrade', 'drop', 'Drops: a visibility key, and a platform\'s override of it.', [ '26', '27' ], false, <<<'JSON'
        { "visibility": { "old_banner": true, "footer": true }, "platforms": { "mobile_web": { "visibility": { "old_banner": false } } } }
        JSON ],
    [ 'upgrade', 'rewrite', 'The rewrite the base names, passed: the typography `small` becomes `caption` and every weight goes up a step.', [ '26', '27' ], true, <<<'JSON'
        { "tokens": { "typography": { "small": { "size": 11, "line": 1.4, "weight": 400 }, "h1": { "size": 30, "line": 1.2, "weight": 600 } } } }
        JSON ],
    [ 'upgrade', 'rewrite-skipped', 'The same theme without the rewrite: `small`, which 27 does not name, is dropped, and the weights stay.', [ '26', '27' ], false, <<<'JSON'
        { "tokens": { "typography": { "small": { "size": 11, "line": 1.4, "weight": 400 }, "h1": { "size": 30, "line": 1.2, "weight": 600 } } } }
        JSON ],
    [ 'upgrade', 'chain', 'Through 27 to 28: the button\'s gap is `old_gap` in 26, `icon_gap` in 27 and `icon_space` in 28; the rest of each step as before, the rewrite once.', [ '26', '27', '28' ], true, <<<'JSON'
        {
            "components": { "button": { "base": { "old_gap": "{space.md}" } }, "link": { "base": { "underline": "underlined" } }, "card": { "base": { "background": "{color.brand}" } } },
            "tokens": { "color": { "brand": "#E4002B" }, "typography": { "h2": { "size": 24, "line": 1.3, "weight": 500 } } },
            "visibility": { "old_banner": true }
        }
        JSON ],
    [ 'upgrade', 'one-schema', 'A chain of one schema: what of the theme fits it.', [ '27' ], false, <<<'JSON'
        { "components": { "button": { "base": { "old_gap": "{space.md}", "radius": "{radius.sm}" } } } }
        JSON ],
    [ 'upgrade', 'list', 'A list is no theme.', [ '26', '27' ], false, '[ 1 ]' ],
    [ 'settings', 'settings', "A site's settings: a setting moved, one dropped, one the new base does not take (an option it has not), and one that stays.", [ '26', '27' ], true, <<<'JSON'
        { "slug": "my-shop", "legacy_flag": true, "mode": "kiosk", "name": "My shop" }
        JSON ],
    [ 'settings', 'settings-chain', 'The same settings through 28.', [ '26', '27', '28' ], false, <<<'JSON'
        { "slug": "my-shop", "business_slug": "kept", "third_party_code": { "head": "<meta>" } }
        JSON ],
    [ 'cutDown', 'cut-down', "A theme of 27 for a client of 26: the block's one-to-one maps undone (`always` is `underlined` again, `{space.lg}` is `{size.lg}`, also on a platform where it is a number 26 cannot read); what has the same path and kind and fits 26 stays; a move into a key 26 already has (the gap, `header.logo`) is not undone; a property whose type differs and no move takes takes 26's default.", [ '27', '26' ], false, <<<'JSON'
        {
            "tokens": { "color": { "primary": "#111111" } },
            "components": {
                "button": { "base": { "icon_gap": "{space.md}" } },
                "link": { "base": { "underline": "always" } },
                "card": { "base": { "padding": "{space.lg}", "background": "{color.surface}" } },
                "modal": { "base": { "padding": 24 } }
            },
            "visibility": { "header.logo": false },
            "platforms": { "mobile_web": { "components": { "card": { "base": { "padding": 8 } } } } }
        }
        JSON ],
    [ 'cutDown', 'cut-down-28', 'A theme of 28 for a client of 27: the gap 28 renamed is `icon_gap` again.', [ '28', '27' ], false, <<<'JSON'
        { "components": { "button": { "base": { "icon_space": "{space.md}", "radius": "{radius.sm}" } } } }
        JSON ],
    [ 'cutDownSettings', 'cut-down-settings', 'Settings of 27 for a client of 26: a setting 26 has not (its description) is left out; the slug 27 moved into `business_slug`, which 26 has too, stays there.', [ '27', '26' ], false, <<<'JSON'
        { "business_slug": "my-shop", "description": "Fresh food", "mode": "single_store" }
        JSON ],
    [ 'upgrade', 'map-boolean', 'A boolean turned around by a map (`true` and `false` as its keys), also a platform\'s override.', [ '26', '27' ], false, <<<'JSON'
        { "visibility": { "hide_prices": true }, "platforms": { "mobile_web": { "visibility": { "hide_prices": false } } } }
        JSON ],
    [ 'upgrade', 'map-many', 'A map that merges two options: `neutral` is `soft`.', [ '26', '27' ], false, <<<'JSON'
        { "components": { "card": { "base": { "tone": "neutral" } } } }
        JSON ],
    [ 'settings', 'settings-map-boolean', 'A boolean setting that becomes an option by a map.', [ '26', '27' ], false, <<<'JSON'
        { "kiosk_only": true }
        JSON ],
    [ 'settings', 'settings-migrate', "Settings saved with 26.4 read with 26.5, the same base: the setting 26.5 renamed takes its new name.", [ '26.5' ], false, <<<'JSON'
        { "slug": "my-shop", "name": "My shop" }
        JSON ],
    [ 'cutDown', 'cut-down-renames', "A theme of 26.5 for a client of 26.4, the same base: every migration 26.4 does not have is undone, a property, a color (its value in dark mode and the aliases to it), a visibility key (and a platform's override) and a home section, in its place.", [ '26.5', '26' ], false, <<<'JSON'
        {
            "components": { "button": { "base": { "spacer": "{space.lg}" } }, "card": { "base": { "background": "{color.danger_text}" } } },
            "tokens": { "color": { "danger_text": "#B02A3E" } },
            "modes": { "dark": { "color": { "danger_text": "#FF8899" } } },
            "visibility": { "header.badge": false },
            "pages": { "home": { "sections": [ { "key": "promo_banners", "visible": true }, { "key": "main_content", "visible": false }, { "key": "login_account", "visible": true } ] } },
            "platforms": { "mobile_web": { "visibility": { "header.badge": true }, "components": { "button": { "base": { "spacer": "{space.xs}" } } } } }
        }
        JSON ],
    [ 'cutDown', 'cut-down-chain', "A theme of 28 for a client of 26: 28's rename undone, then 27's map; the gap is `icon_gap` in both 27 and 26.", [ '28', '27', '26' ], false, <<<'JSON'
        { "components": { "button": { "base": { "icon_space": "{space.md}" } }, "link": { "base": { "underline": "always" } } } }
        JSON ],
    [ 'cutDown', 'cut-down-map-boolean', "A theme of 27 for a client of 26: the boolean the block turned around, turned back, and the map that merged two options, which cannot be undone: 26's default.", [ '27', '26' ], false, <<<'JSON'
        { "visibility": { "show_prices": false }, "components": { "card": { "base": { "tone": "loud" } } } }
        JSON ],
    [ 'cutDownSettings', 'cut-down-settings-renames', 'Settings of 26.5 for a client of 26.4: the setting 26.5 renamed takes its old name.', [ '26.5', '26' ], false, <<<'JSON'
        { "shop_slug": "my-shop", "name": "My shop" }
        JSON ],
    [ 'cutDownSettings', 'cut-down-settings-map-boolean', 'Settings of 27 for a client of 26: the option a boolean became is the boolean again.', [ '27', '26' ], false, <<<'JSON'
        { "kiosk_mode": "off" }
        JSON ],
];

$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;
$answers = [];
foreach ($cases as [ $kind, $name, $description, $bases, $with_rewrite, $json ]) {
    $values = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    $chain = array_map(fn (string $base) => $compiled[$base], $bases);
    $callback = $with_rewrite ? $rewrite : null;
    $expected = match ($kind) {
        'upgrade' => ThemeUpgrades::chain($values, $chain, $callback),
        'settings' => ThemeUpgrades::settingsChain($values, $chain, $callback),
        'cutDown' => ThemeUpgrades::cutDown($values, $chain),
        'cutDownSettings' => ThemeUpgrades::cutDownSettings($values, $chain),
    };
    $answers[] = [
        'name' => $name,
        'description' => $description,
        'kind' => $kind,
        'bases' => $bases,
        'rewrite' => $with_rewrite,
        'values' => json_decode($json, false, 512, JSON_THROW_ON_ERROR),
        'expected' => $expected,
    ];
}

echo json_encode([
    'description' => "What ThemeUpgrades answers for each theme or site's settings between bases made from {$original['product_type']}@{$original['version']}.",
    'php' => PHP_VERSION,
    'schemas' => $schemas,
    'cases' => $answers,
], JSON_PRETTY_PRINT | $flags) . "\n";
