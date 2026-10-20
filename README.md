# @orderingco/ordering-theme-engine

api-core's v2 theme engine in TypeScript. It resolves a theme exactly as api-core's
`ThemeResolver::resolve()` does, so every product (the web, the Dashboard, the apps)
computes the same result without copying the rules: local defaults, zero-latency
preview and CI artifacts. It also validates a schema as
`ThemeSchemaPublisher::validate()` does, so a product's CI knows before publishing what
api-core will answer.

No runtime dependencies. ESM, strict TypeScript, Node 22. It ships a CLI,
`ordering-theme`, that validates a schema, computes its defaults and publishes it.

Schema format: [`docs/schema-format.md`](docs/schema-format.md). Writing a product's
schema: [`docs/creating-a-schema.md`](docs/creating-a-schema.md).

## Installation

```sh
pnpm add -E @orderingco/ordering-theme-engine   # or npm i -E, yarn add -E
```

Pin an exact version in `package.json` (`"@orderingco/ordering-theme-engine": "0.3.1"`):
a minor can change what it resolves, because it follows api-core.

## Usage

```ts
import { resolve, defaults } from '@orderingco/ordering-theme-engine'
import schema from './theme.schema.json' // the schema in nodes

const theme = resolve(schema, site_theme.values) // what api-core serves
const empty = defaults(schema)                   // the empty theme, resolved
```

Validating a schema before publishing it:

```ts
import { validateFull } from '@orderingco/ordering-theme-engine'

const { code, errors, changes } = validateFull(schema, { previous, v1Map }) // meta-schema and the rest, as api-core
// code: null (valid), 'schema.format', 'schema.invalid', 'schema.version_order' or 'schema.incompatible'
```

The meta-schema is compiled into the package. `validateShape(schema)` runs that stage
alone.

### Public API

- `resolve(schema, values, options?)`: the theme over its schema's defaults, every alias
  resolved. This is `ThemeResolver::resolve()`. Throws a `TypeError` if the schema is
  not in nodes.
- `defaults(schema, options?)`: the empty theme resolved, what a site whose theme
  changes nothing serves.
- `compile(schema, v1Map?)`: the node schema as the tuples the engine reads
  (`NodeCompiler`), with its errors. `resolve` uses it and caches the result per object.
- `siteDefaults(compiled)`: the defaults of the `site` block (they belong to the site,
  not the theme).
- `validateFull(schema, { previous?, v1Map?, development? })`: what api-core's
  `ThemeSchemaPublisher::validate()` answers, `{ code, errors, changes }`, meta-schema
  included (see below).
- `validate(schema, options?)`: the same without the meta-schema, with the same codes,
  the same errors in the same order and the same changes as api-core.
- `validateShape(schema)`: the schema's errors against the meta-schema, as
  `ThemeSchemas::meta()`: one per path, `{ path, code: '<path>.schema', message }`
  (`message` is the validator's explanation; api-core does not send it). `[]` if it
  passes.
- `check(compiled)`: `ThemeSchemas::check()` over the tuples from `compile()`.
- `outOfRange(schema)`: numeric defaults outside the `min`/`max` of their editor
  control (`<path>.default_out_of_range`).
- `diff(previous, next)`: `SchemaDiff::compare()`, `{ errors, changes }` with
  `changes: { added, removed, migrated, deprecated }`.
- The format's meta-schema, `@orderingco/ordering-theme-engine/meta/theme-schema-1.json`
  (JSON Schema 2020-12), copied verbatim from api-core.
- Types: `NodeSchema`, `ThemeValues`, `ResolvedTheme`, `ResolveOptions`,
  `ValidateOptions`, `ValidationResult`, `ShapeError`, `Changes`, `V1Map`…

`options.emptyMaps`: PHP does not distinguish an empty map from an empty list and serves
both as `[]`. By default the output does the same, so its JSON matches the API's; with
`'object'` they come out as `{}`.

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
- `defaults`: the empty theme resolved (`defaults()`), what api-core serves for a theme
  that changes nothing, as indented JSON with a trailing newline. Without `--out` it
  prints it; with `--out` it writes it. `--check` (requires `--out`) compares that file
  instead of writing it and exits 1 if it is out of date, for CI when the product
  commits the file. `--site` adds the site settings' defaults (`siteDefaults()`) under
  `site`.
- `publish`: validates the schema locally and, if it passes, sends it to api-core:
  `POST {THEME_PUBLISH_API}/system/theme_schemas` with `{ schema, v1_map?, dry_run? }`
  and the key in `X-Theme-Publish-Key-X`. Product type and version come from the schema
  (`product_type`, `version`). Without `--v1-map` no map is sent and api-core uses the
  last one published for the product type. `--dry-run`: api-core checks everything and
  saves nothing (`valid`).

  Exits 0 if api-core answers `created`, `replaced` (a product in development), `valid`
  or `unchanged` (that version is already published with the same content, so a deploy
  can be repeated), and prints the changes. A rejection exits 1 and prints its errors
  and codes: `schema.invalid` (422), `schema.incompatible` (422),
  `schema.version_order` (409), `schema.version_exists` (409: that version is already
  published with different content, so bump it) or the rejected key (401).

  Environment: `THEME_PUBLISH_API` (the API with its version, e.g.
  `https://apiv4.ordering.co/v400`) and `THEME_PUBLISH_KEY` (the environment's publish
  key, api-core's `THEME_PUBLISH_KEY`). If either is missing, it validates and skips
  publishing (exit 0), unless `--required` or `THEME_PUBLISH_REQUIRED=1` is set, which
  exits 1: a deploy that cannot publish its schema does not deploy.

Exit codes: 0 done; 1 the schema or api-core's answer fails, or a file cannot be read
or is not JSON; 2 a usage error (unknown command or option, missing `<schema.json>`
argument, `--check` without `--out`).

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
   `component_derive`, `matrix`, migrations, pages and the v1 map) and of
   `outOfRange()`. In both cases `version.semver` is added if the version is not
   `major.minor.patch`.
3. With `previous` (the last published version of its base): `schema.version_order` if
   the version is not greater within the same base (as PHP's `version_compare()`), and
   `schema.incompatible` with the errors of `diff()`. If valid, `changes` says what
   changes; without `previous`, `changes` is null.

`validate()` is the same without the meta-schema in stage 2.

`development: true` is api-core's development mode: stage 3 rejects nothing (neither
version order nor incompatibilities), but `changes` is still returned.

`v1Map` is the v1 path map published with the schema (`v1_map`). It compiles the `v1`
blocks, the v1 paths of visibility and images, those of each page's sections and those
of `site`, which `check()` also validates. Without it there are no v1 paths. api-core,
given no map, uses the last one published for the product type (or the one it keeps in
`resources/themes/<product_type>/v1-map.json`): pass that map to get the same result.

What it does **not** do, and api-core does:

- **Identical meta-schema errors.** Whether a schema passes is the same here and in
  api-core, but api-core uses another validator (opis/json-schema): when it fails, the
  paths it names can differ (under an `anyOf`, one names the branches and the other only
  the node). The package compiles the meta-schema with Ajv at build time
  (`scripts/build-shape-validator.mjs`, which writes the unversioned
  `src/shape-validator.js`).
- **The registry.** api-core also rejects a version already published with different
  content (`schema.version_exists`, 409), and looks up `previous` itself (the last one
  of the base).

## Where the rules come from

From the schema. The engine is generic: whatever is specific to a product is declared by
its `theme.schema.json` in the node format ([`docs/schema-format.md`](docs/schema-format.md)),
under `$extensions["co.ordering"]`:

- `scale` and `options`: each property's type;
- `inherits`: components that follow another;
- `follows`: what follows Identity (overall shadow, radius and border);
- `derive`: derived colors (`same`, `shade`, `mix`, `legible`) and a component's derived
  values (`step`, `shade`, `inset`);
- `matrix`: the button's colors in each appearance.

The operations (HSL, mixing, WCAG contrast, shadow scales, ordering a page's sections)
belong to the engine and are the same for every product. A schema that declares no rules
for a category uses the engine's for that category, as api-core does; once it declares
one, the engine's rules for that category no longer apply (§11 of
[`docs/schema-format.md`](docs/schema-format.md)). A node without `$type` takes its
group's. `co.ordering.editor` and the `editor` block belong to the editor; the engine
does not read them.

## Parity with api-core

api-core is the reference. This package passes the same golden cases as api-core's
`ThemeResolverGoldenTest`:

- `fixtures/cases/*.json`: `{ description, migrate?, values, resolved }`, copied from
  api-core's `tests/fixtures/themes/cases`. Each theme (`values`) must resolve to its
  `resolved`. `migrate`, when present, holds the migrations the schema declares in that
  case (`co.ordering.migrate`): a theme stored before them.
- `fixtures/schema.nodes.json`: the frozen schema of those cases
  (`schema-marketplace_website-26.0.json`), converted to nodes by api-core's
  `ordering:theme-schema:convert`.
- `fixtures/schema-26.0.nodes.json` and `fixtures/schema-26.0.empty.json`: the product's
  current schema (`resources/themes/marketplace_website/26.0.json`) and the empty theme
  api-core resolves with it (`scripts/resolve-empty.php`).
- `fixtures/schema-26.0.compiled.json`: the tuples api-core's `NodeCompiler::compile()`
  returns for the current schema with its v1 map (`scripts/compile-schema.php`), which
  `compile()` must match, translucent colors (`alpha`) included.
- `fixtures/php-round.json`: PHP's `round()` over the values the engine rounds
  (`scripts/php-round.php`), and `fixtures/php-strings.json`, its `version_compare()`
  and `(string)` cast of a float (`scripts/php-strings.php`).
- `fixtures/validate.json`: the golden cases of `validate()`.
  `scripts/validate-cases.php` runs in the container: it starts from the current schema
  (with version `26.0.0` and the two `signup_page` paddings inside their control, the
  "base") and applies to each case a mutation, a list of `["set", path, value]` /
  `["delete", path]` operations on the schema, the `previous` and the v1 map
  (`fixtures/v1-map.json`), and stores what `ThemeSchemaPublisher::validate()` answers.
  The test applies the same operations and compares the whole answer, errors in order.
  The cases cover every rule: the original as is (out of range and without semver),
  format, product type, compiler, each `check()` failure, `outOfRange()`, semver,
  version order (equal, lower, two-part, an earlier pre-release) and `diff()` (a patch
  that changes a default or only editor texts and metadata, a minor that adds, removing
  with and without `$deprecated`, `$deprecated: "0"`, migrating, changing the type,
  changing the scale, removing an option, removing a migration, a new base, all errors
  together and a valid one with all four kinds of change), plus several in development
  mode. The script fails if a case does not pass the meta-schema, so `validateFull()`
  answers the same as `validate()` in all of them.

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
- `preg_match()`'s `$` also accepts a trailing newline: the patterns that imitate it end
  in `\n?$`. `\s` is ASCII only.
- `version_compare()` (pre-releases such as `26.1.0-beta`), the `(string)` cast of a
  float (14 digits, `1.0E+25`), `empty()` (`"0"` is empty: `$deprecated: "0"` does not
  deprecate), `strlen()` in bytes and `mb_strlen()` in characters.

Where JavaScript cannot imitate it: `JSON.parse()` reads `2.0` as the integer `2`, PHP as
a float. An integral number without a decimal part counts here as a PHP `int`; this only
changes the answer where PHP requires `is_int()` (a `step`'s `offset`, an image's
`hints`, an `id`, a typography `weight`, `format: 2`). The order of a map's numeric keys
also differs (JavaScript puts them first), and with it the order of their errors; no
real schema has them.

### Out of scope

- **It does not validate themes.** It assumes a valid theme: api-core validates it on
  save and, when serving it, drops whatever no longer fits the schema
  (`ThemeResolver::valid()`). A theme the API would not accept may resolve differently
  here. Like `valid()`, it does first move what the schema migrates
  (`co.ordering.migrate`).
- `resolve()` does not validate the schema (that is `validateFull()`'s job), nor does
  `validate()` check the meta-schema (see above).
- It does not convert or project v1 themes (`ThemeV1Bridge`), optimize images or fetch
  the site settings (`values.site`): that belongs to the API.
- It only reads node schemas (api-core converts the ones registered as tuples).

## Development

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

### Refreshing the fixtures

The fixtures come from api-core and are never written by hand. With api-core's dev
container running (`docker compose -f docker-compose.dev.yml up -d`):

```sh
pnpm sync-fixtures                          # api-core in ../api-core
API_CORE=/path/to/api-core pnpm sync-fixtures
```

The script only reads api-core (uncommitted changes included): it copies the cases, the
schema, the v1 map and the meta-schema (into `meta/`), and runs in the container the
node conversion, `scripts/resolve-empty.php`, `scripts/compile-schema.php`,
`scripts/php-round.php`, `scripts/php-strings.php` and `scripts/validate-cases.php`,
which write to the container's `/tmp` or to stdout. A fixture is replaced only if the
command succeeds and returns JSON. Then `pnpm test` tells whether the engine still
answers the same as api-core.

## Distribution

Published publicly on npmjs as `@orderingco/ordering-theme-engine` (the `orderingco` npm
organization). The repo is `Finitless-com/ordering-theme-engine` on GitHub, MIT
licensed.

### Releasing a version

1. Bump `version` in `package.json` (semver: a change in what it resolves is a minor,
   one that breaks the public API is a major) and merge to `master`.
2. Create the tag `v<version>` on that commit and push it (`git tag v0.3.0 && git push
   origin v0.3.0`).
3. `.github/workflows/publish.yml` checks that the tag matches `package.json`, runs
   typecheck and tests, builds and runs `npm publish --provenance` (provenance requires
   a public repo). It authenticates with npm trusted publishing (OIDC). A published
   version is never replaced: if it already exists, the workflow fails.

The trusted publisher is set on npmjs (the package → *Settings → Trusted Publisher →
GitHub Actions*: `Finitless-com`, `ordering-theme-engine`, `publish.yml`, no
environment, publish directly allowed). The repo keeps no npm token.

`.github/workflows/ci.yml` runs typecheck, tests and build on every PR and every push to
`master`.

### Migrating from GitHub Packages

Up to 0.2.1 the package was published privately on GitHub Packages as
`@finitless-com/ordering-theme-engine`. To move a repo to the new name:

1. Replace the dependency with `"@orderingco/ordering-theme-engine": "<exact version>"`
   and update the imports.
2. Remove the `@finitless-com:registry=https://npm.pkg.github.com` line from the
   project's `.npmrc`, and the GitHub Packages setup from CI (the `registry-url`/`scope`
   of `actions/setup-node`, `permissions: packages: read` and the token secret).
