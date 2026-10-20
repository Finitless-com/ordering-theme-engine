# Theme schema format (nodes, format 1)

This document specifies the format in which each product writes its **theme schema**:
the file that describes everything the product reads from a v2 theme (tokens,
components, visibility, images and pages) and its site's settings (`site`). The schema
belongs to the product; it lives in the product's repo, next to the code that reads it,
and is published to api-core (see [creating-a-schema.md](creating-a-schema.md)).

The rules in this document are the ones enforced by the meta-schema
(`meta/theme-schema-1.json`), this package's `validate()` and api-core on publish.
Where they say "rejected with `<code>`", that is the code all three answer.

Contents:

1. [Root](#1-root)
2. [Nodes and groups](#2-nodes-and-groups)
3. [Property types](#3-property-types)
4. [Tokens](#4-tokens)
5. [Components](#5-components)
6. [Visibility](#6-visibility)
7. [Images](#7-images)
8. [Pages](#8-pages)
9. [Site settings (`site`)](#9-site-settings-site)
10. [`$extensions["co.ordering"]`: what the engine reads](#10-extensionscoordering-what-the-engine-reads)
11. [Derivations, Identity and matrices](#11-derivations-identity-and-matrices)
12. [`$extensions["co.ordering.editor"]` and `editor`: what the editor reads](#12-extensionscoorderingeditor-and-editor-what-the-editor-reads)
13. [Versions and compatibility](#13-versions-and-compatibility)
14. [Migrations (`co.ordering.migrate`)](#14-migrations-coorderingmigrate)
15. [Errors](#15-errors)
16. [Deviations from DTCG](#16-deviations-from-dtcg)

---

## 1. Root

```jsonc
{
  "$schema": "urn:ordering:theme-schema:1", // required, exactly this value
  "product_type": "call_center",            // a type registered in api-core
  "version": "26.0.0",                      // semver base.minor.patch
  "format": 2,                              // the format of the served theme; always 2
  "platforms": ["mobile_web"],              // optional: the platforms with their own values
  "$description": "…",                      // optional
  "$extensions": { "co.ordering.migrate": [ … ] }, // optional (§14)
  "editor": { … },                          // optional (§12)
  "tokens": { … },                          // §4
  "components": { … },                      // §5
  "visibility": { … },                      // §6
  "images": { … },                          // §7
  "pages": { … },                           // §8
  "site": { … }                             // optional (§9)
}
```

- **Required:** `$schema`, `product_type`, `version`, `format`, `tokens`,
  `components`, `visibility`, `images` and `pages`. `components`, `visibility`, `images`
  and `pages` may be `{}`; `tokens` has required groups (§4). No other root key is
  allowed.
- **`$schema`** is how api-core and the engine recognize the node format. With any other
  value the answer is `schema.format`, with no further checks. It cannot be a URL or a
  file path; see the note on autocompletion in
  [creating-a-schema.md](creating-a-schema.md#3-the-meta-schema-and-the-editor).
- **`product_type`**: one of the types in the Builder catalog (`marketplace_app`,
  `franchise_app`, `marketplace_website`, `franchise_website`, `store_app`,
  `driver_app`, `kiosk_app`, `call_center`, `dashboard`). Otherwise, `product_type.invalid`.
- **`version`**: the meta-schema accepts one to three parts (`26`, `26.0`, `26.0.0`),
  but publishing requires three (`version.semver`). The first part is the **base**: the
  product's major version (§13).
- **`format`**: always `2` (`format.invalid`).
- **`platforms`**: a list of names (`^[a-z0-9_]+$`, no repeats). Only the platforms in
  this list can have their own values in a component (§5) or in a visibility key (§6).

## 2. Nodes and groups

The schema is a tree of **groups** whose leaves are **nodes**, shaped like a
[DTCG 2025.10](https://www.designtokens.org/) token.

- **Node:** an object with `$value`, which is the **default**. It may also carry
  `$type`, `$description`, `$deprecated` (a boolean or a text with the reason) and
  `$extensions`, and nothing else.
- **Group:** any other object. Its keys that do not start with `$` are nodes or groups.
  It may carry `$type`, `$description`, `$deprecated` and `$extensions`.
- **Type inheritance:** a node without `$type` takes the one of the nearest group that
  declares it. A component, page or `site` node with no type (neither its own nor
  inherited) is rejected.
- **`$deprecated` on a group** deprecates every node it contains (§13). An empty
  `$deprecated` (`false`, `""` or `"0"`) does not deprecate.
- **Names:** `^[a-z0-9_]+$`. The keys of `visibility` and `images` are the paths the
  theme serves, so they may contain dots: up to 8 segments (`business.header.info`).
- **`$extensions`** accepts namespaced keys (`^[a-z0-9]+(\.[a-z0-9_-]+)+$`). The format
  defines two: `co.ordering` (§10, read by the engine) and `co.ordering.editor` (§12,
  read by the editor). Keys in any other namespace are kept and nobody reads them.

Example:

```jsonc
"color": {
  "$type": "color",                         // applies to the group's nodes
  "primary": { "$value": "#1E72E3" },
  "primary_pressed": {
    "$value": "#1862C5",
    "$extensions": { "co.ordering": { "derive": { "op": "shade", "from": "{color.primary}", "amount": -0.07 } } }
  }
}
```

### Aliases

A value can be an **alias** to a token: `{group.name}` (for example
`{color.primary}`, `{radius.md}`). Each type says which group it can point to (§3).
Tokens themselves do not accept aliases: their values are literals (§4).

## 3. Property types

A **property** is a node of a component, a page or `site`. Its `$type` (and, for a
`dimension`, its scale) says which values it accepts. The engine compiles it to a tuple
`[kind, default, options|null, rules?]` (§10.1), and error codes use the node's path.

| `$type` | `scale` | kind | Value |
|---|---|---|---|
| `color` | | `color` | hex (`#RGB`, `#RGBA`, `#RRGGBB`, `#RRGGBBAA`), `rgb()`/`rgba()`, `transparent`, `{color.*}` or `null` (no default of its own) |
| `role` | | `role` | a color or a role of a component's color: `{main}`, `{on}`, `{ink}`, `{subtle}`, `{pressed}` (§11.4) |
| `dimension` | `radius` | `radius` | only an alias `{radius.*}` |
| `dimension` | `size`, `icon`, `space` | the scale's | a number from 0 to 9999, or an alias to its scale (`{size.md}`) |
| `duration` | | `motion` | a number from 0 to 9999 or `{motion.*}` |
| `opacity` | | `opacity` | a number from 0 to 1 or `{opacity.*}` |
| `number` | | `number` | a number from 0 to 9999 |
| `typography` | | `text` | only `{typography.*}`; with `custom` (§12), also a style of its own |
| `fontRole` | | `font_role` | `heading` or `body` |
| `border` | | `border` | `{ width, color, style? }`, `"none"` or `null` |
| `shadow` | | `shadow` | `{shadow.*}`, a shadow object or `null` |
| `image` | | `image` | `""` or an `http(s)` URL of up to 2048 bytes |
| `boolean` | | `bool` | `true` or `false` (`null` only in a page's `footer`, §8) |
| `option` | | `option` | one of its `options` |
| `layout` | | `layout` | one of its `options`: a structural variant the code already has |
| `sections` | | `sections` | only in `pages`: the list of sections (§8) |
| `slot` | | | only in a component's `slots` (§5.3) |
| `string` | | `string` | plain text of up to 1000 characters (or its `max_length`); if not empty, it matches its `pattern` |
| `html` | | `html` | HTML the client renders as is, up to 200 000 bytes |
| `id` | | `id` | `null`, a non-negative integer or a text of up to 255 bytes (a slug) |

Details:

- **`dimension` without a scale is not a property**: it is rejected with `<path>.type`.
  For a plain number use `number`. (Tokens do use `dimension` without a scale, §4.)
- **`font` is tokens-only** (`tokens.font`). On a property it is rejected with
  `<path>.type`; to choose a component's font use `fontRole`.
- **`options`** (in `co.ordering`, §10) is required on `option` and `layout`, and
  optional on a radius (`dimension` with `scale: "radius"`), where it limits the allowed
  levels: a non-empty list of level names (`["none", "md", "full"]`, without braces). On
  any other type it is rejected with `<path>.options.invalid`. The default must be among
  them.
- **Border:** `width` is a number (0–9999) or `{border.thin}` / `{border.thick}`;
  `color`, a color, a color alias or a role (`{main}`…); `style`, `solid`, `dashed` or
  `dotted`. `"none"` is an explicit "no border" and is served as `null`.
- **Shadow (object):** `{ x, y, blur, color, opacity, spread?, elevation? }`, with `x`,
  `y` and `spread` between -128 and 128, `blur` from 0 to 9999, `color` a literal,
  `opacity` from 0 to 1 and `elevation` from 0 to 24 (Android elevation).
- **Typography (style):** `{ size, line, weight, letter_spacing?, transform? }`, with no
  family (its font role sets it). `size` from 0 to 200 (px or pt); `line` is the line
  height **as a ratio of the size**, unitless, from **0.8 to 3** (`1.5` is 21 px at
  14 px); `weight` one of 100, 200, …, 900 (integer); `letter_spacing` from -20 to 20;
  `transform` `none` or `uppercase`. A typography with `line` in px (`21`) is not valid.
- **Color with no default of its own:** a `color` or `role` with `$value: null` is
  valid: the default comes from elsewhere (a derivation, inheritance).
- **`string` rules:** `max_length` (an integer from 1 to 1000) and `pattern` (§10.2). An
  empty value counts as "unset": no `pattern` rejects it.

## 4. Tokens

`tokens` is the **common vocabulary**: the names are the same in every product and each
schema sets its defaults. `{ group: { $type?, name: node } }`.

### 4.1 Catalog

| Group | Names | `$type` (and `options`) | In the schema |
|---|---|---|---|
| `font` | `heading`, `body` | `font`, with `options` = the 10 catalog fonts, in this order: `archivo`, `inter`, `lato`, `montserrat`, `nunito`, `open_sans`, `playfair_display`, `poppins`, `raleway`, `roboto` | both |
| `typography` | headings (`heading` font): `display`, `h1`, `h2`, `h3`, `h4`; text (`body` font): `body_lg`, `body`, `body_sm`, `label`, `caption`, `button`, `overline` | `typography` | at least one |
| `color` | `primary`, `on_primary`, `primary_subtle`, `primary_pressed`, `primary_text`, `secondary`, `on_secondary`, `secondary_subtle`, `secondary_pressed`, `background`, `surface`, `surface_alt`, `surface_raised`, `overlay`, `on_overlay`, `inverse_surface`, `on_inverse`, `text_heading`, `text`, `text_muted`, `text_disabled`, `link`, `border`, `border_strong`, `divider`, `focus`, `success`, `on_success`, `success_subtle`, `success_text`, `warning`, `on_warning`, `warning_subtle`, `warning_text`, `danger`, `on_danger`, `danger_subtle`, `danger_text`, `info`, `on_info`, `info_subtle`, `disabled`, `rating`, `favorite` | `color` | at least one |
| `opacity` | `disabled`, `pressed`, `scrim` | `opacity` | at least one |
| `radius` | `none`, `xs`, `sm`, `md`, `lg`, `xl`, `xxl`, `full` | `dimension` | all |
| `size` | `sm`, `md`, `lg`, `xl` (control height) | `dimension` | all |
| `icon` | `sm`, `md`, `lg` | `dimension` | all |
| `space` | `none`, `xxs`, `xs`, `sm`, `md`, `lg`, `xl`, `xxl`, `xxxl` | `dimension` | all |
| `layout` | `page_gutter`; `mode` | `page_gutter`: `dimension`; `mode`: `option` with `options` `["original", "appointments"]` | both |
| `motion` | `fast`, `base`, `slow` | `duration` | all |
| `border` | `thin`, `thick` | `dimension` | all |
| `shadow` | `none`, `sm`, `md`, `lg`, `xl` | `shadow` | all |
| `shadow_style` | `style`, `color`, `direction`, `size` | `style`: `option` `["none", "soft", "normal", "strong", "solid"]`; `color`: `color`; `direction`: `option` `["down", "down_right", "down_left", "around"]`; `size`: `option` `["none", "sm", "md", "lg", "xl"]` | optional |
| `border_style` | `radius`, `width`, `style` | `radius`: `option` with the 8 `radius` levels; `width`: `option` `["none", "thin", "thick"]`; `style`: `option` `["solid", "dashed", "dotted"]` | optional |
| `motion_style` | `easing`, `enabled` | `easing`: `option` `["linear", "ease", "ease_in", "ease_out", "ease_in_out"]`; `enabled`: `boolean` | optional |
| `focus_ring` | `width`, `offset`, `color` | `width`: `option` `["thin", "thick"]`; `offset`: `option` with the 9 `space` levels; `color`: `option` with the schema's own colors, in its order | optional |
| `scrollbar` | `size`, `radius` | `dimension` (`number`, the type it had before, is accepted) | optional |

Rules:

- **Groups with their own names** (`color`, `typography` and `opacity`): each schema
  chooses which ones it has, from the catalog, and has at least one of each group
  (`tokens.<group>.required`). The schema can only refer to the ones it has: an alias to
  another is rejected with `<path>.default.alias`.
- **Scales** (`font`, `radius`, `size`, `icon`, `space`, `layout`, `motion`, `border` and
  `shadow`): they are complete; each missing name is `tokens.<group>.<name>.required`.
- **Optional groups** (`shadow_style`, `border_style`, `motion_style`, `focus_ring` and
  `scrollbar`): they may be missing or have only some of their names. They are the
  general Identity settings (§11.2) and the focus ring settings.
- **A name or group outside the catalog** is rejected with
  `tokens.<group>.<name>.unknown` or `tokens.<group>.unknown`.
- **Exact type and options:** each token declares (its own or inherited from the group)
  the table's `$type` and, if the table lists `options`, exactly that list, in that
  order; if the table lists no `options`, it carries none. Otherwise, `tokens.<group>.<name>.type`.
- **Literal values:** a token does not accept aliases. Colors are literals; `dimension`
  and `duration`, numbers from 0 to 9999; typographies and shadows have the shape of §3
  (`null` is no shadow); `focus_ring.color`, a color name from the catalog. Otherwise,
  `tokens.<group>.<name>.invalid`.

### 4.2 Tokens that can be narrowed (`focus_ring.color`, `focus_ring.offset`)

They are the only two tokens whose `options` a schema can declare **only in part**: a
non-empty list, without repeats, of options from its catalog (the schema's colors for
`color`, the `space` levels for `offset`). A theme can only choose among those and the
default must be among them; otherwise, `token_options.focus_ring.<name>.invalid`. A list
that is not part of the catalog is rejected with `tokens.focus_ring.<name>.type`. No
other token can be narrowed.

```jsonc
"focus_ring": {
  "width":  { "$type": "option", "$value": "thick", "$extensions": { "co.ordering": { "options": ["thin", "thick"] } } },
  "offset": { "$type": "option", "$value": "xxs",   "$extensions": { "co.ordering": { "options": ["none", "xxs", "xs", "sm"] } } },
  "color":  { "$type": "option", "$value": "primary", "$extensions": { "co.ordering": { "options": ["primary", "text", "danger"] } } }
}
```

## 5. Components

`components` is `{ component: group }`. Each component has some of these parts; any other
is rejected with `components.<c>.<part>.unknown`:

| Part | Shape | What it is |
|---|---|---|
| `base` | `{ prop: node }` | the component's properties |
| `variants`, `appearances` | `{ name: { prop: node, states?: { state: { prop: node } } } }` | variants with states (`hover`, `pressed`, `focus`, `disabled`…) |
| `sizes`, `shapes`, `colors` | `{ name: { prop: node } }` | axes without states: states belong to `variants` and `appearances`, and are validated only there |
| `slots` | `{ name: slot node }` | other components this one contains (§5.3) |
| `scope` | `{ component: changes }` | what changes in other components inside this one (§5.4) |
| `platforms` | `{ platform: { prop: node } }` | the default of a `base` property on a platform (§5.2) |

Component, variant, state and property names follow `^[a-z0-9_]+$`. The component's
`$extensions["co.ordering"]` may carry `inherits` (§5.1) and `matrix` (§11.4).

### 5.1 Inheritance (`inherits`)

`"$extensions": { "co.ordering": { "inherits": "card" } }` on `business_card`: what a
theme sets on `card` also applies to the properties `business_card` declares, except
what the theme sets on `business_card`. The parent exists, is not the same component and
does not inherit from another; otherwise, `inherits.<component>.invalid`.

It is not DTCG's `$extends`: `inherits` does not copy to the child the parent's
properties the child does not declare.

### 5.2 Platforms

`components.<c>.platforms.<platform>.<prop>` changes the default of a `base` property on
that platform. The platform must be in the root's `platforms`
(`components.<c>.platforms.<p>.unknown`), and the property must exist in `base` with the
same kind (`components.<c>.platforms.<p>.<prop>.invalid`). It is served in
`platforms.<platform>.components.<c>.base`.

### 5.3 Slots

```jsonc
"slots": {
  "cta": { "$type": "slot", "$value": { "component": "button", "variant": "primary", "size": "md" } }
}
```

`component` is another component of the schema that has no `scope` and whose slots, if
it has any, name components without slots. The possible axes are `size`, `variant`,
`appearance`, `color` and `shape`, and each one is `null` or a key of the component's
matching part (`sizes`, `variants`…). An axis the component does not have is
`.unknown`; a value that is not there, `.invalid`.

### 5.4 Scope

A component that groups others (a card with buttons, a header) can change their defaults
inside it:

```jsonc
"scope": {
  "button": { "base": { "radius": { "$type": "dimension", "$value": "{radius.full}", "$extensions": { "co.ordering": { "scale": "radius" } } } } },
  "input": {}
}
```

Each component in `scope` exists, is not the group itself and has no `scope`. Each
property it changes exists in that component (`.unknown`), with the same kind and the
same `options` (`.invalid`), and its default is valid (`.default.invalid`). `{}` if
nothing changes.

## 6. Visibility

```jsonc
"visibility": {
  "$type": "boolean",
  "header.search": { "$value": true },
  "header.logo": {
    "$value": true,
    "$extensions": { "co.ordering": { "platforms": { "mobile_web": false } } }
  }
}
```

- Each key is a boolean node: `$value` `true` or `false` (`null` is not valid).
- `co.ordering.platforms`: its default on each platform that shows it differently. It is
  served in `platforms.<platform>.visibility.<key>` and a theme changes it there;
  without it, the platform follows the key's default. A non-empty map from platforms
  declared at the root to booleans; otherwise, `visibility.<key>.platforms.invalid`.

## 7. Images

```jsonc
"images": {
  "$type": "image",
  "logo": {
    "$value": "",
    "$extensions": { "co.ordering": {
      "formats": ["png", "svg", "webp"],
      "hints": { "size": [800, 200], "ratio": "4:1", "alpha": true, "max_kb": 200, "safe": "3:1" }
    } }
  }
}
```

- `$value`: `""` (none) or an `http(s)` URL.
- `formats`: the formats it accepts, a non-empty list without repeats of `jpg`, `png`,
  `webp`, `avif`, `ico` and `svg`. Without `formats` (or with `null`), api-core accepts
  `jpg`, `png`, `webp` and `avif`. SVG and ICO are served as uploaded; the rest can be
  resized or converted. Otherwise, `images.<key>.formats.invalid`.
- `hints`: what the editor recommends on upload. All optional:
  - `size`: `[width, height]` in px, integers from 1 to 10 000;
  - `ratio`: the aspect ratio, `"w:h"` (integers from 1 to 9999);
  - `alpha`: whether it may have transparency;
  - `max_kb`: its maximum weight in kB (integer, up to 5000);
  - `safe`: the ratio of the central area every container shows, however it crops: the
    important part goes inside it.

  Otherwise, `images.<key>.hints.invalid`.

## 8. Pages

```jsonc
"pages": {
  "home": {
    "footer": { "$type": "boolean", "$value": null },
    "sections": {
      "$type": "sections",
      "$value": [
        { "key": "hero", "visible": true, "hideable": false, "group": null },
        { "key": "featured", "visible": true, "hideable": true, "group": "content" },
        { "key": "recent", "visible": false, "hideable": true, "group": "content" }
      ]
    }
  }
}
```

Each page has exactly `footer` and `sections` (any other field is
`pages.<p>.<field>.unknown`; if one is missing, `pages.<p>.<field>.invalid`):

- `footer`: a boolean or `null`, which follows `visibility.footer`.
- `sections`: the list of sections in their default order. Each section carries `key`
  (unique in the page, `.duplicate`), `visible`, `hideable` and `group` (a name or
  `null`), and nothing else. A section that cannot be hidden (`hideable: false`) must be
  visible.

The theme stores sections as `[{ key, visible }]` in the order it chose. When resolving
them, a section moves only among those of its same `group`; with `group: null` it stays
in place. A section the theme does not mention keeps its place and its default
visibility; one that cannot be hidden is always visible.

## 9. Site settings (`site`)

`site` describes the settings that are not style: they belong **to the site, not to the
theme**. A site has several themes, and activating another does not change these
settings. It is a tree of groups and nodes, with the same property types as §3 (except
`sections` and `slot`), and each node declares its `$type`.

```jsonc
"site": {
  "name": { "$type": "string", "$value": "", "$extensions": { "co.ordering": { "max_length": 60 } } },
  "third_party_code": {
    "$type": "html",
    "head": { "$value": "" },
    "body": { "$value": "" }
  }
}
```

- api-core stores the values on the site, validated against this block, and serves them
  in `values.site` resolved over the defaults, whatever the active theme. A theme does
  not carry them.
- `mode`, `business_slug`, `franchise_slug` and `franchise_id` are the site's **scope**:
  api-core computes them from the site (with a business, `single_store`; with a
  franchise, `franchise`; with neither, `marketplace`) and they are never stored as a
  setting. A product that needs them declares them to get their types and defaults.
- An empty `name` is served with the site's name in the Builder.
- `siteDefaults(compile(schema).schema)` returns the block's defaults.

## 10. `$extensions["co.ordering"]`: what the engine reads

| Key | Where | What it is |
|---|---|---|
| `scale` | `dimension` node | the scale of its levels: `radius`, `size`, `icon` or `space` |
| `options` | `option`, `layout`, radius, token | the options (§3, §4); a non-empty list |
| `inherits` | component | its parent (§5.1) |
| `follows` | component property | `shadow`, `radius` or `border`: follows Identity (§11.2) |
| `derive` | `tokens.color` color, variant property | how it is derived (§11.1, §11.3) |
| `matrix` | component | `{ into, roles }` (§11.4) |
| `platforms` | `visibility` key | its default per platform (§6) |
| `formats`, `hints` | image | §7 |
| `max_length`, `pattern` | `string` | its maximum length in characters (1–1000) and a regular expression in the subset ECMAScript and PCRE read the same way (§10.2) |

No other keys are allowed.

### 10.1 The tuple

The engine compiles each property to `[kind, default, options|null, rules?]`:

- `kind` comes from `$type` and `scale` (§3);
- `options`, from `co.ordering.options` (or `null` if there are none but there are rules);
- `rules`, a map with whatever further restricts the value: `custom` (from
  `co.ordering.editor.custom`, on a `typography`), `max_length` and `pattern` (on a
  `string`). A rule on a type that is not its own is rejected with `<path>.rules.invalid`:
  `custom` only on `typography` and with `true`; `max_length` and `pattern` only on
  `string`, `pattern` non-empty, within §10.2's subset and compiling.

This package's `compile(schema)` returns those tuples, and they are what `check()` and
`resolve()` read.

### 10.2 `pattern`

A `pattern` is a regular expression without delimiters or flags. The editor checks it in
JavaScript (`new RegExp(pattern, 'u')`) and the Ordering.co API in PHP (PCRE), so it must
be written in the subset both read the same way; anything else is `<path>.rules.invalid`.
The subset:

- **Literal characters:** any character except the metacharacters
  `\ ^ $ . | ? * + ( ) [ ] { }` and the control characters (U+0000 to U+001F, U+007F).
  A `]`, `{` or `}` must be escaped even alone (JavaScript rejects it, PCRE takes it as a
  literal); a newline, carriage return or tab is written `\n`, `\r`, `\t`.
- **Escapes:** `\d \D \w \W`, `\n \r \t`, and an escaped metacharacter or `/`. `\-`
  only inside a class. No other escape: not `\s`/`\S` (whitespace by a different list in
  each), `\b`/`\B` (see below), `\A \z \Z \G \K \Q \E \h \v \R \X`, `\p{…}`, `\x…`,
  `\u…`, `\c…`, nor backreferences (`\1`, `\k<…>`).
- **`.`**, the anchors **`^`** and **`$`** (anywhere, never quantified) and **`|`**.
- **Groups:** `( … )`, `(?: … )` and the lookaheads `(?= … )` and `(?! … )`, which are
  never quantified. Not lookbehinds, named groups (`(?<name>`, `(?P<`), inline flags
  (`(?i)`), atomic groups (`(?>`), conditionals or comments. Parentheses balance.
- **Quantifiers:** `?`, `*`, `+`, `{n}`, `{n,}` and `{n,m}` (digits only, n ≤ m ≤ 65535,
  PCRE's largest), each optionally followed by `?` (lazy). Not possessive (`a++`), not
  after another quantifier, not with nothing to repeat; a `{` that is no such quantifier
  is invalid (`a{,3}`, `a{x}`).
- **Classes:** `[ … ]` and `[^ … ]`, not empty (`[]` and `[^]` are invalid), of literals
  other than `\`, `[` and `]` (so no POSIX `[:alpha:]`), the escapes above except `\D`,
  `\W`, `\b` and `\B`, and ranges `a-z` whose ends are single characters (a literal or
  an escaped one) in order. `-` is a literal only first, last or escaped.

Within the subset, the two engines match the same values:

- `$` matches only at the very end of the value, never before a final newline (PHP
  compiles the pattern with the `D` modifier): `^\d{6,12}$` refuses `"123456\n"`.
- `\d` is `[0-9]` and `\w` is `[A-Za-z0-9_]`, ASCII as in JavaScript, although PHP's
  `u` modifier would make them Unicode: the engine writes them as those classes before
  compiling (and `\D`, `\W` as their negations), which is why the negations do not go
  in a class and why `\b` and `\B`, word boundaries by that `\w`, are outside the
  subset.
- `.` matches any character except `\n`, `\r`, U+2028 and U+2029, as in JavaScript:
  PCRE's `.` only leaves out `\n`, so the engine writes it as that class.

## 11. Derivations, Identity and matrices

The rules that make one value follow another are declared by the schema. The engine has
its own rules for a schema that declares none (§11.5); as soon as the schema declares a
rule of one category, the engine's rules of that category stop applying.

### 11.1 Derived colors

On a `tokens.color` color, `co.ordering.derive` says how it is derived when the theme
does not set it:

| `op` | Fields | Result |
|---|---|---|
| `same` | `from` | the same color |
| `shade` | `from`, `amount` (-1 to 1) | lighter in HSL (darker if negative) |
| `mix` | `from`, `over`, `amount` (-1 to 1) | `amount` of the color over `over` |
| `legible` | `from`, `over`, `contrast?` (1–21, default 4.5), `step?` (0–1, default 0.01) | moves toward black or white until it has `contrast` over `over` |

`from` and `over` are aliases to schema colors (`{color.primary}`). `when` says when it
is derived:

- `changed` (default): if the color is missing or if any of its source colors is no
  longer at its default. While nothing changes its `$value` is shown, which may be
  hand-picked;
- `missing`: only if the color is missing (`null`);
- `always`: always.

A rule with a color that does not exist, an unknown operation or a `from`/`over` that is
not a schema color is rejected with `derive.<color>.invalid`.

### 11.2 Identity (`follows`)

Identity is the theme's general settings: `tokens.shadow_style` (style, color, direction
and size of shadows) and `tokens.border_style` (radius, width and style of borders). A
component property with `co.ordering.follows` takes its default from them:

- `shadow`: the level `tokens.shadow_style.size`;
- `radius`: the level `tokens.border_style.radius`, unless the radius's `options` do not
  include it (then its own `$value` is shown);
- `border`: with `tokens.border_style.width` `none`, no border; with a level, a border of
  that width and of the default's color (or `{color.border}` if it has none); and
  `tokens.border_style.style` for every border there is.

`follows` must match the property's kind, and is not valid in states
(`follows.<path>.invalid`). What the theme sets on the component goes on top. By
convention, the `$value` of a shadow or a radius that follows Identity is what Identity
gives with its defaults, so the schema reads the same as what is resolved.
`shadow_style.style`, `color` and `direction` also rebuild every level of
`tokens.shadow`.

### 11.3 Derived component values

On a property inside `variants` (also in its states), `co.ordering.derive` says how it
is derived from another property of the same variant (or of its `base`) when the theme
does not set it. `from` and `by` are property names, so the rule applies equally to the
component and to its copy inside a group's `scope`.

| `op` | Fields | Result |
|---|---|---|
| `step` | `from` (a shadow), `offset` (integer, -4 to 4) | the shadow `offset` levels from the one in `from` (a state's from the resting one) |
| `shade` | `from` (a color), `amount` | the color in `from`, lighter or darker (the background of a `pressed` state) |
| `inset` | `from` (a radius), `by` (a distance) | `from` minus `by`, not below 0; a `full` radius stays `full` |

`when` as in §11.1. A rule outside `variants`, on a property that does not exist or with
a `from`/`by` the variant does not have is rejected with
`component_derive.<c>.<path>.invalid`.

### 11.4 Matrices (`matrix`)

A component with `colors` and `appearances` (the button: `primary`, `neutral`… by
`solid`, `outline`, `ghost`…) can declare
`"matrix": { "into": "variants", "roles": { … } }`. The engine paints each color in each
appearance and writes the result into `into` (`variants.<color>.<appearance>`), which
cannot be a part the component already has (`matrix.<c>.invalid`).

- Each color in `colors` declares its **roles**: `main`, `on` and, optionally, `ink`,
  `subtle` and `pressed`.
- Each appearance uses the roles as values (`"background": "{main}"`,
  `"border": { "width": 1, "color": "{ink}" }`, type `role`).
- `roles` says how each role is derived when the color does not have it, or when the
  theme changes what it derives from: rules like those of §11.1, with `from` the name of
  a role (`"from": "main"`) and `over` a color alias.

### 11.5 The engine's rules

A schema that declares no rules uses the engine's, which are the ones the first web
schema had. A new schema should declare its own:

- **Colors** (if no color has `derive`): `primary_pressed` and `secondary_pressed`
  (`shade` -0.07 of their color), `primary_subtle`, `secondary_subtle`, `success_subtle`,
  `warning_subtle`, `danger_subtle` and `info_subtle` (`mix` 0.12 over `background`),
  `focus` (`same` as `primary`) and `primary_text` (`legible` over `background`).
  **Note:** the engine adds these colors to the resolved theme even if the schema does
  not have them. Declaring a single `derive` is enough to stop it.
- **Identity** (if no property has `follows`): shadows and borders that are an alias or
  `null` follow Identity, except in states, platforms, the shadows of whatever has a
  `transparent` background and the borders of appearances, and so do radii that are an
  alias other than `none` and `full`.
- **Components** (if no variant has `derive` and no component has `matrix`): each
  state's shadow at the same level distance as its default (`step`), the background of a
  `pressed` state (`shade` -0.07), `tab_radius` (`inset` from `radius` by `inset`) and a
  matrix `into: "variants"` on each component with `colors` and `appearances` (`ink` =
  `main`; `subtle`, `mix` 0.12 over `background`; `pressed`, `shade` -0.07).

## 12. `$extensions["co.ordering.editor"]` and `editor`: what the editor reads

None of this changes how a theme is resolved, with three exceptions: `custom` (§3),
`min`/`max` (a numeric default outside them is rejected with
`<path>.default_out_of_range`, in `tokens`, `components`, `pages` and `site`) and `alpha`
(below). Everything is optional: when it is missing, the editor follows its usual rule.
The meta-schema validates the shape; keys not in this list are rejected. What the shape
cannot say is checked on publish too (§12.3).

### 12.1 On each node or group

- **Texts:** `label` and `info`, as `{ key, default, params? }`. `key` is an i18n key
  (`^[A-Z0-9_]+$`, in the Dashboard `BUILDER_THEME_*`; reuse the ones already
  translated), `default` the English text and `params` the values the key interpolates
  (`_color_`): a text, or a list of texts. A node without `label` takes the one from
  `editor.props` by property name; if there is none either, the editor humanizes the key.
- **Organization:** `group` (a name) and `order` (an integer).
- **Control:** `control`, from a closed catalog: `color`, `level`, `range`, `select`,
  `segmented`, `toggle`, `border`, `shadow`, `typography`, `font`, `image`,
  `sortable_sections`, `text`, `html`, `matrix`, `cards` and `scope`.
- **Ranges:** `min`, `max`, `step` (> 0) and `unit` (`px`, `ms`, `%` or `x`). On a
  `level` they are the range of the custom value. An opacity is stored from 0 to 1 with
  `unit` `%`: the editor shows it ×100.
- **Options:** `options` as `[{ value, label?, info?, drawing?, label_platform?,
  weights? }]`. `weights`, on an option of a `font` control, are the weights the font has.
- **Fields of a compound value:** `fields`, `{ field: { label, info, control, min,
  max, step, unit, options } }`: the fields of a typography style or the axes of a slot.
- **Conditions:** `visible_if` (hides) and `disabled_if` (disables), as
  `{ path, op, value? }`, with `op` among `eq`, `neq`, `in`, `not_in`, `truthy` and
  `falsy`. The client evaluates them over the resolved values.
- **State texts:** `info_off` (the note when a toggle is off), `labels.unset` (the
  option that follows the resting state), `labels.none` (the "no…" option) and
  `platform.label` / `platform.info` (the texts when editing a platform).
- **Free text:** `placeholder` (an example while it is empty) and `multiline`.
- **`pair`:** the path that goes on the same row (a color and the text on it).
- **`groups`:** the cards in which a component or a part is shown: `key`, `label`,
  `info`, `paths`, `open`, `control`, `toggle` (`{ path, label }`, a toggle in its
  header), `hidden` (not shown; its values are still stored and served), `part` and
  `placement` (the component tab it goes in and whether it goes `before` or `after` its
  rows), and `ordered` (`ascending` or `descending`: each value of its paths is at least,
  or at most, the previous one; a typography is compared by its size).
- **`hidden`:** not shown, because another control paints it or it is derived.
- **`readonly`:** shown with its resolved value and not editable. A theme may have it
  (api-core validates and resolves it like any other).
- **`custom`** (on a `typography` with a `level` control): the editor offers the scale's
  levels and a custom one, and the value can be a style of its own besides the alias.
- **`alpha`:** a color that may be translucent; the picker shows its opacity and stores
  `rgba(r,g,b,a)` (no spaces, `a` with up to 2 decimals) if it is translucent,
  `transparent` if the opacity is 0 and `#RRGGBB` if it is opaque. If the schema marks
  `alpha` on at least one node of `tokens` or `components`, api-core rejects **when
  saving a theme** a translucent value (or an alias to one) on the colors that do not
  have it (`<path>.alpha`). A schema that marks none restricts nothing.
- **On a component, `parts`:** `{ part: { label } }`, the custom name of one of its
  tabs.
- **On a `sections` node, `section_groups`:** `[{ key, label, info? }]`, the blocks
  within which its sections move (the `group` of §8), in page order.
- **`advanced`**, **`related`** (paths shown next to the node) and **`preview`**
  `{ page, path }`.

### 12.2 The root `editor` block

- **`sections`:** the editor's sections, each with `key`, `label`, `info`, `parent`,
  `groups` (like the ones above, with `paths`), `reset` (the paths it restores), `store`
  (`theme`, by default, or `site`: it is stored in the site's settings) and `layout`
  (`list`, a list of components or pages with each one's detail, or `cards`, the
  section's cards). A group of the visibility section may carry `pages` or
  `components`: the pages or components in which the editor shows it, instead of in a
  section of its own.
- **`section_groups`:** `[{ key, label }]`, the groups of sections (on the web,
  Identity, Advanced and Website).
- **`parts`:** a component's tabs, each with `key`, `label`, `label_states`,
  `label_styles`, `sources` (the parts it joins; by default its own), `choices` (one card
  per option; `false` shows the whole part) and `nested` (`false`: not offered inside a
  group's `scope`).
- **`labels`:** `platforms` (the name of each platform). `page_section_groups` is no
  longer read by any editor; it is kept so that an earlier schema stays valid.
- **`props`:** `{ property: { label, info } }`, the label by property name.

### 12.3 Rules

`check()`, given the schema in nodes, also checks what the editor metadata says against
the rest of the schema (`EditorRules::check()` in PHP, `checkEditor()` in TypeScript).
Each error is `<path>.<rule>`, in the order of this list, and each code is reported once:
two unknown pages in the same group give one `page_unknown`. The path is the node or
group (`tokens.color.overlay`), the editor's group (`editor.sections.10.groups.2`), a
component's card (`components.bottom_nav.$extensions.co.ordering.editor.groups.0`), a
page's section (`pages.home.sections.hero`), or a node's field
(`tokens.typography.h1.fields.line`).

What each rule walks:

- `alpha`, `custom`, limits, line heights, the blocks of a page, fonts and texts: the
  nodes (and, for `alpha`, `custom` and line heights, the groups) of `tokens`,
  `components`, `pages` and `site`, each with its own `$type` or its group's;
- platforms: those four blocks plus `visibility` and `images`;
- where a visibility group is shown and every visibility key in one group: the groups
  of `editor.sections`, the keys of `visibility` and, for components, each component's
  cards;
- a component's order: each component's nodes (its `scope` aside) and its cards;
- the rows of the editor's sections and scales: the groups (and, for scales, the
  components' cards) and the nodes at the paths they list, in any block;
- a page's sections: the default of each page's `sections`;
- visibility keys are booleans: the keys of `visibility`.

The rules, in the order they run:

- **`alpha`.** A color node whose default is translucent (`transparent`, `#RGBA` or
  `#RRGGBBAA` below full alpha, `rgba()`/`hsla()` or `rgb()`/`hsl()` with `/ a`, an alpha
  below 1 or 100%), or an alias to a token marked `alpha` or translucent, says `alpha:
  true` (`alpha_required`). Nothing that is not a color node says `alpha`, a group
  included (`alpha_not_color`).
- **Platforms.** `co.ordering.platforms` only on a visibility key
  (`platforms_not_visibility`); what it says there is §6's
  (`visibility.<key>.platforms.invalid`).
- **Where a visibility group is shown: pages.** Only a group of the `visibility`
  section says `pages` (`pages_not_visibility`), naming pages of the schema
  (`page_unknown`); a page gets no two groups of the same key (`page_group_repeated`).
- **Every visibility key in one group.** Each visibility key is in exactly one group of
  the editor's sections, as a row (`paths`) or a card's switch (`toggle.path`)
  (`in_no_editor_group`, `in_several_editor_groups`), and the groups list only
  visibility keys that exist (`not_visibility_key`, at the path listed).
- **Where a visibility group is shown: components.** Only a group of the `visibility`
  section says `components` (`components_not_visibility`), not together with `pages`
  (`pages_and_components`), naming components of the schema (`component_unknown`); a
  component gets no two groups of the same key (`component_group_repeated`). The shape
  of the `pages` and `components` lists (at least one name, no repeats) is the
  meta-schema's. A component's own card then does not list a path such a group already
  shows in that component, unless as its `toggle` (`shown_by_visibility_group`, at the
  card).
- **A component's order.** Under a component (its `scope` aside), the nodes of one group
  of the schema that share a card (`group`, or none: the rows) have different `order`s
  (`order_repeated`); in a component with cards (`groups`), every node has an `order`
  (`order_missing`). Each card lists paths that exist (`path_unknown`) and its
  component's nodes in their `order` (`order_decreasing`); a base node it lists says its
  key as `group` (`group_mismatch`, at the node), and a base node that says a `group` is
  listed by a card (`in_no_card`).
- **The rows of the editor's sections.** In a group of `editor.sections`, the paths that
  say an `order` say it in the order they are listed, from 1, each the row before (a
  pair shares it) or the next (`order_not_consecutive`), and the `group` they say is that
  group's key (`group_mismatch`, at the node).
- **`custom`.** Only on a typography node with control `level` (`custom_not_level`); every
  such node of `components` says `custom: true` (`custom_required`). `custom: true` on a
  property of another type is §10.1's `<path>.rules.invalid` instead.
- **Limits.** A field of a composite default (`fields`, a typography style's) has its
  `min` at most its `max` (`<path>.fields.<field>.min_over_max`) and the default's number
  within them (`<path>.fields.<field>.default_out_of_range`). A `range`, and a `level`
  that says a `unit` (it takes a custom number), says a numeric `min` and `max`
  (`limits_required`), `min` at most `max` (`min_over_max`); its default outside them is
  `<path>.default_out_of_range` (above).
- **Line heights.** The `line` field of a typography's editor (a level's, the group's, a
  component's) is a ratio of the size: `unit` `x` (`<path>.fields.line.unit_not_x`) and
  its `min` and `max` within 0.8 and 3 (`<path>.fields.line.limits_not_ratio`). The
  fields of the `tokens.typography` group, the custom typography's, are at least as wide
  as each level's (`tokens.typography.fields.<field>.min_not_widest`,
  `max_not_widest`). A default's `line` is §3's.
- **Scales.** A group or card that says `ordered` lists only nodes (`ordered_not_node`)
  whose default is a number or a typography with a size (`ordered_not_number`), each at
  least (`ascending`) or at most (`descending`) the one before (`not_ordered`).
- **The blocks of a page.** `section_groups` only on a `sections` node
  (`section_groups_not_sections`); one whose sections have a `group` says it
  (`section_groups_required`): its keys without repeats (`section_groups_repeated`), and
  exactly the groups of its sections in the order they first come
  (`section_groups_mismatch`).
- **Fonts.** `weights` only on an option of a `font` control (`weights_not_font`). A font
  control that lists `options` lists each font of the node's `co.ordering.options` once
  (`font_listed_twice`), no other (`font_unknown`) and none less (`font_unlisted`), and
  each option's weights go up (`weights_not_increasing`).
- **Texts.** `max_length` and `pattern` only on a `string` (`rules_not_string`, for a
  token or a slot; on a property it is §10.1's `<path>.rules.invalid`, and a default
  longer than `max_length` or off its `pattern` is `<path>.default.invalid`).
  `placeholder` and `multiline` only on a `text` control (`placeholder_not_text`,
  `multiline_not_text`).
- **A page's sections.** A `group` holds at least two sections, since one alone cannot
  move (`alone_in_group`, at the section); a section's `key` and `group` start with a
  letter (`not_snake_case`). The rest of their shape is §8's.
- **Visibility keys are booleans.** Each one's `$type`, its own or its block's, is
  `boolean` (`not_boolean`); its default is §6's.

## 13. Versions and compatibility

`version` is `base.minor.patch`. The **base** is the product's major version (26); a v2
theme is always resolved with **the latest published version of its base**, not with
the one it was created with. So, within a base, each version must serve the themes saved
with the earlier ones. On publish, api-core compares the schema with the latest version
of its base:

- **Order:** the version is greater than the latest of its base (`26.0` counts as
  `26.0.0`). Otherwise, `schema.version_order`.
- **An already published version** does not change: publishing it with different content
  is `schema.version_exists`; with the same, `unchanged`.
- **A patch** (`26.1.0` → `26.1.1`) only changes texts (`$description`) and editor
  metadata (`co.ordering.editor`, the `editor` block). Changing a key, a type, an option,
  a default, a rule, a `$deprecated` or a migration is `schema.patch_changes_values`.
- **A minor** (`26.1.x` → `26.2.0`) can add keys and options and change defaults. It
  cannot:
  - remove a key (`<path>.removed`), unless the previous version marked it
    `$deprecated` or a migration moves it (§14);
  - change its `$type` (`<path>.type_changed`);
  - change its `scale` (`<path>.scale_changed`): moving a `dimension` from one scale to
    another, or removing or giving it one, changes the scale in which a saved theme names
    its level;
  - remove one of its options (`<path>.option_removed`);
  - remove a migration that was already there (`schema.migration_removed`): within a
    base the list only grows.
- **A new base** (`27.0.0`) is not compared with the previous one: it can change
  everything, but themes saved with the previous base do not follow it.

The compared paths are those of each node (`tokens.color.primary`,
`components.button.base.radius`, `visibility.header.logo`…), with the `$type` of the node
or its group and the node's own `scale` (a group does not inherit it).

Every valid response carries `changes`: `{ added, removed, migrated, deprecated }`, with
paths.

**Development mode.** An api-core environment with `THEME_SCHEMA_DEVELOPMENT=true` (local
and staging while no site uses v2 themes) replaces an already published version
(`replaced`) and does not reject for order or compatibility, though it still reports
`changes`. Everything else is validated the same way. `validate(schema, { previous,
development: true })` does the same.

## 14. Migrations (`co.ordering.migrate`)

At the root, `$extensions["co.ordering.migrate"]` is a list of renames and moves of a
theme's values:

```jsonc
"$extensions": {
  "co.ordering.migrate": [
    { "from": "components.button.base.padding_x", "to": "components.button.base.padding_inline" }
  ]
}
```

- api-core applies them in order every time it reads or saves a theme, and this
  package's `resolve()` does too, before anything else (before `inherits`): the value in
  `from` moves to `to` if the theme does not set `to`, and `from` is deleted either way.
- Each migration needs a `from` the schema **no longer has** and a `to` it does have, in
  the same section (the same first segment), and different from each other. Otherwise,
  `migrate.<index>.invalid`.
- Within a base the list only grows (§13).

**What works today.** Validation recognizes paths under `components.*`, `site.*` and a
few tokens, and in practice:

- **`components.*`** works: renaming or moving a property, a variant or a state.
- **`tokens.*`** does not: a `from` of `tokens.color.*` (or `tokens.font.*`,
  `tokens.layout.mode`) is rejected because the name is still in the catalog, and no
  other token is recognized as a target.
- **`visibility.*`, `images.*` and `pages.*`** are rejected: validation does not yet
  recognize their paths.
- **`site.*`** passes validation, but site settings are not in the theme and the
  migration does not move them.

What cannot be declared needs a data migration in api-core
([creating-a-schema.md §6](creating-a-schema.md#6-versions-and-migrations)).

## 15. Errors

`validate()` and api-core answer `{ code, errors }`, with `errors` as
`[{ path, code }]`. Each error's `code` is the path followed by the rule. The first
stage that fails gives the overall `code`:

| `code` | HTTP on publish | Stage |
|---|---|---|
| `schema.format` | 422 | `$schema` is not `urn:ordering:theme-schema:1` |
| `schema.invalid` | 422 | the meta-schema, the compiler, the rules or the semver |
| `schema.version_exists` | 409 | the version is already published with different content (api-core only) |
| `schema.version_order` | 409 | the version is not greater than the latest of its base |
| `schema.incompatible` | 422 | it would break the themes of the latest version of its base |

Rules of each error:

| Suffix | Meaning |
|---|---|
| `<path>.schema` (`schema` at the root) | does not satisfy the meta-schema |
| `.type` | a `$type` (and `scale`) that is no property type, or a token without the catalog's type or options |
| `.required` | a required group or token is missing |
| `.unknown` | a group, token, part, platform, property or axis that does not exist |
| `.invalid` | a value with the wrong shape (in `product_type`, `version`, `format`, a property, a section…) |
| `.default.invalid` | the default is not a value of its type |
| `.default.alias` | the default is an alias to a token the schema does not have |
| `.options.invalid` | `options` where it does not belong, or missing where it does |
| `.rules.invalid` | `custom`, `max_length` or `pattern` on a type that is not its own, or a `pattern` outside §10.2's subset |
| `.platforms.invalid`, `.formats.invalid`, `.hints.invalid` | §6, §7 |
| `.duplicate` | a repeated page section |
| `token_options.<token>.invalid` | §4.2 |
| `inherits.<c>.invalid`, `follows.<path>.invalid`, `derive.<color>.invalid`, `component_derive.<c>.<path>.invalid`, `matrix.<c>.invalid` | §5.1, §11 |
| `migrate.<n>.invalid` | §14 |
| `.default_out_of_range` | a numeric default outside its editor's `min`/`max` |
| `.alpha_required`, `.alpha_not_color` | §12.3, `alpha` |
| `.platforms_not_visibility` | §12.3, platforms |
| `.pages_not_visibility`, `.page_unknown`, `.page_group_repeated`, `.components_not_visibility`, `.pages_and_components`, `.component_unknown`, `.component_group_repeated`, `.shown_by_visibility_group` | §12.3, where a visibility group is shown |
| `.in_no_editor_group`, `.in_several_editor_groups`, `.not_visibility_key` | §12.3, every visibility key in one group |
| `.order_missing`, `.order_repeated`, `.path_unknown`, `.order_decreasing`, `.group_mismatch`, `.in_no_card` | §12.3, a component's order |
| `.order_not_consecutive`, `.group_mismatch` | §12.3, the rows of the editor's sections |
| `.custom_not_level`, `.custom_required` | §12.3, `custom` |
| `.limits_required`, `.min_over_max`, `.fields.<field>.min_over_max`, `.fields.<field>.default_out_of_range` | §12.3, limits |
| `.fields.line.unit_not_x`, `.fields.line.limits_not_ratio`, `tokens.typography.fields.<field>.min_not_widest`, `.max_not_widest` | §12.3, line heights |
| `.ordered_not_node`, `.ordered_not_number`, `.not_ordered` | §12.3, scales |
| `.section_groups_not_sections`, `.section_groups_required`, `.section_groups_repeated`, `.section_groups_mismatch` | §12.3, the blocks of a page |
| `.weights_not_font`, `.font_listed_twice`, `.font_unknown`, `.font_unlisted`, `.weights_not_increasing` | §12.3, fonts |
| `.rules_not_string`, `.placeholder_not_text`, `.multiline_not_text` | §12.3, texts |
| `.alone_in_group`, `.not_snake_case` | §12.3, a page's sections |
| `.not_boolean` | §12.3, visibility keys |
| `version.semver` | the version is not `base.minor.patch` |
| `.removed`, `.type_changed`, `.scale_changed`, `.option_removed`, `schema.patch_changes_values`, `schema.migration_removed` | §13 |

The paths of compiler and rule errors are those of the compiled tuple: the same as the
schema's, plus `.default`, `.options` or `.rules` at the end; those of the editor's rules,
the ones §12.3 says. Meta-schema errors may name
different paths depending on the validator (opis in api-core, Ajv in CI); whether the
schema passes or not is the same.

## 16. Deviations from DTCG

They are deliberate:

- colors in hex or rgba and unitless numbers (px on the web, pt on native);
- custom types: `role`, `option`, `layout`, `image`, `boolean`, `sections`, `slot`,
  `fontRole`, `font`, `opacity`, `string`, `html` and `id`;
- keys with dots in `visibility` and `images`;
- `inherits` instead of `$extends` (§5.1).

An exporter to strict DTCG can convert them if a tool requires it.
