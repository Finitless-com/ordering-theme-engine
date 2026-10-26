# The product schema for a native app

How to write a schema is the web skill's: read `../theme-frontend-web/references/schema.md`
(skeleton, tokens, editor metadata and its rules, CLI scripts, versions). This file is
what changes for a native app.

## 1. Binaries in the field: versions are forever

A website deploys the schema version it reads; a native binary keeps the version it was
built with for months. The engine handles it (schema-format.md §15.2, cut down):

- the app sends its schema version with every theme request (`theme_schema_version`,
  read from the compiled file, three parts);
- api-core resolves the theme with the product type's current version and **cuts it
  down** to the client's: renames and moves are undone, a value of another kind at the
  same path is dropped, what the old version does not accept takes the client's default;
- a client that sends nothing, an unknown version or a newer one gets the current
  version — so **every version shipped in a binary must be published** (registered) in
  each api-core environment before that binary goes out, and must never be removed.

What follows for every later change:

- prefer minors; a new base (major) still reaches old binaries through the cut-down, but
  only what its `upgrade` block can move back;
- prefer renames (they invert cleanly) to merges: when a move lands on a key the old
  version already has, the old client keeps its own value and the moved one is lost to
  it;
- a new key never reaches old binaries: the defaults compiled into each binary must look
  right on their own;
- the compiled file is part of the binary: changing the schema changes nothing in
  installed apps until a new build.

## 2. Product type and platforms

- `product_type`: `marketplace_app`, `franchise_app`, `store_app`, `driver_app` or
  `kiosk_app` (schema-format.md §1). A native app has its own schema, with its own
  defaults (pt), even when a website of the same product exists.
- `platforms` are free names (`^[a-z0-9_]+$`). Declare one only when an author needs a
  different value there: `tablet` for an app that ships a tablet layout is the usual
  one. `ios`/`android` are rarely needed — what differs by platform is usually rendering
  (shadows, fonts), which the runtime absorbs. A platform overrides component `base`
  properties, visibility keys and pages, never tokens; how platforms combine is the
  client's decision, written once in the runtime.
- Dark mode: a schema declares no modes. A served theme may carry `modes.dark`, the
  theme's own literal colors for the schema's color tokens, which the client applies
  itself; derived colors and components (resolved with the light values) are not
  recomputed. Leave it out of the first conversion unless the product decides it.

## 3. Fonts

- The `font` tokens offer exactly the catalog's 10 fonts, and the editor lists them all:
  any theme can choose any of them. A binary **bundles the 10** (static files, one per
  weight: React Native has no variable-font axes) with the weights the typography scale
  and the components use. The runtime's fallback to the default family is a safety net
  for a missing file, not a way to skip fonts.
- Keep the set of weights small (as the web's kept weights): each weight of each family is
  a file in the binary. Decide it with the typography tokens.
- Brand fonts outside the catalog (a kiosk's) need the font added to the engine's catalog
  first (a format change), not a custom token.

## 4. The v1 map

If the app reads a served version 1 theme today, inventory every path it reads
(`inventory.md` §2): visibility flags (`business_view.components.header.components.logo.hidden`),
images (`business_listing_view.components.business_hero.components.image`), layout
switches (`app_design.components.layout.type`), texts. Each one that has a version 2 key
goes in the v1 map (version 2 path → version 1 path, by section), so api-core migrates
the merchants' customizations; the web's map
(Finitless-com/website-marketplace-theming, `docs/theme-v2/mapa-v1-para-api-core.json`)
shows the shape and many of the same paths. A value the v1 theme serves but the app
ignores today (served colors thrown away by the merge, for example) is a product decision:
migrating it changes what merchants see.

## 5. What the schema does not hold

- the native splash screen and the app icon (build assets);
- icons of the UI (bundled, colored by the theme);
- behaviour: permissions, keyboard, deep links, feature flags from other APIs.
