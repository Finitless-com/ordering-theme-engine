<!--
Template for docs/THEMING.md in a themed frontend. Replace every <…>, delete what the
product does not have, keep the section order. It describes the repo as it is now: no
dates, versions or "was / now" notes. Link it from CLAUDE.md: "Read docs/THEMING.md
before styling anything".
-->
# Theming rules

How UI code in this repo gets its look from the theme: stay inside the theme's tokens,
reuse what exists, and know when (and where) to add something new. Read this before
touching any style file, any primitive in `<primitives dir>` or `product.schema.json`.

The schema format and its versioning rules are documented by the theme engine
(`@orderingco/ordering-theme-engine`, the version pinned in `package.json`), in
`node_modules/@orderingco/ordering-theme-engine/docs/` (`schema-format.md`,
`creating-a-schema.md`). This page is about how this repo uses it.

## 1. The theme decides

- Every visual value comes from the theme: colors, spacing, radii, borders, shadows,
  typography, icon and control sizes, opacity, motion, images and what is shown. Nothing
  is hard-coded in a component: no hex, `rgba()`, `px`, `ms` or font name in a style.
- The theme always arrives complete and valid: `theme.values` is the served theme
  completed by the engine's runtime (`complete`) with `<compiled path>`, the schema
  compiled by `pnpm theme:compile`. Readers do not re-check options or ranges and do not
  write fallbacks that duplicate a default; only what the schema lets be null (a border, a
  shadow, an empty image, a slot's axis, a platform override) needs handling.
- Paint from the CSS view (`theme.css`, `toCss` of `@orderingco/ordering-theme-engine/css`);
  read `theme.values` only to compute. Never build a theme by spreading values: build it
  with `<build function>`.
- What the theme sets is not overridden in the front. If something looks wrong with the
  defaults, fix the default in the schema.
- If no token or component property fits a spot, do not invent a value: record it as an
  exception in `theme-exceptions.json` with its reason, and ask.

## 2. Where values live

| Layer | What it is | Example |
|---|---|---|
| `tokens.<group>.<name>` | The shared vocabulary, same names in every product | `color.surface`, `space.md` |
| Identity (`shadow_style`, `border_style`, `motion_style`) | Theme-wide choices that move every property that `follows` them | one radius level for every box |
| `components.<c>.base` | A component's properties; defaults alias tokens | `<card>.base.background = {color.surface}` |
| `variants` / `appearances` / `sizes` / `shapes` / `colors` | Axes of a component; states only in `variants` and `appearances` | `button` sizes |
| `slots`, `scope`, `inherits` | Contained components, group overrides, parent's values | `<card>.slots.cta → button` |
| `visibility`, `images`, `pages`, `site` | What is shown, the theme's images, page sections, site settings | `<header.cart>` |

Token levels in this product: <list the groups and levels the schema uses; names, not numbers>.

## 3. Reading the theme in code

Always through the helpers; never read `product.schema.json` from app code.

| Need | Helper (file) |
|---|---|
| A component as CSS / values | <getThemeCss, getThemeComponent, themePart, themeValue> (`<file>`) |
| Color | <themeColor> (`<file>`) |
| Spacing, page sides | <themeSpace, pageGutter> |
| Radius, shadow, border (apply Identity; `{ fixed: true }` keeps the level) | <themeRadius, themeShadow, themeBorderWidth> |
| Text | <themeText, headingText, themePartText> |
| Icon and control sizes | <themeIconSize, themeControlHeight> |
| Motion | <themeMotion, motionCss> |
| Groups and slots | <scopedTheme, themeSlot> |
| Shown or hidden | <themeVisible>; page sections <usePageSections> |
| Images | <themeImage>; bundled images `<file>` |
| Site settings | `<file>` |

If a helper is missing for a token group, add it next to its siblings.

## 4. Reuse or create: the order to follow

Stop at the first step that fits:

1. **The component already has the property.** Read it.
2. **A shared primitive already does it.** <list: buttons, inputs, card, divider, chip,
   choice, tabs, link, chevron, modal…, with their paths>. Do not build a second version
   with its own CSS.
3. **A token used directly.** Layout glue that moves with the global scale: the gap
   between page blocks, the rhythm between form fields, a plain heading's color.
4. **A new property on an existing component.** A distinct visual part a theme author
   could want different from the token. Its default aliases a token.
5. **A new component.** A reusable piece with its own look that no component covers.

Never create a new **token**: the catalog is fixed and shared by every product.

## 5. Picking the right token

<The purpose → token table of this product: surfaces, text and lines, color on color,
translucency, typography roles, sizes, interaction, motion. Start from the skill's
references/unificacion.md §3 and keep only the tokens this schema has.>

Only what can be clicked or tapped has hover and a pointer cursor, and only with a look
that comes from a theme state. Everything else stays still.

## 6. Adding to the schema

In the same pull request:

1. Add the node to `product.schema.json` with its `$type` and its default (an alias to a
   token; `scale`, `follows` and `alpha` where they apply).
2. Add its editor metadata: label key (`BUILDER_THEME_…`) and English text, group,
   `order`, control, `min`/`max`/`step`/`unit`. Send each new label key to the Dashboard.
3. <If the product has version 1 themes: add it to the v1 map.>
4. Bump `version` if the current one is already published (a published version is
   immutable).
5. Run `pnpm theme:compile`, `pnpm theme:validate` and `pnpm theme:verify`.
6. Read it through a helper and cover it in a test.
7. Update this page if the change touches anything it describes.

## 7. Don'ts

- No hex, `rgb()`, `px`, `rem`, `ms` or font names in component styles (the guard in CI
  fails on them; exceptions live in `theme-exceptions.json` with their reason).
- No colors computed in a component (`darken`, `mix`): derived colors are declared in the
  schema; a color from outside the theme goes through
  `@orderingco/ordering-theme-engine/colors`.
- No reading `theme.values.tokens…` inline when a helper exists; no converting a value
  to CSS in a component.
- No second implementation of a primitive.
- No hover, lift or cursor on something that cannot be clicked or tapped.
- No new token names; no schema key without its default, editor metadata and reader.

## 8. Keeping this page true

A change that touches what this page describes (the schema's tokens or components, a
helper, a primitive, a token rule) updates it in the same pull request. Rewrite in place:
this page describes the current state and is never a changelog. Point to sources instead
of copying values.
