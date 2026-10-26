# Verification on React Native

The checks are the web skill's: read `../theme-frontend-web/references/verification.md`
(CI checks, tests to write, extreme themes, final report). This file is what changes on
React Native.

## 1. Automatic checks

| Check | Command |
|---|---|
| Schema and compiled file | `theme:validate`, `theme:verify` (as on the web) |
| Hand-written values | `guard.mjs --allow theme-exceptions.json [--baseline …]` on `src`, the logic submodule excluded |
| Keys without a reader | `keys.mjs` |
| Types, lint, tests | `npx tsc --noEmit`, lint, `jest` (with the engine mapped, `runtime-native.md` §2) |
| The engine resolves in the bundle | a release bundle for each platform (`npx react-native bundle --platform ios|android --dev false …`) builds without resolution errors |
| Publish dry run | `theme:publish:dry --required`, and every schema version that ships in a binary is published in each environment before the release (`schema.md` §1) |

## 2. Tests

As on the web, plus:

- `toNative`: each type (border, shadow on both platforms, typography with `lineHeight =
  round(size × line)` and no `fontWeight`, radius levels, images `{ uri }` vs bundled);
- the font table: every catalog font × kept weight has a file name, and snapping;
- the theme request sends `theme_version=2`, `product_type` and `theme_schema_version` from
  the compiled file; an envelope of another product type paints the defaults;
- the cache: written under the schema version, completed again on read, a corrupt or
  foreign cache ignored;
- old binaries: the current theme cut down to the previous published version (the
  engine's `cutDown`, from its main entry, with both schemas in nodes; a Node test or
  script, not Jest's React Native preset) completes without errors with that version's
  compiled file, so an installed app keeps working after a schema change.

## 3. On devices

Run on **iOS and Android** (simulator or device; Android with the architecture the app
ships):

- the defaults look like today, except the approved visible changes, screen by screen,
  with screenshots before and after;
- extreme themes (square, pill, dark surfaces, light primary, dark primary, the largest
  scales) — applied by serving them from a development environment or by a debug-only
  theme override, never by changing a real site;
- each catalog font on the heading and body roles: no fallback to the system font, bold
  is the bold file (not a faux bold);
- a small phone (iPhone SE size), a large one, a tablet if shipped, landscape where
  allowed;
- the system's largest text size and reduced motion on;
- right-to-left if the app supports it;
- offline launch after a first launch (the cached theme paints), and a first launch
  offline (the defaults paint).

What could not be run (no simulator, no device for one platform) goes in the report as
not verified.
