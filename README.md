# @ordering/theme-engine

El motor de temas v2 de api-core, en TypeScript. Resuelve un tema exactamente como
lo hace `ThemeResolver::resolve()` de api-core, para que la web
(`website-marketplace-v26`) y el Dashboard calculen lo mismo sin copiar las reglas:
los defaults locales, la vista previa sin latencia y los artefactos de CI
(`docs/THEMES_V2_PLAN.md` §6.3.4 y §6.3.7 de api-core).

Sin dependencias en tiempo de ejecución. ESM, TypeScript estricto, Node 22.

## Uso

```ts
import { resolve, defaults } from '@ordering/theme-engine'
import schema from './theme.schema.json' // el esquema en nodos

const tema = resolve(schema, site_theme.values) // lo mismo que sirve api-core
const vacio = defaults(schema)                  // el tema vacío resuelto
```

API pública:

- `resolve(schema, values, options?)`: el tema sobre los defaults de su esquema, con
  cada alias resuelto. Es `ThemeResolver::resolve()`.
- `defaults(schema, options?)`: el tema vacío resuelto, lo que sirve un sitio cuyo tema
  no cambia nada.
- `compile(schema)`: el esquema en nodos como las tuplas que lee el motor
  (`NodeCompiler`), con sus errores. `resolve` lo usa y lo guarda en caché por objeto.
- `siteDefaults(compiled)`: los defaults del bloque `site` (son del sitio, no del tema).
- Los tipos: `NodeSchema`, `ThemeValues`, `ResolvedTheme`, `ResolveOptions`…

`options.emptyMaps`: PHP no distingue un mapa vacío de una lista vacía y los sirve como
`[]`. Por defecto la salida hace lo mismo, así que su JSON es el del API; con
`'object'` salen como `{}`.

## De dónde salen las reglas

Del esquema. El motor es genérico: lo que es propio de cada producto lo declara su
`theme.schema.json` en el formato de nodos (`docs/THEMES_V2_SCHEMA_FORMAT.md` de
api-core), en `$extensions["co.ordering"]`:

- `scale` y `options`: el tipo de cada propiedad;
- `inherits`: los componentes que siguen a otro;
- `follows`: lo que sigue a Identidad (sombra, redondeo y borde generales);
- `derive`: los colores derivados (`same`, `shade`, `mix`, `legible`) y los valores
  derivados de un componente (`step`, `shade`, `inset`);
- `matrix`: los colores del botón en cada apariencia.

Las operaciones (HSL, mezcla, contraste WCAG, escalas de sombra, orden de las secciones
de una página) son del motor y las mismas para todos los productos. Un esquema que no
declara `follows`, `derive` ni `matrix` usa las reglas del motor, como api-core, y un
nodo sin `$type` toma el de su grupo.
`co.ordering.editor` y el bloque `editor` son del editor: el motor no los lee.

## El contrato con api-core

api-core es la referencia. Este paquete pasa los mismos casos dorados que
`ThemeResolverGoldenTest` de api-core:

- `fixtures/cases/*.json`: `{ description, values, resolved }`, copiados de
  `tests/fixtures/themes/cases` de api-core. Cada tema (`values`) tiene que resolver a
  su `resolved`.
- `fixtures/schema.nodes.json`: el esquema congelado de esos casos
  (`schema-marketplace_website-26.0.json`), convertido a nodos por
  `ordering:theme-schema:convert` de api-core.
- `fixtures/schema-26.0.nodes.json` y `fixtures/schema-26.0.empty.json`: el esquema
  actual del producto (`resources/themes/marketplace_website/26.0.json`) y el tema vacío
  que api-core resuelve con él (`scripts/resolve-empty.php`).
- `fixtures/php-round.json`: el `round()` de PHP sobre los valores que redondea el
  motor (`scripts/php-round.php`).

La comparación trata `[]` y `{}` vacíos como iguales e ignora el orden de las claves
(JavaScript pone primero las claves numéricas), pero compara cada número exactamente.
Si un caso falla, la causa está en el puerto: nunca se ajusta la fixture ni la
comparación.

Donde PHP y JavaScript difieren, el puerto imita a PHP (`src/php.ts`):

- `round()` de PHP 8.3 redondea la mitad lejos del cero y pre-redondea a 15 cifras
  (`127.49999999999997` da 128; `Math.round` da 127). Lo usan los canales de un color
  y las sombras escaladas.
- `[]` y `{}` son el mismo array vacío, que es una lista: un mapa vacío del tema no
  cambia nada y un esquema `{}` cuenta como vacío.
- Los arrays de PHP se copian al asignarlos: el motor nunca cambia el esquema ni el
  tema que recibe, y cada respuesta es un objeto nuevo.
- `===` entre arrays exige las mismas claves en el mismo orden.

### Lo que no hace

- **No valida.** Supone un tema válido: api-core lo valida al guardarlo y, al
  servirlo, descarta lo que ya no encaja con el esquema (`ThemeResolver::valid()`).
  Un tema que el API no aceptaría puede resolver aquí distinto.
- No valida el esquema contra el meta-esquema ni con `ThemeSchemas::check()`: api-core
  solo registra esquemas válidos.
- No convierte ni proyecta temas v1 (`ThemeV1Bridge`), no optimiza imágenes ni trae
  los ajustes del sitio (`values.site`): eso es del API.
- Solo lee esquemas en nodos (los registrados en tuplas los convierte api-core).

## Desarrollo

```sh
nvm use            # Node 22.23.1
pnpm install
pnpm test          # vitest: casos dorados, esquema actual, semántica de PHP
pnpm typecheck
pnpm build         # dist/: ESM y .d.ts
```

### Refrescar las fixtures

Las fixtures salen de api-core, nunca se escriben a mano. Con el contenedor de
desarrollo de api-core levantado (`docker compose -f docker-compose.dev.yml up -d`):

```sh
pnpm sync-fixtures                          # api-core en ../api-core
API_CORE=/ruta/a/api-core pnpm sync-fixtures
```

El script solo lee api-core (incluido lo que no está commiteado): copia los casos y el
esquema, y corre en el contenedor la conversión a nodos, `scripts/resolve-empty.php` y
`scripts/php-round.php`, que escriben en el `/tmp` del contenedor o en la salida
estándar. Una fixture solo se reemplaza si el comando termina bien y devuelve JSON.
Después, `pnpm test` dice si el motor sigue respondiendo lo mismo que api-core.

## Distribución

Por decidir: npm privado, dependencia git o paquete del monorepo. Mientras tanto el
paquete es `private` y no se publica.
