<!--
Template for docs/THEMING.md in a themed React Native app. Replace every <…>, delete what
the app does not have, keep the section order. It describes the app as it is now: no
dates, versions or "was / now" notes. Link it from CLAUDE.md: "Read docs/THEMING.md
before styling anything". A finished one to compare with: docs/THEMING.md of
Finitless-com/ordering-app-marketplace-theming.
-->
# Theming rules

How UI code in this app gets its look from the theme: stay inside the theme's tokens,
reuse what exists, and know when (and where) to add something new. Read this before
touching any style (styled components, `StyleSheet`, inline styles, style props), any
primitive in `<primitives dir>` or `product.schema.json`.

The schema format and its versioning rules are documented by the theme engine
(`@orderingco/ordering-theme-engine`, the version pinned in `package.json`), in
`node_modules/@orderingco/ordering-theme-engine/docs/`. This page is about how this app
uses it.

## 1. The theme decides

- Every visual value comes from the theme: colors, spacing, radii, borders, shadows,
  typography, icon and control sizes, opacity, motion, images and what is shown. Nothing
  is hard-coded: no hex, no number for a length, font size, line height or shadow, no
  `fontFamily` or `fontWeight`, in a styled component, a `StyleSheet`, an inline style or
  a prop like `size={14}`.
- The theme always arrives complete and valid: `theme.values` is the served theme (or the
  cached one, or none) completed by the engine's runtime (`complete`) with
  `src/theme.compiled.json`. Readers do not re-check options or ranges, do not write
  fallbacks that repeat a default and do not use optional chains on the theme; only what
  the schema lets be null (a border, a shadow, an empty image, a platform override)
  needs handling.
- `theme.native` is the same theme as React Native values (`toNative` of
  `@orderingco/ordering-theme-engine/native`, built once per theme): paint from it; read
  `theme.values` only to compute (comparisons, nulls, options, visibility). The theme is
  only `{ values, native, meta }`. Never build one by spreading values (its view keeps the
  old ones): build it with `<build function>` (tests: `themeWithValues`), and never change
  the result.
- One provider, `<provider>`, from `styled-components/native`, at the top; everything
  that paints is inside it.
- Where the theme comes from: the binary paints `complete(compiled, null)` (the defaults
  of the schema version it was built with) until a served theme arrives, and the cached
  one on later launches (`<cache module>`, one cache per project and schema version, read
  before the first frame); the app asks for it itself
  (`GET <root>/theme?theme_version=2&product_type=<product_type>&theme_schema_version=<compiled version>`);
  a served theme equal to the cached one is kept as it is. <Whether a version 1 theme is
  read or mapped.>
- What the theme sets is not overridden in the app. If something looks wrong with the
  defaults, fix the default in the schema, not the style.
- If no token or component property fits a spot, do not invent a value: record it as an
  exception in `theme-exceptions.json` with its reason, and ask.

## 2. Where values live

| Layer | What it is | Example |
|---|---|---|
| `tokens.<group>.<name>` | The shared vocabulary, same names in every product; numbers in pt | `color.surface`, `space.md` |
| Identity (`shadow_style`, `border_style`, `motion_style`) | Theme-wide choices that move every property that `follows` them | one radius level for every box |
| `components.<c>.base` | A component's properties; defaults alias tokens | `<card>.base.background = {color.surface}` |
| Axes (`variants`, `appearances`, `sizes`, `shapes`, `colors`), `slots`, `scope`, `inherits` | As in the format | `button` sizes |
| `visibility`, `images`, `pages`, `site` | What is shown, the theme's images, screen sections, site settings | `<business_card.fee>` |
| `platforms.<p>` | <e.g. `tablet`>: other defaults for component properties, visibility and pages | <…> |

## 3. Reading the theme in code

Always through the helpers (`<helpers file>`); never read `product.schema.json` from app
code.

| Need | Object styles | Styled templates |
|---|---|---|
| A component's properties | <themePart / themeValue> | <…Css twin> |
| Color | <themeColor> | <themeColor> |
| Spacing, page sides | <themeSpace, pageGutter> | <spaceCss> |
| Radius, shadow, border (Identity applied; shadows for iOS and Android at once) | <themeRadius, themeShadow, themeBorder> | <radiusCss, shadowCss, borderCss> |
| Text (family by weight, `lineHeight = size × line`, no `fontWeight`) | <themeText> | <textCss> |
| Icon and control sizes | <themeIconSize, themeControlHeight> | <…> |
| Motion (durations, easing, reduced motion) | <themeMotion> | — |
| Shown or hidden; screen sections | <themeVisible>; <usePageSections> | — |
| Images (`{ uri }` or the bundled asset) | <themeImage> | — |
| Site settings | `<file>` | — |
| A component's box (background, border, shadow: the shadow only on an opaque background) | <nativeBoxStyle> | — |
| A control whose states have different borders: the widest border's room in every state | <reservedBorder> | — |
| The space under a bottom bar; the room a scroll leaves for it | <themeBottomSpace, bottomBarClearance> | — |
| A component as a group's `scope` sees it | <themeScoped> | — |
| A color from the API (not the theme) | `@orderingco/ordering-theme-engine/colors` (`onColor`, `legible`, `isLight`) | — |

If a helper is missing for a token group, add it next to its siblings (with its template
twin) rather than reading `theme.native` or `theme.values` inline. Object styles are built
inside the component, memoized on the theme
(`useMemo(() => StyleSheet.create(...), [theme])`), never at module level and never in the
render body as a prop.

Rules the helpers do not enforce:

- **A shadow needs an opaque box under it.** Never on an image or on a view without a
  background: carry it on a box with a background and put the picture inside.
- **An icon inside a button takes the button's size and ink** when it does not set its
  own.

## 4. Reuse or create: the order to follow

1. **The component already has the property.** Read it.
2. **A shared primitive already does it.** One purpose, one theme element: <list: the
   text, button, icon button, icon, input, radio/check mark and choice row, modal, sheet,
   the one select (a sheet), toast, notice, section title, skeleton, back button, chip,
   header, tab bar, divider, card, the business logo frame…, with their paths>. Do not
   build a second version with its own styles, and do not add a library that paints its
   own.
3. **A token used directly.** Layout glue that moves with the global scale.
4. **A new property on an existing component.** Its default aliases a token.
5. **A new component.**

Never create a new **token**.

## 5. Picking the right token

<The purpose → token table of this app, from the skill's references/unification.md:
surfaces, text and lines, color on color, icon and control sizes, pressed opacity,
motion.>

**Typography by role.** Texts of the same role look the same on every screen; each role
reads one place of the theme, never a preset picked by the screen:

| Role | Reads |
|---|---|
| Screen title | <header.title> |
| Section title (page sections, checkout sections, menu categories) | <section_header.title, through the section title component> |
| Card title (business, product, cart, order) | <the card's name: one style shared by every card> |
| Metadata (a card's time, fee, distance, rating) | <the card's meta, in its muted color> |
| A highlighted value (a status, an estimate) | <at most h3> |
| Body copy, leads under a title | `body` |
| Tab bar labels, badges, captions | <…> |

**Layout rules**

- Every screen's content, its header's included, sits on the page gutter.
- A state (pressed, selected) never moves or resizes its control: its border room is
  reserved in every state and a label that turns bold keeps its bold width.
- A row of chips is one row that scrolls sideways, never wrapped.
- A section owns no outer margin; one with nothing to show renders no view.
- A skeleton has the size of what it stands for.
- A bar pinned to the bottom keeps the system inset under its content or at least
  <the theme's minimum>, whichever is larger (the inset is 0 on Android with gestures).
- A card carries its radius on the box that paints its background and shadow.
- A card that opens something is pressable as a whole; a row holds on a 360 pt phone.
- Dates and times are written by <the project's date utility>, never with a format of
  the screen's own.

## 6. Adding to the schema

In the same pull request:

1. Add the node with its `$type` and default (an alias to a token).
2. Add its editor metadata. The Dashboard translates the editor from its own catalog: a
   new label key shows its English default until the Dashboard adds it in every
   language. List each new key with its text in `docs/theme/editor-labels.md` and open
   the Dashboard's pull request in the same round.
3. <If the app reads version 1 themes: add it to the v1 map.>
4. Bump `version` once the current one is published: a published version is immutable
   (api-core answers 409 `schema.version_exists`). Installed apps keep asking for the
   version they were built with: the new one must be published **before** the build
   that ships it, and a theme of it must cut down cleanly to the old one.
   - Publishing is the pipeline's job: never run `theme:publish` by hand.
     `<publish workflow>` publishes on every push to `<main>` (the same version with the
     same content answers `unchanged`) <and then has the build system take the new code>;
     pull requests check it with a dry run.
   - Locally nothing is published: the app paints the local schema's defaults, or the
     schema is registered in a local API to edit a theme of it.
5. Run `theme:compile`, `theme:validate`, `theme:verify`, `theme:guard` and
   `theme:extremes`.
6. Read it through a helper and cover it in a test. A key read through a variable or as
   a whole look goes in `theme-readers.json` with its reader.
7. Update this page if the change touches anything it describes.

## 7. Fonts

<The catalog's fonts bundled as static files, the weights, where the files live on each
platform, the name a file and its PostScript name must have
(`<FamilyWithoutSpaces>-<Weight>`), and the families that lack a weight and which file
stands for it.> A weight is painted by its file (the text helper with `weight`), never by
`fontWeight`.

## 8. Don'ts

- No literals in styles or style props (the guard in CI fails on them).
- No module-level `StyleSheet.create` with theme values: it never sees a theme change.
- No `fontFamily` or `fontWeight` in a component: the text helper picks the file.
- No `defaultProps` on function components: defaults go in the destructuring.
- No colors computed in a component; no second provider; no optional chains on the
  theme.
- No reading `theme.native…` or `theme.values…` inline when a helper exists; no
  converting a theme value in a component (a shadow or a text built from its fields).
- No second implementation of a primitive; no picker library and no list that opens in
  place.
- No press feedback on what cannot be tapped; touch targets of at least 44 pt.
- No shadow on a see-through box; no state that moves its control.

## 9. Keeping this page true

A change that touches what this page describes updates it in the same pull request.
Rewrite in place: this page describes the current state and is never a changelog.
