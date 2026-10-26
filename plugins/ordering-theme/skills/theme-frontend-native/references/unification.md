# Unifying values on React Native

The rules are the web skill's: read `../theme-frontend-web/references/unification.md`
(the fixed catalog, tolerances, color by role, Identity, exceptions, visible changes).
This file is what changes on React Native.

## 1. Units

Every dimension in the schema is a unitless number: **pt on native** (density-independent
points, what a React Native style takes), px on the web. The same catalog levels apply;
the values are the app's own (a native schema has its own defaults, it does not copy the
web's). Today's `20px` in a styled-components/native template and `20` in a style object
are the same 20 pt.

## 2. Typography

- A text style is `size`, `line` (a ratio), `weight`, `letter_spacing`, `transform`; the
  family comes from its font role (`heading` for display and h1–h4, `body` for the rest).
- **Line height:** React Native's `lineHeight` is absolute. The schema stores the ratio
  (`round(lineHeight / fontSize, 2)`); the runtime paints `line × size`. A text without a
  line height today takes the role's ratio, which is a visible change to list.
- **Size props:** text components that take `size={N}` (`OText`) are typography. Map each
  to the nearest style of its group (title or text) as on the web; a component that
  passes a size for each instance becomes a component property or a `preset`/style name
  that reads a typography token.
- **Weights:** normalize `'bold'`, `'600'`, `600` and platform ternaries to numbers; the
  runtime picks the font file for the weight (`runtime-native.md` §4). A platform ternary
  of weights (`ios ? '600' : 'bold'`) is a font-file problem, not a theme value: with one
  file per weight it disappears.
- **Families:** a hard-coded `fontFamily` goes; the family comes from the role. The
  catalog's 10 fonts are the only ones (schema-format.md §4): the app bundles the
  weights it paints of each one the schema offers (`schema.md` §3).
- **Font scaling:** the system text size (Dynamic Type, Android font scale) multiplies
  sizes on top of the theme. Decide it once, globally (`allowFontScaling`,
  `maxFontSizeMultiplier` on the text primitive), not per component.

## 3. Shadows

A shadow token is `{ x, y, blur, spread, color, opacity, elevation }`, with `elevation`
for Android. Today's three pieces (`shadowColor` + `shadowOffset` + `shadowOpacity` +
`shadowRadius` on iOS, `elevation` on Android, sometimes `boxShadow`) are **one** shadow
level: read them together.

- Match iOS shadows to a level by the web's distance on the main layer:
  `shadowRadius` is roughly half the blur (`blur ≈ 2 × shadowRadius`), `shadowOffset` is
  `x`/`y`, `shadowOpacity` × the color's alpha is the opacity.
- Android's `elevation` values today become the levels' `elevation`; `scales.md` lists
  them. An `elevation` without an iOS shadow (or the reverse) is a platform difference
  the runtime removes: both come from the same token.
- A colored shadow, a shadow used as a border, or `elevation: 99999` used for stacking
  order is not a shadow level (it is an exception, a border, or `zIndex`).

## 4. Borders and radii

- `1px`/`1` → `border.thin`, `2` → `border.thick`. `StyleSheet.hairlineWidth` is its own
  thing (the thinnest line the screen draws): keep it as a recorded exception or map
  dividers to `thin`; decide once.
- Radius: the same levels and tolerance as the web; a radius equal to half the height is
  `full`.

## 5. Sizes

- **Control heights** (`size` tokens): buttons, inputs, selects. A minimum touch target
  of 44 pt (iOS) / 48 dp (Android) for anything tappable is a rule of the primitives
  (`hitSlop` or the height), not a token per component.
- **Icons:** vector-icon `size={N}` and tinted PNG `width`/`height` map to `icon.sm/md/lg`
  (±2). Icon sizes are never typography.
- **Sizes from the window** (`windowWidth - 40`, `windowWidth * 0.62`) are layout, not
  tokens; the constant inside them (`40` = twice the page gutter) is `layout.page_gutter`.

## 6. Spacing

`paddingHorizontal: 20` repeated on every screen is `layout.page_gutter`. The rest follow
the web's rule (±2 pt of a `space` level). Spacing that differs only by platform
(`ios ? 35 : 10`) is usually a safe-area inset written by hand: replace it with the inset,
not with a token.

## 7. Colors

The web's role table applies. Also:

- `tintColor` on a PNG icon is an icon color (the plain color of its role).
- `placeholderTextColor` is `text_muted`.
- The status bar style (`light-content` / `dark-content`) follows the background it sits
  on: compute it from the color (`isLight` of `@orderingco/ordering-theme-engine/colors`),
  do not store it.
- Duplicated legacy keys (`secundaryContrast` and `secondaryContrast`, `textNormal` and
  `textGray` with the same hex) collapse into one token; keys read but not defined today
  get the token their role says.

## 8. Motion

`Animated.timing` durations map to `motion.fast/base/slow` (±60 ms); springs keep their
physics as a component property only if an author would change them. `Modal`'s
`animationType` is behaviour. Respect reduced motion (`runtime-native.md` §6).

## 9. Opacity

`activeOpacity` on touchables is the pressed state: `opacity.pressed` through the button
or touchable primitive, not a value per call.
