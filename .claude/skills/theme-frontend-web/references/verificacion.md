# Verificación

## 1. Checks automáticos (CI)

| Check | Qué falla |
|---|---|
| `theme:validate` | el schema contra el meta-schema, las reglas de api-core y las del editor |
| `theme:verify` | `theme.compiled.json` no es lo que compila el schema actual |
| `guard.mjs --allow theme-exceptions.json [--baseline …]` | literales de diseño nuevos en el código |
| `keys.mjs` | claves del schema sin lector (confirma a mano los aciertos dudosos) |
| `theme:publish:dry --required` | lo que api-core rechazaría al publicar; falla sin el secreto |
| lint, tests, i18n | lo de siempre |

El guard en CI:

- **Mientras se convierte:** se corre con la baseline, que solo baja. Cuando un lote deja
  un archivo por debajo, se regenera con `--write-baseline` en ese mismo commit.
- **Al terminar:** se corre sin baseline, solo con las excepciones registradas.

## 2. Tests que hay que escribir

- **Sincronía de defaults:**
  - `theme.compiled.json` es lo que compila el schema;
  - `complete(compiled, null)` tiene cada sección que la app lee, sin alias sin resolver;
  - el botón se pinta en cada `color` × `appearance`.
- **Robustez:** un tema con valores rotos (alias sin resolver, un `;`, números fuera de
  rango, colores inválidos) pinta los defaults y no rompe el CSS.
- **Helpers:** cada helper con su tema por defecto y con un tema cambiado. Para eso, una
  utilidad `themeWithValues(mutate)` que parte de los defaults, aplica el cambio y
  reconstruye el tema, para que la vista CSS sea coherente.
- **Primitivas:** cambiar cada propiedad de su componente cambia el CSS inyectado
  (radio, borde, sombra, padding, tipografía, estados).
- **Por componente:** cada propiedad que añadiste tiene un test que la cambia y ve el
  efecto (`<componente>Theme.test`).
- **Visibilidad:** cada clave oculta lo que dice y nada más; la override de teléfono
  aplica por debajo del breakpoint.
- **Secciones:** las páginas siguen el orden del tema dentro de cada grupo, ocultan lo
  ocultable y mantienen fijo lo que es `group: null`.
- **Contraste:** con los defaults, `*_text` ≥ 4.5:1 sobre `background`/`surface`,
  `border_strong` ≥ 3:1 y el texto deshabilitado ≥ 3:1.

## 3. Temas extremos

Prueba la app con temas que tensan el schema. Aplica cada tema solo dentro de un iframe o
de una ventana de prueba; nunca cambies datos reales:

| Tema | Qué revela |
|---|---|
| cuadrado: radio `none`, sin sombras | dependencias de radios o sombras fijos |
| píldora: radio `full`, mayúsculas, serif | textos que desbordan, mayúsculas que se contagian (un botón de dirección en el header) |
| oscuro: fondos oscuros, texto claro | colores fijos, iconos invisibles, veils |
| primario claro (`#FFD600`) | `on_primary` y `primary_text` ilegibles |
| primario oscuro | hovers y estados sin contraste |
| escalas grandes: space y tamaños al máximo | solapes, scroll horizontal |

Mide en cada pantalla, en escritorio y a 390 px:

- el contraste en reposo y en hover;
- el anillo de foco visible y sin recortar;
- los radios;
- que no haya scroll horizontal;
- que no haya solapes.

Usa el navegador (skill `claude-in-chrome` o `run`) y guarda capturas de lo que falle.
Las combinaciones que el schema no puede expresar se convierten en propuestas, como hover
separado de pressed o texto de botón por tamaño, y se presentan al usuario.

## 4. Revisión visual con los defaults

Con el tema por defecto, la app debe verse como antes salvo los cambios visibles
aprobados. Compara pantalla por pantalla, en escritorio y teléfono, con sesión y sin ella,
con capturas antes y después. Revisa también:

- un solo `h1` por página y niveles de encabezado sin saltos;
- foco visible en todo lo que se puede pulsar;
- ningún hover ni cursor de mano en lo que no se puede pulsar;
- iconos-botón con nombre accesible.

## 5. Informe final

En `docs/theme/informe.md`:

- **Métricas antes y después:**
  - literales por categoría;
  - colores distintos frente a tokens de color;
  - componentes por función frente a componentes del tema;
  - `styled.button` sueltos;
  - claves del schema por bloque;
  - tests nuevos.
- **Excepciones**, con su motivo, y **cambios visibles**, con su aprobación.
- **Claves de editor nuevas** para el Dashboard.
- **Lo que no se verificó** y por qué.
