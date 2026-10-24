<?php

/*
 * The versions of the reference schema after its first, as JSON on stdout:
 * 1.1.0, a minor of 1.0.0 that changes something of every kind the
 * comparison reports, or 2.0.0, a new base from 1.1.0 with an upgrade block
 * (README.md, "The reference schema"). Each is the text of the one before
 * with replacements, so 1.0.0 is the only one written by hand: a change to
 * it reaches the others, and a replacement whose text is no longer there
 * fails instead of being skipped. scripts/sync-fixtures.sh runs it in
 * PHP 8.3:
 *
 *   php scripts/reference-versions.php meta/reference/reference-1.0.0.json 1.1.0
 */

declare(strict_types=1);

[ , $schema_path, $version ] = $argv + [ null, null, null ];
if (!is_string($schema_path) || !is_file($schema_path) || !in_array($version, [ '1.1.0', '2.0.0' ], true)) {
    fwrite(STDERR, "Usage: php scripts/reference-versions.php <reference-1.0.0.json> <1.1.0|2.0.0>\n");
    exit(1);
}

// $old, which must be in $text exactly $count times, replaced by $new.
$replace = function (string $text, string $old, string $new, int $count = 1): string {
    $found = substr_count($text, $old);
    if ($found !== $count) {
        fwrite(STDERR, "Found $found times instead of $count:\n$old\n");
        exit(1);
    }
    return str_replace($old, $new, $text);
};

// 1.1.0: a minor that renames, removes a deprecated key, adds and retunes.
$minor = (string) file_get_contents($schema_path);
$minor = $replace($minor, '"version": "1.0.0"', '"version": "1.1.0"');
$minor = $replace($minor, <<<'OLD'
"icon_gap": {
          "$type": "dimension",
          "$value": "{space.sm}"
OLD, <<<'NEW'
"gap": {
          "$type": "dimension",
          "$value": "{space.sm}"
NEW);
$minor = $replace($minor, '"favorite": { "$value": "#E11D48", "$deprecated": "Hearts follow the danger color." }', '"rating": { "$value": "#F59E0B" }');
$minor = $replace($minor, '"tokens.color.favorite"', '"tokens.color.rating"');
$minor = $replace($minor, '"product.navigation": { "$value": false },', '"product.arrows": { "$value": false },');
$minor = $replace($minor, '"visibility.product.navigation"', '"visibility.product.arrows"');
$minor = $replace($minor, '{ "key": "recent", "visible": false, "hideable": true, "group": "content" }', '{ "key": "latest", "visible": false, "hideable": true, "group": "content" }');
$minor = $replace($minor, '"contact_email": {', '"support_email": {');
$minor = $replace($minor, '"site.contact_email"', '"site.support_email"');
$minor = $replace($minor, "    \"header.far_away\": { \"\$value\": true, \"\$deprecated\": true },\n", '');
$minor = $replace($minor, ', "visibility.header.far_away"]', ']');
$minor = $replace($minor, <<<'OLD'
        "background": { "$type": "color", "$value": "{color.surface}" },
        "padding": { "$type": "dimension", "$value": "{space.md}", "$extensions": { "co.ordering": { "scale": "space" } } },
        "radius": { "$type": "dimension", "$value": "{radius.md}", "$extensions": { "co.ordering": { "scale": "radius", "follows": "radius" } } },
        "shadow"
OLD, <<<'NEW'
        "background": { "$type": "color", "$value": "{color.surface}" },
        "padding": { "$type": "dimension", "$value": "{space.md}", "$extensions": { "co.ordering": { "scale": "space" } } },
        "gap": { "$type": "dimension", "$value": "{space.sm}", "$extensions": { "co.ordering": { "scale": "space" } } },
        "radius": { "$type": "dimension", "$value": "{radius.md}", "$extensions": { "co.ordering": { "scale": "radius", "follows": "radius" } } },
        "shadow"
NEW);
$minor = $replace($minor, '"options": ["classic", "centered"]', '"options": ["classic", "centered", "split"]');
$minor = $replace($minor, "\"primary\": {\n        \"\$value\": \"#1E72E3\"", "\"primary\": {\n        \"\$value\": \"#1A6BD8\"");
$minor = $replace($minor, '"name": { "$type": "string", "$value": "", "$extensions": { "co.ordering": { "max_length": 60 } } }', '"name": { "$type": "string", "$value": "", "$extensions": { "co.ordering": { "max_length": 50 } } }');
$minor = $replace($minor, '{ "key": "tips", "visible": true, "hideable": true, "group": "extras" }', '{ "key": "tips", "visible": true, "hideable": false, "group": "extras" }');
$minor = $replace($minor, '"autoplay": { "$type": "boolean", "$value": true }', '"autoplay": { "$type": "boolean", "$value": true, "$deprecated": "The banner no longer moves on its own." }');
$migrations = <<<'TEXT'
    "co.ordering.migrate": [
      { "from": "components.button.base.icon_gap", "to": "components.button.base.gap" },
      { "from": "tokens.color.favorite", "to": "tokens.color.rating" },
      { "from": "visibility.product.navigation", "to": "visibility.product.arrows" },
      { "from": "pages.home.sections.recent", "to": "pages.home.sections.latest" },
      { "from": "site.contact_email", "to": "site.support_email" }
    ]
TEXT;
$minor = $replace($minor, '  "$description": "The engine\'s reference schema', "  \"\$extensions\": {\n$migrations\n  },\n  \"\$description\": \"The engine's reference schema");
$minor = $replace($minor, "    \"chip\": {\n      \"variants\"", "    \"chip\": {\n      \"\$deprecated\": \"Filters use the tabs.\",\n      \"variants\"");

if ($version === '1.1.0') {
    echo $minor;
    exit(0);
}

// 2.0.0: a new base, from the last version of base 1.
$base = $replace($minor, '"version": "1.1.0"', '"version": "2.0.0"');
$base = $replace($base, $migrations, '__UPGRADE__');
// A token renamed, with every alias to it.
$base = $replace($base, '"surface_alt": { "$value": "#F5F6F8" }', '"surface_raised": { "$value": "#F5F6F8" }');
$base = $replace($base, '"tokens.color.surface_alt"', '"tokens.color.surface_raised"');
$base = $replace($base, '"{color.surface_alt}"', '"{color.surface_raised}"', 4);
// A property renamed, its default on a platform with it.
$base = $replace($base, "\"logo_width\": {\n          \"\$type\": \"number\"", "\"logo_max_width\": {\n          \"\$type\": \"number\"");
$base = $replace($base, '"components.header.base.logo_width"', '"components.header.base.logo_max_width"');
$base = $replace($base, '"logo_width": { "$type": "number", "$value": 120', '"logo_max_width": { "$type": "number", "$value": 120');
// An option renamed.
$base = $replace($base, '"co.ordering": { "options": ["none", "hover", "always"] }', '"co.ordering": { "options": ["never", "hover", "always"] }');
$base = $replace($base, '{ "value": "none", "label": { "key": "BUILDER_THEME_UNDERLINE_NONE", "default": "Never" } }', '{ "value": "never", "label": { "key": "BUILDER_THEME_UNDERLINE_NONE", "default": "Never" } }');
// A dimension on another scale.
$base = $replace($base, <<<'OLD'
        "padding": { "$type": "dimension", "$value": "{space.md}", "$extensions": { "co.ordering": { "scale": "space" } } },
        "gap": {
OLD, <<<'NEW'
        "padding": { "$type": "dimension", "$value": "{size.md}", "$extensions": { "co.ordering": { "scale": "size" } } },
        "gap": {
NEW);
$base = $replace($base, <<<'OLD'
      "platforms": {
        "mobile_web": {
          "padding": { "$type": "dimension", "$value": "{space.sm}", "$extensions": { "co.ordering": { "scale": "space" } } }
OLD, <<<'NEW'
      "platforms": {
        "mobile_web": {
          "padding": { "$type": "dimension", "$value": "{size.sm}", "$extensions": { "co.ordering": { "scale": "size" } } }
NEW);
// A boolean turned around.
$base = $replace($base, '"address.notes": { "$value": true }', '"address.notes_hidden": { "$value": false }');
$base = $replace($base, '"visibility.address.notes"', '"visibility.address.notes_hidden"');
// A setting moved into a group.
$base = $replace($base, '    "support_email": {', "    \"support\": {\n      \"email\": {");
$base = $replace($base, <<<'OLD'
"$type": "string", "$value": "", "$extensions": { "co.ordering": { "max_length": 120, "pattern": "^[^@]+@[^@]+$" } } },
    "tracking_only"
OLD, <<<'NEW'
"$type": "string", "$value": "", "$extensions": { "co.ordering": { "max_length": 120, "pattern": "^[^@]+@[^@]+$" } } }
    },
    "tracking_only"
NEW);
$base = $replace($base, '"site.support_email"', '"site.support.email"');
// The header's height in pixels becomes a choice: what no block can declare.
$base = $replace($base, <<<'OLD'
        "height": {
          "$type": "dimension",
          "$value": "{size.lg}",
          "$extensions": {
            "co.ordering": { "scale": "size" },
            "co.ordering.editor": { "control": "level", "min": 40, "max": 96, "step": 1, "unit": "px", "group": "bar", "order": 2 }
          }
        },
OLD, <<<'NEW'
        "height": {
          "$type": "option",
          "$value": "regular",
          "$extensions": {
            "co.ordering": { "options": ["compact", "regular", "tall"] },
            "co.ordering.editor": { "control": "segmented", "group": "bar", "order": 2 }
          }
        },
NEW);
$base = $replace($base, '"height": { "$type": "dimension", "$value": "{size.md}", "$extensions": { "co.ordering": { "scale": "size" }, "co.ordering.editor": { "order": 2 } } }', '"height": { "$type": "option", "$value": "compact", "$extensions": { "co.ordering": { "options": ["compact", "regular", "tall"] }, "co.ordering.editor": { "order": 2 } } }');
// Dropped: a group, a deprecated key and a visibility key.
$base = $replace($base, ', "components.chip"', '');
$chip = strpos($base, '    "chip": {');
$after_chip = strpos($base, '    "modal": {');
if ($chip === false || $after_chip === false || $after_chip < $chip) {
    fwrite(STDERR, "The chip group is no longer right before the modal.\n");
    exit(1);
}
$base = substr($base, 0, $chip) . substr($base, $after_chip);
$base = $replace($base, ",\n        \"autoplay\": { \"\$type\": \"boolean\", \"\$value\": true, \"\$deprecated\": \"The banner no longer moves on its own.\" }", '');
$base = $replace($base, "    \"product.arrows\": { \"\$value\": false },\n", '');
$base = $replace($base, ', "visibility.product.arrows"]', ']');
$base = $replace($base, '__UPGRADE__', <<<'TEXT'
    "co.ordering.upgrade": {
      "from": 1,
      "moves": [
        { "from": "tokens.color.surface_alt", "to": "tokens.color.surface_raised" },
        { "from": "components.header.base.logo_width", "to": "components.header.base.logo_max_width" },
        { "from": "components.link.base.underline", "to": "components.link.base.underline", "map": { "none": "never", "hover": "hover", "always": "always" } },
        { "from": "components.card.base.padding", "to": "components.card.base.padding", "map": { "{space.sm}": "{size.sm}", "{space.md}": "{size.md}", "{space.lg}": "{size.lg}" } },
        { "from": "visibility.address.notes", "to": "visibility.address.notes_hidden", "map": { "true": false, "false": true } },
        { "from": "site.support_email", "to": "site.support.email" }
      ],
      "drop": ["components.chip", "components.banner.base.autoplay", "visibility.product.arrows"],
      "rewrite": { "id": "reference.2", "paths": ["components.header.base.height"] }
    }
TEXT);

echo $base;
