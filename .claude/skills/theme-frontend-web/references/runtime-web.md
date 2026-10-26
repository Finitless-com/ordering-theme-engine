# El tema en el frontend web

Se construye **una vez y antes de tocar componentes**: la tubería que lleva el tema
servido hasta los estilos, más los helpers con los que cada componente lo lee. Montarla
al final obliga a reescribir todos los lotes.

## 1. Tubería

```
product.schema.json ──ordering-theme compile──▶ src/theme.compiled.json (versionado; theme:verify)
                                                        │
tema servido (API / first load) ──▶ complete(compiled, values)  ← @orderingco/ordering-theme-engine/runtime
                                                        │  todas las claves, valores entendidos, números recortados
                                                        ▼
                                          toCss(compiled, values)  ← @orderingco/ordering-theme-engine/css
                                                        │  la vista CSS: tokens, identity, components, platforms, fonts
                                                        ▼
                         app theme = { values, css, meta }  →  provider  →  helpers  →  estilos
```

- **`THEME_DEFAULTS = complete(compiled, null)`**: los defaults, para pintar antes de que
  llegue el tema y en tests.
- **Construcción:** el tema de la app se construye una sola vez por tema servido (caché
  con `WeakMap`). Ante cualquier excepción vuelve a los defaults; nunca deja la app sin
  tema.
- **La petición** del tema envía el `product_type` y la `version` del schema, leídos del
  compilado y nunca escritos a mano. api-core recorta un tema de un schema más nuevo a esa
  versión.
- **Las imágenes vacías** (`''`) se completan con las del bundle, en un único módulo.
- **El tema llega completo y válido.** Los lectores no revalidan opciones ni rangos y no
  escriben fallbacks que dupliquen un default. Solo manejan lo que el schema deja ser
  null: un borde, una sombra, una imagen vacía, el eje de un slot, una override de
  plataforma.
- **Nunca se construye un tema esparciendo valores** (`{ ...theme, values }`): su vista CSS
  quedaría vieja. Se reconstruye con la función de construcción, también en tests.
- **Se pinta desde la vista** (`theme.css`); `theme.values` se lee solo para calcular
  (sumas, comparaciones, nulls, opciones). Nunca se convierte un valor a CSS en un
  componente (`${value}px`, una sombra armada a mano).

## 2. Helpers

Un archivo por familia, y ningún componente lee `theme.values.tokens…` en línea. Nombres
del marketplace, que conviene mantener entre productos:

| Necesidad | Helper |
|---|---|
| Un componente como CSS / sus valores | `getThemeCss(theme, c)`, `getThemeMobileCss(theme, c)`, `getThemeComponent(theme, c)` |
| Propiedades de un componente | `themePart(theme, c, { 'css-prop': 'base.path' })` (CSS), `themeValue(theme, c, path)` (valor) |
| Color | `themeColor(theme, 'surface')`, `withAlpha`, `isTransparent` |
| Espacio | `themeSpace(theme, 'md')`, `pageGutter(theme)` |
| Radio, sombra, borde (aplican la Identidad) | `themeRadius(theme, level, component?)`, `themeShadow(theme, level, opts?)`, `themeBorderWidth(theme, level, opts?)`, `themeBorderStyle`; en sombra y borde, `{ fixed: true }` conserva el nivel |
| Texto | `themeText(theme, 'body', { component, props, weight })`, `headingText(theme, level)`, `themePartText(theme, c, path)` |
| Tamaños | `themeIconSize`, `themeControlHeight(theme, c, size)`, `themeSize` |
| Opacidad, velo | `themeOpacity`, `themeScrim` |
| Movimiento | `themeMotion`, `motionCss`, `stateMotionCss` (respetan reduced motion; usan `transition()`/`animation()` del engine) |
| Grupos y slots | `scopedTheme(theme, group)`, `themeSlot`, `slotButtonProps` |
| Visibilidad | `themeVisible(theme, key)`, `themeHidden` |
| Secciones | `pageSections(theme, page)`, el hook `usePageSections(page)` → `{ sections, isVisible, render }` |
| Imágenes | `themeImage(theme, key)`; las imágenes del bundle, en su propio módulo |
| Ajustes del sitio | `siteSetting(...)` en su módulo |
| Fuentes | `fonts(theme.css, extra)` del engine → hojas de Google Fonts, una por familia |

Si falta un helper para un grupo de tokens, se añade junto a sus hermanos.

Implementación de referencia en Finitless-com/website-marketplace-theming (clónalo junto al
repo que conviertes):

| Pieza | Archivo |
|---|---|
| Construcción del tema | `src/utils/appTheme.js` |
| Carga del tema servido | `src/utils/remoteTheme.js` |
| Vista CSS | `src/utils/themeCss.js` |
| Tokens | `src/utils/themeTokens.js`, `themeColors.js` |
| Movimiento | `themeMotion.js` |
| Visibilidad y secciones | `themeVisibility.js`, `themePages.js` |
| Grupos y slots | `themeGroups.js` |
| Ajustes del sitio e imágenes del bundle | `siteSettings.js`, `bundledImages.js` |
| Lectura del schema | `themeSchema.js` (solo tests y scripts) |
| Hooks | `src/ui/hooks/usePageSections.js`, `useThemePlatform.js` |
| Provider | `src/ui/contexts/ThemeContext/index.js` |
| Scope de grupos | `src/ui/src/styles/ComponentScope/` |
| Primitivas | `src/ui/src/styles/*` (Button, Card, Box, Divider, Chip, Choice, Chevron, Focus, Layer, Link…) |
| Utilidades de test | `src/ui/src/styles/__tests__/themeTestUtils.jsx` |
| Sincronía de defaults | `src/utils/__tests__/themeDefaults.test.js` |

## 3. Según la tecnología de estilos

**styled-components o emotion** (el caso del marketplace):

- los helpers devuelven strings CSS y se interpolan:
  `` border-radius: ${p => themeRadius(p.theme, 'md', 'card')}; ``;
- el `ThemeProvider` recibe el tema de la app. Si el tema cambia en runtime, el provider
  debe seguir su prop, no copiarla una sola vez;
- un `GlobalStyle` creado dentro del render se vuelve a montar en cada render: créalo
  fuera;
- un `styled(Primitive)` no repinta la caja: solo añade layout.

**CSS Modules, SCSS, CSS plano o Vue/Svelte con `<style>`:**

- publica la vista como **variables CSS** en `:root`, una por valor:
  - tokens: `--space-md`, `--color-surface`, `--radius-md` (el de Identidad),
    `--shadow-sm`, `--text-body` (con sus partes: `--text-body-size`…);
  - componentes: `--button-base-background`…;
- genera las variables con una función pequeña que recorre `toCss()` y escribe un
  `<style>` cuando cambia el tema;
- las overrides de `platforms.mobile_web` van en el mismo `<style>`, dentro de
  `@media (max-width: <breakpoint - 1>px)`;
- los estilos solo usan `var(--…)`, y el guard prohíbe literales.

**Tailwind:**

- mapea el `theme` de `tailwind.config` a esas variables (`spacing: { md: 'var(--space-md)' }`,
  `colors: { surface: 'var(--color-surface)' }`, `borderRadius`, `boxShadow`, `fontSize`);
- prohíbe los valores arbitrarios (`p-[13px]`, `text-[#333]`) y la paleta por defecto
  (`bg-gray-100`). `inventory.mjs` los detecta como `tw:*`. Con el config ya mapeado,
  corre el guard con `--tailwind-mapped`: deja pasar `p-4` o `bg-surface` y solo falla con
  valores arbitrarios y con la paleta propia de Tailwind;
- para un componente con muchas propiedades, usa una clase propia que lea sus variables.

**En todos los casos:**

- un único breakpoint de teléfono, leído de una constante;
- la plataforma (`mobile_web`) se decide por ancho en un hook, y los helpers la aplican.

## 4. Secciones y visibilidad en el código

```jsx
const { render, isVisible } = usePageSections('home')
return (
  <>
    <Hero />                                   {/* hideable: false, group: null → fijo */}
    {render({
      featured:      () => <Featured />,
      promo_banners: () => <PromoBanners />
    }, { group: 'main' })}                     {/* en el orden y la visibilidad del tema */}
  </>
)

const showFee = themeVisible(theme, 'business_card.fee')  // la override de teléfono gana debajo del breakpoint
```

`render` recorre las secciones visibles del grupo en el orden del tema. Si el tema
reordena, ignora claves desconocidas o duplicadas, deja en su sitio las que faltan y
fuerza visibles las que tienen `hideable: false`.

## 5. Robustez

- **Valores seguros:** la vista del engine ya anula un string con `{ } ; < > ' "`. Si el
  código compone CSS propio, usa `cssBorder`, `cssShadow` y `cssText` del engine.
- **Colores calculados:** nada de `darken`, `lighten` ni `polished` sobre valores del
  tema. Los derivados llegan servidos, y los colores ajenos al tema pasan por
  `@orderingco/ordering-theme-engine/colors`.
- **`url()`:** siempre entre comillas, porque los SVG en data URI rompen sin ellas.
- **Recortes:** `overflow: hidden` recorta sombras y anillos de foco. Deja sitio (una
  primitiva `Focus` con su margen) o mueve la sombra al contenedor exterior.
- **Barras a todo el ancho:** la sombra se proyecta solo hacia abajo o hacia arriba
  (`shadow.down`/`up` de la vista).
- **Capas:** una barra dentro de una capa flotante pinta el fondo de su capa (variable
  `--layer-background`).
- **Skeletons:** su color se pone una vez, global (`border` con brillo `surface_alt`).
