# The theme in a React Native app

The pipeline is built **once, before touching components**, as on the web
(`../theme-frontend-web/references/runtime-web.md` explains why and shows the web's
helpers). What changes on native: there is no CSS, so the completed values become React
Native styles through the engine's `native` entry; the defaults are part of the binary;
the last served theme is cached for the next launch.

The model is `src/theme/` of Finitless-com/ordering-app-marketplace-theming
(`engine.ts`, `remote.ts`, `AppThemeContext.tsx`, `helpers.ts`, `sections.tsx`).

## 1. Pipeline

```
product.schema.json ──ordering-theme compile──▶ src/theme.compiled.json (committed; theme:verify in CI)
                                                       │
served theme (GET theme, version 2) ──────────▶ complete(compiled, values)   ← …/runtime
   cached in AsyncStorage for the next launch          │  every key, values understood, numbers clamped
                                                       ▼
                                   toNative(compiled, values)   ← …/native
                                                       │  tokens, Identity, fonts and components as RN style values
                                                       ▼
                     app theme = { values, native, meta }  (+ legacy keys derived from values while converting)
                                                       → ThemeProvider (styled-components/native) → helpers → styles
```

- **Defaults:** `complete(compiled, null)` is the theme the binary paints before anything
  arrives and offline. It is the build's: the compiled file ships in the binary.
- **Request:** send `theme_version=2`, `product_type` and `theme_schema_version`, all read
  from the compiled file (never written by hand). Check that the envelope's
  `product_type` is the compiled one; otherwise use the defaults. A failure of any kind
  (network, a non-2xx answer, not JSON) gives no theme, never an exception. When the
  theme requests live in a logic submodule the app does not edit, the app asks
  `GET theme` itself and stops reading the submodule's theme, as the web does: the
  marketplace app did that, with the submodule untouched. Agree it with the user.
- **Cache:** store the last served envelope (AsyncStorage) under a key with the theme
  version, the product type, the schema version and the project. Read it **before the
  first frame** (while the native splash is up), paint it completed again (the binary
  may be newer), then refresh. A corrupt cache or another product's is ignored.
- **A served theme equal to the cached one is kept as it is.** Setting and caching it
  again rebuilds the whole theme, and every styled component with it, during the first
  load.
- **Build once per theme:** memoize the app theme per served envelope (a `WeakMap`), and
  rebuild it only when the theme changes. Any exception falls back to the defaults.
  Never change the result (its objects are shared) and never build one by spreading
  (`{ ...theme, values }` keeps the old view).
- **One provider** from `styled-components/native` at the top, above everything that
  paints (toasts, status bar, cookie banners included). An app that has two providers
  (one from `styled-components`, one from `/native`) keeps only the native one.
- **A compatibility layer while converting:** hundreds of files read today's theme shape
  (`theme.colors.*`, `theme.utils.*`, `theme.images.*`). Replacing the provider's theme
  breaks them all at once. Until the batches of phase 7 finish, the app theme also
  carries that legacy shape **derived from the completed values** (each legacy key mapped
  to its token, decided in phase 3), never from a second source. Phase 8 removes it, and
  the guard and `keys.mjs` prove nothing reads it: the theme ends as only
  `{ values, native, meta }`.
- **Readers rely on `complete()`:** no option or range checks, no fallbacks that repeat a
  default, no optional chains on the theme (`theme?.x?.components?…`). Only what the
  schema lets be null needs handling.

## 2. Using the engine from Metro, Hermes, TypeScript and Jest

Pin the engine at 0.14.0 or later: it has the `native` entry and a `default` export
condition on every entry, so Metro and Jest resolve it without aliases.

- Use `@orderingco/ordering-theme-engine/runtime` (`complete`), `/native` (`toNative`)
  and `/colors` (`isLight`, `onColor`, `legible`, `shade`, `mix`, `contrast` for colors
  that come from the API). Not the main entry in the app (it is for Node: the CLI, CI
  and scripts) and not `/css` (it writes web CSS: a unitless `line-height: 1.5` would be
  read as 1.5 pt).
- Metro and Babel need nothing. Still bundle once for each platform
  (`npx react-native bundle --dev false …`) before writing components on top.
- The runtime uses nothing Hermes lacks (no `Intl`, `structuredClone`, lookbehinds).
- TypeScript with `moduleResolution: "node"` ignores `exports`: add `paths` from each
  entry to its declarations (`dist/runtime.d.ts`, `dist/native.d.ts`,
  `dist/palette.d.ts` for `/colors`).
- Jest (react-native preset): the package in `transformIgnorePatterns`' allow-list (it is
  ESM). No `moduleNameMapper`.
- Import the compiled JSON plainly (`import compiled from './theme.compiled.json'`).

## 3. `toNative`: values to React Native

`toNative(compiled, values, options?)` is the engine's (its README, "Native"): every
value converted by its **type** in the schema, never by its name, once per theme (the
tokens, Identity and the fonts at once, each component the first time it is read).

| Type | React Native |
|---|---|
| dimension (space, radius, size, icon), number | the number (pt, ms or a ratio) |
| color | the string as served (hex, `rgb()`, `rgba()`, `transparent`) |
| border | `{ borderWidth, borderStyle, borderColor }`; none is `{ borderWidth: 0 }` |
| shadow | iOS `shadowColor`, `shadowOpacity`, `shadowRadius` (blur / 2), `shadowOffset` and Android `elevation` together, plus `boxShadow` for the new architecture; none is null. Spread cannot be reproduced by `elevation`: accept the approximation |
| typography | `{ fontFamily, fontSize, lineHeight: round(size × line), letterSpacing, textTransform }` with the **file** of the nearest bundled weight as `fontFamily`, never `fontWeight` (it fakes bold on Android) |
| font role | `{ id, name, files }`: the file of each bundled weight |
| `motion_style.easing` | the bezier points for `Easing.bezier` |
| an option, a boolean | as it is |

- `native.tokens` keeps each level fixed; `native.identity` has the levels as a spot that
  follows Identity paints them (`none` and `full` radii never move). The helpers choose:
  a state or a round shape asks for the fixed level.
- `native.components.<c>` has each property converted, Identity already applied where
  the property `follows` it.
- An image is not converted: the image helper turns the URL into `{ uri }`, or the
  bundled asset when it is `''`.
- Components never convert a value themselves (a shadow or a text style built from its
  fields): the view has it. A conversion the view lacks is asked of the engine, not
  written in the app.

## 4. Fonts

- Bundle the catalog's 10 fonts as static files, one per weight, for the weights the
  scale uses (`schema.md` §3), linked with `react-native.config.js` assets (and
  `Info.plist` `UIAppFonts`, Android `assets/fonts`).
- `toNative` paints with the file `<FamilyWithoutSpaces>-<WeightName>`
  (`OpenSans-SemiBold`), Google Fonts' static names, which must be both the file's name
  and its PostScript name; the bundled weights default to 400, 500, 600 and 700. Another
  set of weights or other names go in `toNative`'s options (`weights`, `fontFile`), not
  in a table of the app.
- A requested weight snaps to the nearest bundled one (a tie goes heavier from 600 up,
  lighter below): the engine does it.
- A family without a weight (Lato has no 500 or 600) still needs the four files: ship
  its Regular and Bold under the Medium and SemiBold names, and say so in
  `docs/THEMING.md`.
- A test checks that every catalog font has every bundled weight's file in the assets
  folder, in the Android assets and in `Info.plist`: a missing one falls back to the
  system font without an error.

## 5. Helpers

Mirror the web's names so the products read alike (`themeColor`, `themeSpace`,
`themeRadius`, `themeShadow`, `themeBorder`, `themeText`, `themeIconSize`,
`themeControlHeight`, `themePart`, `themeValue`, `themeVisible`, `usePageSections`,
`themeImage`), returning React Native values instead of CSS: numbers for lengths,
objects for borders, shadows and text styles. They read `theme.native` to paint and
`theme.values` only to compute (comparisons, nulls, options, visibility).

- In object styles, use them directly, memoized on the theme:
  `const s = useMemo(() => StyleSheet.create(styles(theme)), [theme])`.
- In styled-components/native templates, the template needs CSS text. Give each helper a
  template twin that returns it (`spaceCss(theme, 'md')` → `'12px'`,
  `shadowCss(theme, 'sm')`, `textCss(theme, 'body')`) in the same module. A component
  never writes a unit after an interpolation (`${…}px`) or builds a shadow by hand.
- A helper that returns an object (a text style, a border, a box) may build a new one on
  each call. Call it inside the memo on the theme or a styled template, never in a render
  body to make a prop: a style with a new identity on every render cancels `React.memo`
  below it, which a list's cells depend on.

Helpers the marketplace app needed beyond the web's, each for a rule no token gives
(`harmony.md` §5–6); expect to write them:

| Helper | Rule |
|---|---|
| a component's box (`nativeBoxStyle(look)`) | background, border and shadow together; the shadow only on an opaque background |
| `reservedBorder(looks)` | the widest border of a control's states, reserved in every state, so selecting never moves it |
| the space under a bottom bar (`themeBottomSpace`, `bottomBarClearance`) | the system inset or the theme's minimum, whichever is larger; the room a scroll leaves for the bar laid over it |
| `themeScoped(theme, group, name)` | a component as a group's `scope` sees it |
| `themeMotion(theme, level)` | duration and easing; none when the theme or the system turns motion off |

## 6. Motion and accessibility

- Durations from `motion` and the component's `speed`; easing from `native.easing`. A
  component animates only when its `animated` is on and `motion_style.enabled` is not
  false.
- Respect `AccessibilityInfo.isReduceMotionEnabled()` (and its change event): no motion
  when it is on. Keep it in the theme's `meta`, so the motion helper is the only reader.
- Font scaling (`allowFontScaling`, `maxFontSizeMultiplier`) decided once in the text
  primitive. The marketplace app caps it at 1.3; a bar with labels (the tab bar) must
  grow with them instead of clipping.

## 7. Platform, sections, visibility, images

- **Platform:** decide the schema platform (for example `tablet`) once, in a hook, from
  the device (`DeviceInfo.isTablet()` or the window width), and let the helpers apply
  `platforms.<p>` overrides. A phone-only app declares none.
- **Sections:** `usePageSections(page)` → `{ sections, isVisible, render }` as on the web;
  recompute `stickyHeaderIndices` from what renders, and render the sections as direct
  children of the scroll (a `Fragment` around a sticky one breaks it:
  `pages-and-visibility.md` §2).
- **Visibility:** `themeVisible(theme, key)`, the platform override first.
- **Images:** `themeImage(theme, key)` → `{ uri }` or the bundled `require`.
- **Status bar:** its style from the background color it sits on (`isLight`).

## 8. Tests

- A render helper that wraps the app's `ThemeProvider` with a theme **built** from
  values (`themeWithValues(mutate)`: defaults → change → `complete` → `toNative`), never a
  raw JSON object or a spread of one.
- A test that the committed compiled file is what the schema compiles to.
- Unit tests for the helpers, the fonts' files, the cache (its key, a corrupt cache,
  another product's envelope) and the request parameters.
- The rules of §5's table, each with its test (a shadow dropped on a see-through
  background, the reserved border, the bottom space with an inset of 0).
