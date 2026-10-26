# Grouping by function and deciding variants

## 1. One function, one component

Everything that serves the same function uses **one** theme component and **one** code
implementation, a primitive. `components.md` shows the candidates by function: buttons
(stray `styled.button` and `styled(Button)` overrides), cards, notice boxes, separators,
chips, inputs, etc. Confirm each group by reading the code.

Groupings that came up in the marketplace and repeat in almost any frontend:

| Many implementations of… | Become |
|---|---|
| Hand-made buttons, `<div onClick>`, links that look like buttons | `button` (with `colors` × `appearances`, `sizes`, `shapes.icon`), plus `IconButton` |
| Filters, pills, selectable categories | `chip` (with `selected`) |
| Option lists with radio or check | `choice` |
| Containers with background, border, radius and shadow | `card`, and the specific cards inherit it (`inherits: card`) |
| `<hr>` lines, row `border-bottom` | `divider` (item, section, band, vertical) |
| Colored notice boxes | `alert` (info, success, warning, danger) |
| Repeated hearts or stars | `favorite` or `rating` |
| Profile photos in 5 sizes | `avatar` with `sizes` |
| Different chevrons | a `Chevron` primitive (disclose, list, navigate) |
| Section titles with their own margins | `section_header` (owner of the page's h1 and h2) |
| Bars stuck to the bottom | a component (`bottom_nav` or similar) |
| Dropdowns and menu popovers | `menu` |
| Primary, secondary and group inputs | `input`, with `field` for label, help and error |
| Modals, sheets, side panels | `modal` (sizes) and a bottom sheet on phones |
| Spinners and skeletons on each screen | `spinner` (sizes); skeletons with a global color |

## 2. Reuse ladder

For each place that paints something, go down this list and stop at the first step that
fits:

1. **The component already has the property:** read that property.
2. **A shared primitive already does it:** use the primitive, without a second version
   with its own CSS.
3. **A direct token:** layout glue that should move with the global scale and that no one
   will want to change separately (gap between page blocks, form rhythm, color of a plain
   title).
4. **A new property on an existing component:** when it is a distinct visual part that a
   theme author could want different from the token. Its default is an alias to the token
   (`{space.sm}`, `{color.surface}`).
5. **A new component:** when it is a reusable piece with its own look that no component
   covers.

Never a new token.

## 3. The axes of a component

Details in schema-format.md §5 and §11.

| Part | What it is | States |
|---|---|---|
| `base` | Properties of the component | no |
| `variants` | Versions with a different look or structure (tabs: `pill`, `underline`, `plain`) | yes (`hover`, `pressed`, `focus`, `disabled`…) |
| `appearances` | Treatments of the same color (`solid`, `outline`, `tonal`, `ghost`) | yes |
| `colors` | Color roles (`primary`, `secondary`, `danger`…) with `main`, `on`, `ink`, `subtle`, `pressed`; crossed with `appearances` via `matrix` | no |
| `sizes` | Size steps (`sm`, `md`, `lg`): height, padding, text, icon | no |
| `shapes` | Shapes (`icon`: square or round) | no |
| `slots` | Another contained component, with its axes (`business_card.slots.cta → button md solid secondary`) | — |
| `scope` | What changes in other components inside this one (`header.scope.button`) | — |
| `inherits` | Takes what the theme sets on the parent for the properties it declares | — |
| `platforms.<p>` | A different default for a `base` property on a platform (`mobile_web`) | — |

Preferences:

- a **slot** rather than restyling a contained component;
- **inherits** rather than copying the parent's properties;
- **scope** rather than per-place overrides;
- **platforms.mobile_web** rather than `*_mobile` properties.

Two components that must look the same use the same token in their defaults; neither reads
the other's properties.

## 4. Variant or not?

A variant **stays** if it meets at least one of these conditions:

- it changes the **structure** (another layout, other pieces), not just a value;
- it differs in **two or more** visual properties outside tolerance (`unification.md` §2)
  and is used in more than one place;
- a reasonable theme author would want to change it separately (the tabs' underline and
  pill);
- the user said to keep it.

It is **merged** with its neighbor when:

- the differences fall within the scale's tolerance: radius 8 versus 10, padding 12 versus
  14;
- it only differs in a value that an axis already gives (another size is `sizes`, another
  color is `colors`);
- it is used in a single place and the difference has no purpose;
- it is a layout for one specific client.

When merging, note the visible change (before → after, where) for approval.

A structural variant that the code already has and the editor offers as an option is a
property `$type: layout` with `options` (for example, `logo_position: left|center|right`).
Do not invent layouts the code does not implement.

## 5. Properties of a component

- **Names** in `snake_case`, after the part they paint: `background`, `color`, `border`,
  `radius`, `shadow`, `padding`, `gap`, `title`, `name_color`, `logo_size`, `icon_size`,
  `animated`, `speed`.
- **Defaults** as an alias to a token whenever possible. A literal only when the value
  belongs to the component (a header height with its `min`/`max`).
- **`follows`** on the radius, shadow and border of boxes that follow the Identity. Never on
  states.
- **`derive`** on states (hover that raises the shadow one step, pressed that darkens the
  background), instead of a fixed value.
- **Order in the editor:** box → arrangement → texts (each color after its text) →
  sizes → animation (`schema.md` §3).
- **Each property has a reader** in the code and a test that changes its value and sees the
  effect.

## 6. Traps when unifying

- A container's CSS targeting `button` beats the primitive: remove the override, do not
  reinforce it.
- A `styled(Button)` with 20 lines of colors and hover is replaced by the primitive with its
  props (`appearance`, `color`, `size`) or by another component (`Chip`).
- A container that only does layout keeps the layout (gap, grid) and does not paint its
  children's box.
- Icon buttons are square (`shapes.icon`) and have an accessible name (a translated
  `aria-label`).
- The height comes **only** from `sizes`: a 1 px border cannot turn 32 into 34
  (`box-sizing: border-box`).
- A button's text stays on one line; watch the `ellipsis` in flexible footers.
