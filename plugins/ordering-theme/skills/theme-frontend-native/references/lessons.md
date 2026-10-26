# Lessons for React Native

Read the web skill's `../theme-frontend-web/references/lessons.md` first: the process
lessons (go straight to the final architecture, decide spacing early, start with few
tokens, script every sweep, every key has a reader, one control per goal) apply as they
are. These are the ones specific to native apps, from reviewing
`ordering-app-marketplace-v26` and the api-core plan for themes on the apps
(api-core `docs/THEMES_V2_PLAN.md` §1.8, §2.5, §3.7).

## What a native app's theme looks like before

1. **The theme file is not what the code reads.** A `theme.json` held tokens, a dead copy
   of the web's layout tree under `layouts` (nothing read it; the code read the same
   keys at the root, from the served theme) and an image manifest that the app
   overwrote at start. Inventory what the code reads, not what the file has.
2. **Keys read but not defined paint nothing.** Ten color keys were read and did not
   exist (`danger5`, used by the error borders and texts of login and sign-up, painted
   nothing). The theme must give each a token, and a test must catch the next one.
3. **A merge can throw away the served theme.** The app merged the served theme over
   the local one but kept the local colors on purpose: merchants' colors never reached
   the app. Migrating them changes what merchants see: a product decision.
4. **Two providers.** One `ThemeProvider` came from `styled-components` (the web entry)
   and another from `styled-components/native`; pieces mounted outside the inner one
   (toast, cookie banner, status bar) fell back to hard-coded colors.
5. **The design variant is real.** A whole second layout (`maweja`) was switched at run
   time with forked folders and inline ternaries. It is a schema option, not noise to
   flatten.
6. **Rules written in CLAUDE.md were not followed.** "No inline styles" coexisted with
   ~1 000 inline `style={{` and ~90 `StyleSheet.create`. Only a guard in CI holds.

## Rendering

7. **Module-level `StyleSheet.create` never sees the theme.** It is computed at import.
8. **`defaultProps` is ignored on function components (React 19).** `OButton`'s default
   class never applied.
9. **Fonts:** one family plus `fontWeight` is a faux bold on Android; `fontFamily:
   'Poppins'` does not exist on Android (the files are `Poppins-Regular`…); the catalog's
   10 fonts are all bundled, static, one file per kept weight.
10. **Line height is absolute** in React Native: store the ratio, paint `size × line`.
11. **Shadows are three systems:** iOS `shadow*`, Android `elevation` (no color before
    API 28), `boxShadow` only on the new architecture — which may be on for one platform
    and off for the other.
12. **`"1px solid …"` is not a React Native border:** borders are `{ width, style, color }`.
13. **Remote SVG needs `react-native-svg`;** the native splash and the app icon cannot
    change at run time.
14. **Spacing by platform is often a hand-made safe-area inset** (`ios ? 35 : 10`): use
    the inset.

## Shipping

15. **A binary keeps its schema version for months.** It must send it, the version must
    be published before the release, and later changes must cut down cleanly to it
    (`schema.md` §1).
16. **The engine package is ESM with `import`-only exports:** check that Metro, Jest and
    TypeScript resolve it before writing any component on top (`runtime-native.md` §2).
17. **The app today asks for the version 1 theme** (no `theme_version` on `first_load` and
    `GET theme`), and those requests live in the logic submodule: switching them is part
    of the runtime phase, upstream in ordering-components or in the app, behind the cache
    and the defaults.
18. **Hundreds of files read the old theme shape.** Swapping the provider's theme at once
    breaks them: keep the legacy keys derived from the completed values until the last
    batch, then remove them.
