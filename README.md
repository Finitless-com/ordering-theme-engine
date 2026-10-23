# @orderingco/ordering-theme-engine

The v2 theme engine, in two implementations that answer the same:

- **PHP** (`orderingco/ordering-theme-engine` on Packagist, [below](#php)): the engine
  the Ordering.co API runs to resolve every site's theme and to validate the schemas products
  publish.
- **TypeScript** (`@orderingco/ordering-theme-engine` on npmjs): the same resolution and
  validation for the products (the web, the Dashboard, the apps), so they compute what
  the Ordering.co API will serve or answer without copying the rules: local defaults, zero-latency
  preview, CI artifacts. No runtime dependencies. Strict TypeScript, Node 22, ESM only:
  `exports` has no `require` condition, so CommonJS loads it with `await import()`. It
  ships a CLI, `ordering-theme`, that validates a schema, computes its defaults and the
  rules a client checks a served theme with, and publishes it; and three browser entries:
  `@orderingco/ordering-theme-engine/runtime`, that completes a served theme with
  them ([Runtime](#runtime-browser)), and `@orderingco/ordering-theme-engine/css`, that
  turns it into CSS once per theme ([CSS](#css-browser)), and
  `@orderingco/ordering-theme-engine/colors`, its color operations for colors a client
  gets at run time ([Colors](#colors-browser)).

Both live here and are checked against each other in one CI
([parity](#parity-between-php-and-typescript)).

Schema format:
[`docs/schema-format.md`](https://github.com/Finitless-com/ordering-theme-engine/blob/master/docs/schema-format.md).
Writing a product's schema:
[`docs/creating-a-schema.md`](https://github.com/Finitless-com/ordering-theme-engine/blob/master/docs/creating-a-schema.md).
Both ship in the npm package and the Composer dist, under `docs/`. What changed in each
version: [`CHANGELOG.md`](https://github.com/Finitless-com/ordering-theme-engine/blob/master/CHANGELOG.md).

## Installation

```sh
pnpm add -E @orderingco/ordering-theme-engine   # or npm i -E, yarn add -E
```

Pin an exact version in `package.json` (`"@orderingco/ordering-theme-engine": "0.12.0"`):
a minor can change what it resolves, because it follows the Ordering.co API.

## Usage

```ts
import { resolve, defaults } from '@orderingco/ordering-theme-engine'
import schema from './theme.schema.json' // the schema in nodes

const theme = resolve(schema, site_theme.values) // what the Ordering.co API serves
const empty = defaults(schema)                   // the empty theme, resolved
```

`resolve()` and `defaults()` compile the schema once per object (a `WeakMap`): a schema
changed in place after the first call keeps resolving as it was. Pass a new object (a
copy) instead.

Validating a schema before publishing it:

```ts
import { validateFull } from '@orderingco/ordering-theme-engine'

const { code, errors, warnings, changes, unchanged } = validateFull(schema, { previous, v1Map }) // meta-schema and the rest, as the Ordering.co API
// code: null (valid), 'schema.format', 'schema.invalid', 'schema.version_exists', 'schema.version_order' or 'schema.incompatible'
```

The meta-schema is compiled into the package. `validateShape(schema)` runs that stage
alone.

### Public API

- `resolve(schema, values)`: the theme over its schema's defaults
  (`ThemeResolver::resolve()`): the aliases of `components` and of
  `platforms.<platform>.components` replaced by their tokens' values, and each
  `tokens.typography` style with the `family` of its font role. `tokens` and `modes`
  stay as they are: their values are literals, and neither a schema nor a theme may put
  an alias there. Throws a `TypeError` if the schema is not in nodes.
- `defaults(schema)`: the empty theme resolved, what a site whose theme
  changes nothing serves.
- `runtimeRules(schema)` and `serializeRules(rules)`: the rules a client checks a theme
  it is served with without bundling the schema (each value's options, its range or
  its kind), and the file a product versions with them; `ordering-theme rules` writes
  it. TypeScript only: api-core validates with the compiled schema itself
  (`ThemeValidator`). The format is documented in `src/rules.ts` and in
  [schema-format.md §12.4](docs/schema-format.md#124-runtime-rules).
- `compileRuntime(schema)` and `serializeRuntime(compiled)`: the compiled form the
  `runtime` entry reads (below), from the schema in nodes, and the file a product
  versions with it; `ordering-theme compile` writes it. `complete(compiled, values)` is
  also exported here.
- `@orderingco/ordering-theme-engine/runtime`: `complete(compiled, values)`, a served
  theme completed and checked for a client, without the schema
  ([below](#runtime-browser)).
- `@orderingco/ordering-theme-engine/css`: `toCss(compiled, values)`, a completed theme
  as CSS, `fonts(view, extra?)`, its Google Fonts stylesheets, `transition()` and the
  conversions apart ([below](#css-browser)).
- `@orderingco/ordering-theme-engine/colors`: `rgbOf()`, `contrast()`, `isLight()`,
  `onColor()`, `shade()`, `mix()`, `legible()` ([below](#colors-browser)).
- `compile(schema, v1Map?)`: the node schema as the tuples the engine reads
  (`NodeCompiler`), with its errors: `{ schema, errors }`. `resolve` uses it and caches
  the result per object.
- `siteDefaults(compile(schema).schema)`: the defaults of the `site` block (they belong
  to the site, not the theme), `{}` without one. It reads the tuples: given the schema
  in nodes, or compile()'s whole `{ schema, errors }`, it throws a `TypeError`.
- `validateFull(schema, { previous?, previousBase?, v1Map?, development? })`: what the
  Ordering.co API's `ThemeSchemaPublisher::validate()` answers, `{ code, errors,
  warnings, changes, unchanged }`, meta-schema included (see below).
- `validate(schema, options?)`: the same without the meta-schema. For a schema that
  passes the meta-schema it answers the same codes, the same errors in the same order
  and the same changes as the Ordering.co API; for one that fails it, the API answers
  the meta-schema's errors instead (`validateFull()`).
- `validateShape(schema)`: the schema's errors against the meta-schema, as
  `ThemeSchemas::meta()`: one per path, `{ path, code: '<path>.schema', message }`
  (`message` is the validator's explanation; the Ordering.co API does not send it). `[]` if it
  passes.
- `check(compiled, nodes?)`: `ThemeSchemas::check()` over the tuples from `compile()`;
  given the schema in nodes they come from, it then runs `checkEditor()` on it.
  `check(compiled)` alone skips the editor rules (the tuples no longer carry the
  metadata).
- `checkEditor(schema)`: what the editor metadata gets wrong (`co.ordering.editor` and
  the `editor` block, which the compiler drops), `EditorRules::check()`: the rules of §12.3
  of [`docs/schema-format.md`](https://github.com/Finitless-com/ordering-theme-engine/blob/master/docs/schema-format.md).
- `outOfRange(schema)`: numeric defaults outside the `min`/`max` of their editor
  control (`<path>.default_out_of_range`).
- `v1MapErrors(compiled, v1Map)`: the paths of a version 1 map the schema does not have
  (`v1_map.<section>.<path>.unknown`), `ThemeSchemaPublisher::v1MapErrors()`;
  `validate()` runs it given a `v1Map`.
- `diff(previous, next)`: `SchemaDiff::compare()`, `{ errors, changes }` with
  `changes: { added, removed, migrated, deprecated, options_added, defaults_changed,
  rules_changed, sections_changed }`.
- `checkUpgrade(next, previousBase)`: what a schema's upgrade block
  (`co.ordering.upgrade`) gets wrong against the last version of the previous base,
  both in nodes (`ThemeUpgrades::check()`); null as the previous base when the product
  type has none. `validateFull(schema, { previousBase })` runs it too.
- `upgrade(values, next, previous, rewrite?)`, `upgradeChain(values, schemas,
  rewrite?)`: a theme of an older base read in a newer one, step by step
  (`ThemeUpgrades::apply()`, `::chain()`); `schemas` goes from the last version of the
  theme's base to the version to read it with. `rewrite(id, values, previous, next)`
  runs what a base names in `rewrite` (api-core registers its own); without it that
  step is skipped. `upgradeSettings()`, `upgradeSettingsChain()`: a site's settings.
  `cutDown(values, schemas)`, `cutDownSettings(settings, schemas)`: a version's values
  as an older client reads them, what newer versions renamed or moved undone;
  `schemas` goes from the version of the values down to the client's, with the last
  version of each base between them. The schemas in nodes or compiled.
- `valid(values, schema)`: what of a stored theme fits its schema
  (`ThemeResolver::valid()`): what the schema migrates moved first, then every value the
  schema does not have or that is not valid left out, so its default shows. The schema
  in nodes or compiled; the theme is not changed. `validSettings(settings, schema)`
  does the same for a site's settings (`ThemeResolver::validSettings()`), its `site.`
  migrations included. `themeErrors(values, compiled)` and
  `settingsErrors(settings, compiled)` are `ThemeValidator::validate()` and
  `::settings()`: each problem `{ path, code }`, in the API's order.
- The format's meta-schema, `@orderingco/ordering-theme-engine/meta/theme-schema-1.json`
  (JSON Schema 2020-12), the same file the PHP engine reads.
- The reference schema, `@orderingco/ordering-theme-engine/meta/reference/` (see
  [The reference schema](#the-reference-schema)).
- Types: `NodeSchema`, `ThemeValues`, `ResolvedTheme`,
  `ValidateOptions`, `ValidationResult`, `ShapeError`, `Changes`, `V1Map`,
  `RuntimeSchema`, `CompleteValues`…

Every map comes out as an object, an empty one too (`{}`), as api-core serves it (its
`ThemeMaps`): `modes`, and `platforms` or `images` when the schema has none. A page's
`sections` are a list, an empty one too.

`modes` is what the theme sets per mode, `{ <mode>: { color: { <name>: <color> } } }`:
the colors a client uses instead of `tokens.color.<name>` in that mode, as the theme
stores them (the engine neither derives nor resolves them). The only mode is `dark`
(`ThemeFormat::MODES`). A schema declares no modes, so `defaults()` always gives it
empty: `{}`.

### Runtime (browser)

A website reads the theme the Ordering.co API serves (`theme.values`, already resolved)
without bundling the schema or the engine: `@orderingco/ordering-theme-engine/runtime`
holds only what reading a served theme needs. ESM, no dependencies, no `node:` imports,
`sideEffects: false`; about 4 KB minified and gzipped (CI fails it over 6 KB, or if it
takes any other module of the engine: `pnpm size`, `scripts/runtime-size.mjs`).

```ts
import { complete, type RuntimeSchema } from '@orderingco/ordering-theme-engine/runtime'
import compiled from './theme.runtime.json' // ordering-theme compile theme.schema.json --out src/theme.runtime.json

const values = complete(compiled as RuntimeSchema, served.values) // complete and understood
const first = complete(compiled as RuntimeSchema, null)          // the defaults, to paint before it arrives
```

`complete(compiled, values)` answers every key of the schema, each one a value the
client understands, whatever it is served: nothing, a partial theme (a version 1 theme
converted, `complete: false`), one of another version of the schema, or broken values.

- A key the schema lacks is dropped; one the theme leaves out takes its default.
- A value that is not understood takes its default: of another kind than the default,
  an option the schema does not list, an invalid color (an alias that was not resolved
  is one), a null where the default is not null (a border and a shadow may always be
  null: none).
- A number is clamped to its rule's range: its editor's `min` and `max`, a level
  control's widened to its scale's levels (as `runtimeRules()` does), else the format's.
- A text style, a border and a shadow are checked field by field: an invalid or missing
  field is the default's (a text style without a `family` keeps none).
- The open blocks are checked by what they hold: `platforms.<platform>` by the rules of
  the component property, visibility key or page it changes; a group's `scope` by the
  rules of each component it may change, completed from the theme's own component
  (already checked); a page's `sections` in the theme's order with only the page's
  sections, once each; a slot's axes, those its schema declares, by its component's
  parts; `images`, an `http(s)` URL of up to 2048 bytes or `''`; `site`, each setting by
  its options, `max_length` and `pattern`; `modes`, the colors of the schema's own.

Neither argument is changed. What the theme leaves out is the compiled form's defaults,
built once and shared by every answer, so the answer is an object of its own (a key of
it may be replaced, `values.images = …`) but what it holds must not be changed. It throws a `TypeError` given anything but the compiled
form (the schema in nodes, the tuples, the rules). A value's rule is the one
`runtimeRules()` gives it and each check is the engine's own (the same colors, lengths
and patterns as the Ordering.co API), but it does not replace `valid()`: api-core already
validated the theme when it was saved and when it served it; this protects the client
from what it does not expect.

The compiled form (`ordering-theme compile`, `compileRuntime()`) is the empty theme
with the site's settings (`defaults --site`), each value that has a rule written
`{ "$value": default, "$rule": rule }`, and the rest of `runtimeRules()` under
`rules`; without the editor's metadata and the version 1 paths. For the marketplace
website's schema it is about 98 KB, 11.6 KB gzipped, against 110 KB gzipped for the
schema. The format is documented in `src/runtime.ts` and in
[schema-format.md §12.4](docs/schema-format.md#124-runtime-rules). Each number's rule
also carries its CSS unit, which the `css` entry reads.

### CSS (browser)

`@orderingco/ordering-theme-engine/css` turns the values `complete()` answers into the
CSS a web client paints with, once per theme: the tokens, `identity` and `fonts` at
once, each component (a scope's and a platform's too) the first time it is read; after
that, reading a value is a property access. ESM, no dependencies, `sideEffects: false`, without the runtime or the rest of
the engine; about 4.5 KB minified and gzipped (CI fails it over 5 KB: `pnpm size`).

```ts
import { complete } from '@orderingco/ordering-theme-engine/runtime'
import { animation, fonts, toCss, transition } from '@orderingco/ordering-theme-engine/css'

const values = complete(compiled, served.values)
const css = toCss(compiled, values)              // the same values give the same view

css.components.business_card.base.shadow.css     // '0px 1px 4px 0px rgba(0,0,0,0.1)'
css.components.business_card.base.shadow.down    // the same shadow cast down (a bar across the top)
css.components.business_card.base.name.css       // "font-family: 'Montserrat', sans-serif;\nfont-size: 20px;…"
css.identity.radius.md                           // the level as Identity paints it (border_style.radius)
transition(css.components.button.motion, ['color', 'box-shadow'])
animation(css.components.modal.motion, 'fade-in', '1 forwards')
fonts(css, { body: [500] })                      // { heading: { name, weights, href }, body: … }
```

Each value is converted by its type in the schema, never by its name:

- a number by its unit (`"12px"`, `"200ms"`, `"70%"`, or unitless: `"0.5"`, `"3"`); a
  color as it is; a border `"1px solid #E9ECEF"` (null: `"none"`); a font
  `"'Inter', sans-serif"`; `motion_style.easing` `"ease-in-out"`; an option or a
  boolean as it is;
- a shadow, a `CssShadow`: `css` (the opacity inside the color; null: `"none"`), the
  same shadow cast `down`, `up`, `right` and `left` as far as it falls, `drop` (a
  `filter` of `drop-shadow()`s: a solid outline is four) and `reach`, how far it goes
  past each side of its box in px;
- a typography style, a `CssText`: its declarations together (`css`) and each one apart
  (`size`, `line`, `weight`, `family`, `letter_spacing`, `transform`), the weight the
  nearest its font has. A style without a family takes its role's: heading for
  `display` and `h1`–`h4`, body for the rest, a component's `font_role` in it; a
  component's style that names no font and has no `font_role` is painted with the
  body's, and its `css` declares no family (it keeps its parent's).

Besides `tokens` (as the theme sets them) and `components` (a group's `scope` holds
whole components; each component has its `motion`, `{ ms, easing }` or null when the
identity or the component does not animate) the view has `identity` (the radius,
shadow and border levels as a spot that follows Identity paints them, the focus ring
and the scrollbar), `platforms` (each platform's own values of the components) and
`fonts` (each role's catalog id, Google Fonts name and `font-family`).

Every string of the view is safe to interpolate in CSS: a value with `{ } ; < > ' " \`,
a comment or a control character (such as a role, `{main}`, left in a matrix's
appearances) is null. The only quotes and semicolons are the engine's own, in a family
and in a `CssText`'s `css`. The conversions are also exported for the CSS a client
writes itself: `cssBorder()`, `cssShadow()`, `cssText()`, `alpha(color, a)`,
`isTransparent()`, `snapWeight(font, weight)`.

### Colors (browser)

For a color a client gets at run time and not from the theme (a business's ribbon, a
map marker), `@orderingco/ordering-theme-engine/colors` holds the engine's own color
operations, apart from `css` so only the code that uses them loads them (about 1.5 KB
minified and gzipped; CI fails it over 2 KB): `rgbOf(color)` (`{ r, g, b, a }` of a
hex, rgb(), rgba() or `transparent`, else null), `contrast(a, b)` (WCAG, 1–21),
`isLight(color)` (it contrasts at least as much with black as with white),
`onColor(background, light?, dark?)` (the text color over it by that rule, white or
black by default), and `derive`'s operations, the same as the theme's colors are
derived with: `shade(color, amount)`, `mix(color, over, amount)` and
`legible(color, background, ratio = 4.5, step = 0.01)`. The fonts' family names and weights
(`range` for a variable one) are the format's (`FONT_FACES` in `src/format.ts`).

## The reference schema

`meta/reference/` holds a schema that is no product's: it uses every shape of the format
at least once, so that whoever builds on the format (api-core's registry and resolver,
an editor) tests against the format instead of copying a product's schema, which goes
stale as soon as the product changes. It ships in both packages, next to the
meta-schema: `@orderingco/ordering-theme-engine/meta/reference/<file>` in npm and
`vendor/orderingco/ordering-theme-engine/meta/reference/<file>` in Composer.

| File | What it is |
|---|---|
| `reference-1.0.0.json` | The first version of base 1, for `marketplace_website`. |
| `reference-1.0.0.v1-map.json` | Its version 1 map, which names only keys every version has. |
| `reference-1.1.0.json` | A minor of 1.0.0 that changes something of every kind the comparison reports: migrations of a token, a property, a visibility key, a page's section and a setting; a deprecated key removed, keys and an option added, a default, a rule and a section's `hideable` changed, a key and a group deprecated. |
| `reference-2.0.0.json` | A new base from 1.1.0, with an upgrade block: a token renamed with its aliases, a property renamed with its default on a platform, maps of an option, of a level to another scale and of a boolean, a setting moved into a group, drops of a group, a deprecated key and a visibility key, and a `rewrite` (`reference.2`, which an API must register to publish it) for the header's height, a size in pixels in 1.x and a choice of three in 2.0.0. |

Each version is valid as publishing checks it against the one before (`--previous`,
`--previous-base`), and `test/reference.test.ts` fails when the meta-schema defines a
property or an enum value no version uses, or when a token group, a component part, a
slot axis or a property kind is missing: a change to the format adds its shape here in
the same change. `php/tests/ReferenceSchemaTest.php` checks the versions in PHP.

Its names and defaults are made up; they may change in a minor of the engine, never in a
patch.

## PHP

```sh
composer require orderingco/ordering-theme-engine
```

PHP 8.1 or later, with `ext-json` and `ext-mbstring`; its one dependency is
`opis/json-schema`, which checks a schema against `meta/theme-schema-1.json` (the same
file the TypeScript engine compiles). Namespace `Ordering\ThemeEngine\`, PSR-4 from
`php/src/`. Every entry point is a static method over arrays: no framework, no database,
no I/O beyond reading the meta-schema.

```php
use Ordering\ThemeEngine\ThemeResolver;
use Ordering\ThemeEngine\ThemeSchemaPublisher;
use Ordering\ThemeEngine\ThemeSchemas;

$compiled = ThemeSchemas::tuples($nodes, $v1Map);              // ['schema' => tuples, 'errors' => [...]]
$theme = ThemeResolver::resolve($values, $compiled['schema']); // what the Ordering.co API serves
$answer = ThemeSchemaPublisher::validate($nodes, $v1Map, $previous, $development, $previousBase);
// ['code' => null|'schema.format'|'schema.invalid'|'schema.version_exists'|'schema.version_order'|'schema.incompatible',
//  'errors' => [...], 'warnings' => [...], 'changes' => ..., 'unchanged' => bool]
```

The engine reads a schema as tuples: `ThemeSchemas::tuples()` checks one in nodes
against the meta-schema and compiles it (one already in tuples comes back as it is).
A null `$v1Map` compiles no version 1 paths: the Ordering.co API passes the map it keeps for the
product type.

Public entry points:

- `ThemeResolver`: `resolve($values, $schema, &$unresolved)` (the theme over its
  schema's defaults, its aliases resolved as `resolve()` resolves them; `$unresolved`
  gets the values before their aliases), `valid()` (what of a stored theme fits its schema, migrations applied),
  `validSettings()` (the same for a site's settings), `inherit()`, `merge()`,
  `resolveAliases()`.
- `ThemeSchemas`: `tuples()`, `meta()` (the meta-schema errors), `check()` (a schema in
  tuples and, given the nodes it was compiled from, their editor metadata), `defaults()`,
  `siteDefaults()` (of a schema in tuples; the nodes or `tuples()`'s whole answer throw
  a `TypeError`), `declare()`, `kindAt()`, `specAt()`,
  `propertySpec()`, `isComponent()`; the constants `PRODUCT_TYPES`, `KEY`, `VERSION`
  and `COMPILED` (the revision of what `tuples()` answers, for whoever caches it).
- `ThemeSchemaPublisher`: `validate()`, what publishing checks before the registry
  (`validateFull()` in TypeScript), `outOfRange()` and `v1MapErrors()`.
- `ThemeValidator`: `validate()` (a theme against its schema), `settings()` (a site's
  settings), `codes()`, `value()`.
- `ThemeUpgrades`: `check($next, $previous_base)` (in nodes), `apply($values, $next,
  $previous, $rewrite)`, `chain($values, $schemas, $rewrite)`, `settings()`,
  `settingsChain()`, `cutDown($values, $schemas)`, `cutDownSettings($settings, $schemas)` (compiled) and
  `isBlock()`: a theme from one base to the next (§15 of the schema format).
- `ThemeMigrations` (`apply()`, `drop()`, `check()`, `mapKey()`), `ThemeSources` (`of()`, `aliases()`: where
  each value comes from, for the editor), `ThemeImage` (`formats()`, `validFormats()`,
  `validHints()`, `FORMATS`, `DEFAULT_FORMATS`).
- `Schema\NodeCompiler` (`compile()`, `isNodes()`), `Schema\NodeConverter` (`convert()`
  from tuples to nodes, `declare()`, `json()`), `Schema\SchemaDiff` (`compare()`,
  `leaves()`, `editorRules()`, `platformBase()`, `canonical()`),
  `Schema\EditorMetadata` (`inject()`), `Schema\EditorRules` (`check()`, the editor
  metadata's rules, and `translucent()`).
- `ThemeFormat`, `ThemeColors`, `ThemeDerivations`, `ThemeShadows`, `ThemeOverall` and
  `ThemePages` hold the format's catalog and the operations the resolver applies;
  `ThemePattern` (`source()`, `matches()`, `regex()`), a string's `pattern` rule.

What stays in the Ordering.co API: the registry of published schemas and its cache, publishing
(looking up `previous` and the previous base), reading a site's
theme (which version a client gets, the chain of bases to read a stored theme through,
saving it converted), the rewrites a base names and their registry
(`upgrade.rewrite.unknown`), version 1 themes (`ThemeV1Bridge`), the site's settings and
inspecting an uploaded image.

## The `ordering-theme` CLI

What a product's repo runs on its schema. With the package installed,
`pnpm exec ordering-theme` (or `npx ordering-theme`); without it,
`npx @orderingco/ordering-theme-engine <command>`.

```sh
ordering-theme validate theme.schema.json [--v1-map v1-map.json] [--previous last.json] [--previous-base last-of-26.json] [--development]
ordering-theme validate theme.schema.json [--v1-map v1-map.json] --against "$THEME_PUBLISH_API" [--development]
ordering-theme defaults theme.schema.json [--out src/theme.defaults.json] [--check] [--site]
ordering-theme rules    theme.schema.json [--out src/theme.rules.json] [--check]
ordering-theme compile  theme.schema.json [--v1-map v1-map.json] [--out src/theme.runtime.json] [--check]
ordering-theme publish  theme.schema.json [--v1-map v1-map.json] [--previous-base last-of-26.json] [--dry-run] [--required]
ordering-theme --help      # -h
ordering-theme --version   # -v
```

- `validate`: `validateFull()`. Prints one error per line (its code, with the path if
  the code does not include it, plus the validator's message for meta-schema errors) and
  exits 1; exits 0 if valid. With `--previous` (the last published version of its base)
  it also checks the version order and that nothing breaks, and prints the changes; the
  same version with the same content is `unchanged`, as the Ordering.co API answers it,
  and with another content `schema.version_exists`. With `--previous-base` (the last
  published version of the previous base) it also checks the schema's upgrade block
  (`checkUpgrade()`); `publish` takes it too. `--against <api>` reads both from the
  Ordering.co API instead, as publishing does, so a product's CI compares without
  files: `GET {api}/system/theme_schemas/{product_type}` and `…/{version}` for each
  version it needs (the same version, the latest of its base, the latest of the
  closest earlier base), with `THEME_PUBLISH_KEY` in `X-Theme-Publish-Key-X` (those
  GETs take the same key as publishing); it exits 1 when it cannot read them. Without
  `--v1-map` it compares the schema alone with a published version. `--development`
  checks as an API in development mode: what production refuses for the version and
  for compatibility is printed on stderr, one `warning: <code>` per line, and the
  schema passes.
- `defaults`: the empty theme resolved (`defaults()`), what the Ordering.co API serves for a theme
  that changes nothing, as indented JSON with a trailing newline. Without `--out` it
  prints it; with `--out` it writes it. `--check` (requires `--out`) compares that file
  instead of writing it and exits 1 if it is out of date, for CI when the product
  commits the file. `--site` adds the site settings' defaults (`siteDefaults()`) under
  `site`, and leaves `site` out when the schema has none. A schema that is not in nodes,
  or that does not compile (its compiler errors are printed), exits 1 and writes
  nothing; it does not run the rest of `validate`. Every empty map is written `{}` and
  a page's sections a list, as api-core serves them.
- `rules`: `runtimeRules()` written by `serializeRules()`, one rule per line, the maps
  that hold them indented, with a trailing newline: what a product checks a theme it is
  served with, at runtime, without bundling the schema. `--out` and `--check` work as
  for `defaults`, and so do the schemas it refuses.
- `compile`: `compileRuntime()` written by `serializeRuntime()`, one line of JSON with a
  trailing newline: what the `runtime` entry's `complete()` reads
  ([Runtime](#runtime-browser)). `--out` and `--check` work as for `defaults`, and so do
  the schemas it refuses. `--v1-map` takes the version 1 map published with the schema
  and fails when it names what the schema does not have (`v1_map.<section>.<path>.unknown`,
  printed as `validate` prints them); the compiled form does not carry it, since a
  served theme is already version 2.
- `publish`: validates the schema locally and, if it passes, sends it to the Ordering.co API:
  `POST {THEME_PUBLISH_API}/system/theme_schemas` with `{ schema, v1_map?, dry_run? }`
  and the key in `X-Theme-Publish-Key-X`. Product type and version come from the schema
  (`product_type`, `version`). Without `--v1-map` no map is sent and the Ordering.co API uses the
  last one published for the product type, while the local validation runs with no map:
  a schema that passes here can still be a 422 there, on its v1 paths. A product with a
  v1 map passes it. `--dry-run`: the Ordering.co API checks everything, the registry
  included, and saves nothing. A version that would be stored answers `valid`; one
  already published answers as publishing would: `unchanged` with the same content
  (schema and v1 map), and 409 `schema.version_exists` with other content (outside
  development mode, where it answers `valid`).

  Exits 0 if the Ordering.co API answers `created`, `replaced` (a product in development), `valid`
  or `unchanged` (that version is already published with the same content, so a deploy
  can be repeated), and prints the changes and, when the API sends them, its
  `warnings` (`warning: <code>` on stderr). A rejection exits 1 and prints its errors
  and codes: `schema.invalid` (422), `schema.incompatible` (422),
  `schema.version_order` (409), `schema.version_exists` (409: that version is already
  published with different content, so bump it) or the rejected key (401).

  Environment: `THEME_PUBLISH_API` (the API with its version, e.g.
  `https://apiv4.ordering.co/v400`) and `THEME_PUBLISH_KEY` (the environment's publish
  key, the Ordering.co API's `THEME_PUBLISH_KEY`). If either is missing, it validates and skips
  publishing (exit 0), unless `--required` is passed or `THEME_PUBLISH_REQUIRED` is `1`
  or `true` (any case), which exits 1: a deploy that cannot publish its schema does not
  deploy. No other variable is read: a CI's own flag (`WORKERS_CI`, `CI`) does not make
  it required, so pass `--required` in a deploy.

`help`, `--help` or `-h` as the command, and `--help` or `-h` after one, print the usage
on stdout and exit 0; `--version` or `-v` as the command prints the version.

Exit codes:

- 0: done (valid, written, up to date, published or skipped);
- 1: the schema fails (`validate`, `publish`'s local check, a `defaults`, `rules` or
  `compile` schema not in nodes or that does not compile, a `compile` version 1 map that
  names what the schema lacks), `--check` finds the file out of date, the Ordering.co
  API rejects or cannot be reached, a `--required` publish lacks its environment, or a
  file cannot be read or is not JSON;
- 2: a usage error, with the reason on stderr: no command (the usage is printed on
  stderr), an unknown command or option, no `<schema.json>` or more than one, and
  `--check` without `--out`.

In CI, for example:

```sh
ordering-theme defaults theme.schema.json --out src/theme.defaults.json --site --check
ordering-theme rules theme.schema.json --out src/theme.rules.json --check
ordering-theme compile theme.schema.json --v1-map v1-map.json --out src/theme.runtime.json --check
ordering-theme validate theme.schema.json --v1-map v1-map.json --against "$THEME_PUBLISH_API"   # on the PR
ordering-theme publish theme.schema.json --v1-map v1-map.json --dry-run   # on the PR
ordering-theme publish theme.schema.json --v1-map v1-map.json --required  # on deploy
```

## What `validateFull()` checks

The stages of `ThemeSchemaPublisher::validate()`; the first that fails sets the code:

1. `schema.format`: the schema is not in nodes (`$schema` is not
   `urn:ordering:theme-schema:1`).
2. `schema.invalid`: the meta-schema errors (`validateShape()`), or if it passes, the
   compiler's errors (a value that is not a node, a type that is no property type, a
   token that does not declare the type or options it is validated with) or, if there
   are none, those of `check()` (header, tokens, components, `scope`, `slots`,
   platforms, `inherits`, visibility and images, `site`, `follows`, `derive`,
   `component_derive`, `matrix`, migrations, pages, the v1 map and, last, the editor
   metadata, `checkEditor()`, which runs because `validate()` passes `check()` the
   nodes), of `outOfRange()` and, given a `v1Map`, of `v1MapErrors()`. In both cases
   `version.semver` is added if the version is not `major.minor.patch`.
3. With `previous` (the last published version of its base, or the same version as
   published): the same version with the same content is valid and `unchanged: true`;
   with another content, `schema.version_exists`; a lower version within the same base
   (as PHP's `version_compare()`), `schema.version_order`; and `schema.incompatible`
   with the errors of `diff()`. If valid, `changes` says what changes; without
   `previous`, `changes` is null.

`validate()` is the same without the meta-schema in stage 2.

`development: true` is the Ordering.co API's development mode: stage 3 rejects nothing, and
what production would refuse there (`schema.version_exists`, `schema.version_order` and
the errors of `diff()`) comes back in `warnings`, each `{ path, code }`, with `changes`.
Outside development `warnings` is `[]`.

`v1Map` is the v1 path map published with the schema (`v1_map`). It compiles the `v1`
blocks, the v1 paths of visibility and images, those of each page's sections and those
of `site`, which `check()` also validates, and every version 2 path it names must be one
the schema has (`v1_map.<section>.<path>.unknown`): a map that outlives a key would
convert a version 1 site without it, silently. Without it there are no v1 paths. The
Ordering.co API (api-core repo), given no map, uses the last one published for the
product type: pass that map to get the same result.

What it does **not** do, and the Ordering.co API does:

- **Identical meta-schema errors.** Whether a schema passes is the same here and in
  the Ordering.co API, but the PHP engine uses another validator (opis/json-schema): when it fails, the
  paths it names can differ (under an `anyOf`, one names the branches and the other only
  the node). The package compiles the meta-schema with Ajv at build time
  (`scripts/build-shape-validator.mjs`, which writes the unversioned
  `src/shape-validator.js`).
- **The registry.** The Ordering.co API looks up `previous` itself (the same version
  when it is published, else the last one of the base) and the previous base; the
  CLI's `validate --against` reads them from it.

## Where the rules come from

From the schema. The engine is generic: whatever is specific to a product is declared by
its `theme.schema.json` in the node format ([`docs/schema-format.md`](https://github.com/Finitless-com/ordering-theme-engine/blob/master/docs/schema-format.md)),
under `$extensions["co.ordering"]`:

- `scale` and `options`: each property's type;
- `inherits`: components that follow another;
- `follows`: what follows Identity (overall shadow, radius and border);
- `derive`: derived colors (`same`, `shade`, `mix`, `legible`) and a component's derived
  values (`step`, `shade`, `inset`);
- `matrix`: the button's colors in each appearance.

The operations (HSL, mixing, WCAG contrast, shadow scales, ordering a page's sections)
belong to the engine and are the same for every product. A schema that declares no rules
for a category uses the engine's for that category, as the Ordering.co API does; once it declares
one, the engine's rules for that category no longer apply (§11 of
[`docs/schema-format.md`](https://github.com/Finitless-com/ordering-theme-engine/blob/master/docs/schema-format.md)). A node without `$type` takes its
group's. `co.ordering.editor` and the `editor` block belong to the editor: the engine
resolves nothing with them, and `check()` validates them, given the nodes.

## What the TypeScript engine leaves out

- **`resolve()` does not validate themes.** It assumes a valid theme: the Ordering.co API
  validates it on save and, when serving it, drops whatever no longer fits the schema
  (`ThemeResolver::valid()`). A theme the API would not accept may resolve differently
  here unless it goes through `valid()` first. Like `valid()`, it does first move what
  the schema migrates (`co.ordering.migrate`).
- `resolve()` does not validate the schema (that is `validateFull()`'s job), nor does
  `validate()` check the meta-schema (see above).
- It does not convert or project v1 themes (`ThemeV1Bridge`), optimize images or fetch
  the site settings (`values.site`): that belongs to the API.
- It only reads node schemas (the Ordering.co API converts the ones registered as tuples).

## Distribution

Published publicly on npmjs as `@orderingco/ordering-theme-engine` (the `orderingco` npm
organization) and on Packagist as `orderingco/ordering-theme-engine`. The repo is
`Finitless-com/ordering-theme-engine` on GitHub, MIT licensed. One version number covers
both: a change in what either resolves or validates changes both.

The npm package ships `dist/`, `meta/`, `docs/` and `CHANGELOG.md` (`files` in
`package.json`). The Composer dist is a git archive of the tag, without what
`.gitattributes` marks `export-ignore`: it ships `composer.json`, `php/src`, `meta/`,
`docs/`, the README, `CHANGELOG.md` and the license.

### Releasing a version

1. Bump `version` in `package.json` (semver: a change in what it resolves is a minor,
   one that breaks the public API is a major), add the version to `CHANGELOG.md` and
   merge to `master`. `composer.json` has no `version`: Composer takes it from the tag.
2. Create the tag `v<version>` on that commit and push it (`git tag v<version> && git
   push origin v<version>`).
3. `.github/workflows/publish.yml` checks that the tag matches `package.json`, runs
   typecheck and tests, builds and runs `npm publish --provenance` (provenance requires
   a public repo). It authenticates with npm trusted publishing (OIDC). A published
   version is never replaced: if it already exists, the workflow fails.
4. Packagist publishes the same tag on its own (the package's GitHub hook); there is no
   PHP publish step.

The trusted publisher is set on npmjs (the package → *Settings → Trusted Publisher →
GitHub Actions*: `Finitless-com`, `ordering-theme-engine`, `publish.yml`, no
environment, publish directly allowed). The repo keeps no npm token.

`.github/workflows/ci.yml` runs on every PR and every push to `master`: typecheck, tests
and build of the TypeScript engine, and the size budgets of the `runtime`, `css` and `colors` entries (`pnpm size`); `composer validate`, PHPUnit on PHP 8.1 and 8.3, and
the parity check (`scripts/sync-fixtures.sh` must leave `fixtures/` unchanged).

### Migrating from GitHub Packages

Up to 0.2.1 the package was published privately on GitHub Packages as
`@finitless-com/ordering-theme-engine`. To move a repo to the new name:

1. Replace the dependency with `"@orderingco/ordering-theme-engine": "<exact version>"`
   and update the imports.
2. Remove the `@finitless-com:registry=https://npm.pkg.github.com` line from the
   project's `.npmrc`, and the GitHub Packages setup from CI (the `registry-url`/`scope`
   of `actions/setup-node`, `permissions: packages: read` and the token secret).

## Contributing

How the two implementations are kept equal, and how to work on them.

### Parity between PHP and TypeScript

The PHP engine (`php/src`) is the reference: it is what the Ordering.co API runs. The TypeScript
engine must answer exactly what it answers, and CI checks it in two steps: the fixtures
are regenerated from `php/src` and must not change (`scripts/sync-fixtures.sh`), and the
TypeScript tests compare themselves with those fixtures. A change of behavior lands in
both implementations, and their fixtures, in the same PR.

Inputs, written by hand or copied from the product, which the scripts never write:

- `fixtures/cases/*.json`: the golden cases, `{ description, migrate?, values, resolved }`.
  `migrate`, when present, holds the migrations the schema declares in that case
  (`co.ordering.migrate`): a theme stored before them.
- `fixtures/schema.tuples.json`: the frozen schema of those cases (Web Marketplace 26 as
  it was, in tuples).
- `meta/reference/reference-1.0.0.json` and its version 1 map: the reference schema
  ([The reference schema](#the-reference-schema)), which the cases below start from.
- `meta/theme-schema-1.json`: the format's meta-schema, which both implementations read.

What the PHP engine answers for them:

- the `resolved` of each case: `ThemeResolver::resolve()` over the frozen schema
  (`scripts/golden-fixtures.php`; `ThemeResolverGoldenTest` checks them in PHP);
- `fixtures/schema.nodes.json`: the frozen schema converted to nodes
  (`scripts/convert-schema.php`), which the TypeScript golden test resolves over;
- `fixtures/reference.empty.json`: the empty theme over the reference schema
  (`scripts/resolve-empty.php`);
- `fixtures/reference.compiled.json`: the tuples `NodeCompiler::compile()` returns for
  the reference schema with its v1 map (`scripts/compile-schema.php`), which `compile()`
  must match, translucent colors (`alpha`) and the editor's limits (`limits`) included;
- `fixtures/php-round.json`: PHP's `round()` over the values the engine rounds
  (`scripts/php-round.php`), and `fixtures/php-strings.json`, its `version_compare()`
  and `(string)` cast of a float (`scripts/php-strings.php`);
- `fixtures/valid.json`: what `ThemeValidator::validate()` and `ThemeResolver::valid()`
  answer for themes that break every rule of each section (unknown, invalid, alias,
  alpha, duplicate, out_of_range, format; a page's list that stays a list, a mode's
  colors, numbers clamped to their limits and to the format's range on reading), and
  `ThemeValidator::settings()` and `ThemeResolver::validSettings()` for site settings
  (a case may add `limits` and `site` specs of its own), over the compiled reference
  schema (`scripts/valid-cases.php`);
  `themeErrors()`, `valid()`, `settingsErrors()` and `validSettings()` must answer the
  same;
- `fixtures/upgrade.json`: four versions made from the reference schema (1.4, 1.5,
  2.0 and 3.0, each a list of operations) and what `ThemeUpgrades` answers for themes and site
  settings between them: identity, moves (with a platform's override, a whole page and
  the previous base's own migration), a move onto a key the theme sets, `map` (an
  option, a scale, a level and a number it does not name), a color's aliases and modes,
  a type change no move takes, `drop`, the rewrite with and without its callback, the
  chain 26 → 27 → 28, a chain of one, settings (a migration within the base too), maps
  of booleans and one that merges options, and cut-downs to an older version that undo
  renames within the base, a base's moves and maps, and a chain 3 → 2 → 1
  (`scripts/upgrade-cases.php`); the TypeScript test runs the same rewrite;
- `fixtures/validate.json`: the golden cases of `validate()`
  (`scripts/validate-cases.php`). Each case is a mutation of the reference schema, a
  list of `["set", path, value]` / `["delete", path]` operations on the schema, the
  `previous`, the previous base and the v1 map, and stores what
  `ThemeSchemaPublisher::validate()` answers. The test applies the same operations and
  compares the whole answer, errors in order. The cases cover every rule: a stale v1
  map, format, product type, compiler, each
  `check()` failure, each editor rule, the schema's own colors in migrations, the
  migrations of tokens (and of a group the schema still has), of visibility, images and pages, and the v1 map (its paths the schema has not too), `outOfRange()`, semver, version order (the same content, another content, lower, two-part, an
  earlier pre-release) and `diff()` (a patch that changes a default, what the editor
  validates, or only editor texts and metadata, a minor that adds, removing with and without `$deprecated`,
  `$deprecated: "0"`, migrating (a key, a visibility key, an image and a whole page, and a
  page only partly migrated), changing the type, changing the scale, removing an
  option, removing `alpha`, a page's sections removed, renamed, reordered or changed,
  a property with a platform's default migrated or removed, removing a migration, a
  new base, all errors together and valid ones with every kind of change), the upgrade
  block against the previous base (valid, a platform's default, maps of booleans, a
  `to` the base's own migrations move on, missing, a `from` that is not the previous
  base or not below its own, every error at once, a minor that adds to it and one that
  removes from it), plus several in development mode. The script fails if a
  case does not pass the meta-schema, so `validateFull()` answers the same as
  `validate()` in all of them.

The comparison treats empty `[]` and `{}` as equal and ignores key order (JavaScript puts
numeric keys first), but compares every number exactly. If a case fails, the cause is in
the port: never adjust the fixture or the comparison.

Where PHP and JavaScript differ, the port imitates PHP (`src/php.ts`):

- PHP 8.3's `round()` rounds half away from zero and pre-rounds to 15 digits
  (`127.49999999999997` gives 128; `Math.round` gives 127). Used by color channels and
  scaled shadows.
- `[]` and `{}` are the same empty array, which is a list: an empty map in the theme
  changes nothing and a `{}` schema counts as empty.
- PHP arrays are copied on assignment: the engine never mutates the schema or theme it
  receives, and every answer is a new object.
- `===` between arrays requires the same keys in the same order.
- `preg_match()`'s `$` also accepts a trailing newline: the engine's own patterns that
  imitate it end in `\n?$`. `\s` is ASCII only.
- A string's `pattern` rule is the exception: instead of one engine imitating the
  other, both accept only the subset of regular expressions that PCRE and ECMAScript
  (`u` flag) read the same way (`ThemePattern` and `src/pattern.ts`, one scanner written
  twice; §10.2 of the schema format). Within it, PHP compiles with the `D` modifier, so
  `$` matches only at the very end as in JavaScript (`^\d{6,12}$` refuses `"123456\n"`
  in both), and both compile `.`, `\d`, `\D`, `\w` and `\W` written out as the classes
  JavaScript reads (PHP's `u` modifier would make `\d` and `\w` Unicode, and its `.`
  would match `\r`, U+2028 and U+2029). Outside the subset a pattern is
  `<path>.rules.invalid`, and no value matches it.
- `version_compare()` (pre-releases such as `26.1.0-beta`), the `(string)` cast of a
  float (14 digits, `1.0E+25`), `empty()` (`"0"` is empty: `$deprecated: "0"` does not
  deprecate), `strlen()` in bytes and `mb_strlen()` in characters.

Where JavaScript cannot imitate it: `JSON.parse()` reads `2.0` as the integer `2`, PHP as
a float. An integral number without a decimal part counts here as a PHP `int`; this only
changes the answer where PHP requires `is_int()` (a `step`'s `offset`, an image's
`hints`, an `id`, a typography `weight`, `format: 2`). The order of a map's numeric keys
also differs (JavaScript puts them first), and with it the order of their errors; no
real schema has them.

### Development

```sh
nvm use            # Node 22.23.1
pnpm install
pnpm test          # vitest: golden cases, current schema, validate(), the CLI, PHP semantics
pnpm typecheck
pnpm build         # dist/: ESM, .d.ts, the meta-schema validator and the CLI
```

`src/shape-validator.js`, the compiled meta-schema, is not versioned: `pnpm build` and
the test setup (`test/global-setup.ts`) write it. Ajv and esbuild are only used to
generate it.

The PHP side needs only docker (the host needs no PHP):

```sh
docker run --rm -u "$(id -u):$(id -g)" -v "$PWD:/app" -w /app -e COMPOSER_HOME=/tmp/composer composer:2 install
docker run --rm -u "$(id -u):$(id -g)" -v "$PWD:/app" -w /app php:8.3.35-cli vendor/bin/phpunit
```

#### Refreshing the fixtures

```sh
pnpm sync-fixtures   # bash scripts/sync-fixtures.sh
```

It runs the PHP scripts in docker, in `php:8.3.35-cli` (the PHP the fixtures record;
`PHP_IMAGE` overrides it), installing `vendor/` with `composer:2` if it is missing, and
rewrites every fixture the PHP engine answers from the inputs above. A fixture is
replaced only if its command succeeds and returns JSON. Run it after changing `php/src`
or an input, review the diff, and then `pnpm test` tells whether the TypeScript engine
still answers the same. It does not depend on the Ordering.co API.
