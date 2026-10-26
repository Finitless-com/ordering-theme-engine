# The theme in the web frontend

Build it **once and before touching components**: the pipeline that carries the served
theme to the styles, plus the helpers with which each component reads it. Setting it up
at the end forces you to rewrite every batch.

## 1. Pipeline

```
product.schema.json ──ordering-theme compile──▶ src/theme.compiled.json (committed; theme:verify)
                                                        │
served theme (API / first load) ──▶ complete(compiled, values)  ← @orderingco/ordering-theme-engine/runtime
                                                        │  every key, understood values, clamped numbers
                                                        ▼
                                          toCss(compiled, values)  ← @orderingco/ordering-theme-engine/css
                                                        │  the CSS view: tokens, identity, components, platforms, fonts
                                                        ▼
                         app theme = { values, css, meta }  →  provider  →  helpers  →  styles
```

- **`THEME_DEFAULTS = complete(compiled, null)`**: the defaults, to render before the
  theme arrives and in tests.
- **Construction:** the app theme is built only once per served theme (cached
  with `WeakMap`). On any exception it falls back to the defaults; it never leaves the app
  without a theme.
- **The theme request** sends the schema's `product_type` and `version`, read from the
  compiled file and never written by hand. api-core trims a theme from a newer schema to that
  version.
- **Empty images** (`''`) are filled with the bundle's, in a single module.
- **The theme arrives complete and valid.** Readers do not revalidate options or ranges and do
  not write fallbacks that duplicate a default. They only handle what the schema lets be
  null: a border, a shadow, an empty image, a slot's axis, a platform
  override.
- **Never build a theme by spreading values** (`{ ...theme, values }`): its CSS view
  would be stale. Rebuild it with the construction function, in tests too.
- **Render from the view** (`theme.css`); read `theme.values` only to compute
  (sums, comparisons, nulls, options). Never convert a value to CSS in a
  component (`${value}px`, a shadow assembled by hand).

## 2. Helpers

One file per family, and no component reads `theme.values.tokens…` inline. Names
from the marketplace, worth keeping across products:

| Need | Helper |
|---|---|
| A component as CSS / its values | `getThemeCss(theme, c)`, `getThemeMobileCss(theme, c)`, `getThemeComponent(theme, c)` |
| Properties of a component | `themePart(theme, c, { 'css-prop': 'base.path' })` (CSS), `themeValue(theme, c, path)` (value) |
| Color | `themeColor(theme, 'surface')`, `withAlpha`, `isTransparent` |
| Space | `themeSpace(theme, 'md')`, `pageGutter(theme)` |
| Radius, shadow, border (apply the Identity) | `themeRadius(theme, level, component?)`, `themeShadow(theme, level, opts?)`, `themeBorderWidth(theme, level, opts?)`, `themeBorderStyle`; on shadow and border, `{ fixed: true }` keeps the level |
| Text | `themeText(theme, 'body', { component, props, weight })`, `headingText(theme, level)`, `themePartText(theme, c, path)` |
| Sizes | `themeIconSize`, `themeControlHeight(theme, c, size)`, `themeSize` |
| Opacity, scrim | `themeOpacity`, `themeScrim` |
| Motion | `themeMotion`, `motionCss`, `stateMotionCss` (respect reduced motion; use the engine's `transition()`/`animation()`) |
| Groups and slots | `scopedTheme(theme, group)`, `themeSlot`, `slotButtonProps` |
| Visibility | `themeVisible(theme, key)`, `themeHidden` |
| Sections | `pageSections(theme, page)`, the hook `usePageSections(page)` → `{ sections, isVisible, render }` |
| Images | `themeImage(theme, key)`; the bundle's images, in their own module |
| Site settings | `siteSetting(...)` in its module |
| Fonts | the engine's `fonts(theme.css, extra)` → Google Fonts sheets, one per family |

If a helper is missing for a token group, add it next to its siblings.

Reference implementation in Finitless-com/website-marketplace-theming (clone it next to the
repo you are converting):

| Piece | File |
|---|---|
| Theme construction | `src/utils/appTheme.js` |
| Loading the served theme | `src/utils/remoteTheme.js` |
| CSS view | `src/utils/themeCss.js` |
| Tokens | `src/utils/themeTokens.js`, `themeColors.js` |
| Motion | `themeMotion.js` |
| Visibility and sections | `themeVisibility.js`, `themePages.js` |
| Groups and slots | `themeGroups.js` |
| Site settings and bundle images | `siteSettings.js`, `bundledImages.js` |
| Reading the schema | `themeSchema.js` (tests and scripts only) |
| Hooks | `src/ui/hooks/usePageSections.js`, `useThemePlatform.js` |
| Provider | `src/ui/contexts/ThemeContext/index.js` |
| Group scope | `src/ui/src/styles/ComponentScope/` |
| Primitives | `src/ui/src/styles/*` (Button, Card, Box, Divider, Chip, Choice, Chevron, Focus, Layer, Link…) |
| Test utilities | `src/ui/src/styles/__tests__/themeTestUtils.jsx` |
| Defaults sync | `src/utils/__tests__/themeDefaults.test.js` |

## 3. By styling technology

**styled-components or emotion** (the marketplace's case):

- the helpers return CSS strings and are interpolated:
  `` border-radius: ${p => themeRadius(p.theme, 'md', 'card')}; ``;
- the `ThemeProvider` receives the app theme. If the theme changes at runtime, the provider
  must follow its prop, not copy it only once;
- a `GlobalStyle` created inside the render is remounted on every render: create it
  outside;
- a `styled(Primitive)` does not repaint the box: it only adds layout.

**CSS Modules, SCSS, plain CSS or Vue/Svelte with `<style>`:**

- publish the view as **CSS variables** on `:root`, one per value:
  - tokens: `--space-md`, `--color-surface`, `--radius-md` (the Identity one),
    `--shadow-sm`, `--text-body` (with its parts: `--text-body-size`…);
  - components: `--button-base-background`…;
- generate the variables with a small function that walks `toCss()` and writes a
  `<style>` when the theme changes;
- the `platforms.mobile_web` overrides go in the same `<style>`, inside
  `@media (max-width: <breakpoint - 1>px)`;
- the styles only use `var(--…)`, and the guard forbids literals.

**Tailwind:**

- map the `theme` of `tailwind.config` to those variables (`spacing: { md: 'var(--space-md)' }`,
  `colors: { surface: 'var(--color-surface)' }`, `borderRadius`, `boxShadow`, `fontSize`);
- forbid arbitrary values (`p-[13px]`, `text-[#333]`) and the default palette
  (`bg-gray-100`). `inventory.mjs` detects them as `tw:*`. With the config already mapped,
  run the guard with `--tailwind-mapped`: it lets `p-4` or `bg-surface` through and only fails on
  arbitrary values and on Tailwind's own palette;
- for a component with many properties, use a class of its own that reads its variables.

**In every case:**

- a single phone breakpoint, read from a constant;
- the platform (`mobile_web`) is decided by width in a hook, and the helpers apply it.

## 4. Sections and visibility in the code

```jsx
const { render, isVisible } = usePageSections('home')
return (
  <>
    <Hero />                                   {/* hideable: false, group: null → fixed */}
    {render({
      featured:      () => <Featured />,
      promo_banners: () => <PromoBanners />
    }, { group: 'main' })}                     {/* in the theme's order and visibility */}
  </>
)

const showFee = themeVisible(theme, 'business_card.fee')  // the phone override wins below the breakpoint
```

`render` walks the group's visible sections in the theme's order. If the theme
reorders, it ignores unknown or duplicate keys, leaves the missing ones in place and
forces visible those that have `hideable: false`.

## 5. Robustness

- **Safe values:** the engine's view already nulls a string with `{ } ; < > ' "`. If the
  code composes its own CSS, use the engine's `cssBorder`, `cssShadow` and `cssText`.
- **Computed colors:** no `darken`, `lighten` or `polished` on theme
  values. Derived colors arrive served, and colors outside the theme go through
  `@orderingco/ordering-theme-engine/colors`.
- **`url()`:** always quoted, because SVGs in data URIs break without quotes.
- **Clipping:** `overflow: hidden` clips shadows and focus rings. Leave room (a
  `Focus` primitive with its margin) or move the shadow to the outer container.
- **Full-width bars:** the shadow is cast only downward or upward
  (`shadow.down`/`up` of the view).
- **Layers:** a bar inside a floating layer paints its layer's background (variable
  `--layer-background`).
- **Skeletons:** their color is set once, globally (`border` with `surface_alt` shine).
