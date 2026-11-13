# Verification on React Native

The checks are the web skill's: read `../theme-frontend-web/references/verification.md`
(CI checks, tests to write, extreme themes, final report). This file is what changes on
React Native.

## 1. Automatic checks

| Check | Command |
|---|---|
| Schema and compiled file | `theme:validate`, `theme:verify` (as on the web) |
| Hand-written values | `guard.mjs --allow theme-exceptions.json [--baseline …]` on `src`, the logic submodule excluded |
| Keys without a reader | `keys.mjs --allow theme-readers.json` (the keys read through a variable recorded with their reader, so it can fail in CI) |
| Extreme themes reach what the app paints | `theme:extremes` (`templates/extremes.mjs`): each theme resolved as api-core does, completed and converted |
| Types, lint, tests | `npx tsc --noEmit`, lint, `jest` (with the engine mapped, `runtime-native.md` §2) |
| The engine resolves in the bundle | a release bundle for each platform (`npx react-native bundle --platform ios|android --dev false …`) builds without resolution errors |
| Publish dry run | `theme:publish:dry --required` in the pull requests' quality gate; the publish itself is the workflow's (`shipping.md` §1), and every schema version that ships in a binary is published before the release (`schema.md` §1) |

## 2. Tests

As on the web, plus:

- the helpers on the engine's view: a border, a shadow, a text style with a weight (the
  file of that weight, no `fontWeight`), a radius that follows Identity and one that stays
  fixed, images `{ uri }` vs bundled. `toNative` itself is the engine's and tested there;
- the fonts: every catalog font × bundled weight has its file in the assets folder, in
  the Android assets and in `Info.plist`, under the name `toNative` paints with;
- the rules no token gives: a shadow dropped on a see-through background, the border
  reserved across a control's states, the space under a bottom bar with an inset of 0;
- the theme request sends `theme_version=2`, `product_type` and `theme_schema_version` from
  the compiled file; an envelope of another product type paints the defaults;
- the cache: written under the schema version and the project, completed again on read,
  a corrupt or foreign cache ignored, a served theme equal to the cached one kept;
- old binaries: the current theme cut down to the previous published version (the
  engine's `cutDown`, from its main entry, with both schemas in nodes; a Node test or
  script, not Jest's React Native preset) completes without errors with that version's
  compiled file, so an installed app keeps working after a schema change.

## 3. On devices

Run on **iOS and Android** (simulator or device; Android with the architecture the app
ships). One platform seen is half a verification: the marketplace app's first pass had no
Xcode, and iOS later showed its own bugs (shadow warnings, deep links, a sheet under a
system sheet).

To see a served theme, register the schema in a local api-core and edit a theme of it
from a local Dashboard (`shipping.md` §1): a local API serves nothing for a product type
it does not know, and the app then only shows its defaults.

- the defaults look like today, except the approved visible changes, screen by screen,
  with screenshots before and after;
- extreme themes (square, pill, dark surfaces, light primary, dark primary, the largest
  scales) — applied by serving them from a development environment or by a debug-only
  theme override, never by changing a real site;
- each catalog font on the heading and body roles: no fallback to the system font, bold
  is the bold file (not a faux bold);
- widths of 320, 360 and 411–430 pt, on both platforms (a narrow Android phone breaks
  rows an iPhone holds), a tablet if shipped, landscape where allowed;
- the system's largest text size (content capped by the text primitive; bars with labels
  grow instead of clipping) and reduced motion on;
- Android with gesture navigation and with the three-button bar: the space under every
  bottom bar;
- right-to-left if the app supports it;
- offline launch after a first launch (the cached theme paints), and a first launch
  offline (the defaults paint). Both need a **release** build: a debug build loads its
  bundle from Metro and proves nothing offline;
- a build made by the build system, not only a local one (`shipping.md` §3).

Say for each claim the platform, the build and the theme it was seen with. What could not
be run (no simulator, no device for one platform, no release build) goes in the report as
not verified, by name.
