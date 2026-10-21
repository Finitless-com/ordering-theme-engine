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
// scale, and one whose type, 27 changes; an option 27 renames; keys 27
// drops), and a migration of its own base.
// 27.0: the product's schema, with its block from 26 (it leaves the
// changed type of the modal's padding to the step that drops what changed
// kind). 28.0: renames the button's icon gap.
$schemas = [
    '26' => [
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
        $del('site.description'),
        $set([ '$extensions', 'co.ordering.migrate' ], [ [ 'from' => 'components.card.base.corner', 'to' => 'components.card.base.radius' ] ]),
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
            ],
            'drop' => [ 'visibility.old_banner', 'site.legacy_flag' ],
            'rewrite' => [ 'id' => 'test.bolder', 'paths' => [ 'tokens.typography.small' ] ],
        ]),
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
    [ 'cutDown', 'cut-down', 'A theme of 27 for a client of 26: what has the same path and kind and fits 26 stays; an option 26 has not, a property whose scale or type differs (even a number both take) and a key 26 has not take 26\'s defaults.', [ '27', '26' ], false, <<<'JSON'
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
    [ 'cutDown', 'cut-down-28', 'A theme of 28 for a client of 27: the gap 28 renamed is no key of 27.', [ '28', '27' ], false, <<<'JSON'
        { "components": { "button": { "base": { "icon_space": "{space.md}", "radius": "{radius.sm}" } } } }
        JSON ],
    [ 'cutDownSettings', 'cut-down-settings', 'Settings of 27 for a client of 26: a setting 26 has not (its description) is left out.', [ '27', '26' ], false, <<<'JSON'
        { "business_slug": "my-shop", "description": "Fresh food", "mode": "single_store" }
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
        'cutDown' => ThemeUpgrades::cutDown($values, $chain[0], $chain[1]),
        'cutDownSettings' => ThemeUpgrades::cutDownSettings($values, $chain[0], $chain[1]),
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
