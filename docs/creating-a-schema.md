# Creating a product's theme schema

A step-by-step guide for a product team (a native app, the Call Center, a website)
creating its theme schema from scratch. The full format is in
[schema-format.md](schema-format.md); this is the path.

The schema is a JSON file in the product's repo (for example `theme.schema.json`), next
to the code that reads it. Whoever adds a component or a key in the code adds it to the
schema in the same PR. The product's CI validates it and publishes it to api-core, which
is the authority: it validates it again, stores each version and uses it to resolve the
themes of the sites of that product type.

1. [Register the product type in api-core](#1-register-the-product-type-in-api-core)
2. [Start from the common vocabulary](#2-start-from-the-common-vocabulary)
3. [The meta-schema and the editor](#3-the-meta-schema-and-the-editor)
4. [Validate locally](#4-validate-locally)
5. [Publish](#5-publish)
6. [Versions, migrations and new bases](#6-versions-migrations-and-new-bases)
7. [Using the result: `resolve()` and `defaults()`](#7-using-the-result-resolve-and-defaults)
8. [Complete minimal example](#8-complete-minimal-example)

## 1. Register the product type in api-core

`product_type` must be a type in the Builder catalog. Today they are:

`marketplace_app`, `franchise_app`, `marketplace_website`, `franchise_website`,
`store_app`, `driver_app`, `kiosk_app`, `call_center` and `dashboard`.

- **If your product is one of them, there is nothing to do in api-core.** Use that value.
- **If it is a new type**, api-core needs a PR that adds a case to
  `App\Enums\BuilderProductTypeEnum` (`app/Enums/BuilderProductTypeEnum.php`), with a
  value `^[a-z0-9_]+$` of up to 40 characters. That PR decides whether it is an app
  (`appValues()`, compiled from a Builder base) or a website (`webValues()`), and, if it
  is a website, its `webSiteType()` and its `temporalDomainSuffix()`: that belongs to the
  Builder, not to the theme. Until that PR is deployed, api-core rejects the schema with
  `product_type.invalid`.

  The engine has its own copy of the list (`PRODUCT_TYPES` in TypeScript,
  `ThemeSchemas::PRODUCT_TYPES` in PHP), and api-core validates schemas with the PHP
  engine, so a new type also needs an engine version that adds it to both and an
  api-core that depends on it. Until then `validate()` answers `product_type.invalid`,
  locally and in api-core.

You also need, for each api-core environment you publish to (local, staging,
production), its **publish key** (`THEME_PUBLISH_KEY` in that api-core). Whoever runs
api-core provides it; it goes in the CI secrets and never in the repo. An environment
with no key configured rejects every publish.

## 2. Start from the common vocabulary

Token names are the same in every product
([schema-format.md §4](schema-format.md#4-tokens)): that way a preset or a brand color
means the same thing on the web, in the app and in the Call Center. What each product
decides is the defaults and which part of the vocabulary it uses:

1. **Complete scales.** Copy `font`, `radius`, `size`, `icon`, `space`, `layout`,
   `motion`, `border` and `shadow` with all their names (the example in §8 has them with
   the web's defaults) and adjust the values. They are unitless numbers: px on the web,
   pt on native.
2. **Colors, typographies and opacities.** Choose from the catalog only the ones your
   code reads, at least one of each group. Do not invent names: one outside the catalog
   is rejected (`tokens.color.brand.unknown`). If you are missing one, it is added to
   the format's catalog (here and in api-core) before you use it.
3. **Exact types and options.** Each token carries the catalog's `$type`; those of
   `font`, `layout.mode` and the Identity groups also carry exactly their `options`, in
   the same order. The simplest way is to put the `$type` on the group.
4. **Identity, optional.** `shadow_style`, `border_style`, `motion_style`,
   `focus_ring` and `scrollbar` are general settings; declare them if your editor offers
   them. Properties that follow Identity carry `co.ordering.follows`
   ([§11.2](schema-format.md#112-identity-follows)).
5. **Declare your derivations.** If one of your colors derives from another (the
   primary's `pressed`), say so with `co.ordering.derive`. If the schema declares none,
   the engine uses its default rules and **adds to the resolved theme colors your schema
   does not have** (`primary_subtle`, `focus`, `primary_text`…); with a single `derive`,
   it does not ([§11.5](schema-format.md#115-the-engines-rules)). The same goes for
   `follows` and for components' `derive` and `matrix`: declaring one replaces all the
   engine's rules of that category.

Then come your `components`, `visibility`, `images`, `pages` and, if you have them, your
site's settings (`site`). Everything your code reads from the theme must be in the
schema, with its default: a theme stores only what changes, and the rest comes from
there.

## 3. The meta-schema and the editor

The package ships the format's meta-schema (JSON Schema 2020-12):

```
node_modules/@orderingco/ordering-theme-engine/meta/theme-schema-1.json
```

importable as `@orderingco/ordering-theme-engine/meta/theme-schema-1.json`.

A schema that uses every shape of the format, with a minor and a new base after it, is in
`meta/reference/` ([README](../README.md#the-reference-schema)): read it next to this guide
for an example of each part.

**`$schema` cannot point to the file.** It must be exactly
`"urn:ordering:theme-schema:1"`: that is how api-core recognizes the format, and with any
other value it answers `schema.format`. That limits autocompletion:

- Editors that read `$schema` before their own associations (VS Code, for example) try
  to load the URN, cannot, and do not use an association by file name (`json.schemas`
  with `fileMatch`) while the file has that `$schema`.
- Editors that let you associate a JSON Schema with a file by its name or path, and give
  that association priority, can use the file above.

Meanwhile, the reference is `ordering-theme validate` (§4), which validates against the
same meta-schema and gives each error with its path.

## 4. Validate locally

Install the package as a dev dependency, with an exact version. It is published on
npmjs and needs no token:

```sh
pnpm add -D --save-exact @orderingco/ordering-theme-engine
```

### With the CLI

```sh
pnpm exec ordering-theme validate theme.schema.json                     # meta-schema + rules, like api-core
pnpm exec ordering-theme validate theme.schema.json --previous prev.json # and against the latest version of your base (§6)
pnpm exec ordering-theme validate theme.schema.json --v1-map v1-map.json --against "$THEME_PUBLISH_API"  # in CI: against what the API publishes
pnpm exec ordering-theme defaults theme.schema.json --out src/theme.defaults.json
pnpm exec ordering-theme defaults theme.schema.json --out src/theme.defaults.json --check  # in CI: is it up to date?
pnpm exec ordering-theme rules theme.schema.json --out src/theme.rules.json          # what a served theme is checked with
pnpm exec ordering-theme rules theme.schema.json --out src/theme.rules.json --check
```

`validate` exits with code 1 if the schema does not pass, and lists each `{ path, code }`
([schema-format.md §16](schema-format.md#16-errors)). The rules include the editor
metadata's ([§12.3](schema-format.md#123-rules)): every path a group lists exists, each
visibility key is in one group, the orders, limits, scales and fonts agree; a product
repository needs no script of its own for them. `ordering-theme --help` lists all
the options.

`--against <api>` reads from the API, with `THEME_PUBLISH_KEY`, what publishing compares
the schema with: the same version if it is published (the same content is `unchanged`,
another is `schema.version_exists`), the latest version of your base and the latest of
the previous base (for the upgrade block). A pull request runs it without keeping the
published versions as files. With `--development` it checks as a development API does
and prints what production would refuse as `warning:` lines.

### From code

`validateFull(schema, options?)` does the same as the CLI: the meta-schema (compiled
inside the package, without Ajv) and then `validate()`, which applies the same rules as
api-core, in the same order and with the same codes:

```ts
import { readFileSync } from 'node:fs'
import { validateFull } from '@orderingco/ordering-theme-engine'

const schema = JSON.parse(readFileSync('theme.schema.json', 'utf8'))

// With `previous`, the latest published version of your base, it also compares the versions (§6).
const { code, errors } = validateFull(schema)
if (code !== null) throw new Error(`${code}: ${errors.map((e) => e.code).join(', ')}`)
```

Given the published version itself as `previous`, it answers `unchanged` for the same
content and `schema.version_exists` for another, as api-core does; `validate --against`
looks it up for you, and `publish --dry-run` asks api-core everything.

## 5. Publish

```sh
export THEME_PUBLISH_API=https://apiv4.ordering.co/v400   # the environment's api-core, with its version
export THEME_PUBLISH_KEY=…                                # that environment's key

pnpm exec ordering-theme publish theme.schema.json --dry-run   # everything but storing
pnpm exec ordering-theme publish theme.schema.json
```

`publish` validates locally first and sends nothing if the schema does not pass. Without
`THEME_PUBLISH_API` or `THEME_PUBLISH_KEY` it does not publish and exits successfully;
with `--required` (or `THEME_PUBLISH_REQUIRED` set to `1` or `true`) it exits with an
error, which is what you want in a deploy. No other variable, such as a CI's own flag,
makes it required.

Recommended in CI:

- **on every PR:** `publish --dry-run` against staging. It answers the same as
  publishing, without storing anything;
- **on every deploy:** `publish` against that environment's api-core, before deploying.
  Publishing the same version again with the same content answers `unchanged`, so the
  deploy can be repeated.

Under the hood it is a call to api-core (system routes, key only):

```http
POST {THEME_PUBLISH_API}/system/theme_schemas
X-Theme-Publish-Key-X: <key>
Content-Type: application/json

{ "schema": { … }, "dry_run": true }
```

- `schema`: the schema as is.
- `v1_map`: only for a product with v1 themes that api-core converts (today, the
  marketplace website). A new product does not send it.
- `dry_run`: `true` checks everything, the registry included, and stores nothing. A
  version already published answers as publishing would: `unchanged` with the same
  content, 409 `schema.version_exists` with other content (outside development mode).
  Only a version that would be stored answers `valid`.

Responses (`{ error, result, … }`, like all of api-core):

| HTTP | When | Body |
|---|---|---|
| 201 | published | `result: { status: "created", product_type, version, sha256, changes }` |
| 200 | the version already existed with the same content / valid `dry_run` / replaced in development mode | `status: "unchanged"` / `"valid"` / `"replaced"` |
| 401 | no key, wrong key or the environment has no key | `result: ["You don't have permission"]` |
| 409 | `schema.version_exists`, `schema.version_order` | `error_codes: [code]`, `errors: [{ path, code }]` |
| 422 | `schema.format`, `schema.invalid`, `schema.incompatible` | same |

`changes` is `{ added, removed, migrated, deprecated }` against the latest version of the
base (null if it is the first). With the same key, `GET {api}/system/theme_schemas/{product_type}`
lists the published versions (`{ version, sha256, created_at }`) and
`GET {api}/system/theme_schemas/{product_type}/{version}` returns one.

## 6. Versions, migrations and new bases

`version` is `base.minor.patch`. The base is the product's major version (for example
`26` for the v26 apps). The Ordering.co API resolves **every theme of the product type
with its current version**, the last one published: a theme saved with an earlier
version is read through the steps that version left declared, and saved with the
current version the next time someone edits it. So publishing `26.3.0` changes what
every site sees without anyone migrating anything, and the rules below are what keep
that safe. Everything about it, with the exact rules, the codes and worked examples, is
in one place: [schema-format.md §13–15](schema-format.md#13-versions-and-compatibility).
In short:

| Change | Version | What you declare |
|---|---|---|
| Texts, labels and editor metadata that validates no value (not `alpha`, `custom`, `min`, `max`) | patch | nothing |
| Add a key, a component, an option, a page's section; change a default | minor | nothing: themes that do not set it get the default |
| Tighten a `max_length`, a `pattern` or an editor's `min`/`max`; make a section not `hideable`, or move it to another `group` | minor | nothing: a saved value that no longer fits is left out, and its default shows |
| Mark a key `$deprecated`; remove one the previous version already marked | minor | nothing: its value is left out |
| Rename or move a token (within its group), a component property, a visibility key, an image, a page or one of its sections, keeping its section and kind | minor | a migration, `co.ordering.migrate` ([§14](schema-format.md#14-migrations-within-a-base-coorderingmigrate)) |
| Remove a key (a page's section too) that is not deprecated, change a type or a `dimension`'s scale, remove an option, `alpha` or `custom`, turn a key into another section's, change what a stored value means | **new base** (`27.0.0`) | the upgrade block, `co.ordering.upgrade` ([§15](schema-format.md#15-a-new-base-coorderingupgrade)) |

**Within a base: migrations.** Declare `{ from, to }` at the root, in
`$extensions["co.ordering.migrate"]`: `from` is the key the schema no longer has, `to`
the one it has, in the same section and of the same kind. The API moves each saved
theme's value when it reads or saves it, unless the theme already sets `to`; on every
platform the theme overrides too, and for a token its value in each mode and every
alias to it. A property's default on a platform goes with it, and a `site.*` migration
moves the site's setting. The list only grows: every later version of the base keeps
it. Example:
[§14.5](schema-format.md#145-example-a-minor-that-renames-a-property-and-a-visibility-key).

**A new base: the upgrade block.** `27.0.0` may change anything, and declares at the
root, in `$extensions["co.ordering.upgrade"]`, how a theme of 26 takes its values:
`from: 26`, the `moves` (each `{ from, to }`, with a `map` that translates options,
levels or booleans, by `"true"` and `"false"`), the paths it `drop`s on purpose, and, for what cannot be declared (a value
whose meaning changes), a `rewrite` that names a transformation the API team registers
and deploys first. Every key of 26 must arrive at the same path with the same kind or be
moved, dropped or rewritten: publishing refuses the rest
(`upgrade.<path>.unaccounted`), so no loss is silent. Check it before publishing with
`ordering-theme validate theme.schema.json --previous-base <the last 26 schema>`.
Stored themes are not migrated in the database: the API converts each one when it reads
it and saves it converted on its next write, and a client still on 26 gets the theme cut
down to 26, with every rename and one-to-one map undone (a merge into a key 26 already
had, or a map that is not one to one, takes 26's default). Example:
[§15.5](schema-format.md#155-example-2700).

**While the product is in development.** An api-core with
`THEME_SCHEMA_DEVELOPMENT=true` (local and staging until v2 themes launch) replaces an
already published version and does not reject for order or compatibility: what
production would refuse comes back as `warnings`. In production it is off: there the
rules apply from the first version.

**The version 1 map** names only what the schema has: when a version renames or removes
a key the map names, update the map in the same change, or publishing refuses it
(`v1_map.<section>.<path>.unknown`).

## 7. Using the result: `resolve()` and `defaults()`

api-core resolves each site's theme at runtime. The package computes the same thing on
the client for whatever has to exist without the API's response, or before it:

```ts
import { defaults, resolve } from '@orderingco/ordering-theme-engine'
import schema from './theme.schema.json' with { type: 'json' }

const empty = defaults(schema)               // the resolved empty theme
const theme = resolve(schema, themeValues)   // a theme (what it stores: only what changes) over the defaults
```

- **Local defaults.** `defaults(schema)` (or `ordering-theme defaults`) is what the
  product paints before the first response, offline or with the API down. In a native
  app it is critical: the published binary does not change, so it is generated at build
  time with that build's schema version.
- **Preview.** `resolve(schema, values)` gives what api-core would serve for those
  values, with no latency.
- **Checking what is served.** A client that does not bundle the schema checks the
  theme it is served with `runtimeRules(schema)` (or `ordering-theme rules`): each
  value's options, its range or its kind, from the same limits api-core binds a saved
  theme with ([schema-format.md §12.4](schema-format.md#124-runtime-rules)).

The result has `tokens`, `components`, `visibility`, `images`, `pages`, `modes` and
`platforms`, with each alias of the components (and of `platforms.<platform>.components`)
replaced by its value and each typography with its `family` (the font of its role).
Tokens and `modes` hold literal values: neither a schema nor a theme may put an alias
there. A platform's own values come separately, in `platforms.<platform>`, and the
client applies them on top. Every empty map comes out as `{}` and a page's sections as
a list, as api-core serves them.

`resolve()` assumes a theme valid for the schema: it applies the schema's migrations, as
api-core does when serving it, but does not drop values that no longer fit. It does not
resolve site settings (`values.site`): they belong to the site, and api-core serves
them.

## 8. Complete minimal example

A complete, valid schema (it passes the meta-schema and `validate()`) for a Call Center:
the complete scales, a few colors and typographies, a button that follows Identity in its
radius and derives the background of its `pressed` state, a visibility key, an image, a
page and a site setting.

```json
{
  "$schema": "urn:ordering:theme-schema:1",
  "product_type": "call_center",
  "version": "1.0.0",
  "format": 2,
  "platforms": [],
  "tokens": {
    "font": {
      "$type": "font",
      "heading": {
        "$value": "inter",
        "$extensions": { "co.ordering": { "options": ["archivo", "inter", "lato", "montserrat", "nunito", "open_sans", "playfair_display", "poppins", "raleway", "roboto"] } }
      },
      "body": {
        "$value": "inter",
        "$extensions": { "co.ordering": { "options": ["archivo", "inter", "lato", "montserrat", "nunito", "open_sans", "playfair_display", "poppins", "raleway", "roboto"] } }
      }
    },
    "typography": {
      "$type": "typography",
      "h1": { "$value": { "size": 24, "line": 1.33, "weight": 700 } },
      "body": { "$value": { "size": 14, "line": 1.5, "weight": 400 } },
      "button": { "$value": { "size": 14, "line": 1.43, "weight": 600 } }
    },
    "color": {
      "$type": "color",
      "primary": {
        "$value": "#1E72E3",
        "$extensions": { "co.ordering.editor": { "label": { "key": "BUILDER_THEME_COLOR_PRIMARY", "default": "Primary" } } }
      },
      "on_primary": { "$value": "#FFFFFF" },
      "primary_pressed": {
        "$value": "#1862C5",
        "$extensions": { "co.ordering": { "derive": { "op": "shade", "from": "{color.primary}", "amount": -0.07 } } }
      },
      "background": { "$value": "#FFFFFF" },
      "surface": { "$value": "#F5F6F8" },
      "text": { "$value": "#1A1A1A" },
      "text_muted": { "$value": "#6B7280" },
      "border": { "$value": "#E5E7EB" }
    },
    "radius": {
      "$type": "dimension",
      "none": { "$value": 0 }, "xs": { "$value": 4 }, "sm": { "$value": 8 }, "md": { "$value": 10 },
      "lg": { "$value": 15 }, "xl": { "$value": 20 }, "xxl": { "$value": 30 }, "full": { "$value": 9999 }
    },
    "size": {
      "$type": "dimension",
      "sm": { "$value": 32 }, "md": { "$value": 40 }, "lg": { "$value": 48 }, "xl": { "$value": 56 }
    },
    "icon": {
      "$type": "dimension",
      "sm": { "$value": 16 }, "md": { "$value": 20 }, "lg": { "$value": 24 }
    },
    "space": {
      "$type": "dimension",
      "none": { "$value": 0 }, "xxs": { "$value": 2 }, "xs": { "$value": 4 }, "sm": { "$value": 8 }, "md": { "$value": 12 },
      "lg": { "$value": 16 }, "xl": { "$value": 24 }, "xxl": { "$value": 32 }, "xxxl": { "$value": 48 }
    },
    "layout": {
      "page_gutter": { "$type": "dimension", "$value": 16 },
      "mode": {
        "$type": "option",
        "$value": "original",
        "$extensions": { "co.ordering": { "options": ["original", "appointments"] } }
      }
    },
    "motion": {
      "$type": "duration",
      "fast": { "$value": 120 }, "base": { "$value": 200 }, "slow": { "$value": 300 }
    },
    "border": {
      "$type": "dimension",
      "thin": { "$value": 1 }, "thick": { "$value": 2 }
    },
    "shadow": {
      "$type": "shadow",
      "none": { "$value": null },
      "sm": { "$value": { "x": 0, "y": 1, "blur": 4, "spread": 0, "color": "#000000", "opacity": 0.1, "elevation": 1 } },
      "md": { "$value": { "x": 0, "y": 2, "blur": 8, "spread": 0, "color": "#000000", "opacity": 0.1, "elevation": 3 } },
      "lg": { "$value": { "x": 0, "y": 4, "blur": 12, "spread": 0, "color": "#000000", "opacity": 0.15, "elevation": 6 } },
      "xl": { "$value": { "x": 0, "y": 8, "blur": 24, "spread": 0, "color": "#000000", "opacity": 0.2, "elevation": 12 } }
    },
    "opacity": {
      "$type": "opacity",
      "disabled": { "$value": 0.5 }
    }
  },
  "components": {
    "button": {
      "base": {
        "background": { "$type": "color", "$value": "{color.primary}" },
        "color": { "$type": "color", "$value": "{color.on_primary}" },
        "radius": {
          "$type": "dimension",
          "$value": "{radius.md}",
          "$extensions": { "co.ordering": { "scale": "radius", "follows": "radius" } }
        },
        "height": {
          "$type": "dimension",
          "$value": "{size.md}",
          "$extensions": { "co.ordering": { "scale": "size" } }
        },
        "padding_x": {
          "$type": "number",
          "$value": 16,
          "$extensions": { "co.ordering.editor": { "control": "range", "min": 0, "max": 48, "step": 1, "unit": "px" } }
        },
        "text": { "$type": "typography", "$value": "{typography.button}" }
      },
      "variants": {
        "primary": {
          "states": {
            "pressed": {
              "background": {
                "$type": "color",
                "$value": "{color.primary_pressed}",
                "$extensions": { "co.ordering": { "derive": { "op": "shade", "from": "background", "amount": -0.07 } } }
              }
            }
          }
        }
      }
    }
  },
  "visibility": {
    "$type": "boolean",
    "header.logo": { "$value": true }
  },
  "images": {
    "$type": "image",
    "logo": {
      "$value": "",
      "$extensions": { "co.ordering": { "formats": ["png", "svg", "webp"], "hints": { "ratio": "4:1", "alpha": true, "max_kb": 200 } } }
    }
  },
  "pages": {
    "home": {
      "footer": { "$type": "boolean", "$value": true },
      "sections": {
        "$type": "sections",
        "$value": [
          { "key": "customer_search", "visible": true, "hideable": false, "group": null },
          { "key": "recent_orders", "visible": true, "hideable": true, "group": null }
        ]
      }
    }
  },
  "site": {
    "name": {
      "$type": "string",
      "$value": "",
      "$extensions": { "co.ordering": { "max_length": 60 } }
    }
  }
}
```

`defaults()` for this schema gives, for example:

```jsonc
{
  "tokens": { "color": { "primary": "#1E72E3", "primary_pressed": "#1862C5", … }, … },
  "components": {
    "button": {
      "base": {
        "background": "#1E72E3", "color": "#FFFFFF", "radius": 10, "height": 40, "padding_x": 16,
        "text": { "size": 14, "line": 1.43, "weight": 600, "family": "inter" }
      },
      "variants": { "primary": { "states": { "pressed": { "background": "#1862C5" } } } }
    }
  },
  "visibility": { "header.logo": true },
  "images": { "logo": "" },
  "pages": { "home": { "footer": true, "sections": [{ "key": "customer_search", "visible": true }, { "key": "recent_orders", "visible": true }] } },
  "modes": [],
  "platforms": []
}
```

And a theme that only changes the primary (`{ "tokens": { "color": { "primary": "#FF0000" } } }`)
resolves `primary_pressed` and the pressed button's background to `#DB0000`: both derive
from the primary.
