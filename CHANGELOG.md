# Changelog

One version covers the npm package (`@orderingco/ordering-theme-engine`) and, from 0.4.0,
the Composer package (`orderingco/ordering-theme-engine`). A minor can change what is
resolved or validated, because the engine follows the Ordering.co API; **API** marks a
change to the public API.

## Unreleased

Breaking for api-core and the CLI's users (nothing is launched, so nothing old is kept):

- **API:** `cutDown(values, schemas)` and `cutDownSettings(settings, schemas)`
  (`ThemeUpgrades::cutDown(array $values, array $schemas)`, `::cutDownSettings(array
  $settings, array $schemas)`) take the schemas from the version the values are of down
  to the client's, with the last version of each base between them, instead of
  `(values, from, to)`; they read the values with the first one (`valid()`) themselves.
- **API:** `validate()`/`validateFull()` (`ThemeSchemaPublisher::validate()`) answer
  `{ code, errors, warnings, changes, unchanged }`. Given the same version as
  `previous`, the same content is valid and `unchanged`, another content is
  `schema.version_exists` (it was `schema.version_order`).
- **API:** `changes` also carries `options_added`, `defaults_changed`, `rules_changed`
  and `sections_changed`.
- **API:** the compiled schema carries `limits` (the editor's numeric `min` and `max`,
  and those of its `fields`, by path): `ThemeSchemas::COMPILED` is 6.
- **Behavior change:** a page's sections are a key each for SchemaDiff
  (`pages.<page>.sections.<key>`, of type `section`), in `added`, `removed`, `migrated`
  and in the upgrade block's `unaccounted`: removing one in a minor is
  `<path>.removed` unless its list or page was `$deprecated` or a migration moves it,
  and a new base accounts for each.
- **Behavior change:** what of the editor's metadata validates a value is a rule:
  changing `alpha`, `custom` or the `min`/`max` of a control or of its `fields` in a
  patch is `schema.patch_changes_values`, and a minor that takes `alpha` or `custom`
  away is `<path>.alpha_removed` / `<path>.custom_removed` (`schema.incompatible`). A
  minor may still tighten the limits, `max_length` and `pattern`, add `alpha`, and change
  a section's `hideable` and `group`.
- **Behavior change:** a theme (`ThemeValidator::validate()`, `themeErrors()`) refuses a
  number outside its editor's `min`/`max` (`<path>.out_of_range`) and a typography's
  field outside its `fields` (`<path>.<field>.out_of_range`: a token's own, else the
  `tokens.typography` group's, which a component's custom style takes too), and an image
  whose URL ends in the extension of a format its key does not take
  (`images.<key>.format`); a site's settings (`ThemeValidator::settings()`,
  `settingsErrors()`) their numbers out of range. `valid()` leaves them out on reading.
- **Behavior change:** a mode's colors (`modes.<mode>.color.<name>`) are the schema's
  own, as `tokens.color`: one it does not name is `.unknown`, and `valid()` and
  `cutDown()` leave it out.
- **Behavior change:** publishing refuses a version 1 map that names what the schema
  does not have: `v1_map.v1.<path>.unknown`, `v1_map.visibility.<key>.unknown`,
  `v1_map.images.<key>.unknown`, `v1_map.pages.<page>.unknown`,
  `v1_map.pages.<page>.<section>.unknown`, `v1_map.site.<setting>.unknown`
  (`schema.invalid`; `ThemeSchemaPublisher::v1MapErrors()`, `v1MapErrors()`). The
  compiler still leaves such paths out of the tuples.
- **CLI:** `validate --previous` answers `unchanged` for the same version with the same
  content and `schema.version_exists` for another, as the API does; warnings print on
  stderr as `warning: <code>`.
- `fixtures/schema-26.0.nodes.json`: its typography's line height fields are ratios (1 to
  2, unit `x`), as the editor's rules ask; they were px (8 to 120), which every saved
  line height broke once limits bind.

Fixed:

- `valid()` and `cutDown()` (`ThemeResolver::valid()`, `ThemeUpgrades::cutDown()`) keep a
  page's sections a list when they leave out a section that is not the last one: the
  list became a map (`{"1": …}`), which the next validation refused whole
  (`pages.<page>.sections.invalid`), so the editor could not save the theme.
- A migration of `site.*` within a base moves the site's setting: `validSettings()`
  (**API**, `ThemeResolver::validSettings(array $settings, array $schema)`) applies the
  schema's `site.` migrations and leaves out what does not fit, as `valid()` a theme;
  `upgradeSettingsChain()` with one schema runs it. It was reported `migrated` and the
  value was lost.
- A property with a default of its own on a platform
  (`components.<c>.platforms.<p>.<prop>`) can be migrated and moved: the platform's
  default goes with its property, `migrated` in a minor and accounted for in a new
  base, and covered by the property's `$deprecated`. It was `.removed` /
  `upgrade.<path>.unaccounted`.
- A `map` translates a boolean, by the keys `"true"` and `"false"`, and its values may be
  booleans for a boolean destination (the meta-schema takes them): a boolean was dropped
  whatever the map said. A map from a boolean with another key, or to a boolean with a
  value that is no boolean, is `upgrade.moves.<n>.map.invalid`.
- A client of an older version keeps what a newer version renamed or moved: `cutDown()`
  undoes, newer to older, the migrations the older version lacks and, from the next
  base, that base's migrations and its upgrade block's moves (a one-to-one `map`
  inverted). A move into a key the older version already has, and a map that is not one
  to one, are not undone: the client's default shows there, as before. Values a newer
  version added still go away.

Added:

- **CLI:** `validate --against <api>` reads from the API what publishing compares the
  schema with (`GET {api}/system/theme_schemas/{product_type}` and `…/{version}`, with
  `THEME_PUBLISH_KEY`): the same version (the same content is `unchanged`), the latest of
  its base and the latest of the previous base; `--development` checks as an API in
  development mode. `publish` prints the API's `warnings`.
- In development `validate()` answers what production would refuse in `warnings`
  (`schema.version_exists`, `schema.version_order` and SchemaDiff's errors) instead of
  nothing.
- **API:** `SchemaDiff::editorRules()`, `::platformBase()`, `::canonical()`;
  `NodeFormat::limits()`; `ThemeMigrations::mapKey()`; `editorLimits()` (compile.ts) and
  `editorRules()`, `platformBase()`, `canonical()` (diff.ts). `NodeConverter::convert()`
  writes a compiled schema's `alpha` and `limits` back into the editor metadata.
- Docs: `schema-format.md` §7 (an image's format), §12 (what of the editor validates a
  value), §13 (patch and minor rules, `changes`, development warnings), §14 (platforms'
  defaults, site settings), §15 (boolean maps, cutting down to an older client, a
  platform's default in `unaccounted`), §16 (the new codes and a theme's own);
  `creating-a-schema.md` §4 and §6; the README.

## 0.6.0 (2026-10-21)

- Docs: `schema-format.md` §13–15 are the whole story of versions for a schema author:
  which change needs which tool, migrations within a base (§14: paths and kinds, what
  moves, what publish refuses, an example) and a new base's upgrade block (§15: the
  block, its steps, what happens to stored themes and older clients, every code, an
  example); `creating-a-schema.md` §6 sums them up. Errors are now §16.
- **Removed:** `co.ordering.editor.preview` (`{ page, path }`), the data of an editor
  preview no product uses. The editor's metadata takes no unknown key, so a schema that
  still carries it fails the meta-schema:
  `<path>.$extensions.co.ordering.editor.schema`, `schema.invalid`.
  `fixtures/schema-26.0.nodes.json` no longer has it.
- **API:** a base declares how a theme of the previous base takes its values, the root's
  `$extensions["co.ordering.upgrade"]`: `{ from, moves: [{ from, to, map? }], drop,
  rewrite?: { id, paths } }` (§15 of the schema format). The meta-schema takes it,
  `compile()` carries it to the tuples as `upgrade`, and `check()` refuses one of
  another shape (`upgrade.invalid`). `ThemeSchemas::COMPILED` is 5.
- **API:** `checkUpgrade(next, previousBase)` (`ThemeUpgrades::check()`): the block
  against the last version of the previous base, with `upgrade.required`,
  `upgrade.from.invalid`, `upgrade.moves.<n>.from.unknown`, `.to.unknown`, `.kind`,
  `.map.invalid`, `.duplicate`, `upgrade.drop.<n>.unknown` and
  `upgrade.<path>.unaccounted`. `validate()`/`validateFull()` take `previousBase` (PHP:
  `ThemeSchemaPublisher::validate()`'s fifth argument) and answer them as
  `schema.invalid`; the CLI's `validate` and `publish` take `--previous-base <file>`.
- **API:** `upgrade(values, next, previous, rewrite?)` and `upgradeChain(values,
  schemas, rewrite?)` (`ThemeUpgrades::apply()`, `::chain()`): a theme's values read
  through each base's step, ending in `valid()`; `upgradeSettings()` and
  `upgradeSettingsChain()` (`::settings()`, `::settingsChain()`) for a site's settings;
  `cutDown(values, from, to)` and `cutDownSettings()` (`::cutDown()`,
  `::cutDownSettings()`): a version's values as an older version reads them. Golden
  cases in `fixtures/upgrade.json`.
- Within a base the upgrade block only grows, as the migrations: a minor that drops a
  move or a path, or changes `from` or the rewrite, is `schema.upgrade_removed`.
- A migration may carry a `map` (the moves of an upgrade block do): the value it moves
  is translated, one without an entry dropped, and `from` may then be `to`.
- PHP writes an empty `co.ordering.migrate` (and the block's empty lists) as a list for
  the meta-schema: an empty list of migrations failed it in PHP and passed it in
  TypeScript.
- **API:** `valid(values, schema)` in TypeScript, `ThemeResolver::valid()`: what of a
  stored theme fits its schema, migrations applied, the rest left out; and
  `themeErrors()` and `settingsErrors()`, `ThemeValidator::validate()` and
  `::settings()`. Golden cases in `fixtures/valid.json`.
- `co.ordering.migrate` moves `visibility`, `images` and `pages` paths (§14.1 of the
  schema format): a visibility key, an image, a whole page, a page's footer, its list of
  sections, or one section, which moves with its `visible`, renamed in its place within
  the page or to the end of another page's list. They were all `migrate.<n>.invalid`.
  In those sections `from` must have the shape of a path of the same kind as `to` (a
  footer does not become a section); otherwise, `migrate.<n>.invalid`.
- **Behavior change:** each migration also moves the same path under every platform the
  theme overrides (`platforms.<platform>.…`): a platform's override of a migrated
  component property, visibility key or page moved nowhere and was left out on reading.
- `kindAt()` (PHP's `ThemeSchemas::kindAt()`) answers `bool` for a visibility key or a
  page's footer, `image`, `page`, `sections` and `section` for the schema's own keys. The
  `v1` block still takes only tokens, components and site settings.
- On publish, the `footer` and `sections` of a page a migration moves as a whole are
  `migrated`, not `removed`.
- `kindAt()` gives every token a kind, its group (`typography`, `opacity`, `radius`…;
  still `color`, `font` and `layout_mode`), by the schema's own names as it did for
  colors: a token of any group can be migrated within its group, and a `v1` map may
  name any token the schema has.
- **Behavior change:** a migration of a token (`tokens.<group>.<name>`) rewrites every
  alias to it the theme stores (`{color.brand}` becomes `{color.primary}`), which was
  left naming a token the schema no longer has and dropped on reading; a color's also
  moves its value in each mode (`modes.<mode>.color.<name>`).
- **Behavior change:** `check()` rejects a migration whose `from`, in `tokens` or
  `components`, is a group the schema still has (`tokens.color`,
  `components.button.base`, a variant): `migrate.<n>.invalid`. It passed as a key the
  schema no longer has, and moved a whole group themes still set.

## 0.5.1 (2026-10-20)

- **Behavior change:** a string's `pattern` rule must be in the subset of regular
  expressions that PCRE and ECMAScript (`u` flag) read the same way (§10.2 of the schema
  format), checked by one scanner in both engines (PHP's `ThemePattern`,
  `src/pattern.ts`). A pattern outside it that passed before, because PCRE or JavaScript
  compiled it (`\A`, `a++`, `[^]`, `\s`, `\b`, a lone `]`, a lookbehind…), is now
  `<path>.rules.invalid`, and no value matches it.
- **Behavior change:** within the subset a value matches the same in both. PHP compiles
  the pattern with the `D` modifier, so `$` matches only at the very end (`"123456\n"` no
  longer passes `^\d{6,12}$` in PHP); `\d` and `\w` are ASCII in PHP too (its `u`
  modifier made them Unicode, so `^\d{6,12}$` took `"٣٣٣٣٣٣"`); and `.` no longer
  matches `\r`, U+2028 or U+2029 in PHP. The engine's other regular expressions are
  unchanged.
- `siteDefaults()` (and PHP's `ThemeSchemas::siteDefaults()`) throws a `TypeError` when
  given a schema in nodes or the whole answer of `compile()` (`tuples()`), instead of
  answering the raw node tree or `{}`. What it answers for a compiled schema is unchanged.
- CLI `defaults`: a schema that does not compile exits 1 and prints the compiler's
  errors (it exited 0), and one that is not in nodes exits 1 with a one-line message
  (it crashed with a stack).
- CLI `publish`: `THEME_PUBLISH_REQUIRED` also takes `true`, in any case.
- The npm package ships `docs/` and this changelog. The README links to the docs by
  absolute URL and documents `modes`, what `resolve()` resolves, `--dry-run`, the exit
  codes and the per-object compile cache; §12.3 of the schema format lists the editor
  rules in the order they run.

## 0.5.0 (2026-10-20)

- **API:** `check(compiled, nodes?)` takes the schema in nodes it was compiled from and
  then checks its editor metadata; `checkEditor(schema)` runs those rules alone (§12.3 of
  the schema format). PHP: `ThemeSchemas::check($schema, $nodes)` and
  `Schema\EditorRules::check()`.
- `validate()` and `validateFull()` include the editor rules, as the Ordering.co API
  does: a schema that passed 0.4.0 can fail with `schema.invalid`.

## 0.4.0 (2026-10-20)

- **API:** the PHP engine the Ordering.co API runs is a Composer package,
  `orderingco/ordering-theme-engine` (namespace `Ordering\ThemeEngine\`), and the
  fixtures are generated from it in this repo.
- A token color has a kind only when the schema names it (the catalog's when the schema
  names none): `ThemeSchemas::kindAt()`, and with it the checks of the v1 map and the
  migrations, read the schema's own colors.

## 0.3.1 (2026-10-20)

- Published through npm trusted publishing, with provenance. README and docs in English.

## 0.3.0 (2026-10-20)

- **API:** renamed to `@orderingco/ordering-theme-engine`, public on npmjs under MIT (it
  was `@finitless-com/ordering-theme-engine` on GitHub Packages).
- **API:** `validateFull()` (the meta-schema, then `validate()`) and `validateShape()`,
  with the meta-schema compiled into the package (no runtime dependencies).
- **API:** the `ordering-theme` CLI: `validate`, `defaults` and `publish`.
- A scale change within a base is incompatible (`<path>.scale_changed`).
- `resolve()` applies the schema's migrations (`co.ordering.migrate`), and `compile()`
  lists the colors marked translucent (`alpha`).
- A visibility key may set its default per platform, a visibility group may name the
  pages it is shown in, a token may be narrowed (`token_options`), a property may carry
  rules (`custom`, `max_length`, `pattern`) and an image hints its safe area.
- A typography's line height is a ratio of its size (0.8 to 3), not px.
- The schema format and how to create a schema are documented under `docs/`.

## 0.2.1 (2026-10-19)

- Each schema names its own colors, text styles and opacities.
- A matrix role can be legible over a color, and the editor can mark a color translucent.

## 0.2.0 (2026-10-19)

- **API:** `validate(schema, { previous, v1Map, development })`, `check()`, `diff()`
  and `outOfRange()`: what the Ordering.co API answers before publishing a schema. The
  meta-schema ships as `meta/theme-schema-1.json`.

## 0.1.0 (2026-10-19)

- **API:** `resolve()`, `defaults()`, `compile()` and `siteDefaults()`: the Ordering.co
  API's theme resolution in TypeScript. Published privately on GitHub Packages as
  `@finitless-com/ordering-theme-engine`.
