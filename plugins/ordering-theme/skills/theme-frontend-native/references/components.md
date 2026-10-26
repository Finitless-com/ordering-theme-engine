# Grouping by function on React Native

The method is the web skill's: read `../theme-frontend-web/references/components.md` (one
function, one component; the reuse ladder; a component's axes; when a variant stays;
property naming and order). This file is what changes on React Native.

## 1. Primitives

A React Native app usually has a set of shared primitives already (`OText`, `OButton`,
`OIcon`, `OInput`, `OModal`, `OBottomPopup`…) that most screens bypass with
`styled.TouchableOpacity`, raw `<Text>` with its own size, raw `TextInput` and their own
modals. The conversion makes the primitives read the theme first, then moves every bypass
onto them. Typical groupings:

| Many implementations of… | Become |
|---|---|
| `styled.TouchableOpacity` / `Pressable` buttons, `OButton` with `textStyle`/`style` overrides, feature buttons (floating, logout, GPS) | `button` (colors × appearances, sizes, `shapes.icon`), with an icon button |
| Raw `<Text>`, `styled.Text`, `OText` with a literal `size`/`weight`/`lineHeight` | the text primitive with a typography style (`preset`), its color from a role |
| Tinted PNG icons and vector icons with literal sizes | one icon primitive: `icon.*` sizes, color by role |
| Local headers (back button + title) on each screen | `header` (the screen's top bar) |
| Bottom tab bar | `tab_bar` (its own component: active/inactive colors, label typography, icon size) |
| `OModal`, raw `Modal`, custom animated sheets | `modal` and a bottom `sheet`, one implementation each |
| Pills, chips, tags, badges duplicated per layout | `chip` and `badge` |
| Dividers with drifting heights and colors (1/4/8 pt, `#EAEAEA`, `border`, gray fills) | `divider` (item, section, band) |
| Cards per feature and per design variant | `card`, and the concrete cards `inherits: card` |
| Skeleton lines with literal widths and heights | a skeleton primitive whose color is global; sizes stay layout |
| `NotFoundSource` and local empty wrappers | `empty_state` |
| `Alert.alert`, toasts, notice boxes | `toast`, `alert` (system alerts stay system: not themable) |
| Spinners | `spinner` (sizes) |
| Inputs (own, react-native-paper, phone, digit/OTP, search) | `input` + `field`, `search_bar` inheriting `input` |

## 2. Press feedback and states

- Touchables: `activeOpacity` comes from `opacity.pressed`, or the button's `pressed`
  state when it has one; android ripple, if used, takes the pressed color. One rule in
  the primitive, not per call.
- `disabled` comes from the theme's disabled state; `hover` does not exist on touch
  screens (keep the state in the schema only if the web shares it — a native schema
  does not need it).
- `focus` matters for inputs and for keyboard/TV navigation: its ring or border comes
  from the theme.

## 3. Design variants

An app may already switch whole layouts at run time (for example
`app_design.components.layout.type`: `original` | `maweja`, with forked folders per screen and inline
ternaries like `isMaweja ? 180 : 162`). Do not flatten them:

- a variant that changes the **structure** of screens is a `layout` property
  (`$type: layout`, options = the variants the code has) on each component that forks
  (the listing, the business page, the cart, the checkout…): the schema's root keys are
  closed and `tokens.layout.mode`'s options are fixed, so there is no app-wide option to
  add. When every forked screen must switch together, give the components the same
  property and default, and say so in the editor's info;
- the **values** that differ between variants (`180` vs `162`, radius `20` vs `30`) are
  component properties whose defaults are the default variant's; a variant that only
  changes values merges into the base and leaves its values to the theme;
- a component duplicated per variant (two `CartBadge`, two `AddressPill`) becomes one.

Ask the user which variants stay: it is a product decision.

## 4. Styles that cannot see the theme

- A module-level `StyleSheet.create` is computed once, at import: it never sees the
  theme. Move it inside the component (memoized on the theme), or into a function of the
  theme, or into a styled component.
- Inline `style={{ … }}` with literals moves to the component's styles, reading helpers.
- `defaultProps` on function components is ignored by React 19: defaults go in the
  destructuring.

## 5. Platform differences

- A difference that is only how the platform paints (iOS shadow vs Android elevation, font
  file per weight) is absorbed by the runtime helpers, not stored twice.
- A difference an author could want (a larger header on tablets) is a `platforms` entry
  of the schema (`schema.md` §2).
- Behaviour (keyboard `behavior`, permissions) is not the theme's.
