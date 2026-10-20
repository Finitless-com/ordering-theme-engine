# Crear el esquema de temas de un producto

Guía paso a paso para el equipo de un producto (una app nativa, el Call Center, una web)
que crea su esquema de temas desde cero. El formato completo está en
[schema-format.md](schema-format.md); aquí va el camino.

El esquema es un archivo JSON en el repo del producto (por ejemplo `theme.schema.json`),
junto al código que lo lee. Quien agrega un componente o una clave en el código la agrega
al esquema en el mismo PR. El CI del producto lo valida y lo publica en api-core, que es
la autoridad: lo vuelve a validar, guarda cada versión y resuelve con ella los temas de
los sitios de ese tipo de producto.

1. [Registrar el tipo de producto en api-core](#1-registrar-el-tipo-de-producto-en-api-core)
2. [Partir del vocabulario común](#2-partir-del-vocabulario-común)
3. [El meta-esquema y el editor](#3-el-meta-esquema-y-el-editor)
4. [Validar en local](#4-validar-en-local)
5. [Publicar](#5-publicar)
6. [Versiones y migraciones](#6-versiones-y-migraciones)
7. [Usar el resultado: `resolve()` y `defaults()`](#7-usar-el-resultado-resolve-y-defaults)
8. [Ejemplo mínimo completo](#8-ejemplo-mínimo-completo)

## 1. Registrar el tipo de producto en api-core

`product_type` tiene que ser un tipo del catálogo del Builder. Hoy son:

`marketplace_app`, `franchise_app`, `marketplace_website`, `franchise_website`,
`store_app`, `driver_app`, `kiosk_app`, `call_center` y `dashboard`.

- **Si tu producto es uno de ellos, no hay nada que hacer en api-core.** Usa ese valor.
- **Si es un tipo nuevo**, es el único cambio en api-core: un PR que agrega un caso a
  `App\Enums\BuilderProductTypeEnum` (`app/Enums/BuilderProductTypeEnum.php`), con un
  valor `^[a-z0-9_]+$` de hasta 40 caracteres. En ese PR se decide si es una app
  (`appValues()`, se compila desde una base del Builder) o una web (`webValues()`), y,
  si es una web, su `webSiteType()` y su `temporalDomainSuffix()`: eso es del Builder, no
  del tema. Hasta que ese PR esté desplegado, api-core rechaza el esquema con
  `product_type.invalid`.

  Este paquete tiene su propia copia de la lista (`PRODUCT_TYPES`), así que un tipo
  nuevo también necesita una versión del paquete que lo incluya; si no, `validate()` en
  local responde `product_type.invalid` aunque api-core ya lo acepte.

Además necesitas, para cada entorno de api-core en que publiques (local, staging,
producción), su **clave de publicación** (`THEME_PUBLISH_KEY` en ese api-core). La da
quien opera api-core; va en los secretos del CI y nunca en el repo. Un entorno sin clave
configurada rechaza toda publicación.

## 2. Partir del vocabulario común

Los nombres de los tokens son los mismos en todos los productos
([schema-format.md §4](schema-format.md#4-tokens)): así un preset o un color de marca
significa lo mismo en la web, en la app y en el Call Center. Lo que cada producto decide
son los defaults y qué parte del vocabulario usa:

1. **Escalas completas.** Copia `font`, `radius`, `size`, `icon`, `space`, `layout`,
   `motion`, `border` y `shadow` con todos sus nombres (el ejemplo de §8 los tiene con
   los defaults de la web) y ajusta los valores. Son números sin unidad: px en la web,
   pt en nativo.
2. **Colores, tipografías y opacidades.** Elige del catálogo solo los que tu código lee,
   al menos uno de cada grupo. No inventes nombres: uno fuera del catálogo se rechaza
   (`tokens.color.brand.unknown`). Si te falta uno, se agrega al catálogo del formato
   (aquí y en api-core) antes de usarlo.
3. **Tipos y opciones exactos.** Cada token lleva el `$type` del catálogo; los de
   `font`, `layout.mode` y los grupos de Identidad, además, exactamente sus `options`, en
   el mismo orden. La forma más simple es poner el `$type` en el grupo.
4. **Identidad, opcional.** `shadow_style`, `border_style`, `motion_style`,
   `focus_ring` y `scrollbar` son ajustes generales; decláralos si tu editor los ofrece.
   Las propiedades que siguen a Identidad llevan `co.ordering.follows`
   ([§11.2](schema-format.md#112-identidad-follows)).
5. **Declara tus derivaciones.** Si un color tuyo deriva de otro (el `pressed` del
   primario), dilo con `co.ordering.derive`. Si el esquema no declara ninguna, el motor
   usa sus reglas por defecto y **agrega al tema resuelto colores que tu esquema no
   tiene** (`primary_subtle`, `focus`, `primary_text`…); con un solo `derive`, no
   ([§11.5](schema-format.md#115-las-reglas-del-motor)). Lo mismo vale para `follows` y
   para los `derive` y `matrix` de los componentes: declarar uno reemplaza todas las
   reglas del motor de esa categoría.

Después vienen tus `components`, `visibility`, `images`, `pages` y, si los tienes, los
ajustes de tu sitio (`site`). Todo lo que tu código lee del tema tiene que estar en el
esquema, con su default: un tema guarda solo lo que cambia, y lo demás sale de ahí.

## 3. El meta-esquema y el editor

El paquete trae el meta-esquema del formato (JSON Schema 2020-12):

```
node_modules/@finitless-com/ordering-theme-engine/meta/theme-schema-1.json
```

importable como `@finitless-com/ordering-theme-engine/meta/theme-schema-1.json`.

**`$schema` no puede apuntar al archivo.** Tiene que ser exactamente
`"urn:ordering:theme-schema:1"`: es como api-core reconoce el formato, y con cualquier
otro valor responde `schema.format`. Eso limita el autocompletado:

- Los editores que leen `$schema` antes que sus propias asociaciones (VS Code, por
  ejemplo) intentan cargar el URN, no pueden, y no usan una asociación por nombre de
  archivo (`json.schemas` con `fileMatch`) mientras el archivo tenga ese `$schema`.
- Los editores que permiten asociar un JSON Schema a un archivo por su nombre o ruta, y
  dan prioridad a esa asociación, pueden usar el archivo de arriba.

Mientras tanto, la referencia es `ordering-theme validate` (§4), que valida contra el
mismo meta-esquema y da cada error con su ruta.

## 4. Validar en local

Instala el paquete como dependencia de desarrollo, con una versión exacta. Se publica en
npmjs y no necesita token:

```sh
pnpm add -D --save-exact @finitless-com/ordering-theme-engine
```

### Con la CLI

```sh
pnpm exec ordering-theme validate theme.schema.json                     # meta-esquema + reglas, como api-core
pnpm exec ordering-theme validate theme.schema.json --previous prev.json # y contra la última versión de tu base (§6)
pnpm exec ordering-theme defaults theme.schema.json --out src/theme.defaults.json
pnpm exec ordering-theme defaults theme.schema.json --out src/theme.defaults.json --check  # en el CI: ¿está al día?
```

`validate` termina con código 1 si el esquema no pasa, y lista cada `{ path, code }`
([schema-format.md §15](schema-format.md#15-errores)). `ordering-theme --help` da todas
las opciones.

### Desde código

`validateFull(schema, options?)` hace lo mismo que la CLI: el meta-esquema y después
`validate()`, que aplica las mismas reglas que api-core, en el mismo orden y con los
mismos códigos. Con una versión del paquete que no lo trae, son dos pasos: el
meta-esquema, con tu validador de JSON Schema 2020-12, y `validate()`:

```ts
import { readFileSync } from 'node:fs'
import Ajv2020 from 'ajv/dist/2020.js'
import meta from '@finitless-com/ordering-theme-engine/meta/theme-schema-1.json' with { type: 'json' }
import { validate } from '@finitless-com/ordering-theme-engine'

const schema = JSON.parse(readFileSync('theme.schema.json', 'utf8'))

// 1. La forma. strict: false porque el meta-esquema usa uniones de tipos y
//    condiciones que el modo estricto de Ajv avisa (no cambian el resultado).
const shape = new Ajv2020({ allErrors: true, strict: false }).compile(meta)
if (!shape(schema)) throw new Error(JSON.stringify(shape.errors, null, 2))

// 2. Lo demás. Con `previous`, la última versión publicada de tu base, también
//    compara las versiones (§6).
const { code, errors } = validate(schema)
if (code !== null) throw new Error(`${code}: ${errors.map((e) => e.code).join(', ')}`)
```

Lo que solo api-core puede decir es si la versión ya está publicada con otro contenido
(`schema.version_exists`); para eso está `publish --dry-run`.

## 5. Publicar

```sh
export THEME_PUBLISH_API=https://apiv4.ordering.co/v400   # el api-core del entorno, con su versión
export THEME_PUBLISH_KEY=…                                # la clave de ese entorno

pnpm exec ordering-theme publish theme.schema.json --dry-run   # todo menos guardar
pnpm exec ordering-theme publish theme.schema.json
```

`publish` valida primero en local y no envía nada si el esquema no pasa. Sin
`THEME_PUBLISH_API` o `THEME_PUBLISH_KEY` no publica y termina bien; con `--required`
(o `THEME_PUBLISH_REQUIRED=1`) termina con error, que es lo que conviene en un deploy.

Lo recomendado en el CI:

- **en cada PR:** `publish --dry-run` contra staging. Responde lo mismo que publicar,
  sin guardar nada;
- **en cada deploy:** `publish` contra el api-core de ese entorno, antes de desplegar.
  Volver a publicar la misma versión con el mismo contenido responde `unchanged`, así que
  el deploy se puede repetir.

Por debajo es una llamada a api-core (rutas de sistema, solo con la clave):

```http
POST {THEME_PUBLISH_API}/system/theme_schemas
X-Theme-Publish-Key-X: <clave>
Content-Type: application/json

{ "schema": { … }, "dry_run": true }
```

- `schema`: el esquema tal cual.
- `v1_map`: solo para un producto con temas v1 que api-core convierte (hoy, la web
  marketplace). Un producto nuevo no lo manda.
- `dry_run`: `true` comprueba todo y no guarda.

Respuestas (`{ error, result, … }`, como todo api-core):

| HTTP | Cuándo | Cuerpo |
|---|---|---|
| 201 | se publicó | `result: { status: "created", product_type, version, sha256, changes }` |
| 200 | la versión ya existía con el mismo contenido / `dry_run` válido / reemplazada en modo desarrollo | `status: "unchanged"` / `"valid"` / `"replaced"` |
| 401 | sin clave, clave equivocada o el entorno no tiene clave | `result: ["You don't have permission"]` |
| 409 | `schema.version_exists`, `schema.version_order` | `error_codes: [código]`, `errors: [{ path, code }]` |
| 422 | `schema.format`, `schema.invalid`, `schema.incompatible` | ídem |

`changes` es `{ added, removed, migrated, deprecated }` contra la última versión de la
base (null si es la primera). Con la misma clave, `GET {api}/system/theme_schemas/{product_type}`
lista las versiones publicadas (`{ version, sha256, created_at }`) y
`GET {api}/system/theme_schemas/{product_type}/{version}` devuelve una.

## 6. Versiones y migraciones

`version` es `base.minor.patch`. La base es la versión mayor del producto (por ejemplo
`26` para las apps v26), y **un tema se resuelve con la última versión publicada de su
base**. Así, publicar `26.3.0` cambia lo que ven todos los sitios con temas de la base
26, sin que nadie migre nada, siempre que la versión nueva sea compatible con las
anteriores. Las reglas exactas están en
[schema-format.md §13](schema-format.md#13-versiones-y-compatibilidad); en resumen:

| Cambio | Versión |
|---|---|
| Textos, etiquetas y metadatos del editor | patch |
| Agregar una clave, un componente, una opción; cambiar un default | minor |
| Marcar una clave `$deprecated` | minor |
| Quitar una clave que la versión anterior ya marcaba `$deprecated` | minor |
| Renombrar o mover una propiedad de componente, con su migración | minor |
| Quitar una clave no deprecada, cambiar un tipo o la escala de una `dimension`, quitar una opción, quitar una migración | **rompe**: base nueva |

Cambiar la escala de una `dimension` (`size` → `space`) no lo detecta la comparación,
pero rompe igual los temas guardados: trátalo como un cambio de tipo.

**Cuándo basta `co.ordering.migrate`.** Para renombrar o mover una propiedad, una
variante o un estado de un componente: declara `{ from, to }` en la raíz y api-core mueve
el valor de cada tema guardado al leerlo y al guardarlo
([schema-format.md §14](schema-format.md#14-migraciones-coorderingmigrate)).

**Cuándo hace falta una migración de datos en api-core.** Cuando lo que cambia no se
puede declarar:

- quitar o renombrar un token (`tokens.*`), porque los temas tienen alias a él;
- mover una clave de `visibility`, `images` o `pages`, o convertir una en otra (una clave
  de visibilidad que pasa a ser una sección de página);
- cambiar el significado de un valor guardado (una altura de línea en px que pasa a ser
  proporción);
- cualquier cambio que rompe dentro de una base con temas ya guardados.

Eso es un PR en api-core con una migración que reescribe los temas guardados
(`site_themes.values` y `draft_values` de cada proyecto) y los presets, que se despliega
antes de publicar la versión del esquema que lo necesita. Coordínalo con quien mantiene
api-core.

**Mientras el producto está en desarrollo.** Un api-core con
`THEME_SCHEMA_DEVELOPMENT=true` (local y staging hasta el lanzamiento de los temas v2)
reemplaza una versión ya publicada y no rechaza por orden ni por compatibilidad. En
producción está apagado: allí las reglas valen desde la primera versión.

## 7. Usar el resultado: `resolve()` y `defaults()`

El tema de cada sitio lo resuelve api-core en tiempo de ejecución. El paquete calcula lo
mismo en el cliente para lo que tiene que existir sin la respuesta del API, o antes:

```ts
import { defaults, resolve } from '@finitless-com/ordering-theme-engine'
import schema from './theme.schema.json' with { type: 'json' }

const empty = defaults(schema)               // el tema vacío resuelto
const theme = resolve(schema, themeValues)   // un tema (lo que guarda: solo lo que cambia) sobre los defaults
```

- **Defaults locales.** `defaults(schema)` (o `ordering-theme defaults`) es lo que pinta
  el producto antes de la primera respuesta, sin conexión o con el API caído. En una app
  nativa es crítico: el binario publicado no cambia, así que se genera en el build con la
  versión del esquema de ese build.
- **Vista previa.** `resolve(schema, valores)` da lo que api-core serviría para esos
  valores, sin latencia.

El resultado tiene `tokens`, `components`, `visibility`, `images`, `pages`, `modes` y
`platforms`, con cada alias reemplazado por su valor y cada tipografía con su `family`
(la fuente de su rol). Los valores propios de una plataforma vienen aparte, en
`platforms.<plataforma>`, y el cliente los aplica encima. Por defecto los mapas vacíos
salen como `[]`, como los sirve api-core; con `{ emptyMaps: 'object' }` salen como `{}`.

`resolve()` supone un tema válido para el esquema: no aplica las migraciones ni descarta
los valores que ya no encajan, como sí hace api-core al servirlo. Los ajustes del sitio
(`values.site`) no los resuelve: son del sitio, y los sirve api-core.

## 8. Ejemplo mínimo completo

Un esquema completo y válido (pasa el meta-esquema y `validate()`) para un Call Center:
las escalas completas, unos pocos colores y tipografías, un botón que sigue a Identidad en
su redondeo y deriva el fondo de su estado `pressed`, una clave de visibilidad, una
imagen, una página y un ajuste del sitio.

```json
{
  "$schema": "urn:ordering:theme-schema:1",
  "product_type": "call_center",
  "version": "1.0.0",
  "format": 2,
  "platforms": [],
  "tokens": {
    "font": {
      "$type": "font",
      "heading": {
        "$value": "inter",
        "$extensions": { "co.ordering": { "options": ["archivo", "inter", "lato", "montserrat", "nunito", "open_sans", "playfair_display", "poppins", "raleway", "roboto"] } }
      },
      "body": {
        "$value": "inter",
        "$extensions": { "co.ordering": { "options": ["archivo", "inter", "lato", "montserrat", "nunito", "open_sans", "playfair_display", "poppins", "raleway", "roboto"] } }
      }
    },
    "typography": {
      "$type": "typography",
      "h1": { "$value": { "size": 24, "line": 1.33, "weight": 700 } },
      "body": { "$value": { "size": 14, "line": 1.5, "weight": 400 } },
      "button": { "$value": { "size": 14, "line": 1.43, "weight": 600 } }
    },
    "color": {
      "$type": "color",
      "primary": {
        "$value": "#1E72E3",
        "$extensions": { "co.ordering.editor": { "label": { "key": "BUILDER_THEME_COLOR_PRIMARY", "default": "Primary" } } }
      },
      "on_primary": { "$value": "#FFFFFF" },
      "primary_pressed": {
        "$value": "#1862C5",
        "$extensions": { "co.ordering": { "derive": { "op": "shade", "from": "{color.primary}", "amount": -0.07 } } }
      },
      "background": { "$value": "#FFFFFF" },
      "surface": { "$value": "#F5F6F8" },
      "text": { "$value": "#1A1A1A" },
      "text_muted": { "$value": "#6B7280" },
      "border": { "$value": "#E5E7EB" }
    },
    "radius": {
      "$type": "dimension",
      "none": { "$value": 0 }, "xs": { "$value": 4 }, "sm": { "$value": 8 }, "md": { "$value": 10 },
      "lg": { "$value": 15 }, "xl": { "$value": 20 }, "xxl": { "$value": 30 }, "full": { "$value": 9999 }
    },
    "size": {
      "$type": "dimension",
      "sm": { "$value": 32 }, "md": { "$value": 40 }, "lg": { "$value": 48 }, "xl": { "$value": 56 }
    },
    "icon": {
      "$type": "dimension",
      "sm": { "$value": 16 }, "md": { "$value": 20 }, "lg": { "$value": 24 }
    },
    "space": {
      "$type": "dimension",
      "none": { "$value": 0 }, "xxs": { "$value": 2 }, "xs": { "$value": 4 }, "sm": { "$value": 8 }, "md": { "$value": 12 },
      "lg": { "$value": 16 }, "xl": { "$value": 24 }, "xxl": { "$value": 32 }, "xxxl": { "$value": 48 }
    },
    "layout": {
      "page_gutter": { "$type": "dimension", "$value": 16 },
      "mode": {
        "$type": "option",
        "$value": "original",
        "$extensions": { "co.ordering": { "options": ["original", "appointments"] } }
      }
    },
    "motion": {
      "$type": "duration",
      "fast": { "$value": 120 }, "base": { "$value": 200 }, "slow": { "$value": 300 }
    },
    "border": {
      "$type": "dimension",
      "thin": { "$value": 1 }, "thick": { "$value": 2 }
    },
    "shadow": {
      "$type": "shadow",
      "none": { "$value": null },
      "sm": { "$value": { "x": 0, "y": 1, "blur": 4, "spread": 0, "color": "#000000", "opacity": 0.1, "elevation": 1 } },
      "md": { "$value": { "x": 0, "y": 2, "blur": 8, "spread": 0, "color": "#000000", "opacity": 0.1, "elevation": 3 } },
      "lg": { "$value": { "x": 0, "y": 4, "blur": 12, "spread": 0, "color": "#000000", "opacity": 0.15, "elevation": 6 } },
      "xl": { "$value": { "x": 0, "y": 8, "blur": 24, "spread": 0, "color": "#000000", "opacity": 0.2, "elevation": 12 } }
    },
    "opacity": {
      "$type": "opacity",
      "disabled": { "$value": 0.5 }
    }
  },
  "components": {
    "button": {
      "base": {
        "background": { "$type": "color", "$value": "{color.primary}" },
        "color": { "$type": "color", "$value": "{color.on_primary}" },
        "radius": {
          "$type": "dimension",
          "$value": "{radius.md}",
          "$extensions": { "co.ordering": { "scale": "radius", "follows": "radius" } }
        },
        "height": {
          "$type": "dimension",
          "$value": "{size.md}",
          "$extensions": { "co.ordering": { "scale": "size" } }
        },
        "padding_x": {
          "$type": "number",
          "$value": 16,
          "$extensions": { "co.ordering.editor": { "control": "range", "min": 0, "max": 48, "step": 1, "unit": "px" } }
        },
        "text": { "$type": "typography", "$value": "{typography.button}" }
      },
      "variants": {
        "primary": {
          "states": {
            "pressed": {
              "background": {
                "$type": "color",
                "$value": "{color.primary_pressed}",
                "$extensions": { "co.ordering": { "derive": { "op": "shade", "from": "background", "amount": -0.07 } } }
              }
            }
          }
        }
      }
    }
  },
  "visibility": {
    "$type": "boolean",
    "header.logo": { "$value": true }
  },
  "images": {
    "$type": "image",
    "logo": {
      "$value": "",
      "$extensions": { "co.ordering": { "formats": ["png", "svg", "webp"], "hints": { "ratio": "4:1", "alpha": true, "max_kb": 200 } } }
    }
  },
  "pages": {
    "home": {
      "footer": { "$type": "boolean", "$value": true },
      "sections": {
        "$type": "sections",
        "$value": [
          { "key": "customer_search", "visible": true, "hideable": false, "group": null },
          { "key": "recent_orders", "visible": true, "hideable": true, "group": null }
        ]
      }
    }
  },
  "site": {
    "name": {
      "$type": "string",
      "$value": "",
      "$extensions": { "co.ordering": { "max_length": 60 } }
    }
  }
}
```

`defaults()` de este esquema da, por ejemplo:

```jsonc
{
  "tokens": { "color": { "primary": "#1E72E3", "primary_pressed": "#1862C5", … }, … },
  "components": {
    "button": {
      "base": {
        "background": "#1E72E3", "color": "#FFFFFF", "radius": 10, "height": 40, "padding_x": 16,
        "text": { "size": 14, "line": 1.43, "weight": 600, "family": "inter" }
      },
      "variants": { "primary": { "states": { "pressed": { "background": "#1862C5" } } } }
    }
  },
  "visibility": { "header.logo": true },
  "images": { "logo": "" },
  "pages": { "home": { "footer": true, "sections": [{ "key": "customer_search", "visible": true }, { "key": "recent_orders", "visible": true }] } },
  "modes": [],
  "platforms": []
}
```

Y un tema que solo cambia el primario (`{ "tokens": { "color": { "primary": "#FF0000" } } }`)
resuelve `primary_pressed` y el fondo del botón presionado a `#DB0000`: los dos derivan
del primario.
