# Agrupar por función y decidir variantes

## 1. Una función, un componente

Todo lo que cumple la misma función usa **un** componente del tema y **una**
implementación de código, una primitiva. `components.md` muestra los candidatos por
función: botones (`styled.button` sueltos y overrides de `styled(Button)`), cards, cajas
de aviso, separadores, chips, inputs, etc. Confirma cada grupo leyendo el código.

Agrupaciones que salieron en el marketplace y que se repiten en casi cualquier frontend:

| Muchas implementaciones de… | Se vuelven |
|---|---|
| Botones hechos a mano, `<div onClick>`, links con pinta de botón | `button` (con `colors` × `appearances`, `sizes`, `shapes.icon`), más `IconButton` |
| Filtros, píldoras, categorías seleccionables | `chip` (con `selected`) |
| Listas de opciones con radio o check | `choice` |
| Contenedores con fondo, borde, radio y sombra | `card`, y las cards concretas lo heredan (`inherits: card`) |
| Líneas `<hr>`, `border-bottom` de filas | `divider` (item, section, band, vertical) |
| Cajas de aviso de colores | `alert` (info, success, warning, danger) |
| Corazones o estrellas repetidos | `favorite` o `rating` |
| Fotos de perfil en 5 tamaños | `avatar` con `sizes` |
| Chevrons distintos | una primitiva `Chevron` (disclose, list, navigate) |
| Títulos de sección con márgenes propios | `section_header` (dueño de los h1 y h2 de página) |
| Barras pegadas abajo | un componente (`bottom_nav` o similar) |
| Dropdowns y popovers de menú | `menu` |
| Inputs primario, secundario y de grupo | `input`, con `field` para etiqueta, ayuda y error |
| Modales, hojas, side panels | `modal` (sizes) y una hoja inferior en teléfono |
| Spinners y skeletons de cada pantalla | `spinner` (sizes); skeletons con color global |

## 2. Escalera de reutilización

Para cada sitio que pinta algo, baja por esta lista y para en el primer paso que encaje:

1. **El componente ya tiene la propiedad:** se lee esa propiedad.
2. **Una primitiva compartida ya lo hace:** se usa la primitiva, sin una segunda versión
   con su propio CSS.
3. **Un token directo:** pegamento de layout que debe moverse con la escala global y que
   nadie querrá cambiar por separado (gap entre bloques de página, ritmo de formulario,
   color de un título plano).
4. **Una propiedad nueva en un componente existente:** cuando es una parte visual
   distinta que un autor de tema podría querer distinta del token. Su default es un alias
   al token (`{space.sm}`, `{color.surface}`).
5. **Un componente nuevo:** cuando es una pieza reutilizable con aspecto propio que ningún
   componente cubre.

Nunca un token nuevo.

## 3. Los ejes de un componente

Detalle en schema-format.md §5 y §11.

| Parte | Qué es | Estados |
|---|---|---|
| `base` | Propiedades del componente | no |
| `variants` | Versiones con distinto aspecto o estructura (tabs: `pill`, `underline`, `plain`) | sí (`hover`, `pressed`, `focus`, `disabled`…) |
| `appearances` | Tratamientos de un mismo color (`solid`, `outline`, `tonal`, `ghost`) | sí |
| `colors` | Roles de color (`primary`, `secondary`, `danger`…) con `main`, `on`, `ink`, `subtle`, `pressed`; se cruzan con `appearances` vía `matrix` | no |
| `sizes` | Escalones de tamaño (`sm`, `md`, `lg`): alto, padding, texto, icono | no |
| `shapes` | Formas (`icon`: cuadrado o redondo) | no |
| `slots` | Otro componente contenido, con sus ejes (`business_card.slots.cta → button md solid secondary`) | — |
| `scope` | Lo que cambia en otros componentes dentro de este (`header.scope.button`) | — |
| `inherits` | Toma lo que el tema pone en el padre para las propiedades que declara | — |
| `platforms.<p>` | Default distinto de una propiedad de `base` en una plataforma (`mobile_web`) | — |

Preferencias:

- un **slot** antes que reestilizar un componente contenido;
- **inherits** antes que copiar las propiedades del padre;
- **scope** antes que overrides por sitio;
- **platforms.mobile_web** antes que propiedades `*_mobile`.

Dos componentes que deben verse igual usan el mismo token en sus defaults; no leen uno las
propiedades del otro.

## 4. ¿Variante o no?

Una variante **se queda** si cumple al menos una de estas condiciones:

- cambia la **estructura** (otro layout, otras piezas), no solo un valor;
- difiere en **dos o más** propiedades visuales fuera de tolerancia (`unificacion.md` §2)
  y se usa en más de un sitio;
- un autor de tema razonable querría cambiarla por separado (el subrayado de las tabs y
  la píldora);
- el usuario dijo que se mantenga.

Se **une** con su vecina cuando:

- las diferencias caen dentro de la tolerancia de la escala: radio 8 frente a 10, padding
  12 frente a 14;
- solo difiere en un valor que ya da un eje (otro tamaño es `sizes`, otro color es
  `colors`);
- se usa en un único sitio y la diferencia no tiene propósito;
- es un layout de un cliente concreto.

Al unir, apunta el cambio visible (antes → después, dónde) para la aprobación.

Una variante estructural que el código ya tiene y el editor ofrece como opción es una
propiedad `$type: layout` con `options` (por ejemplo, `logo_position: left|center|right`).
No inventes layouts que el código no implementa.

## 5. Propiedades de un componente

- **Nombres** en `snake_case`, por la parte que pintan: `background`, `color`, `border`,
  `radius`, `shadow`, `padding`, `gap`, `title`, `name_color`, `logo_size`, `icon_size`,
  `animated`, `speed`.
- **Defaults** como alias a un token siempre que se pueda. Un literal solo cuando el valor
  es propio del componente (una altura de cabecera con su `min`/`max`).
- **`follows`** en radio, sombra y borde de las cajas que siguen a la Identidad. Nunca en
  estados.
- **`derive`** en estados (hover que sube un escalón de sombra, pressed que oscurece el
  fondo), en vez de un valor fijo.
- **Orden en el editor:** caja → disposición → textos (cada color después de su texto) →
  tamaños → animación (`schema.md` §3).
- **Cada propiedad tiene un lector** en el código y un test que cambia su valor y ve el
  efecto.

## 6. Trampas al unificar

- El CSS de un contenedor dirigido a `button` le gana a la primitiva: hay que quitar el
  override, no reforzarlo.
- `styled(Button)` con 20 líneas de colores y hover se reemplaza por la primitiva con sus
  props (`appearance`, `color`, `size`) o por otro componente (`Chip`).
- Un contenedor que solo hace layout conserva el layout (gap, grid) y no pinta la caja de
  sus hijos.
- Los iconos-botón son cuadrados (`shapes.icon`) y con nombre accesible (`aria-label`
  traducido).
- La altura sale **solo** de `sizes`: un borde de 1 px no puede convertir 32 en 34
  (`box-sizing: border-box`).
- El texto de un botón va en una línea; vigila el `ellipsis` en footers flexibles.
