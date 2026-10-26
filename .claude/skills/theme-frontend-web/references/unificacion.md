# Unificar valores en tokens

La meta es **una escala corta que reproduzca el aspecto actual**: cada valor del código cae
en un nivel del catálogo; lo que no cae se decide (nivel más cercano con su cambio visible,
mover el valor del nivel, o excepción registrada). Nunca se crea un nivel por cada valor.

## 1. El catálogo es fijo

Grupos y niveles: schema-format.md §4. Un producto **elige el valor** de cada nivel y
**qué colores, tipografías y opacidades** usa, pero no inventa nombres.

| Grupo | Niveles | Notas |
|---|---|---|
| `space` | none, xxs, xs, sm, md, lg, xl, xxl, xxxl | todos obligatorios |
| `radius` | none, xs, sm, md, lg, xl, xxl, full | `none` y `full` nunca cambian |
| `size` | sm, md, lg, xl | **alturas de control** (botón, input, select) |
| `icon` | sm, md, lg | tamaños de icono, no tipografía |
| `border` | thin, thick | anchos |
| `shadow` | none, sm, md, lg, xl | `none` = null |
| `motion` | fast, base, slow | duraciones |
| `opacity` | disabled, pressed, scrim | elige las que use el código |
| `typography` | display, h1–h4 (fuente de títulos); body_lg, body, body_sm, label, caption, button, overline (fuente de texto) | elige las que use el código |
| `font` | heading, body | 10 familias del catálogo |
| `color` | ver §3 | elige las que use el código |
| `layout` | page_gutter, mode | obligatorio |
| Identidad | `shadow_style`, `border_style`, `motion_style`, `focus_ring`, `scrollbar` | opcionales: perillas globales del editor |

Los defaults web de partida están en creating-a-schema.md §8. `scales.mjs` los usa.

## 2. Escalas numéricas

Para cada escala, `scales.md` muestra:

- el valor del catálogo y el **ajustado**, que es el valor más usado de lo que absorbe cada
  nivel tras mover los niveles a donde están los valores del código;
- la cobertura de ambos;
- los valores fuera de escala, con sus usos y componentes.

**Cómo decidir el valor de cada nivel:** si el ajustado cubre claramente más, propón el
ajustado (el código usaba, por ejemplo, 5/10/15/20/30 en lugar de 4/8/12/16/24): el tema por
defecto se verá igual. Si el catálogo cubre igual, quédate con el catálogo. Presenta la
tabla al usuario: es una decisión de diseño.

**Tolerancias para encajar un valor en un nivel** (las que funcionaron en el marketplace):

| Escala | Regla |
|---|---|
| space | ±2 px del nivel; lo demás es excepción o se acerca con cambio visible aprobado |
| radius | ±2 px; `≥ 50px` o `≥ 50%` → `full`; `0` → `none` |
| border | `1px → thin`, `2px → thick`; 1.5/3/6 px y triángulos CSS quedan fijos |
| font size | el estilo más cercano dentro de su grupo (abajo) |
| icon | ±2 px de sm/md/lg |
| altura de control | ±2 px de sm/md/lg/xl; la altura sale **solo** del tamaño, con `box-sizing: border-box` |
| motion | ±60 ms de fast/base/slow |
| shadow | capa principal = mayor blur + abs(y); distancia `2·abs(Δy) + abs(Δblur) + abs(Δspread) + 40·abs(Δalpha)` ≤ 6 |

**Tipografía:** elige el grupo antes que el tamaño.

- Es título si el contexto es un título y mide ≥ 16, si mide ≥ 18, o si es negrita y mide
  ≥ 17. Entonces va a display, h1–h4.
- Si no, es texto: body_lg, body o caption. Negrita de ~14 es `label`; el texto de botón es
  `button`.
- Un texto de 16 px en negrita es `body_lg` con su peso, **no** `h4`.
- El estilo trae tamaño, interlineado (ratio, no px: `round(line_px / size, 2)`), peso,
  `letter_spacing` y `transform`. La familia sale del rol de fuente.
- Un bloque que solo declara peso conserva ese peso y no toma el estilo entero.
- Los tamaños de icono (`font-size` dentro de `svg` o de un icono) **no** son tipografía.

**Espaciado:** decídelo al principio, no al final. Separa:

- separación entre bloques de página y del título al contenido: `space.xl`;
- ritmo entre campos de formulario: `space.md`;
- márgenes laterales de página: `layout.page_gutter`;
- interior de un componente: una propiedad del componente (`padding`, `gap`) cuyo default
  es un alias a `space.*`.

## 3. Colores: por rol, no por cercanía

`scales.md` agrupa los colores en familias por distancia perceptual (ΔE76 < 5) y cuenta sus
roles: texto, fondo, borde, icono, sombra. Es solo el punto de partida. **El token se elige
por el propósito del uso** (propiedad + contexto), y un mismo hex puede ir a tokens
distintos.

| Uso | Token |
|---|---|
| Lienzo de la página (body, home, listados) | `background` |
| Cards y todo lo que flota (modales, popovers, menús, hojas, dropdowns) | `surface` |
| Bandas y rellenos suaves | `surface_alt` |
| Texto normal | `text` |
| Texto secundario, placeholders | `text_muted` |
| Texto deshabilitado, iconos decorativos | `text_disabled` |
| Relleno o línea de un control deshabilitado | `disabled` |
| Separadores, bordes de card, skeletons | `border` |
| Bordes de controles de formulario (≥ 3:1), thumb del scrollbar, spinner | `border_strong` |
| Texto sobre un relleno de color | `on_<color>` |
| Un color usado como texto sobre la página (errores, importes) | `primary_text`, `success_text`, `warning_text`, `danger_text` (≥ 4.5:1; no hay `secondary_text` ni `info_text`) |
| Texto e iconos sobre fotos | `on_overlay` |
| Velo detrás de un modal | `overlay` |
| Marca, selección, foco, progreso | `primary` (par seleccionado/no seleccionado: primary/secondary) |
| Estados | `success`, `warning`, `danger`, `info`, con `on_*`, `*_subtle` y `*_text` (info no tiene `_text`) |
| Estrellas | `rating` |
| Favorito | `favorite` |

Reglas de mapeo que funcionaron:

- **Negro o casi negro:** en texto es `text`; en fondo es `inverse_surface`; en borde es
  `border_strong`.
- **Blanco:** en fondo o borde es `surface` (o `background` si es el lienzo). En texto se
  **revisa a mano**: puede ser `on_primary`, `on_*`, o texto sobre una foto.
- **Gris claro:** en fondo es `surface_alt`; en borde es `border`; en texto es
  `text_disabled`.
- **Amarillo:** `rating` si el contexto es una estrella o una reseña; si no, `warning`.
- **Azul:** en un enlace es texto con el color primario legible (`primary_text`); en lo
  demás, `primary` o `info`, según el propósito.
- **`rgba(0,0,0,a)`:** como fondo con a ≥ 0.3 es `overlay`; con menos, es un tinte de
  hover o pressed y sale del estado del componente.
- **Sombras:** su color es parte del token de sombra, no un color.
- **Translucidez:** un color es opaco salvo que su nodo diga `alpha: true`.
- **Colores derivados:** `*_pressed`, `*_subtle`, `on_*`, `*_text` se declaran con
  `derive` en el schema y llegan calculados. El componente no usa `darken` ni `mix`.
- **Un color que no es del tema** (el de un negocio, que viene de la API) pasa por las
  funciones de color del engine (`@orderingco/ordering-theme-engine/colors`: `onColor`,
  `legible`, `shade`, `mix`).

**Segunda pasada:** cuando todo está mapeado, une los tokens que comparten propósito. En el
marketplace se quitaron `text_heading`, `divider`, `link`, `focus`, `surface_raised`,
`body_sm`, `overline` y `opacity.scrim`, y quedaron 39 colores. Menos tokens es un editor
más usable y un tema más coherente.

## 4. Identidad: lo que sigue al nivel global y lo que queda fijo

`shadow_style.size`, `border_style.radius` y `border_style.width/style` cambian a la vez
todo lo que los **sigue** (`follows` en el schema; el helper aplica el nivel global).

Siguen a la Identidad las cajas: cards, botones, inputs y modales.

Quedan **fijos**. En sombras y bordes, el helper recibe `{ fixed: true }`; los radios
`none` y `full` conservan su valor solos, porque la vista `identity` del engine no los
mueve:

- los estados (hover, focus, pressed) mantienen su propio nivel;
- las sombras de bloques con fondo transparente;
- los radios `none` y `full`;
- los bordes de un solo lado (separadores, subrayados);
- los outlines;
- las cajas de checkbox y radio;
- las formas redondas;
- los bordes revisados a mano (chips, cintas).

Regístralo: un TSV `kind, helper, level, component, archivo:línea, selector` deja
auditable qué sigue y qué no.

## 5. Excepciones

Una excepción es un literal que se queda a propósito. Va en `theme-exceptions.json` con su
motivo (formato en `templates/`); el guard las permite. Categorías habituales:

- no es un color (`transparent`, `currentColor`, `inherit`);
- un fallback que nunca se ve: se borra en vez de registrarse;
- marca de un tercero (botones de Google, Apple, Facebook, PayPal; campos de Stripe);
- texto o velo sobre una foto, si no encaja en `on_overlay` u `overlay`;
- ilustraciones SVG y keyframes;
- UI interna que el cliente final no ve (modo agente, call center);
- medidas de layout que no son de diseño (`100%`, `calc` con porcentajes, anchos de
  columna);
- valores fuera de escala que el usuario decidió mantener.

Un valor fuera de escala **sin** decisión no es una excepción: es una pregunta pendiente.

## 6. Cambios visibles

Cada valor que se mueve a un nivel distinto del suyo (padding de 10 a 12, alto de 45 a 48)
se apunta en `docs/theme/tokens.md` (antes → después, dónde) y lo aprueba el usuario. Los
defaults del schema reproducen el aspecto actual salvo esos cambios.
