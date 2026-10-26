# Escribir el product schema

La fuente de verdad del formato son las docs del engine en la versión fijada:
`docs/creating-a-schema.md` (CaS) y `docs/schema-format.md` (SF), en
`node_modules/@orderingco/ordering-theme-engine/docs/` o en `docs/` del repo del engine
(Finitless-com/ordering-theme-engine). Este archivo resume lo que más se usa
y no las sustituye. Ante una duda, abre la sección citada.

Ejemplos:

- el esquema de referencia con cada forma del formato:
  `meta/reference/reference-1.0.0.json` del engine;
- un schema real completo: `product.schema.json` de Finitless-com/website-marketplace-theming
  (75 componentes, 197 claves de visibilidad, 39 imágenes, 26 páginas). Léelo con `jq` o
  `node`; tiene 48 000 líneas.

## 1. Esqueleto

```json
{
  "$schema": "urn:ordering:theme-schema:1",
  "product_type": "<de la lista de SF §1>",
  "version": "1.0.0",
  "format": 2,
  "platforms": ["mobile_web"],
  "editor": { "section_groups": [], "sections": [], "parts": [], "labels": {}, "props": {} },
  "tokens": { "…": "las escalas obligatorias de §2; vacío no valida" },
  "components": {},
  "visibility": { "$type": "boolean" },
  "images": { "$type": "image" },
  "pages": {},
  "site": {}
}
```

- **`$schema`** es exactamente esa URN. No es una URL, así que editores como VS Code no
  autocompletan: ignoran una asociación de archivo mientras `$schema` tiene la URN. Solo
  sirve en editores que priorizan la asociación con `meta/theme-schema-1.json`. La
  referencia es `ordering-theme validate` (CaS §3).
- **Nodos y grupos:** un nodo tiene `$value` y puede tener `$type`, `$description`,
  `$deprecated` y `$extensions`; un grupo es cualquier otro objeto. El `$type` en el grupo
  vale para sus hojas.
- **Nombres:** `^[a-z0-9_]+$`. Las claves de `visibility` e `images` admiten puntos, hasta
  8 segmentos.
- **Alias:** `{grupo.nombre}` (`{color.primary}`, `{space.md}`). Solo en componentes,
  páginas y `site`, nunca en `tokens`.
- **`$extensions["co.ordering"]`** es lo que lee el engine: `scale`, `options`,
  `inherits`, `follows`, `derive`, `matrix`, `platforms`, `formats`, `hints`,
  `max_length`, `pattern`. **`$extensions["co.ordering.editor"]`** es lo que lee el editor
  (§3).
- **Tipos de propiedad** (SF §3): `color`, `role`, `dimension` (con `scale`: `space`,
  `radius`, `size` o `icon`), `number`, `duration`, `opacity`, `typography`, `fontRole`,
  `border`, `shadow`, `image`, `boolean`, `option`/`layout` (con `options`), `sections`,
  `slot`, `string`, `html` e `id`. Una `dimension` sin `scale` en una propiedad no es
  válida: usa `number`.

## 2. Tokens

Copia las escalas completas de CaS §8 (font, radius, size, icon, space, layout, motion,
border, shadow) y pon los valores aprobados en la fase 3. Las reglas:

- Cada token con el `$type` exacto del catálogo, y en `font` las 10 `options` en el orden
  del catálogo.
- Valores literales y sin unidades: px en web.
- Solo los colores, tipografías y opacidades que el código lee; al menos uno de cada.
- Los grupos de Identidad (`shadow_style`, `border_style`, `motion_style`, `focus_ring`,
  `scrollbar`) se añaden si el editor los ofrece, y las propiedades que los siguen llevan
  `follows`.
- **Declara tus propias reglas:**
  - `derive` en los colores derivados (`shade`, `mix`, `legible`, `same`, con `when`);
  - `follows` en las propiedades;
  - `derive`/`matrix` en los componentes.

  Si una categoría no declara ninguna regla, el engine aplica las suyas heredadas y añade
  colores que tu schema no tiene (SF §11.5).
- Los colores derivados que el autor no debe tocar llevan `hidden: true` en el editor.
- Un color translúcido lleva `alpha: true` en el editor (`alpha_required`).

## 3. Metadatos del editor (SF §12)

Por nodo, en `co.ordering.editor`:

- **`label`:** `{ "key": "BUILDER_THEME_…", "default": "English text" }`, y opcionalmente
  `info` con la misma forma. Las claves siguen un patrón por tipo:
  `BUILDER_THEME_COMPONENT_<C>`, `BUILDER_THEME_PROP_<P>`, `BUILDER_THEME_VIS_<K>`,
  `BUILDER_THEME_PAGE_<P>`, `BUILDER_THEME_COLOR_<C>`, `BUILDER_THEME_OPTION_<O>`,
  `BUILDER_THEME_GROUP_<G>`. Las etiquetas compartidas de propiedades van en
  `editor.props`. **Lista cada clave nueva con su texto inglés para el Dashboard**, que
  traduce desde su catálogo.
- **`group` y `order`:** dentro de un componente, `order` sigue la secuencia caja (fondo,
  borde, radio, sombra, padding) → disposición → textos (cada color justo después de su
  texto) → tamaños → animación. Si el componente tiene tarjetas (`groups`), **todo** nodo
  lleva `order`, y los `paths` de cada tarjeta van en orden creciente.
- **`control`:** `color`, `level` (escalas), `range`, `select`, `segmented`, `toggle`,
  `border`, `shadow`, `typography`, `font`, `image`, `sortable_sections`, `text`, `html`,
  `matrix`, `cards` o `scope`.
- **`min`, `max`, `step` y `unit`** (`px`, `ms`, `%`, `x`) en todo número. Salen de lo que
  la web aguanta, y el default debe caer dentro. **Atan los valores:** un default fuera de
  rango es `default_out_of_range` y un valor guardado fuera de rango se recorta al leerlo.
- **Otras claves:** `visible_if`/`disabled_if` (`{ path, op, value }`), `hidden`,
  `readonly`, `related` (claves relacionadas que el editor enlaza) y `custom` (tipografía
  propia en un control `level`).
- **Escalas:** `ordered` (`ascending`/`descending`) en la tarjeta del editor que lista los
  niveles (`editor.sections[].groups[]`), para que el editor exija que sean monótonos.

Bloque `editor` de la raíz, con la estructura que funcionó en el marketplace:

| `section_groups` | `sections` (key → parent, layout) |
|---|---|
| `identity` | `colors`, `typography`, `border`, `shadows`, `sizes`, `layout`, `motion` → identity, cards |
| `advanced` | `components` → advanced, list (grupos: controles y partes del sitio) |
| `website` | `site` (`store: "site"`), `pages` (list), `visibility` (cards, un grupo por zona), `images` (cards) → website |

Una sección que restablece algo lleva `reset` con los paths, por ejemplo
`["visibility", "platforms.{platform}.visibility"]`. `editor.parts` da nombre a las
pestañas de las partes de un componente (`base`, `colors`, `variants`, `sizes`, `shapes`,
`slots`, `scope`, `platforms`).

Las reglas del editor que más fallan (§12.3), con su código:

| Código | Causa |
|---|---|
| `in_no_editor_group`, `in_several_editor_groups` | una clave de visibilidad no está en un solo grupo |
| `order_missing`, `order_repeated`, `order_decreasing`, `order_not_consecutive` | `order` incompleto, repetido o desordenado |
| `group_mismatch` | el `group` del nodo no coincide con la tarjeta o el grupo que lo lista |
| `in_no_card` | un nodo de `base` declara un `group` que ninguna tarjeta lista |
| `limits_required`, `min_over_max`, `default_out_of_range` | falta `min`/`max` o el default queda fuera |
| `alpha_required` | default translúcido sin `alpha: true` |
| `section_groups_required`, `_mismatch`, `alone_in_group` | grupos de secciones de una página |
| `custom_required` | una tipografía de componente con control `level` sin `custom: true` |
| `font_unlisted`, `font_listed_twice` | `options` del control de fuente |

## 4. Componentes, visibilidad, páginas, imágenes y `site`

- **Componentes:** `componentes.md` y SF §5, §11. Un ejemplo con tarjetas, orden, toggle,
  slots, scope y plataformas es el `header` del esquema de referencia; uno con
  `colors` × `appearances` y `matrix` es su `button`.
- **Visibilidad, páginas, imágenes y `site`:** `paginas-y-visibilidad.md`, y SF §6–§9.
- **Principio (CaS §2, al final):** todo lo que el código lee del tema está en el schema
  con su default; un tema guarda solo lo que cambia.

## 5. CLI y scripts del repo

```jsonc
// package.json
"theme:validate": "ordering-theme validate product.schema.json",
"theme:compile":  "ordering-theme compile product.schema.json --out src/theme.compiled.json",
"theme:verify":   "pnpm theme:compile --check",
"theme:publish":  "ordering-theme publish product.schema.json",
"theme:publish:dry": "ordering-theme publish product.schema.json --dry-run"
```

- **Mapa v1:** si el producto tiene temas v1, añade `--v1-map <mapa.json>` a validate,
  compile y publish.
- **`compile`:** escribe la forma compilada (defaults, reglas, unidades CSS,
  `product_type`, `version`) que leen `complete()` y `toCss()`. Se versiona, y
  `theme:verify` falla si quedó vieja.
- **Otros comandos:** `defaults` (el tema vacío resuelto) y `rules` (reglas para un
  cliente sin schema). La lista completa está en el README del engine, sección "The
  `ordering-theme` CLI".
- **Publicar:**
  - `publish` necesita `THEME_PUBLISH_API` y `THEME_PUBLISH_KEY`;
  - sin ellas se salta con salida 0, salvo con `--required`;
  - en CI, `--dry-run --required` en cada PR (si no, el paso pasa en silencio sin el
    secreto) y `--required` en el deploy, cada entorno con su API y su clave;
  - publicar de verdad solo con autorización del usuario.
- **Salida:** 0 ok, 1 schema inválido o archivo viejo, 2 uso incorrecto.

## 6. Versiones (CaS §6, SF §13–15)

- Un schema nuevo empieza en **`1.0.0`**, sin bloques `migrate` ni `upgrade`.
- Una versión **publicada es inmutable** (409 `schema.version_exists`). Se sube solo
  cuando la actual ya está en producción.
- **Patch:** textos y metadatos del editor que no validan nada.
- **Minor:**
  - añadir una clave, un componente, una opción, una sección o `alpha`;
  - cambiar defaults;
  - ajustar `max_length`, `pattern` o `min`/`max`;
  - cambiar el `hideable` o el `group` de una sección;
  - marcar `$deprecated`, o quitar una clave que la versión anterior ya marcó así;
  - renombrar o mover dentro de su sección vía `co.ordering.migrate`.
- **Nueva base (major):** lo que un minor prohíbe:
  - quitar una clave no deprecada;
  - cambiar el tipo o la escala;
  - quitar una opción, `alpha` o `custom`;
  - quitar una migración.

  Lleva `co.ordering.upgrade`, que dice adónde va cada clave anterior (SF §13–15).
- **`--development`:** convierte los rechazos de versión en avisos mientras se itera.
- **Mapa v1:** solo para productos con temas v1. Es un JSON `ruta v2 → ruta v1` por
  sección, fuera del schema vivo, con el que api-core migra los temas viejos. Varias
  claves v2 del mismo tipo pueden compartir una ruta v1. Una clave sin entrada toma su
  default al migrar.

## 7. Al añadir algo más adelante

1. Añade el nodo con `$type`, default (alias a token), `scale`/`follows`/`alpha`.
2. Añade sus metadatos del editor.
3. Añade su entrada en el mapa v1, si tiene equivalente.
4. Sube la versión, si la actual ya está publicada.
5. Corre `theme:compile`, `theme:validate` y `theme:verify`.
6. Conecta su lector, mediante un helper, y su test.
7. Actualiza `docs/THEMING.md` si cambia algo de lo que describe.
