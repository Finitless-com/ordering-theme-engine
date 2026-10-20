# Formato del esquema de temas (nodos, formato 1)

Este documento es la especificación del formato con que cada producto escribe su
**esquema de temas**: el archivo que describe todo lo que el producto lee de un tema v2
(tokens, componentes, visibilidad, imágenes y páginas) y los ajustes de su sitio
(`site`). El esquema es del producto; vive en su repo, junto al código que lo lee, y se
publica en api-core (ver [creating-a-schema.md](creating-a-schema.md)).

Las reglas de este documento son las que aplican el meta-esquema
(`meta/theme-schema-1.json`), `validate()` de este paquete y api-core al publicar.
Cuando dicen "se rechaza con `<código>`", ese es el código que responden los tres.

Índice:

1. [Raíz](#1-raíz)
2. [Nodos y grupos](#2-nodos-y-grupos)
3. [Tipos de propiedad](#3-tipos-de-propiedad)
4. [Tokens](#4-tokens)
5. [Componentes](#5-componentes)
6. [Visibilidad](#6-visibilidad)
7. [Imágenes](#7-imágenes)
8. [Páginas](#8-páginas)
9. [Ajustes del sitio (`site`)](#9-ajustes-del-sitio-site)
10. [`$extensions["co.ordering"]`: lo que lee el motor](#10-extensionscoordering-lo-que-lee-el-motor)
11. [Derivaciones, Identidad y matrices](#11-derivaciones-identidad-y-matrices)
12. [`$extensions["co.ordering.editor"]` y `editor`: lo que lee el editor](#12-extensionscoorderingeditor-y-editor-lo-que-lee-el-editor)
13. [Versiones y compatibilidad](#13-versiones-y-compatibilidad)
14. [Migraciones (`co.ordering.migrate`)](#14-migraciones-coorderingmigrate)
15. [Errores](#15-errores)
16. [Desvíos de DTCG](#16-desvíos-de-dtcg)

---

## 1. Raíz

```jsonc
{
  "$schema": "urn:ordering:theme-schema:1", // obligatorio y exactamente este valor
  "product_type": "call_center",            // un tipo registrado en api-core
  "version": "26.0.0",                      // semver base.minor.patch
  "format": 2,                              // el formato del tema que se sirve; siempre 2
  "platforms": ["mobile_web"],              // opcional: las plataformas con valores propios
  "$description": "…",                      // opcional
  "$extensions": { "co.ordering.migrate": [ … ] }, // opcional (§14)
  "editor": { … },                          // opcional (§12)
  "tokens": { … },                          // §4
  "components": { … },                      // §5
  "visibility": { … },                      // §6
  "images": { … },                          // §7
  "pages": { … },                           // §8
  "site": { … }                             // opcional (§9)
}
```

- **Obligatorias:** `$schema`, `product_type`, `version`, `format`, `tokens`,
  `components`, `visibility`, `images` y `pages`. `components`, `visibility`, `images`
  y `pages` pueden ser `{}`; `tokens` tiene grupos obligatorios (§4). No se admite
  ninguna otra clave en la raíz.
- **`$schema`** es como api-core y el motor reconocen el formato de nodos. Con otro valor
  la respuesta es `schema.format`, sin más comprobaciones. No puede ser una URL ni una
  ruta a un archivo; ver la nota sobre el autocompletado en
  [creating-a-schema.md](creating-a-schema.md#3-el-meta-esquema-y-el-editor).
- **`product_type`**: uno de los tipos del catálogo del Builder (`marketplace_app`,
  `franchise_app`, `marketplace_website`, `franchise_website`, `store_app`,
  `driver_app`, `kiosk_app`, `call_center`, `dashboard`). Si no, `product_type.invalid`.
- **`version`**: el meta-esquema acepta de una a tres partes (`26`, `26.0`, `26.0.0`),
  pero publicar exige tres (`version.semver`). La primera parte es la **base**: la
  versión mayor del producto (§13).
- **`format`**: siempre `2` (`format.invalid`).
- **`platforms`**: una lista de nombres (`^[a-z0-9_]+$`, sin repetir). Solo las
  plataformas de esta lista pueden tener valores propios en un componente (§5) o en una
  clave de visibilidad (§6).

## 2. Nodos y grupos

El esquema es un árbol de **grupos** cuyas hojas son **nodos**, con la forma de un token
[DTCG 2025.10](https://www.designtokens.org/).

- **Nodo:** un objeto con `$value`, que es el **default**. Puede llevar además `$type`,
  `$description`, `$deprecated` (booleano o texto con el motivo) y `$extensions`, y
  nada más.
- **Grupo:** cualquier otro objeto. Sus claves que no empiezan con `$` son nodos o
  grupos. Puede llevar `$type`, `$description`, `$deprecated` y `$extensions`.
- **Herencia del tipo:** un nodo sin `$type` toma el del grupo más cercano que lo
  declare. Un nodo de componente, página o `site` que no tiene tipo (ni propio ni
  heredado) se rechaza.
- **`$deprecated` en un grupo** depreca todos los nodos que contiene (§13). Un
  `$deprecated` vacío (`false`, `""` o `"0"`) no depreca.
- **Nombres:** `^[a-z0-9_]+$`. Las claves de `visibility` e `images` son las rutas que
  sirve el tema, así que pueden llevar puntos: hasta 8 segmentos
  (`business.header.info`).
- **`$extensions`** acepta claves con espacio de nombres (`^[a-z0-9]+(\.[a-z0-9_-]+)+$`).
  El formato define dos: `co.ordering` (§10, lo lee el motor) y `co.ordering.editor`
  (§12, lo lee el editor). Las de otro espacio de nombres se conservan y nadie las lee.

Ejemplo:

```jsonc
"color": {
  "$type": "color",                         // vale para los nodos del grupo
  "primary": { "$value": "#1E72E3" },
  "primary_pressed": {
    "$value": "#1862C5",
    "$extensions": { "co.ordering": { "derive": { "op": "shade", "from": "{color.primary}", "amount": -0.07 } } }
  }
}
```

### Alias

Un valor puede ser un **alias** a un token: `{grupo.nombre}` (por ejemplo
`{color.primary}`, `{radius.md}`). Cada tipo dice a qué grupo puede apuntar (§3). Los
tokens mismos no admiten alias: sus valores son literales (§4).

## 3. Tipos de propiedad

Una **propiedad** es un nodo de un componente, una página o `site`. Su `$type` (y, para
una `dimension`, su escala) dice qué valores acepta. El motor lo compila a una tupla
`[kind, default, options|null, rules?]` (§10.1), y los códigos de error usan la ruta del
nodo.

| `$type` | `scale` | kind | Valor |
|---|---|---|---|
| `color` | | `color` | hex (`#RGB`, `#RGBA`, `#RRGGBB`, `#RRGGBBAA`), `rgb()`/`rgba()`, `transparent`, `{color.*}` o `null` (sin default propio) |
| `role` | | `role` | un color o un rol del color de un componente: `{main}`, `{on}`, `{ink}`, `{subtle}`, `{pressed}` (§11.4) |
| `dimension` | `radius` | `radius` | solo un alias `{radius.*}` |
| `dimension` | `size`, `icon`, `space` | el de la escala | número de 0 a 9999, o alias a su escala (`{size.md}`) |
| `duration` | | `motion` | número de 0 a 9999 o `{motion.*}` |
| `opacity` | | `opacity` | número de 0 a 1 o `{opacity.*}` |
| `number` | | `number` | número de 0 a 9999 |
| `typography` | | `text` | solo `{typography.*}`; con `custom` (§12), también un estilo propio |
| `fontRole` | | `font_role` | `heading` o `body` |
| `border` | | `border` | `{ width, color, style? }`, `"none"` o `null` |
| `shadow` | | `shadow` | `{shadow.*}`, un objeto de sombra o `null` |
| `image` | | `image` | `""` o una URL `http(s)` de hasta 2048 bytes |
| `boolean` | | `bool` | `true` o `false` (`null` solo en el `footer` de una página, §8) |
| `option` | | `option` | uno de sus `options` |
| `layout` | | `layout` | uno de sus `options`: una variante de estructura que el código ya tiene |
| `sections` | | `sections` | solo en `pages`: la lista de secciones (§8) |
| `slot` | | | solo en `slots` de un componente (§5.3) |
| `string` | | `string` | texto plano de hasta 1000 caracteres (o su `max_length`); si no está vacío, cumple su `pattern` |
| `html` | | `html` | HTML que el cliente pinta tal cual, hasta 200 000 bytes |
| `id` | | `id` | `null`, un entero no negativo o un texto de hasta 255 bytes (un slug) |

Detalles:

- **`dimension` sin escala no es una propiedad**: se rechaza con `<ruta>.type`. Para un
  número suelto usa `number`. (Los tokens sí usan `dimension` sin escala, §4.)
- **`font` es solo de tokens** (`tokens.font`). En una propiedad se rechaza con
  `<ruta>.type`; para elegir la fuente de un componente usa `fontRole`.
- **`options`** (en `co.ordering`, §10) es obligatorio en `option` y `layout`, y
  opcional en un redondeo (`dimension` con `scale: "radius"`), donde limita los niveles
  permitidos: una lista no vacía de nombres de nivel (`["none", "md", "full"]`, sin
  llaves). En cualquier otro tipo se rechaza con `<ruta>.options.invalid`. El default
  tiene que estar entre ellas.
- **Borde:** `width` es un número (0–9999) o `{border.thin}` / `{border.thick}`;
  `color`, un color, un alias de color o un rol (`{main}`…); `style`, `solid`, `dashed`
  o `dotted`. `"none"` es "sin borde" explícito y se sirve como `null`.
- **Sombra (objeto):** `{ x, y, blur, color, opacity, spread?, elevation? }`, con `x`,
  `y` y `spread` entre -128 y 128, `blur` de 0 a 9999, `color` literal, `opacity` de 0
  a 1 y `elevation` de 0 a 24 (la elevación de Android).
- **Tipografía (estilo):** `{ size, line, weight, letter_spacing?, transform? }`, sin
  familia (la pone su rol de fuente). `size` de 0 a 200 (px o pt); `line` es la altura de
  línea **como proporción del tamaño**, sin unidad, de **0.8 a 3** (`1.5` es 21 px con
  14 px); `weight` uno de 100, 200, …, 900 (entero); `letter_spacing` de -20 a 20;
  `transform` `none` o `uppercase`. Una tipografía con `line` en px (`21`) no es válida.
- **Color sin default propio:** un `color` o `role` con `$value: null` es válido: el
  default sale de otro lado (una derivación, la herencia).
- **Reglas de `string`:** `max_length` (entero de 1 a 1000) y `pattern` (§10). Un valor
  vacío cuenta como "sin fijar": ningún `pattern` lo rechaza.

## 4. Tokens

`tokens` es el **vocabulario común**: los nombres son los mismos en todos los productos
y cada esquema pone sus defaults. `{ grupo: { $type?, nombre: nodo } }`.

### 4.1 Catálogo

| Grupo | Nombres | `$type` (y `options`) | En el esquema |
|---|---|---|---|
| `font` | `heading`, `body` | `font`, con `options` = las 10 fuentes del catálogo, en este orden: `archivo`, `inter`, `lato`, `montserrat`, `nunito`, `open_sans`, `playfair_display`, `poppins`, `raleway`, `roboto` | los dos |
| `typography` | títulos (fuente `heading`): `display`, `h1`, `h2`, `h3`, `h4`; texto (fuente `body`): `body_lg`, `body`, `body_sm`, `label`, `caption`, `button`, `overline` | `typography` | al menos uno |
| `color` | `primary`, `on_primary`, `primary_subtle`, `primary_pressed`, `primary_text`, `secondary`, `on_secondary`, `secondary_subtle`, `secondary_pressed`, `background`, `surface`, `surface_alt`, `surface_raised`, `overlay`, `on_overlay`, `inverse_surface`, `on_inverse`, `text_heading`, `text`, `text_muted`, `text_disabled`, `link`, `border`, `border_strong`, `divider`, `focus`, `success`, `on_success`, `success_subtle`, `success_text`, `warning`, `on_warning`, `warning_subtle`, `warning_text`, `danger`, `on_danger`, `danger_subtle`, `danger_text`, `info`, `on_info`, `info_subtle`, `disabled`, `rating`, `favorite` | `color` | al menos uno |
| `opacity` | `disabled`, `pressed`, `scrim` | `opacity` | al menos uno |
| `radius` | `none`, `xs`, `sm`, `md`, `lg`, `xl`, `xxl`, `full` | `dimension` | todos |
| `size` | `sm`, `md`, `lg`, `xl` (alto de los controles) | `dimension` | todos |
| `icon` | `sm`, `md`, `lg` | `dimension` | todos |
| `space` | `none`, `xxs`, `xs`, `sm`, `md`, `lg`, `xl`, `xxl`, `xxxl` | `dimension` | todos |
| `layout` | `page_gutter`; `mode` | `page_gutter`: `dimension`; `mode`: `option` con `options` `["original", "appointments"]` | los dos |
| `motion` | `fast`, `base`, `slow` | `duration` | todos |
| `border` | `thin`, `thick` | `dimension` | todos |
| `shadow` | `none`, `sm`, `md`, `lg`, `xl` | `shadow` | todos |
| `shadow_style` | `style`, `color`, `direction`, `size` | `style`: `option` `["none", "soft", "normal", "strong", "solid"]`; `color`: `color`; `direction`: `option` `["down", "down_right", "down_left", "around"]`; `size`: `option` `["none", "sm", "md", "lg", "xl"]` | opcional |
| `border_style` | `radius`, `width`, `style` | `radius`: `option` con los 8 niveles de `radius`; `width`: `option` `["none", "thin", "thick"]`; `style`: `option` `["solid", "dashed", "dotted"]` | opcional |
| `motion_style` | `easing`, `enabled` | `easing`: `option` `["linear", "ease", "ease_in", "ease_out", "ease_in_out"]`; `enabled`: `boolean` | opcional |
| `focus_ring` | `width`, `offset`, `color` | `width`: `option` `["thin", "thick"]`; `offset`: `option` con los 9 niveles de `space`; `color`: `option` con los colores del propio esquema, en su orden | opcional |
| `scrollbar` | `size`, `radius` | `dimension` (se acepta `number`, el tipo que tenía antes) | opcional |

Reglas:

- **Grupos con nombres propios** (`color`, `typography` y `opacity`): cada esquema elige
  cuáles tiene, entre los del catálogo, y tiene al menos uno de cada grupo
  (`tokens.<grupo>.required`). El esquema solo puede referirse a los que tiene: un alias
  a otro se rechaza con `<ruta>.default.alias`.
- **Escalas** (`font`, `radius`, `size`, `icon`, `space`, `layout`, `motion`, `border` y
  `shadow`): van completas; cada nombre que falta es `tokens.<grupo>.<nombre>.required`.
- **Grupos opcionales** (`shadow_style`, `border_style`, `motion_style`, `focus_ring` y
  `scrollbar`): pueden faltar o tener solo algunos de sus nombres. Son los ajustes
  generales de Identidad (§11.2) y del anillo de foco.
- **Un nombre o un grupo fuera del catálogo** se rechaza con
  `tokens.<grupo>.<nombre>.unknown` o `tokens.<grupo>.unknown`.
- **Tipo y opciones exactos:** cada token declara (propio o heredado del grupo) el
  `$type` de la tabla y, si la tabla dice `options`, exactamente esa lista, en ese
  orden; si la tabla no dice `options`, no lleva ninguna. Si no, `tokens.<grupo>.<nombre>.type`.
- **Valores literales:** un token no admite alias. Colores literales; `dimension` y
  `duration`, números de 0 a 9999; tipografías y sombras con la forma de §3 (`null` es sin
  sombra); `focus_ring.color`, un nombre de color del catálogo. Si no,
  `tokens.<grupo>.<nombre>.invalid`.

### 4.2 Tokens que se pueden acotar (`focus_ring.color`, `focus_ring.offset`)

Son los únicos dos tokens cuyas `options` un esquema puede declarar **solo en parte**:
una lista no vacía, sin repetidos, de opciones de su catálogo (los colores del esquema
para `color`, los niveles de `space` para `offset`). Un tema solo puede elegir entre esas
y el default tiene que estar entre ellas; si no, `token_options.focus_ring.<nombre>.invalid`.
Una lista que no es parte del catálogo se rechaza con `tokens.focus_ring.<nombre>.type`.
Ningún otro token se puede acotar.

```jsonc
"focus_ring": {
  "width":  { "$type": "option", "$value": "thick", "$extensions": { "co.ordering": { "options": ["thin", "thick"] } } },
  "offset": { "$type": "option", "$value": "xxs",   "$extensions": { "co.ordering": { "options": ["none", "xxs", "xs", "sm"] } } },
  "color":  { "$type": "option", "$value": "primary", "$extensions": { "co.ordering": { "options": ["primary", "text", "danger"] } } }
}
```

## 5. Componentes

`components` es `{ componente: grupo }`. Cada componente tiene algunas de estas partes;
cualquier otra se rechaza con `components.<c>.<parte>.unknown`:

| Parte | Forma | Qué es |
|---|---|---|
| `base` | `{ prop: nodo }` | las propiedades del componente |
| `variants`, `appearances` | `{ nombre: { prop: nodo, states?: { estado: { prop: nodo } } } }` | variantes con estados (`hover`, `pressed`, `focus`, `disabled`…) |
| `sizes`, `shapes`, `colors` | `{ nombre: { prop: nodo } }` | ejes sin estados: los estados son de `variants` y `appearances`, y solo ahí se validan |
| `slots` | `{ nombre: nodo slot }` | otros componentes que este contiene (§5.3) |
| `scope` | `{ componente: cambios }` | lo que cambia de otros componentes dentro de este (§5.4) |
| `platforms` | `{ plataforma: { prop: nodo } }` | el default de una propiedad de `base` en una plataforma (§5.2) |

Los nombres de componente, variante, estado y propiedad siguen `^[a-z0-9_]+$`. El
`$extensions["co.ordering"]` del componente puede llevar `inherits` (§5.1) y `matrix`
(§11.4).

### 5.1 Herencia (`inherits`)

`"$extensions": { "co.ordering": { "inherits": "card" } }` en `business_card`: lo que un
tema pone en `card` también vale para las propiedades que `business_card` declara, salvo
lo que el tema ponga en `business_card`. El padre existe, no es el mismo componente y no
hereda de otro; si no, `inherits.<componente>.invalid`.

No es el `$extends` de DTCG: `inherits` no copia al hijo las propiedades del padre que el
hijo no declara.

### 5.2 Plataformas

`components.<c>.platforms.<plataforma>.<prop>` cambia el default de una propiedad de la
`base` en esa plataforma. La plataforma tiene que estar en `platforms` de la raíz
(`components.<c>.platforms.<p>.unknown`), y la propiedad existir en la `base` con el
mismo kind (`components.<c>.platforms.<p>.<prop>.invalid`). Se sirve en
`platforms.<plataforma>.components.<c>.base`.

### 5.3 Slots

```jsonc
"slots": {
  "cta": { "$type": "slot", "$value": { "component": "button", "variant": "primary", "size": "md" } }
}
```

`component` es otro componente del esquema que no tiene `scope` y cuyos slots, si los
tiene, nombran componentes sin slots. Los ejes posibles son `size`, `variant`,
`appearance`, `color` y `shape`, y cada uno es `null` o una clave de la parte
correspondiente del componente (`sizes`, `variants`…). Un eje que el componente no tiene
es `.unknown`; un valor que no está, `.invalid`.

### 5.4 Scope

Un componente que agrupa otros (una tarjeta con botones, un header) puede cambiar sus
defaults dentro de él:

```jsonc
"scope": {
  "button": { "base": { "radius": { "$type": "dimension", "$value": "{radius.full}", "$extensions": { "co.ordering": { "scale": "radius" } } } } },
  "input": {}
}
```

Cada componente del `scope` existe, no es el propio grupo y no tiene `scope`. Cada
propiedad que cambia existe en ese componente (`.unknown`), con el mismo kind y las
mismas `options` (`.invalid`), y su default es válido (`.default.invalid`). `{}` si no
cambia nada.

## 6. Visibilidad

```jsonc
"visibility": {
  "$type": "boolean",
  "header.search": { "$value": true },
  "header.logo": {
    "$value": true,
    "$extensions": { "co.ordering": { "platforms": { "mobile_web": false } } }
  }
}
```

- Cada clave es un nodo booleano: `$value` `true` o `false` (`null` no vale).
- `co.ordering.platforms`: su default en cada plataforma que la muestra distinto. Se
  sirve en `platforms.<plataforma>.visibility.<clave>` y un tema lo cambia ahí; sin él,
  la plataforma sigue al default de la clave. Un mapa no vacío de plataformas declaradas
  en la raíz a booleanos; si no, `visibility.<clave>.platforms.invalid`.

## 7. Imágenes

```jsonc
"images": {
  "$type": "image",
  "logo": {
    "$value": "",
    "$extensions": { "co.ordering": {
      "formats": ["png", "svg", "webp"],
      "hints": { "size": [800, 200], "ratio": "4:1", "alpha": true, "max_kb": 200, "safe": "3:1" }
    } }
  }
}
```

- `$value`: `""` (ninguna) o una URL `http(s)`.
- `formats`: los formatos que acepta, una lista no vacía y sin repetidos de `jpg`, `png`,
  `webp`, `avif`, `ico` y `svg`. Sin `formats` (o con `null`), api-core acepta `jpg`,
  `png`, `webp` y `avif`. SVG e ICO se sirven tal cual se suben; el resto se puede
  redimensionar o convertir. Si no, `images.<clave>.formats.invalid`.
- `hints`: lo que el editor recomienda al subirla. Todos opcionales:
  - `size`: `[ancho, alto]` en px, enteros de 1 a 10 000;
  - `ratio`: la proporción, `"w:h"` (enteros de 1 a 9999);
  - `alpha`: si puede tener transparencia;
  - `max_kb`: su peso máximo en kB (entero, hasta 5000);
  - `safe`: la proporción de la parte central que todo contenedor muestra, recorte el que
    recorte: lo importante va dentro de ella.

  Si no, `images.<clave>.hints.invalid`.

## 8. Páginas

```jsonc
"pages": {
  "home": {
    "footer": { "$type": "boolean", "$value": null },
    "sections": {
      "$type": "sections",
      "$value": [
        { "key": "hero", "visible": true, "hideable": false, "group": null },
        { "key": "featured", "visible": true, "hideable": true, "group": "content" },
        { "key": "recent", "visible": false, "hideable": true, "group": "content" }
      ]
    }
  }
}
```

Cada página tiene exactamente `footer` y `sections` (cualquier otro campo es
`pages.<p>.<campo>.unknown`; si falta uno, `pages.<p>.<campo>.invalid`):

- `footer`: booleano o `null`, que sigue a `visibility.footer`.
- `sections`: la lista de secciones en su orden por defecto. Cada sección lleva `key`
  (única en la página, `.duplicate`), `visible`, `hideable` y `group` (un nombre o
  `null`), y nada más. Una sección que no se puede ocultar (`hideable: false`) tiene que
  ser visible.

El tema guarda las secciones como `[{ key, visible }]` en el orden que eligió. Al
resolverlas, una sección se mueve solo entre las de su mismo `group`; con `group: null`
se queda en su lugar. Una sección que el tema no menciona conserva su lugar y su
visibilidad por defecto; una que no se puede ocultar siempre sale visible.

## 9. Ajustes del sitio (`site`)

`site` describe los ajustes que no son estilo: son **del sitio, no del tema**. Un sitio
tiene varios temas, y activar otro no cambia estos ajustes. Es un árbol de grupos y
nodos, con los mismos tipos de propiedad de §3 (salvo `sections` y `slot`), y cada nodo
declara su `$type`.

```jsonc
"site": {
  "name": { "$type": "string", "$value": "", "$extensions": { "co.ordering": { "max_length": 60 } } },
  "third_party_code": {
    "$type": "html",
    "head": { "$value": "" },
    "body": { "$value": "" }
  }
}
```

- api-core guarda los valores en el sitio, validados contra este bloque, y los sirve en
  `values.site` resueltos sobre los defaults, sea cual sea el tema activo. Un tema no los
  lleva.
- `mode`, `business_slug`, `franchise_slug` y `franchise_id` son el **alcance** del
  sitio: api-core los calcula del sitio (con un negocio, `single_store`; con una
  franquicia, `franchise`; sin ninguno, `marketplace`) y nunca se guardan como ajuste.
  Un producto que los necesite los declara para tener sus tipos y defaults.
- `name` vacío se sirve con el nombre del sitio en el Builder.
- `siteDefaults(compile(schema).schema)` da los defaults del bloque.

## 10. `$extensions["co.ordering"]`: lo que lee el motor

| Clave | Dónde | Qué es |
|---|---|---|
| `scale` | nodo `dimension` | la escala de sus niveles: `radius`, `size`, `icon` o `space` |
| `options` | `option`, `layout`, redondeo, token | las opciones (§3, §4); lista no vacía |
| `inherits` | componente | su padre (§5.1) |
| `follows` | propiedad de componente | `shadow`, `radius` o `border`: sigue a Identidad (§11.2) |
| `derive` | color de `tokens.color`, propiedad de una variante | cómo se deriva (§11.1, §11.3) |
| `matrix` | componente | `{ into, roles }` (§11.4) |
| `platforms` | clave de `visibility` | su default por plataforma (§6) |
| `formats`, `hints` | imagen | §7 |
| `max_length`, `pattern` | `string` | su largo máximo en caracteres (1–1000) y una expresión regular que ECMAScript y PCRE leen igual |

No admite otras claves.

### 10.1 La tupla

El motor compila cada propiedad a `[kind, default, options|null, rules?]`:

- `kind` sale de `$type` y `scale` (§3);
- `options`, de `co.ordering.options` (o `null` si no hay pero sí hay reglas);
- `rules`, un mapa con lo que además restringe el valor: `custom` (de
  `co.ordering.editor.custom`, en una `typography`), `max_length` y `pattern` (en un
  `string`). Una regla en un tipo que no es el suyo se rechaza con `<ruta>.rules.invalid`:
  `custom` solo en `typography` y con `true`; `max_length` y `pattern` solo en `string`,
  `pattern` no vacío y una expresión válida.

`compile(schema)` de este paquete devuelve esas tuplas, y es lo que leen `check()` y
`resolve()`.

## 11. Derivaciones, Identidad y matrices

Las reglas que hacen que un valor siga a otro las declara el esquema. El motor tiene
reglas propias para un esquema que no declara ninguna (§11.5); en cuanto el esquema
declara una regla de una categoría, las del motor de esa categoría dejan de aplicarse.

### 11.1 Colores derivados

En un color de `tokens.color`, `co.ordering.derive` dice cómo se deriva cuando el tema no
lo fija:

| `op` | Campos | Resultado |
|---|---|---|
| `same` | `from` | el mismo color |
| `shade` | `from`, `amount` (-1 a 1) | más claro en HSL (más oscuro si es negativo) |
| `mix` | `from`, `over`, `amount` (-1 a 1) | `amount` del color sobre `over` |
| `legible` | `from`, `over`, `contrast?` (1–21, por defecto 4.5), `step?` (0–1, por defecto 0.01) | se mueve hacia negro o blanco hasta tener `contrast` sobre `over` |

`from` y `over` son alias a colores del esquema (`{color.primary}`). `when` dice cuándo
se deriva:

- `changed` (por defecto): si el color falta o si alguno de sus colores de origen ya no
  está en su default. Mientras nada cambie se ve su `$value`, que puede estar elegido a
  mano;
- `missing`: solo si el color falta (`null`);
- `always`: siempre.

Una regla con un color que no existe, una operación desconocida o un `from`/`over` que no
es un color del esquema se rechaza con `derive.<color>.invalid`.

### 11.2 Identidad (`follows`)

Identidad son los ajustes generales del tema: `tokens.shadow_style` (estilo, color,
dirección y tamaño de las sombras) y `tokens.border_style` (redondeo, ancho y estilo de
los bordes). Una propiedad de componente con `co.ordering.follows` toma de ellos su
default:

- `shadow`: el nivel `tokens.shadow_style.size`;
- `radius`: el nivel `tokens.border_style.radius`, salvo que las `options` del redondeo
  no lo incluyan (entonces se ve su propio `$value`);
- `border`: con `tokens.border_style.width` `none`, sin borde; con un nivel, un borde de
  ese ancho y del color del default (o `{color.border}` si no tiene); y
  `tokens.border_style.style` para todo borde que haya.

`follows` tiene que coincidir con el kind de la propiedad, y no vale en los estados
(`follows.<ruta>.invalid`). Lo que el tema pone en el componente va encima. Como
convención, el `$value` de una sombra o un redondeo que sigue a Identidad es lo que
Identidad da con sus defaults, para que el esquema se lea igual que lo que se resuelve.
`shadow_style.style`, `color` y `direction` rehacen además todos los niveles de
`tokens.shadow`.

### 11.3 Valores derivados de un componente

En una propiedad dentro de `variants` (también en sus estados), `co.ordering.derive`
dice cómo se deriva de otra propiedad de la misma variante (o de su `base`) cuando el
tema no la fija. `from` y `by` son nombres de propiedad, así que la regla vale igual para
el componente y para su copia dentro del `scope` de un grupo.

| `op` | Campos | Resultado |
|---|---|---|
| `step` | `from` (una sombra), `offset` (entero, -4 a 4) | la sombra `offset` niveles desde la de `from` (la de un estado desde la de reposo) |
| `shade` | `from` (un color), `amount` | el color de `from`, más claro o más oscuro (el fondo de un estado `pressed`) |
| `inset` | `from` (un redondeo), `by` (una distancia) | `from` menos `by`, sin bajar de 0; un redondeo `full` sigue `full` |

`when` como en §11.1. Una regla fuera de `variants`, sobre una propiedad que no existe o
con `from`/`by` que la variante no tiene se rechaza con
`component_derive.<c>.<ruta>.invalid`.

### 11.4 Matrices (`matrix`)

Un componente con `colors` y `appearances` (el botón: `primary`, `neutral`… por
`solid`, `outline`, `ghost`…) puede declarar
`"matrix": { "into": "variants", "roles": { … } }`. El motor pinta cada color en cada
apariencia y escribe el resultado en `into` (`variants.<color>.<apariencia>`), que no
puede ser una parte que el componente ya tenga (`matrix.<c>.invalid`).

- Cada color de `colors` declara sus **roles**: `main`, `on` y, si quiere, `ink`,
  `subtle` y `pressed`.
- Cada apariencia usa los roles como valores (`"background": "{main}"`,
  `"border": { "width": 1, "color": "{ink}" }`, tipo `role`).
- `roles` dice cómo se deriva cada rol que el color no tiene, o que el tema cambia al
  cambiar aquello de lo que deriva: reglas como las de §11.1, con `from` el nombre de un
  rol (`"from": "main"`) y `over` un alias de color.

### 11.5 Las reglas del motor

Un esquema que no declara reglas usa las del motor, que son las que tenía el primer
esquema de la web. Para un esquema nuevo conviene declarar las propias:

- **Colores** (si ningún color tiene `derive`): `primary_pressed` y `secondary_pressed`
  (`shade` -0.07 de su color), `primary_subtle`, `secondary_subtle`, `success_subtle`,
  `warning_subtle`, `danger_subtle` e `info_subtle` (`mix` 0.12 sobre `background`),
  `focus` (`same` que `primary`) y `primary_text` (`legible` sobre `background`).
  **Ojo:** el motor agrega al tema resuelto estos colores aunque el esquema no los tenga.
  Basta con declarar un solo `derive` para que no lo haga.
- **Identidad** (si ninguna propiedad tiene `follows`): siguen a Identidad las sombras y
  bordes que son un alias o `null`, salvo en los estados, las plataformas, las sombras de
  lo que tiene fondo `transparent` y los bordes de las apariencias, y los redondeos que
  son un alias distinto de `none` y `full`.
- **Componentes** (si ninguna variante tiene `derive` ni ningún componente `matrix`): la
  sombra de cada estado a la misma distancia de niveles que su default (`step`), el fondo
  de un estado `pressed` (`shade` -0.07), `tab_radius` (`inset` desde `radius` por
  `inset`) y una matriz `into: "variants"` en cada componente con `colors` y
  `appearances` (`ink` = `main`; `subtle`, `mix` 0.12 sobre `background`; `pressed`,
  `shade` -0.07).

## 12. `$extensions["co.ordering.editor"]` y `editor`: lo que lee el editor

Nada de esto cambia cómo se resuelve un tema, con tres excepciones: `custom` (§3),
`min`/`max` (un default numérico fuera de ellos se rechaza con
`<ruta>.default_out_of_range`, en `tokens`, `components`, `pages` y `site`) y `alpha`
(abajo). Todo es opcional: cuando falta, el editor sigue su regla de siempre. El
meta-esquema valida la forma; las claves que no están en esta lista se rechazan.

### 12.1 En cada nodo o grupo

- **Textos:** `label` e `info`, como `{ key, default, params? }`. `key` es una clave i18n
  (`^[A-Z0-9_]+$`, en el Dashboard `BUILDER_THEME_*`; reutiliza las que ya están
  traducidas), `default` el texto en inglés y `params` los valores que la clave
  interpola (`_color_`): un texto, o una lista de textos. Un nodo sin `label` toma el de
  `editor.props` por el nombre de la propiedad; si tampoco hay, el editor humaniza la
  clave.
- **Organización:** `group` (un nombre) y `order` (entero).
- **Control:** `control`, de un catálogo cerrado: `color`, `level`, `range`, `select`,
  `segmented`, `toggle`, `border`, `shadow`, `typography`, `font`, `image`,
  `sortable_sections`, `text`, `html`, `matrix`, `cards` y `scope`.
- **Rangos:** `min`, `max`, `step` (> 0) y `unit` (`px`, `ms`, `%` o `x`). En un `level`
  son el rango del valor propio. Una opacidad se guarda de 0 a 1 con `unit` `%`: el
  editor la muestra ×100.
- **Opciones:** `options` como `[{ value, label?, info?, drawing?, label_platform?,
  weights? }]`. `weights`, en una opción de un control `font`, son los pesos que tiene la
  fuente.
- **Campos de un valor compuesto:** `fields`, `{ campo: { label, info, control, min,
  max, step, unit, options } }`: los campos de un estilo tipográfico o los ejes de un
  slot.
- **Condiciones:** `visible_if` (oculta) y `disabled_if` (desactiva), como
  `{ path, op, value? }`, con `op` entre `eq`, `neq`, `in`, `not_in`, `truthy` y
  `falsy`. Las evalúa el cliente sobre los valores resueltos.
- **Textos de un estado:** `info_off` (la nota cuando un interruptor está apagado),
  `labels.unset` (la opción que sigue al reposo), `labels.none` (la opción "sin…") y
  `platform.label` / `platform.info` (los textos cuando se edita una plataforma).
- **Texto libre:** `placeholder` (un ejemplo mientras está vacío) y `multiline`.
- **`pair`:** la ruta que va en la misma fila (un color y el texto sobre él).
- **`groups`:** las tarjetas en que se muestra un componente o una parte: `key`, `label`,
  `info`, `paths`, `open`, `control`, `toggle` (`{ path, label }`, un interruptor en su
  encabezado), `hidden` (no se muestra; sus valores se siguen guardando y sirviendo),
  `part` y `placement` (la pestaña del componente en que va y si va `before` o `after`
  de sus filas), y `ordered` (`ascending` o `descending`: cada valor de sus rutas es al
  menos, o a lo sumo, el anterior; una tipografía se compara por su tamaño).
- **`hidden`:** no se muestra, porque se pinta desde otro control o es derivado.
- **`readonly`:** se muestra con su valor resuelto y no se edita. Un tema puede tenerlo
  (api-core lo valida y lo resuelve como cualquier otro).
- **`custom`** (en una `typography` con control `level`): el editor ofrece los niveles
  de la escala y uno propio, y el valor puede ser un estilo propio además del alias.
- **`alpha`:** un color que puede ser translúcido; el selector muestra su opacidad y
  guarda `rgba(r,g,b,a)` (sin espacios, `a` con hasta 2 decimales) si es translúcido,
  `transparent` si la opacidad es 0 y `#RRGGBB` si es opaco. Si el esquema marca
  `alpha` en al menos un nodo de `tokens` o `components`, api-core rechaza **al guardar
  un tema** un valor translúcido (o un alias a uno) en los colores que no lo tienen
  (`<ruta>.alpha`). Un esquema que no marca ninguno no restringe nada.
- **En un componente, `parts`:** `{ parte: { label } }`, el nombre propio de una de sus
  pestañas.
- **En un nodo `sections`, `section_groups`:** `[{ key, label, info? }]`, los bloques en
  que se mueven sus secciones (los `group` de §8), en el orden de la página.
- **`advanced`**, **`related`** (rutas que se muestran junto al nodo) y **`preview`**
  `{ page, path }`.

### 12.2 El bloque `editor` de la raíz

- **`sections`:** las secciones del editor, cada una con `key`, `label`, `info`,
  `parent`, `groups` (como los de arriba, con `paths`), `reset` (las rutas que restaura),
  `store` (`theme`, por defecto, o `site`: se guarda en los ajustes del sitio) y `layout`
  (`list`, una lista de componentes o páginas con el detalle de cada uno, o `cards`, las
  tarjetas de la sección). Un grupo de la sección de visibilidad puede llevar `pages` o
  `components`: las páginas o componentes en que el editor lo muestra, en lugar de en una
  sección propia.
- **`section_groups`:** `[{ key, label }]`, los grupos de secciones (en la web,
  Identidad, Avanzado y Sitio web).
- **`parts`:** las pestañas de un componente, cada una con `key`, `label`,
  `label_states`, `label_styles`, `sources` (las partes que junta; por defecto la suya),
  `choices` (una ficha por opción; `false` muestra la parte entera) y `nested` (`false`:
  no se ofrece dentro del `scope` de un grupo).
- **`labels`:** `platforms` (el nombre de cada plataforma). `page_section_groups` ya no
  lo lee ningún editor; se conserva para que un esquema anterior siga siendo válido.
- **`props`:** `{ propiedad: { label, info } }`, la etiqueta por nombre de propiedad.

Que cada ruta de `paths` exista, o que cada clave de visibilidad esté en un solo grupo, no
lo comprueban ni el motor ni api-core: es responsabilidad del CI del producto.

## 13. Versiones y compatibilidad

`version` es `base.minor.patch`. La **base** es la versión mayor del producto (26); un
tema v2 se resuelve siempre con **la última versión publicada de su base**, no con la que
se creó. Por eso, dentro de una base, cada versión tiene que servir a los temas guardados
con las anteriores. Al publicar, api-core compara el esquema con la última versión de su
base:

- **El orden:** la versión es mayor que la última de su base (`26.0` cuenta como
  `26.0.0`). Si no, `schema.version_order`.
- **Una versión ya publicada** no cambia: publicarla con otro contenido es
  `schema.version_exists`; con el mismo, `unchanged`.
- **Un patch** (`26.1.0` → `26.1.1`) solo cambia textos (`$description`) y metadatos del
  editor (`co.ordering.editor`, el bloque `editor`). Cambiar una clave, un tipo, una
  opción, un default, una regla, un `$deprecated` o una migración es
  `schema.patch_changes_values`.
- **Una minor** (`26.1.x` → `26.2.0`) puede agregar claves y opciones y cambiar defaults.
  No puede:
  - quitar una clave (`<ruta>.removed`), salvo que la versión anterior la marcara
    `$deprecated` o que una migración la mueva (§14);
  - cambiar su `$type` (`<ruta>.type_changed`);
  - cambiar su `scale` (`<ruta>.scale_changed`): pasar una `dimension` de una escala a
    otra, o quitarle o darle una, cambia la escala en la que un tema guardado nombra su
    nivel;
  - quitar una de sus opciones (`<ruta>.option_removed`);
  - quitar una migración que ya estaba (`schema.migration_removed`): dentro de una base
    la lista solo crece.
- **Una base nueva** (`27.0.0`) no se compara con la anterior: puede cambiarlo todo,
  pero los temas guardados con la base anterior no la siguen.

Las rutas que se comparan son las de cada nodo (`tokens.color.primary`,
`components.button.base.radius`, `visibility.header.logo`…), con el `$type` del nodo o
de su grupo y la `scale` del propio nodo (un grupo no la hereda).

Cada respuesta válida trae `changes`: `{ added, removed, migrated, deprecated }`, con
rutas.

**Modo desarrollo.** Un entorno de api-core con `THEME_SCHEMA_DEVELOPMENT=true` (local y
staging mientras ningún sitio use temas v2) reemplaza una versión ya publicada
(`replaced`) y no rechaza por orden ni por compatibilidad, aunque sigue informando
`changes`. Todo lo demás se valida igual. `validate(schema, { previous, development:
true })` hace lo mismo.

## 14. Migraciones (`co.ordering.migrate`)

En la raíz, `$extensions["co.ordering.migrate"]` es una lista de renombres y movimientos
de los valores de un tema:

```jsonc
"$extensions": {
  "co.ordering.migrate": [
    { "from": "components.button.base.padding_x", "to": "components.button.base.padding_inline" }
  ]
}
```

- api-core las aplica en orden cada vez que lee o guarda un tema, y `resolve()` de este
  paquete también, antes que nada (antes de `inherits`): el valor en `from` pasa a `to`
  si el tema no fija `to`, y `from` se borra igual.
- Cada migración necesita un `from` que el esquema **ya no tiene** y un `to` que sí
  tiene, de la misma sección (el mismo primer segmento), y distintos. Si no,
  `migrate.<índice>.invalid`.
- Dentro de una base la lista solo crece (§13).

**Lo que sirve hoy.** La validación reconoce rutas de `components.*`, `site.*` y de unos
pocos tokens, y en la práctica:

- **`components.*`** funciona: renombrar o mover una propiedad, una variante o un estado.
- **`tokens.*`** no: un `from` de `tokens.color.*` (o `tokens.font.*`,
  `tokens.layout.mode`) se rechaza porque el nombre sigue en el catálogo, y cualquier
  otro token no se reconoce como destino.
- **`visibility.*`, `images.*` y `pages.*`** se rechazan: la validación aún no reconoce
  sus rutas.
- **`site.*`** pasa la validación, pero los ajustes del sitio no están en el tema y la
  migración no los mueve.

Lo que no se puede declarar necesita una migración de datos en api-core
([creating-a-schema.md §6](creating-a-schema.md#6-versiones-y-migraciones)).

## 15. Errores

`validate()` y api-core responden `{ code, errors }`, con `errors` como
`[{ path, code }]`. El `code` de cada error es la ruta seguida de la regla. La primera
etapa que falla da el `code` general:

| `code` | HTTP al publicar | Etapa |
|---|---|---|
| `schema.format` | 422 | `$schema` no es `urn:ordering:theme-schema:1` |
| `schema.invalid` | 422 | el meta-esquema, el compilador, las reglas o el semver |
| `schema.version_exists` | 409 | la versión ya está publicada con otro contenido (solo api-core) |
| `schema.version_order` | 409 | la versión no es mayor que la última de su base |
| `schema.incompatible` | 422 | rompería los temas de la última versión de su base |

Reglas de cada error:

| Sufijo | Significa |
|---|---|
| `<ruta>.schema` (`schema` en la raíz) | no cumple el meta-esquema |
| `.type` | un `$type` (y `scale`) que no es ningún tipo de propiedad, o un token sin el tipo u opciones del catálogo |
| `.required` | falta un grupo o un token obligatorio |
| `.unknown` | un grupo, token, parte, plataforma, propiedad o eje que no existe |
| `.invalid` | un valor con forma incorrecta (en `product_type`, `version`, `format`, una propiedad, una sección…) |
| `.default.invalid` | el default no es un valor de su tipo |
| `.default.alias` | el default es un alias a un token que el esquema no tiene |
| `.options.invalid` | `options` donde no corresponde, o falta donde sí |
| `.rules.invalid` | `custom`, `max_length` o `pattern` en un tipo que no es el suyo |
| `.platforms.invalid`, `.formats.invalid`, `.hints.invalid` | §6, §7 |
| `.duplicate` | una sección de página repetida |
| `token_options.<token>.invalid` | §4.2 |
| `inherits.<c>.invalid`, `follows.<ruta>.invalid`, `derive.<color>.invalid`, `component_derive.<c>.<ruta>.invalid`, `matrix.<c>.invalid` | §5.1, §11 |
| `migrate.<n>.invalid` | §14 |
| `.default_out_of_range` | un default numérico fuera del `min`/`max` de su editor |
| `version.semver` | la versión no es `base.minor.patch` |
| `.removed`, `.type_changed`, `.scale_changed`, `.option_removed`, `schema.patch_changes_values`, `schema.migration_removed` | §13 |

Las rutas de los errores del compilador y de las reglas son las de la tupla compilada:
las mismas del esquema, más `.default`, `.options` o `.rules` al final. Los errores del
meta-esquema pueden nombrar rutas distintas según el validador (opis en api-core, Ajv en
el CI); que el esquema pase o no es lo mismo.

## 16. Desvíos de DTCG

Son deliberados:

- colores en hex o rgba y números sin unidad (px en la web, pt en nativo);
- tipos propios: `role`, `option`, `layout`, `image`, `boolean`, `sections`, `slot`,
  `fontRole`, `font`, `opacity`, `string`, `html` e `id`;
- claves con puntos en `visibility` e `images`;
- `inherits` en lugar de `$extends` (§5.1).

Un exportador a DTCG estricto los puede convertir si alguna herramienta lo pide.
