<!--
Template for docs/THEMING.md in a themed React Native app. Replace every <…>, delete what
the app does not have, keep the section order. It describes the app as it is now: no
dates, versions or "was / now" notes. Link it from CLAUDE.md: "Read docs/THEMING.md
before styling anything".
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
- `theme.native` is the same theme as React Native values (`<toNative module>`): paint
  from it; read `theme.values` only to compute. Never build a theme by spreading values:
  build it with `<build function>` (tests: `themeWithValues`).
- The binary paints `complete(compiled, null)` (the defaults of the schema version it was
  built with) until the served theme arrives, and the cached theme on later launches.
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
| A color from the API (not the theme) | `@orderingco/ordering-theme-engine/colors` (`onColor`, `legible`, `isLight`) | — |

## 4. Reuse or create: the order to follow

1. **The component already has the property.** Read it.
2. **A shared primitive already does it.** <list: OText, OButton, icon, OInput, modal,
   sheet, header, tab bar, divider, chip, card…, with their paths>. Do not build a
   second version with its own styles.
3. **A token used directly.** Layout glue that moves with the global scale.
4. **A new property on an existing component.** Its default aliases a token.
5. **A new component.**

Never create a new **token**.

## 5. Picking the right token

<The purpose → token table of this app, from the skill's references/unification.md:
surfaces, text and lines, color on color, typography roles, icon and control sizes,
pressed opacity, motion.>

## 6. Adding to the schema

In the same pull request:

1. Add the node with its `$type` and default (an alias to a token).
2. Add its editor metadata, and send each new label key to the Dashboard.
3. <If the app reads version 1 themes: add it to the v1 map.>
4. Bump `version` if the current one is already published, and publish the new version
   in every environment **before** the build that ships it: installed apps keep sending
   their own version, and a change must cut down cleanly to it.
5. Run `theme:compile`, `theme:validate` and `theme:verify`.
6. Read it through a helper and cover it in a test.
7. Update this page if the change touches anything it describes.

## 7. Don'ts

- No literals in styles or style props (the guard in CI fails on them).
- No module-level `StyleSheet.create` with theme values: it never sees a theme change.
- No `fontFamily` or `fontWeight` in a component: the text helper picks the file.
- No `defaultProps` on function components: defaults go in the destructuring.
- No colors computed in a component; no second provider; no optional chains on the
  theme.
- No press feedback on what cannot be tapped; touch targets of at least 44 pt.

## 8. Keeping this page true

A change that touches what this page describes updates it in the same pull request.
Rewrite in place: this page describes the current state and is never a changelog.
