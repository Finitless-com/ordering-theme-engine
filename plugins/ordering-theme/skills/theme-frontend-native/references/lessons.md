# Lessons for React Native

Read the web skill's `../theme-frontend-web/references/lessons.md` first: the process
lessons (go straight to the final architecture, decide spacing early, start with few
tokens, script every sweep, every key has a reader, one control per goal) apply as they
are. These are the ones specific to native apps, from reviewing
`ordering-app-marketplace-v26`, the api-core plan for themes on the apps
(api-core `docs/THEMES_V2_PLAN.md` §1.8, §2.5, §3.7) and its conversion into
Finitless-com/ordering-app-marketplace-theming (November 2026), whose `docs/theme/` keeps
every deliverable and decision.

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
5. **A design variant is real, and it may go.** A whole second layout (`maweja`) was
   switched at run time with forked folders and inline ternaries. It is either a schema
   option or code to delete; here its clients stayed on the original repository and the
   themed app dropped it whole. The user decides, before phase 2.
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
15. **A shadow needs an opaque box under it.** On a transparent PNG or a view without a
    background Android paints a grey box and iOS warns it cannot compute the shadow.
16. **Android's elevation paints nothing above the box.** Cards with a shadow and no
    border lost their top edge on Android; the decision "shadow, no border" was reversed
    to a thin border by default.
17. **The bottom inset is 0 on Android with gesture navigation** when the activity pads
    the content itself: labels and buttons sat on the screen's edge until every bottom bar
    took a minimum from the theme.
18. **A state that changes a border or a weight moves the row.** Reserve the widest
    border and the bold width in every state.
19. **A `Fragment` is not a scroll child.** Around a sticky section it raised "Invalid prop
    `style` supplied to `React.Fragment`": sections are direct children of the scroll.
20. **`avif` and `svg` are not for theme images.** `avif` paints on Android 12+ only and
    not in FastImage on iOS; neither platform's image component paints `svg`. Every image
    accepts `jpg`, `png` and `webp`.
21. **Libraries that paint their own UI cannot read the theme.** Pickers, dropdowns,
    skeletons, pagers, a phone field and a chat thread were replaced by primitives of the
    app; every select became one sheet.
22. **Identity radius `full` makes large boxes oval** and crops their content, as CSS
    does. It is a proposal for the editor, not something a component fixes.

## Shipping

23. **A binary keeps its schema version for months.** It must send it, the version must
    be published before the release, and later changes must cut down cleanly to it
    (`schema.md` §1).
24. **The engine resolves by itself from 0.14.0** (a `default` export condition and the
    `native` entry). Do not write an adapter, a font table or resolver aliases in the
    app: `toNative` was put in the engine so every app shares it.
25. **The app asks for the version 2 theme itself.** The theme requests of the logic
    submodule ask for version 1; the submodule was left untouched and its theme is no
    longer read (`runtime-native.md` §1).
26. **Hundreds of files read the old theme shape.** Swapping the provider's theme at once
    breaks them: keep the legacy keys derived from the completed values until the last
    batch, then remove them.
27. **Versions are spent from the first merge.** The publish workflow registers a version
    on every merge to the main branch: the schema reached 1.3.0 with one test project
    using it, and was started again at 1.0.0 before opening, with a backup, a deletion in
    production the owner authorized, a cache to clear and every app to build again
    (`shipping.md` §2). Decide it at the start.
28. **A merge is not a build.** The Builder builds from its own copy of the branch: until
    that copy is cloned again, new builds carry the old code. The publish workflow now
    asks for the clone after publishing (`shipping.md` §3).
29. **A repository split from the original inherits its identity.** The crash reporting
    DSN was fixed in a file: the new app's errors went to the original's project until it
    got its own.
30. **The editor's labels are another repository's pull request.** A key added without
    its Dashboard label shows English in every language.

## After the guard

31. **Zero literals is not one look.** Four review rounds followed the conversion: card
    titles had three styles and section titles three sizes, all read from the theme
    (`harmony.md`).
32. **The web's defaults do not fit a phone.** Roles, names and colors transfer; sizes
    do not. Dozens of defaults were set again to today's look on a phone, and presets
    that lost today's weight became custom styles.
33. **A schema copied from the web carries keys nobody draws.** 100 were removed in
    phase 8; then 31 visibility keys the app does draw were found missing by comparing
    the two schemas key by key.
34. **`keys.mjs` cannot see a key read through a variable.** 44 were reported and all had
    readers. Record them (`theme-readers.json`, `--allow`) so the check runs in CI and
    only a real miss fails.
35. **Extreme themes belong in CI.** A script resolves each one as api-core does,
    completes and converts it, and checks what the app would paint
    (`templates/extremes.mjs`); the device pass is still needed for overflow and contrast.
36. **Performance is part of the conversion.** Every component is touched, and the
    review that followed found a theme set again when equal to the cached one, a tab icon
    component declared inside the navigator (remounted on every render) and a menu that
    mounted every row (298 product cards for a 298-product business). Keep a
    `docs/PERFORMANCE.md` with what was measured.
37. **Bundled images weigh.** A welcome hero and six tutorial slides were 29.8 MB of
    opaque PNG (3.5 MB as JPEG); 125 asset files nothing painted were removed.

## Process

38. **Start counts matter.** TypeScript had 349 errors before the conversion: without
    the number, "none in theme code" cannot be shown. Record the type errors, the lint
    errors and the tests at the start, and get the type check to zero afterwards.
39. **iOS was not seen in the first verification** (no Xcode on the machine), and an
    offline launch needs a release build. Say which platform and build each claim was
    seen on; the unseen platform found its own bugs later.
40. **A served theme needs a registered schema.** A local API serves no theme for a
    product type it does not know: register the schema locally to see a theme other than
    the defaults (`shipping.md` §1).
41. **Agents report; the coordinator checks.** An agent's report is a claim: every
    screenshot is opened and every diff read before anything is told to the user. Agents
    commit locally; one push and one pull request come after the review.
42. **Local files stay local.** The app's runtime configuration and the push provider's
    files pointed at a local API and a test project on the developer's machine: ask at
    the start which files are never staged.
43. **Decisions are not reopened by later work.** A later round, a ported fix or an
    agent's preference does not undo what the log says: when they collide, ask.
