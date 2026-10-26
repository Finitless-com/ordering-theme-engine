# The theme in a React Native app

The pipeline is built **once, before touching components**, as on the web
(`../theme-frontend-web/references/runtime-web.md` explains why and shows the web's
helpers). What changes on native: there is no CSS, so the app turns the completed values
into React Native styles itself; the defaults are part of the binary; the last served
theme is cached for the next launch.

## 1. Pipeline

```
product.schema.json ──ordering-theme compile──▶ src/theme.compiled.json (committed; theme:verify in CI)
                                                       │
served theme (first_load / GET theme, version 2) ──▶ complete(compiled, values)   ← …/runtime
   cached in AsyncStorage for the next launch          │  every key, values understood, numbers clamped
                                                       ▼
                                   toNative(values)  ← the app's own adapter (§3)
                                                       │  tokens and components as RN style values, once per theme
                                                       ▼
                     app theme = { values, native, meta, …legacy keys derived from values while converting }
                                                       → ThemeProvider (styled-components/native) → helpers → styles
```

- **Defaults:** `complete(compiled, null)` is the theme the binary paints before anything
  arrives and offline. It is the build's: the compiled file ships in the binary.
- **Request:** send `theme_version=2`, `product_type` and `theme_schema_version`, both read
  from the compiled file (never written by hand), on `first_load` and on `GET theme`.
  Check that the envelope's `product_type` is the compiled one; otherwise use the
  defaults. When those requests live in a logic submodule the app does not edit (the
  marketplace app's are in ordering-components' `OptimizationLoadContext` and
  `OrderingThemeContext`), the change goes upstream, in that repository and its pull
  request, with an option the app passes; or the app makes the theme request itself.
  Agree which with the user.
- **Cache:** store the last served envelope (AsyncStorage) under a key that includes the
  schema version; on launch paint the cache (completed again: the binary may be newer),
  then refresh. Never paint a cache of another schema version without `complete()`.
- **Build once per theme:** memoize the app theme per served envelope (a `WeakMap`), and
  rebuild it only when the theme changes. Any exception falls back to the defaults.
- **One provider** from `styled-components/native` at the top, above everything that
  paints (toasts, status bar, cookie banners included). An app that has two providers
  (one from `styled-components`, one from `/native`) keeps only the native one.
- **A compatibility layer while converting:** hundreds of files read today's theme shape
  (`theme.colors.*`, `theme.utils.*`, `theme.images.*`). Replacing the provider's theme
  breaks them all at once. Until the batches of phase 7 finish, the app theme also
  carries that legacy shape **derived from the completed values** (each legacy key mapped
  to its token, decided in phase 3), never from a second source. Phase 8 removes it, and
  the guard and `keys.mjs` prove nothing reads it.
- **Readers rely on `complete()`:** no option or range checks, no fallbacks that repeat a
  default, no optional chains on the theme (`theme?.x?.components?…`). Only what the
  schema lets be null needs handling.

## 2. Using the engine from Metro, Hermes, TypeScript and Jest

- Use `@orderingco/ordering-theme-engine/runtime` (`complete`) and `/colors` (`isLight`,
  `onColor`, `legible`, `shade`, `mix`, `contrast` for colors that come from the API). Not
  the main entry (it is for Node: CLI and CI) and not `/css` (it writes web CSS: a
  unitless `line-height: 1.5` would be read as 1.5 pt).
- The package is ESM only and its `exports` have only `types` and `import` conditions.
  Check that Metro resolves `…/runtime` (bundle once: `npx react-native bundle` or start
  Metro and import it). If it does not, in order of preference:
  1. ask for a `default` condition in the engine's exports (a one-line engine change);
  2. a `module-resolver` alias in `babel.config.js` to `dist/runtime.js` / `dist/palette.js`;
  3. a `resolver.resolveRequest` in `metro.config.js` for the package.

  Do not add `import` to `unstable_conditionNames` globally: it changes how every other
  package resolves.
- The runtime uses nothing Hermes lacks (no `Intl`, `structuredClone`, lookbehinds).
- TypeScript with `moduleResolution: "node"` ignores `exports`: add `paths` to
  `dist/runtime.d.ts` and `dist/palette.d.ts`. (`bundler` would also need `module` set to
  an ES module or `preserve`, which a `commonjs` app does not have.)
- Jest (react-native preset): `moduleNameMapper` for the subpaths to their `dist` files,
  and the package in `transformIgnorePatterns`' allow-list.
- Import the compiled JSON plainly (`import compiled from './theme.compiled.json'`).

## 3. `toNative`: values to React Native

A small module of the app turns the completed values into what styles take, once per
theme, by each value's **type** in the schema (never by its name):

| Type | React Native |
|---|---|
| dimension (space, radius, size, icon), number | the number (pt) |
| radius level | the number, Identity already applied in components; for a spot outside a component, port the engine's Identity rule (`identity()` in the engine's `src/css.ts`): `none` and `full` never move |
| color | the string as served (hex, `rgb()`, `rgba()`, `transparent`) |
| border | `{ borderWidth, borderColor, borderStyle }`, or none |
| shadow | iOS `shadowColor`, `shadowOpacity`, `shadowRadius ≈ blur / 2`, `shadowOffset { width: x, height: y }` + Android `elevation`; `boxShadow` only where the new architecture is on for that platform (check `android/gradle.properties` `newArchEnabled` and iOS `RCTNewArchEnabled`). Spread and `solid` styles cannot be reproduced by `elevation`: accept the approximation |
| typography | `{ fontFamily, fontSize, lineHeight: round(size × line), letterSpacing, textTransform }`, **no `fontWeight`** (the family file carries the weight; `fontWeight` on Android fakes bold) |
| font role | the family of the role, for the weight asked (§4) |
| duration | ms, with the easing of `motion_style` (§6) |
| opacity | the number |
| image | `{ uri }`, or the bundled asset when `''` |

Keep the adapter pure and tested; components never convert values themselves.

## 4. Fonts

- Bundle the catalog's 10 fonts as static files, one per weight, for the weights the
  scale uses (`schema.md` §3), linked with `react-native.config.js` assets (and
  `Info.plist` `UIAppFonts`, Android `assets/fonts`).
- A table in the app maps `(font id, weight) → file / PostScript name` (the engine has no
  file names). Snap the requested weight to the **bundled** ones with the engine's rule,
  ported (do not call `snapWeight` from `/css`: it only clamps for variable fonts):
  nearest; a tie goes heavier from 600 up, lighter below.
- A missing file falls back to the default family (a safety net: every catalog font is
  bundled), never to the system font silently.

## 5. Helpers

Mirror the web's names so the products read alike (`themeColor`, `themeSpace`,
`themeRadius`, `themeShadow`, `themeText`, `themeIconSize`, `themeControlHeight`,
`themePart`, `themeValue`, `themeVisible`, `usePageSections`, `themeImage`,
`siteSetting`), returning React Native values instead of CSS: numbers for lengths,
objects for borders, shadows and text styles.

- In object styles, use them directly: `const s = useMemo(() => styles(theme), [theme])`.
- In styled-components/native templates, the template needs CSS text. Give each helper a
  template twin that returns it (`spaceCss(theme, 'md')` → `'12px'`,
  `shadowCss(theme, 'sm')`, `textCss(theme, 'body')`) in the same module. A component
  never writes a unit after an interpolation (`${…}px`) or builds a shadow by hand.

## 6. Motion and accessibility

- Durations from `motion` and the component's `speed`; easing from `motion_style.easing`
  as `Easing.bezier`: ease `(.25, .1, .25, 1)`, ease-in `(.42, 0, 1, 1)`, ease-out
  `(0, 0, .58, 1)`, ease-in-out `(.42, 0, .58, 1)`, linear. A component animates only when
  its `animated` is on and `motion_style.enabled` is not false.
- Respect `AccessibilityInfo.isReduceMotionEnabled()`: no motion when it is on.
- Font scaling (`allowFontScaling`, `maxFontSizeMultiplier`) decided once in the text
  primitive.

## 7. Platform, sections, visibility, images

- **Platform:** decide the schema platform (for example `tablet`) once, in a hook, from
  the device (`DeviceInfo.isTablet()` or the window width), and let the helpers apply
  `platforms.<p>` overrides.
- **Sections:** `usePageSections(page)` → `{ sections, isVisible, render }` as on the web;
  recompute `stickyHeaderIndices` from what renders.
- **Visibility:** `themeVisible(theme, key)`, the platform override first.
- **Images:** `themeImage(theme, key)` → `{ uri }` or the bundled `require`.
- **Status bar:** its style from the background color it sits on (`isLight`).

## 8. Tests

- A render helper that wraps the app's `ThemeProvider` with a theme **built** from
  values (`themeWithValues(mutate)`: defaults → change → `complete` → `toNative`), never a
  raw JSON object or a spread of one.
- A test that the committed compiled file is what the schema compiles to.
- Unit tests for `toNative` (each type), the font table, the cache (version key, corrupt
  cache), and the request parameters.
