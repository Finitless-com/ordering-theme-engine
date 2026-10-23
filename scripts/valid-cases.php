<?php

/*
 * The golden cases of what of a theme fits its schema: for each theme,
 * what ThemeValidator::validate() answers (its errors, in order) and what
 * ThemeResolver::valid() keeps of it, over the reference schema as
 * NodeCompiler compiles it with its version 1 map and the colors the
 * editor may make translucent (fixtures/reference.compiled.json); and for
 * site settings, what ThemeValidator::settings() answers and what
 * ThemeResolver::validSettings() keeps of them (a case may give some
 * settings `limits` and `site` specs of its own, over the schema's). JSON on stdout;
 * scripts/sync-fixtures.sh runs it in PHP 8.3, after compile-schema.php:
 *
 *   php scripts/valid-cases.php fixtures/reference.compiled.json
 *
 * The values are written here as JSON and read back as objects for the
 * fixture, so `{}` stays `{}`.
 */

declare(strict_types=1);

use Ordering\ThemeEngine\ThemeResolver;
use Ordering\ThemeEngine\ThemeValidator;

require dirname(__DIR__) . '/vendor/autoload.php';

set_error_handler(function (int $severity, string $message, string $file, int $line) {
    throw new \ErrorException($message, 0, $severity, $file, $line);
});

[ , $schema_path ] = $argv + [ null, null ];
if (!is_string($schema_path) || !is_file($schema_path)) {
    fwrite(STDERR, "Usage: php scripts/valid-cases.php <compiled schema fixture>\n");
    exit(1);
}
$compiled = json_decode((string) file_get_contents($schema_path), true, 512, JSON_THROW_ON_ERROR);
if ($compiled['errors'] !== [] || !is_array($compiled['schema']['alpha'] ?? null)) {
    fwrite(STDERR, "The schema does not compile, or marks no translucent color.\n");
    exit(1);
}
$schema = $compiled['schema'];

$themes = [
    'list' => [ 'A list is no theme: nothing of it is kept.', '[{"tokens": {}}]' ],
    'empty' => [ 'The empty theme: nothing is wrong.', '{}' ],
    'sections' => [ 'A section a theme has not, and sections that are not maps.', <<<'JSON'
        { "theme": {}, "tokens": [1, 2], "components": "button", "visibility": { "footer": false }, "pages": [] }
        JSON ],
    'tokens' => [ "Tokens: an unknown group and name, invalid values of each kind, a catalog color and a typography style the schema does not name, an alias to one, and a translucent color where the schema keeps it opaque (and one where it does not).", <<<'JSON'
        {
            "tokens": {
                "color": { "primary": "#E4002B", "secondary": "red", "danger_text": "#B02A3E", "background": "rgba(0, 0, 0, 0.5)", "overlay": "#00000080", "surface": "#FFFFFF80" },
                "typography": { "h1": { "size": 30, "line": 1.2, "weight": 700 }, "h2": { "size": 30, "line": 9, "weight": 700 }, "small": { "size": 10, "line": 1.2, "weight": 400 } },
                "radius": { "md": 8, "huge": 40, "lg": -1 },
                "shadow_style": { "style": "soft", "direction": "up", "color": "#000000" },
                "border_style": { "width": "none", "radius": "round" },
                "focus_ring": { "color": "primary", "offset": "huge" },
                "layout": { "mode": "appointments", "page_gutter": "16" },
                "font": { "body": "inter", "heading": "comic_sans" },
                "weird": { "x": 1 },
                "opacity": "half"
            }
        }
        JSON ],
    'components' => [ "Components: an unknown component, section, variant, state and property, values of the wrong kind, an alias of another group, a level a property leaves out, a color without a default set to null, a button's colors and appearances, its sizes, a scope and slots, and colors made translucent where the schema keeps them opaque, by the node that declares them.", <<<'JSON'
        {
            "components": {
                "nope": { "base": { "radius": "{radius.md}" } },
                "card": {
                    "base": { "background": "{color.surface}", "radius": "{space.md}", "padding": 12, "shadow": "big", "glow": "#FFFFFF", "border": { "width": 1, "color": "#00000080" } },
                    "variants": { "default": { "background": "#FFFFFF80", "states": { "hover": { "shadow": "{shadow.lg}", "background": "#ffffff" }, "pressed": { "shadow": "{shadow.lg}" } } }, "fancy": {} },
                    "extra": {}
                },
                "button": {
                    "base": { "radius": "{radius.xl}", "full_width": "yes" },
                    "colors": { "primary": { "main": "#00AA55", "ink": null, "glow": "#FFFFFF" }, "nope": { "main": "#000000" } },
                    "appearances": { "solid": { "background": "{on}", "states": { "pressed": { "background": null }, "disabled": { "color": "#FFFFFF80" }, "focus": { "background": "{main}" } } } },
                    "sizes": { "sm": { "height": "{size.lg}", "padding_x": "{size.lg}", "states": { "hover": {} } } }
                },
                "header": {
                    "scope": { "button": { "base": { "radius": "{radius.sm}", "speed": "fast" } }, "card": { "base": {} } },
                    "slots": { "cta": { "size": "md", "appearance": "glossy", "component": "button" }, "order_type": { "component": "link", "variant": "default" }, "nope": { "size": "md" }, "search": "link" }
                },
                "modal": { "base": { "background": "#00000080" } }
            }
        }
        JSON ],
    'visibility-images-pages' => [ 'Visibility, images and pages: unknown keys, values of the wrong kind, an unknown page and field, a footer that is no bool, sections that are no list, a section unknown, repeated, hidden that cannot be, and not `{ key, visible }`.', <<<'JSON'
        {
            "visibility": { "footer": false, "header.search": "no", "header.nope": true },
            "images": { "logotype": "https://example.com/logo.png", "favicon": "ftp://example.com/x.ico", "nope": "" },
            "pages": {
                "home": { "footer": "yes", "sections": [ { "key": "hero", "visible": false }, { "key": "featured", "visible": false }, { "key": "featured", "visible": true }, { "key": "nope", "visible": true }, { "key": "recent", "visible": true, "extra": 1 } ], "extra": true },
                "checkout": { "sections": [ { "key": "tips" } ] },
                "not_found": { "sections": { "key": "hero" } },
                "nope": { "footer": true }
            }
        }
        JSON ],
    'modes-platforms' => [ "Modes and platforms: an unknown mode and group, a color invalid in a mode, a translucent one where the schema keeps it opaque, an unknown platform and part, a platform's component, visibility and pages, each with what is wrong in them, and an alias to a color the schema does not name.", <<<'JSON'
        {
            "modes": { "dark": { "color": { "primary": "#111111", "secondary": "nope", "surface": "#11111180", "overlay": "#11111180" }, "typography": {} }, "light": { "color": {} } },
            "platforms": {
                "tv": { "components": {} },
                "mobile_web": {
                    "components": { "card": { "base": { "radius": "{radius.xl}", "background": "{color.danger_text}" }, "scope": {} }, "modal": { "base": { "background": "#00000080" } } },
                    "visibility": { "footer": true, "header.search": 1, "nope": false },
                    "pages": { "home": { "footer": false, "sections": [ { "key": "hero", "visible": false } ] } }
                }
            }
        }
        JSON ],
    'platform-parts' => [ "A platform's part that is no map, and one it has not.", <<<'JSON'
        { "platforms": { "mobile_web": { "visibility": [ true ], "pages": "home", "tokens": {} } } }
        JSON ],
    'section-lists' => [ "Sections left out of a page's list, not the last ones, also on a platform: the list kept is still a list.", <<<'JSON'
        {
            "pages": { "home": { "sections": [ { "key": "nope", "visible": false }, { "key": "hero", "visible": false }, { "key": "featured", "visible": false }, { "key": "recent", "visible": true } ] } },
            "platforms": { "mobile_web": { "pages": { "home": { "sections": [ { "key": "nope", "visible": true }, { "key": "featured", "visible": true } ] } } } }
        }
        JSON ],
    'mode-colors' => [ "A mode's colors are the schema's own, as `tokens.color`: a color of the catalog the schema does not name is unknown in a mode too.", <<<'JSON'
        { "modes": { "dark": { "color": { "primary": "#111111", "danger_text": "#B02A3E", "favorite": "#FF5A5F" } } }, "tokens": { "color": { "danger_text": "#B02A3E" } } }
        JSON ],
    'out-of-range' => [ "Numbers outside their editor's limits: a token, a typography style's fields (one out, one within), a property's base, a platform's default with limits of its own and a platform's value of a property with none there, which takes its base's, refused when saved and clamped to the closest limit when read; within them, at the limits, and an alias are kept.", <<<'JSON'
        {
            "tokens": {
                "size": { "lg": 90, "md": 20 },
                "space": { "md": 96 },
                "typography": { "h1": { "size": 120, "line": 2.5, "weight": 600 }, "h2": { "size": 24, "line": 1.2, "weight": 600 } }
            },
            "components": {
                "button": { "base": { "icon_gap": "{space.lg}", "speed": 1500 }, "sizes": { "sm": { "height": 40, "icon_size": 16 } } },
                "header": { "base": { "logo_width": 400, "height": 96 } }
            },
            "platforms": { "mobile_web": { "components": { "banner": { "base": { "columns": 5 } }, "button": { "base": { "speed": 1500 } } } } }
        }
        JSON ],
    'out-of-format' => [ "Numbers outside the format's range, where the editor sets no limits or within the ones it sets: an invalid number when saved, clamped to the format's range or the node's limits when read (a token of a scale, an opacity, a typography style's fields, a component's base, a group's scope, a platform's default); a layout's mode, an alias and a number that is no number of its kind (a radius) are not clamped.", <<<'JSON'
        {
            "tokens": {
                "radius": { "md": 12000, "sm": -4 },
                "opacity": { "disabled": 1.5 },
                "layout": { "mode": "original", "page_gutter": 120 },
                "typography": { "h2": { "size": 300, "line": 5, "weight": 600, "letter_spacing": -30 } }
            },
            "components": {
                "card": { "base": { "padding": 10001, "radius": 6 } },
                "header": { "scope": { "button": { "base": { "icon_gap": 200, "radius": "{radius.sm}" } } } }
            },
            "platforms": { "mobile_web": { "components": { "banner": { "base": { "columns": -3 } }, "header": { "base": { "height": 10000 } } } } }
        }
        JSON ],
    'image-formats' => [ "An image's extension is one of its formats (in any case, the query aside): the logotype takes SVG, the favicon does not, and a key without formats takes no ICO.", <<<'JSON'
        {
            "images": {
                "logotype": "https://cdn.example.com/a/logo.SVG?w=2",
                "isotype": "https://cdn.example.com/hero.ico",
                "favicon": "https://cdn.example.com/favicon.svg"
            }
        }
        JSON ],
    'image-formats-unnamed' => [ "`jpeg` is `jpg`, and a URL without the extension of a known format says nothing of it.", <<<'JSON'
        {
            "images": {
                "logotype": "https://cdn.example.com/image/upload/abc",
                "isotype": "https://cdn.example.com/logo.JPEG",
                "favicon": "https://cdn.example.com/icon.v2/file"
            }
        }
        JSON ],
];

$settings = [
    'settings-valid' => [ 'Valid settings.', '{ "mode": "single_store", "name": "Shop", "third_party_code": { "head": "<script></script>" }, "franchise_id": 12 }' ],
    'settings-invalid' => [ 'Settings: an unknown key and group, values of the wrong kind, an option it does not have, a group that is a value and a value that is a group.', <<<'JSON'
        { "mode": "kiosk", "name": 12, "nope": true, "tracking_only": "yes", "third_party_code": "x", "smart_banner": { "apple_id": "123", "nope": "x" }, "franchise_id": -1, "business_slug": { "x": 1 } }
        JSON ],
    'settings-list' => [ 'A list is no settings.', '[ "x" ]' ],
    'settings-out-of-range' => [ "A setting outside its editor's limits (the franchise's id, given `min` 1 and `max` 1000 for this case): an id is not clamped, it is left out.", '{ "franchise_id": 5000, "name": "Shop" }', [ 'site.franchise_id' => [ 'min' => 1, 'max' => 1000 ] ] ],
    'settings-clamped' => [ "Numbers of the settings (`site.max_items`, a number from 1 to 50, and `site.layout.columns`, a number without limits, given for this case): refused when saved outside their limits or the format's range and clamped when read; one within them is kept.", '{ "max_items": 80, "layout": { "columns": 12000, "gap": 4 }, "name": "Shop" }', [ 'site.max_items' => [ 'min' => 1, 'max' => 50 ] ], [ 'max_items' => [ 'number', 10 ], 'layout' => [ 'columns' => [ 'number', 3 ], 'gap' => [ 'number', 8 ] ] ] ],
];

$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;
$cases = [];
foreach ($themes as $name => [ $description, $json ]) {
    $values = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    $cases[] = [
        'name' => $name,
        'description' => $description,
        'values' => json_decode($json, false, 512, JSON_THROW_ON_ERROR),
        'errors' => ThemeValidator::validate($values, $schema),
        'valid' => ThemeResolver::valid($values, $schema),
    ];
}
foreach ($settings as $name => $case) {
    [ $description, $json ] = $case;
    $limits = $case[2] ?? null;
    $site = $case[3] ?? null;
    $over = $limits === null ? $schema : [ ...$schema, 'limits' => [ ...$schema['limits'], ...$limits ] ];
    $over = $site === null ? $over : [ ...$over, 'site' => [ ...$over['site'], ...$site ] ];
    $settings_values = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    $cases[] = [
        'name' => $name,
        'description' => $description,
        'settings' => json_decode($json, false, 512, JSON_THROW_ON_ERROR),
        ...($limits === null ? [] : [ 'limits' => $limits ]),
        ...($site === null ? [] : [ 'site' => $site ]),
        'errors' => ThemeValidator::settings($settings_values, $over),
        'valid' => is_array($settings_values) && !array_is_list($settings_values) ? ThemeResolver::validSettings($settings_values, $over) : ThemeResolver::validSettings([], $over),
    ];
}

echo json_encode([
    'description' => "What ThemeValidator::validate() and ThemeResolver::valid() answer for each theme, and ThemeValidator::settings() for each site's settings, over {$schema['product_type']}@{$schema['version']}.",
    'php' => PHP_VERSION,
    'cases' => $cases,
], JSON_PRETTY_PRINT | $flags) . "\n";
