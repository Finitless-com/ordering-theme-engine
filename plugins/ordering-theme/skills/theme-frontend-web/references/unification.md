# Unifying values into tokens

The goal is **a short scale that reproduces the current look**: each value in the code falls
on a catalog level; what does not fall is decided (nearest level with its visible change,
moving the level's value, or a recorded exception). Never create a level for each value.

## 1. The catalog is fixed

Groups and levels: schema-format.md §4. A product **chooses the value** of each level and
**which colors, typography styles and opacities** it uses, but does not invent names.

| Group | Levels | Notes |
|---|---|---|
| `space` | none, xxs, xs, sm, md, lg, xl, xxl, xxxl | all required |
| `radius` | none, xs, sm, md, lg, xl, xxl, full | `none` and `full` never change |
| `size` | sm, md, lg, xl | **control heights** (button, input, select) |
| `icon` | sm, md, lg | icon sizes, not typography |
| `border` | thin, thick | widths |
| `shadow` | none, sm, md, lg, xl | `none` = null |
| `motion` | fast, base, slow | durations |
| `opacity` | disabled, pressed, scrim | choose the ones the code uses |
| `typography` | display, h1–h4 (heading font); body_lg, body, body_sm, label, caption, button, overline (body font) | choose the ones the code uses |
| `font` | heading, body | 10 families in the catalog |
| `color` | see §3 | choose the ones the code uses |
| `layout` | page_gutter, mode | required |
| Identity | `shadow_style`, `border_style`, `motion_style`, `focus_ring`, `scrollbar` | optional: global knobs in the editor |

The starting web defaults are in creating-a-schema.md §8. `scales.mjs` uses them.

## 2. Numeric scales

For each scale, `scales.md` shows:

- the catalog value and the **fitted** one, which is the most used value among those each
  level absorbs after moving the levels to where the code's values are;
- the coverage of both;
- the values off the scale, with their uses and components.

**How to decide the value of each level:** if the fitted value clearly covers more, propose
the fitted value (the code used, for example, 5/10/15/20/30 instead of 4/8/12/16/24): the
default theme will look the same. If the catalog covers the same, keep the catalog. Present
the table to the user: it is a design decision.

**Tolerances for fitting a value to a level** (the ones that worked in the marketplace):

| Scale | Rule |
|---|---|
| space | ±2 px of the level; the rest is an exception or is moved closer with an approved visible change |
| radius | ±2 px; `≥ 50px` or `≥ 50%` → `full`; `0` → `none` |
| border | `1px → thin`, `2px → thick`; 1.5/3/6 px and CSS triangles stay fixed |
| font size | the nearest style within its group (below) |
| icon | ±2 px of sm/md/lg |
| control height | ±2 px of sm/md/lg/xl; the height comes **only** from the size, with `box-sizing: border-box` |
| motion | ±60 ms of fast/base/slow |
| shadow | main layer = largest blur + abs(y); distance `2·abs(Δy) + abs(Δblur) + abs(Δspread) + 40·abs(Δalpha)` ≤ 6 |

**Typography:** choose the group before the size.

- It is a heading if the context is a heading and it measures ≥ 16, if it measures ≥ 18, or
  if it is bold and measures ≥ 17. Then it goes to display, h1–h4.
- Otherwise it is text: body_lg, body or caption. Bold at ~14 is `label`; button text is
  `button`.
- A 16 px bold text is `body_lg` with its weight, **not** `h4`.
- The style carries size, line height (a ratio, not px: `round(line_px / size, 2)`), weight,
  `letter_spacing` and `transform`. The family comes from the font role.
- A block that only declares a weight keeps that weight and does not take the whole style.
- Icon sizes (`font-size` inside an `svg` or an icon) are **not** typography.

**Spacing:** decide it at the start, not at the end. Separate:

- separation between page blocks and from the title to the content: `space.xl`;
- rhythm between form fields: `space.md`;
- page side margins: `layout.page_gutter`;
- inside a component: a property of the component (`padding`, `gap`) whose default is an
  alias to `space.*`.

## 3. Colors: by role, not by closeness

`scales.md` groups colors into families by perceptual distance (ΔE76 < 5) and counts their
roles: text, background, border, icon, shadow. It is only the starting point. **The token is
chosen by the purpose of the use** (property + context), and the same hex can go to
different tokens.

| Use | Token |
|---|---|
| Page canvas (body, home, listings) | `background` |
| Cards and everything that floats (modals, popovers, menus, sheets, dropdowns) | `surface` |
| Bands and soft fills | `surface_alt` |
| Normal text | `text` |
| Secondary text, placeholders | `text_muted` |
| Disabled text, decorative icons | `text_disabled` |
| Fill or line of a disabled control | `disabled` |
| Separators, card borders, skeletons | `border` |
| Form control borders (≥ 3:1), scrollbar thumb, spinner | `border_strong` |
| Text on a color fill | `on_<color>` |
| A color used as text on the page (errors, amounts) | `primary_text`, `success_text`, `warning_text`, `danger_text` (≥ 4.5:1; there is no `secondary_text` or `info_text`) |
| Text and icons on photos | `on_overlay` |
| Scrim behind a modal | `overlay` |
| Brand, selection, focus, progress | `primary` (selected/unselected pair: primary/secondary) |
| States | `success`, `warning`, `danger`, `info`, with `on_*`, `*_subtle` and `*_text` (info has no `_text`) |
| Stars | `rating` |
| Favorite | `favorite` |

Mapping rules that worked:

- **Black or near black:** in text it is `text`; in a background it is `inverse_surface`; in
  a border it is `border_strong`.
- **White:** in a background or border it is `surface` (or `background` if it is the
  canvas). In text it is **reviewed by hand**: it can be `on_primary`, `on_*`, or text on a
  photo.
- **Light gray:** in a background it is `surface_alt`; in a border it is `border`; in text it
  is `text_disabled`.
- **Yellow:** `rating` if the context is a star or a review; otherwise, `warning`.
- **Blue:** in a link it is text with the legible primary color (`primary_text`); elsewhere,
  `primary` or `info`, depending on the purpose.
- **`rgba(0,0,0,a)`:** as a background with a ≥ 0.3 it is `overlay`; with less, it is a
  hover or pressed tint and comes from the component's state.
- **Shadows:** their color is part of the shadow token, not a color.
- **Translucency:** a color is opaque unless its node says `alpha: true`.
- **Derived colors:** `*_pressed`, `*_subtle`, `on_*`, `*_text` are declared with `derive`
  in the schema and arrive computed. The component does not use `darken` or `mix`.
- **A color that is not from the theme** (a business's, which comes from the API) goes
  through the engine's color functions (`@orderingco/ordering-theme-engine/colors`:
  `onColor`, `legible`, `shade`, `mix`).

**Second pass:** when everything is mapped, merge the tokens that share a purpose. In the
marketplace, `text_heading`, `divider`, `link`, `focus`, `surface_raised`, `body_sm`,
`overline` and `opacity.scrim` were removed, leaving 39 colors. Fewer tokens make a more
usable editor and a more coherent theme.

## 4. Identity: what follows the global level and what stays fixed

`shadow_style.size`, `border_style.radius` and `border_style.width/style` change at once
everything that **follows** them (`follows` in the schema; the helper applies the global
level).

Boxes follow the Identity: cards, buttons, inputs and modals.

These stay **fixed**. For shadows and borders, the helper receives `{ fixed: true }`; the
`none` and `full` radii keep their value on their own, because the engine's `identity` view
does not move them:

- states (hover, focus, pressed) keep their own level;
- shadows of blocks with a transparent background;
- the `none` and `full` radii;
- single-side borders (separators, underlines);
- outlines;
- checkbox and radio boxes;
- round shapes;
- borders reviewed by hand (chips, ribbons).

Record it: a TSV `kind, helper, level, component, file:line, selector` makes what follows
and what does not auditable.

## 5. Exceptions

An exception is a literal that stays on purpose. It goes in `theme-exceptions.json` with
its reason (format in `templates/`); the guard allows them. Common categories:

- not a color (`transparent`, `currentColor`, `inherit`);
- a fallback that is never seen: it is deleted instead of recorded;
- a third party's brand (Google, Apple, Facebook, PayPal buttons; Stripe fields);
- text or a scrim on a photo, if it does not fit `on_overlay` or `overlay`;
- SVG illustrations and keyframes;
- internal UI the end customer does not see (agent mode, call center);
- layout measurements that are not design (`100%`, `calc` with percentages, column
  widths);
- values off the scale that the user decided to keep.

A value off the scale **without** a decision is not an exception: it is a pending question.

## 6. Visible changes

Each value moved to a level other than its own (padding from 10 to 12, height from 45 to 48)
is noted in `docs/theme/tokens.md` (before → after, where) and approved by the user. The
schema's defaults reproduce the current look except for those changes.
