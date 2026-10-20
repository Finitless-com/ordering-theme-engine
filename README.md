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
  ships a CLI, `ordering-theme`, that validates a schema, computes its defaults and
  publishes it.

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

Pin an exact version in `package.json` (`"@orderingco/ordering-theme-engine": "0.5.1"`):
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

const { code, errors, changes } = validateFull(schema, { previous, v1Map }) // meta-schema and the rest, as the Ordering.co API
// code: null (valid), 'schema.format', 'schema.invalid', 'schema.version_order' or 'schema.incompatible'
```

The meta-schema is compiled into the package. `validateShape(schema)` runs that stage
alone.

### Public API

- `resolve(schema, values, options?)`: the theme over its schema's defaults
  (`ThemeResolver::resolve()`): the aliases of `components` and of
  `platforms.<platform>.components` replaced by their tokens' values, and each
  `tokens.typography` style with the `family` of its font role. `tokens` and `modes`
  stay as they are: their values are literals, and neither a schema nor a theme may put
  an alias there. Throws a `TypeError` if the schema is not in nodes.
- `defaults(schema, options?)`: the empty theme resolved, what a site whose theme
  changes nothing serves.
- `compile(schema, v1Map?)`: the node schema as the tuples the engine reads
  (`NodeCompiler`), with its errors: `{ schema, errors }`. `resolve` uses it and caches
  the result per object.
- `siteDefaults(compile(schema).schema)`: the defaults of the `site` block (they belong
  to the site, not the theme), `{}` without one. It reads the tuples: given the schema
  in nodes, or compile()'s whole `{ schema, errors }`, it throws a `TypeError`.
- `validateFull(schema, { previous?, v1Map?, development? })`: what the Ordering.co API's
  `ThemeSchemaPublisher::validate()` answers, `{ code, errors, changes }`, meta-schema
  included (see below).
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
- `diff(previous, next)`: `SchemaDiff::compare()`, `{ errors, changes }` with
  `changes: { added, removed, migrated, deprecated }`.
- The format's meta-schema, `@orderingco/ordering-theme-engine/meta/theme-schema-1.json`
  (JSON Schema 2020-12), the same file the PHP engine reads.
- Types: `NodeSchema`, `ThemeValues`, `ResolvedTheme`, `ResolveOptions`,
  `ValidateOptions`, `ValidationResult`, `ShapeError`, `Changes`, `V1Map`…

`options.emptyMaps`: PHP does not distinguish an empty map from an empty list and serves
both as `[]`. By default the output does the same, so its JSON matches the API's; with
`'object'` they come out as `{}`. It is all or nothing: it applies to every empty map of
the answer (`modes`, and `platforms` or `images` when the schema has none…), with no
choice per key.

`modes` is what the theme sets per mode, `{ <mode>: { color: { <name>: <color> } } }`:
the colors a client uses instead of `tokens.color.<name>` in that mode, as the theme
stores them (the engine neither derives nor resolves them). The only mode is `dark`
(`ThemeFormat::MODES`). A schema declares no modes, so `defaults()` always gives it
empty: `[]` by default, `{}` with `emptyMaps: 'object'`.

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
$answer = ThemeSchemaPublisher::validate($nodes, $v1Map, $previous, $development);
// ['code' => null|'schema.format'|'schema.invalid'|'schema.version_order'|'schema.incompatible', 'errors' => [...], 'changes' => ...]
```

The engine reads a schema as tuples: `ThemeSchemas::tuples()` checks one in nodes
against the meta-schema and compiles it (one already in tuples comes back as it is).
A null `$v1Map` compiles no version 1 paths: the Ordering.co API passes the map it keeps for the
product type.

Public entry points:

- `ThemeResolver`: `resolve($values, $schema, &$unresolved)` (the theme over its
  schema's defaults, its aliases resolved as `resolve()` resolves them; `$unresolved`
  gets the values before their aliases), `valid()` (what of a stored theme fits its schema, migrations applied),
  `inherit()`, `merge()`, `resolveAliases()`.
- `ThemeSchemas`: `tuples()`, `meta()` (the meta-schema errors), `check()` (a schema in
  tuples and, given the nodes it was compiled from, their editor metadata), `defaults()`,
  `siteDefaults()` (of a schema in tuples; the nodes or `tuples()`'s whole answer throw
  a `TypeError`), `declare()`, `kindAt()`, `specAt()`,
  `propertySpec()`, `isComponent()`; the constants `PRODUCT_TYPES`, `KEY`, `VERSION`
  and `COMPILED` (the revision of what `tuples()` answers, for whoever caches it).
- `ThemeSchemaPublisher`: `validate()`, what publishing checks before the registry
  (`validateFull()` in TypeScript), and `outOfRange()`.
- `ThemeValidator`: `validate()` (a theme against its schema), `settings()` (a site's
  settings), `codes()`, `value()`.
- `ThemeMigrations` (`apply()`, `check()`), `ThemeSources` (`of()`, `aliases()`: where
  each value comes from, for the editor), `ThemeImage` (`formats()`, `validFormats()`,
  `validHints()`, `FORMATS`, `DEFAULT_FORMATS`).
- `Schema\NodeCompiler` (`compile()`, `isNodes()`), `Schema\NodeConverter` (`convert()`
  from tuples to nodes, `declare()`, `json()`), `Schema\SchemaDiff` (`compare()`),
  `Schema\EditorMetadata` (`inject()`), `Schema\EditorRules` (`check()`, the editor
  metadata's rules, and `translucent()`).
- `ThemeFormat`, `ThemeColors`, `ThemeDerivations`, `ThemeShadows`, `ThemeOverall` and
  `ThemePages` hold the format's catalog and the operations the resolver applies;
  `ThemePattern` (`source()`, `matches()`, `regex()`), a string's `pattern` rule.

What stays in the Ordering.co API: the registry of published schemas and its cache, publishing
(`schema.version_exists`, looking up `previous`), reading a site's theme, version 1
themes (`ThemeV1Bridge`), the site's settings, stored-data migrations and inspecting an
uploaded image.

## The `ordering-theme` CLI

What a product's repo runs on its schema. With the package installed,
`pnpm exec ordering-theme` (or `npx ordering-theme`); without it,
`npx @orderingco/ordering-theme-engine <command>`.

```sh
ordering-theme validate theme.schema.json [--v1-map v1-map.json] [--previous last.json]
ordering-theme defaults theme.schema.json [--out src/theme.defaults.json] [--check] [--site]
ordering-theme publish  theme.schema.json [--v1-map v1-map.json] [--dry-run] [--required]
ordering-theme --help      # -h
ordering-theme --version   # -v
```

- `validate`: `validateFull()`. Prints one error per line (its code, with the path if
  the code does not include it, plus the validator's message for meta-schema errors) and
  exits 1; exits 0 if valid. With `--previous` (the last published version of its base)
  it also checks the version order and that nothing breaks, and prints the changes.
- `defaults`: the empty theme resolved (`defaults()`), what the Ordering.co API serves for a theme
  that changes nothing, as indented JSON with a trailing newline. Without `--out` it
  prints it; with `--out` it writes it. `--check` (requires `--out`) compares that file
  instead of writing it and exits 1 if it is out of date, for CI when the product
  commits the file. `--site` adds the site settings' defaults (`siteDefaults()`) under
  `site`, and leaves `site` out when the schema has none. A schema that is not in nodes,
  or that does not compile (its compiler errors are printed), exits 1 and writes
  nothing; it does not run the rest of `validate`.
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
  can be repeated), and prints the changes. A rejection exits 1 and prints its errors
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
- 1: the schema fails (`validate`, `publish`'s local check, a `defaults` schema not in
  nodes or that does not compile), `--check` finds the file out of date, the Ordering.co
  API rejects or cannot be reached, a `--required` publish lacks its environment, or a
  file cannot be read or is not JSON;
- 2: a usage error, with the reason on stderr: no command (the usage is printed on
  stderr), an unknown command or option, no `<schema.json>` or more than one, and
  `--check` without `--out`.

In CI, for example:

```sh
ordering-theme defaults theme.schema.json --out src/theme.defaults.json --check
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
   nodes) and of `outOfRange()`. In both cases `version.semver` is added if the version
   is not `major.minor.patch`.
3. With `previous` (the last published version of its base): `schema.version_order` if
   the version is not greater within the same base (as PHP's `version_compare()`), and
   `schema.incompatible` with the errors of `diff()`. If valid, `changes` says what
   changes; without `previous`, `changes` is null.

`validate()` is the same without the meta-schema in stage 2.

`development: true` is the Ordering.co API's development mode: stage 3 rejects nothing (neither
version order nor incompatibilities), but `changes` is still returned.

`v1Map` is the v1 path map published with the schema (`v1_map`). It compiles the `v1`
blocks, the v1 paths of visibility and images, those of each page's sections and those
of `site`, which `check()` also validates. Without it there are no v1 paths. The
Ordering.co API (api-core repo), given no map, uses the last one published for the
product type (or the one it keeps in `resources/themes/<product_type>/v1-map.json`):
pass that map to get the same result.

What it does **not** do, and the Ordering.co API does:

- **Identical meta-schema errors.** Whether a schema passes is the same here and in
  the Ordering.co API, but the PHP engine uses another validator (opis/json-schema): when it fails, the
  paths it names can differ (under an `anyOf`, one names the branches and the other only
  the node). The package compiles the meta-schema with Ajv at build time
  (`scripts/build-shape-validator.mjs`, which writes the unversioned
  `src/shape-validator.js`).
- **The registry.** The Ordering.co API also rejects a version already published with different
  content (`schema.version_exists`, 409), and looks up `previous` itself (the last one
  of the base).

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

- **It does not validate themes.** It assumes a valid theme: the Ordering.co API validates it on
  save and, when serving it, drops whatever no longer fits the schema
  (`ThemeResolver::valid()`). A theme the API would not accept may resolve differently
  here. Like `valid()`, it does first move what the schema migrates
  (`co.ordering.migrate`).
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
and build of the TypeScript engine; `composer validate`, PHPUnit on PHP 8.1 and 8.3, and
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
- `fixtures/schema-26.0.nodes.json` and `fixtures/v1-map.json`: the product's current
  schema and its version 1 map (copies of the Ordering.co API's
  `resources/themes/marketplace_website/`).
- `meta/theme-schema-1.json`: the format's meta-schema, which both implementations read.

What the PHP engine answers for them:

- the `resolved` of each case: `ThemeResolver::resolve()` over the frozen schema
  (`scripts/golden-fixtures.php`; `ThemeResolverGoldenTest` checks them in PHP);
- `fixtures/schema.nodes.json`: the frozen schema converted to nodes
  (`scripts/convert-schema.php`), which the TypeScript golden test resolves over;
- `fixtures/schema-26.0.empty.json`: the empty theme over the current schema
  (`scripts/resolve-empty.php`);
- `fixtures/schema-26.0.compiled.json`: the tuples `NodeCompiler::compile()` returns for
  the current schema with its v1 map (`scripts/compile-schema.php`), which `compile()`
  must match, translucent colors (`alpha`) included;
- `fixtures/php-round.json`: PHP's `round()` over the values the engine rounds
  (`scripts/php-round.php`), and `fixtures/php-strings.json`, its `version_compare()`
  and `(string)` cast of a float (`scripts/php-strings.php`);
- `fixtures/validate.json`: the golden cases of `validate()`
  (`scripts/validate-cases.php`). It starts from the current schema (with version
  `26.0.0`, the two `signup_page` paddings inside their control and the editor
  metadata the 26.0 schema gets wrong made right, `editor_fixes()`: the "base") and
  applies to each case a mutation, a list of `["set", path, value]` / `["delete", path]`
  operations on the schema, the `previous` and the v1 map, and stores what
  `ThemeSchemaPublisher::validate()` answers. The test applies the same operations and
  compares the whole answer, errors in order. The cases cover every rule: the original
  as is (out of range, without semver and with the editor errors of 26.0), format, product type, compiler, each
  `check()` failure, each editor rule, the schema's own colors in migrations and the v1
  map, `outOfRange()`, semver, version order (equal, lower, two-part, an
  earlier pre-release) and `diff()` (a patch that changes a default or only editor texts
  and metadata, a minor that adds, removing with and without `$deprecated`,
  `$deprecated: "0"`, migrating, changing the type, changing the scale, removing an
  option, removing a migration, a new base, all errors together and a valid one with
  all four kinds of change), plus several in development mode. The script fails if a
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
