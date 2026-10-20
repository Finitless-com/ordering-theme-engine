# @finitless-com/ordering-theme-engine

El motor de temas v2 de api-core, en TypeScript. Resuelve un tema exactamente como
lo hace `ThemeResolver::resolve()` de api-core, para que la web
(`website-marketplace-v26`) y el Dashboard calculen lo mismo sin copiar las reglas:
los defaults locales, la vista previa sin latencia y los artefactos de CI
(`docs/THEMES_V2_PLAN.md` §6.3.4 y §6.3.7 de api-core). Y valida un esquema como
`ThemeSchemaPublisher::validate()`, para que el CI de un producto sepa antes de
publicar lo que api-core le va a responder.

Sin dependencias en tiempo de ejecución. ESM, TypeScript estricto, Node 22. Trae un
comando, `ordering-theme`, que valida, calcula los defaults y publica un esquema.

El formato del esquema: [`docs/schema-format.md`](docs/schema-format.md). Cómo
escribir el de un producto: [`docs/creating-a-schema.md`](docs/creating-a-schema.md).

## Instalación

Se publica en npmjs, público: no hace falta `.npmrc` ni token.

```sh
pnpm add -E @finitless-com/ordering-theme-engine   # o npm i -E, yarn add -E
```

Una versión exacta en `package.json` (`"@finitless-com/ordering-theme-engine": "0.3.0"`):
un minor puede cambiar lo que resuelve, porque sigue a api-core.

## Uso

```ts
import { resolve, defaults } from '@finitless-com/ordering-theme-engine'
import schema from './theme.schema.json' // el esquema en nodos

const tema = resolve(schema, site_theme.values) // lo mismo que sirve api-core
const vacio = defaults(schema)                  // el tema vacío resuelto
```

Validar un esquema antes de publicarlo:

```ts
import { validateFull } from '@finitless-com/ordering-theme-engine'

const { code, errors, changes } = validateFull(schema, { previous, v1Map }) // el meta-esquema y lo demás, como api-core
// code: null (válido), 'schema.format', 'schema.invalid', 'schema.version_order' o 'schema.incompatible'
```

El meta-esquema va compilado dentro del paquete: no hace falta Ajv ni otro validador.
`validateShape(schema)` corre solo esa etapa.

API pública:

- `resolve(schema, values, options?)`: el tema sobre los defaults de su esquema, con
  cada alias resuelto. Es `ThemeResolver::resolve()`.
- `defaults(schema, options?)`: el tema vacío resuelto, lo que sirve un sitio cuyo tema
  no cambia nada.
- `compile(schema)`: el esquema en nodos como las tuplas que lee el motor
  (`NodeCompiler`), con sus errores. `resolve` lo usa y lo guarda en caché por objeto.
- `siteDefaults(compiled)`: los defaults del bloque `site` (son del sitio, no del tema).
- `validateFull(schema, { previous?, v1Map?, development? })`: lo que responde
  `ThemeSchemaPublisher::validate()` de api-core, `{ code, errors, changes }`, meta-esquema
  incluido (ver abajo).
- `validate(schema, options?)`: lo mismo sin el meta-esquema, con los mismos códigos,
  los mismos errores en el mismo orden y los mismos cambios que api-core.
- `validateShape(schema)`: los errores del esquema contra el meta-esquema, como
  `ThemeSchemas::meta()`: uno por ruta, `{ path, code: '<path>.schema', message }`
  (`message` es la explicación del validador; api-core no la manda). `[]` si lo cumple.
- `check(compiled)`: `ThemeSchemas::check()` sobre las tuplas que da `compile()`.
- `outOfRange(schema)`: los defaults numéricos fuera del `min`/`max` de su control del
  editor (`<path>.default_out_of_range`).
- `diff(previous, next)`: `SchemaDiff::compare()`, `{ errors, changes }` con
  `changes: { added, removed, migrated, deprecated }`.
- El meta-esquema del formato, `@finitless-com/ordering-theme-engine/meta/theme-schema-1.json`
  (JSON Schema 2020-12), copiado tal cual de api-core.
- Los tipos: `NodeSchema`, `ThemeValues`, `ResolvedTheme`, `ResolveOptions`,
  `ValidateOptions`, `ValidationResult`, `ShapeError`, `Changes`, `V1Map`…

`options.emptyMaps`: PHP no distingue un mapa vacío de una lista vacía y los sirve como
`[]`. Por defecto la salida hace lo mismo, así que su JSON es el del API; con
`'object'` salen como `{}`.

## El comando `ordering-theme`

Lo que el repo de un producto corre sobre su esquema, para que ninguno copie sus propios
scripts. Con el paquete instalado, `pnpm exec ordering-theme` (o `npx ordering-theme`);
sin instalarlo, `npx @finitless-com/ordering-theme-engine <comando>`.

```sh
ordering-theme validate theme.schema.json [--v1-map v1-map.json] [--previous last.json]
ordering-theme defaults theme.schema.json [--out src/theme.defaults.json] [--check] [--site]
ordering-theme publish  theme.schema.json [--v1-map v1-map.json] [--dry-run] [--required]
ordering-theme --help
```

- `validate`: `validateFull()`. Imprime un error por línea (su código, con la ruta si el
  código no la dice, y lo que dice el validador en los del meta-esquema) y sale con 1;
  con 0 si es válido. Con `--previous` (la última versión publicada de su base) también
  comprueba el orden de la versión y que nada se rompa, e imprime los cambios.
- `defaults`: el tema vacío resuelto (`defaults()`), lo que api-core sirve para un tema
  que no cambia nada, como JSON con sangría y salto de línea final. Sin `--out` lo
  imprime; con `--out` lo escribe, y con `--check` además sale con 1 si ese archivo no
  está al día (para el CI, si el producto lo versiona). `--site` le añade los defaults
  de los ajustes del sitio (`siteDefaults()`) en `site`.
- `publish`: valida el esquema aquí y, si pasa, lo manda a api-core: `POST
  {THEME_PUBLISH_API}/system/theme_schemas` con `{ schema, v1_map?, dry_run? }` y la
  clave en `X-Theme-Publish-Key-X`. El tipo de producto y la versión son los del
  esquema (`product_type`, `version`). Sin `--v1-map` no manda mapa y api-core usa el
  último publicado para el tipo de producto. `--dry-run`: api-core lo comprueba todo y no
  guarda nada (`valid`).

  Sale con 0 si api-core responde `created`, `replaced` (un producto en desarrollo),
  `valid` o `unchanged` (esa versión ya está publicada con el mismo contenido, así que
  un deploy se puede repetir), e imprime los cambios. Un rechazo sale con 1 e imprime
  sus errores y sus códigos: `schema.invalid` (422), `schema.incompatible` (422),
  `schema.version_order` (409), `schema.version_exists` (409, esa versión ya está
  publicada con otro contenido: hay que subirla) o la clave rechazada (401).

  Entorno: `THEME_PUBLISH_API` (el API con su versión, p. ej.
  `https://apiv4.ordering.co/v400`) y `THEME_PUBLISH_KEY` (la clave de publicación del
  entorno, `THEME_PUBLISH_KEY` de api-core). Si falta alguna, valida y no publica
  (sale con 0), salvo con `--required` o `THEME_PUBLISH_REQUIRED=1`, que sale con 1:
  un deploy que no puede publicar su esquema no se despliega.

Códigos de salida: 0 hecho, 1 el esquema o la respuesta de api-core falla, 2 un error de
uso (un comando u opción que no existe, falta el archivo).

En un CI, por ejemplo:

```sh
ordering-theme defaults theme.schema.json --out src/theme.defaults.json --check
ordering-theme publish theme.schema.json --v1-map v1-map.json --dry-run   # en el PR
ordering-theme publish theme.schema.json --v1-map v1-map.json --required  # en el deploy
```

## Qué valida `validateFull()`

Las etapas de `ThemeSchemaPublisher::validate()`; la primera que falla da el código:

1. `schema.format`: el esquema no está en nodos (`$schema` no es
   `urn:ordering:theme-schema:1`).
2. `schema.invalid`: los errores del meta-esquema (`validateShape()`), o si lo cumple,
   los del compilador (un valor que no es nodo, un tipo que no
   es ningún tipo de propiedad, un token que no declara el tipo u opciones con que se
   valida) o, si no hay, los de `check()` (cabecera, tokens, componentes, `scope`,
   `slots`, plataformas, `inherits`, visibilidad e imágenes, `site`, `follows`, `derive`,
   `component_derive`, `matrix`, migraciones, páginas y el mapa v1) y los de
   `outOfRange()`. En los dos casos se añade `version.semver` si la versión no es
   `mayor.menor.parche`.
3. Con `previous` (la última versión publicada de su base): `schema.version_order` si
   la versión no es mayor dentro de la misma base (como `version_compare()` de PHP), y
   `schema.incompatible` con los errores de `diff()`. Si es válido, `changes` dice qué
   cambia; sin `previous`, `changes` es null.

`validate()` es lo mismo sin el meta-esquema de la etapa 2.

`development: true` es el modo desarrollo de api-core: la etapa 3 no rechaza nada (ni
el orden de la versión ni las incompatibilidades), pero `changes` sigue saliendo.

`v1Map` es el mapa de rutas v1 que se publica con el esquema (`v1_map`); compila los
bloques `v1`, las rutas v1 de visibilidad e imágenes, las de las secciones de cada
página y las de `site`, que `check()` también valida. Sin él no hay rutas v1. api-core,
si no recibe mapa, usa el último publicado para el tipo de producto (o el que guarda en
`resources/themes/<product_type>/v1-map.json`): para obtener lo mismo, pasa ese mapa.

Lo que **no** hace, y api-core sí:

- **Los mismos errores del meta-esquema.** Si un esquema lo cumple o no es lo mismo
  aquí y en api-core, pero api-core usa otro validador (opis/json-schema): cuando falla,
  las rutas que nombra pueden ser otras (en un `anyOf`, uno nombra las ramas y el otro
  solo el nodo). El paquete lo compila con Ajv al construirse
  (`scripts/build-shape-validator.mjs`, que escribe `src/shape-validator.js`, no
  versionado).
- **El registro.** api-core también rechaza una versión ya publicada con otro contenido
  (`schema.version_exists`, 409), y busca él mismo el `previous` (la última de la base).

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

- `fixtures/cases/*.json`: `{ description, migrate?, values, resolved }`, copiados de
  `tests/fixtures/themes/cases` de api-core. Cada tema (`values`) tiene que resolver a
  su `resolved`. `migrate`, si está, son las migraciones que el esquema declara en ese
  caso (`co.ordering.migrate`): un tema guardado antes de ellas.
- `fixtures/schema.nodes.json`: el esquema congelado de esos casos
  (`schema-marketplace_website-26.0.json`), convertido a nodos por
  `ordering:theme-schema:convert` de api-core.
- `fixtures/schema-26.0.nodes.json` y `fixtures/schema-26.0.empty.json`: el esquema
  actual del producto (`resources/themes/marketplace_website/26.0.json`) y el tema vacío
  que api-core resuelve con él (`scripts/resolve-empty.php`).
- `fixtures/schema-26.0.compiled.json`: las tuplas que `NodeCompiler::compile()` de
  api-core da para el esquema actual con su mapa v1 (`scripts/compile-schema.php`), que
  `compile()` tiene que dar iguales, sus colores translúcidos (`alpha`) incluidos.
- `fixtures/php-round.json`: el `round()` de PHP sobre los valores que redondea el
  motor (`scripts/php-round.php`), y `fixtures/php-strings.json`, su `version_compare()`
  y su `(string)` de un float (`scripts/php-strings.php`).
- `fixtures/validate.json`: los casos dorados de `validate()`. `scripts/validate-cases.php`
  corre en el contenedor: parte del esquema actual (con versión `26.0.0` y los dos
  paddings de `signup_page` dentro de su control, la «base») y aplica a cada caso una
  mutación, una lista de operaciones `["set", ruta, valor]` / `["delete", ruta]` sobre el
  esquema, el `previous` y el mapa v1 (`fixtures/v1-map.json`), y guarda lo que responde
  `ThemeSchemaPublisher::validate()`. La prueba aplica las mismas operaciones y compara
  la respuesta entera, errores en orden. Cubren cada regla: el original tal cual
  (fuera de rango y sin semver), formato, tipo de producto, compilador, cada fallo de
  `check()`, `outOfRange()`, semver, orden de versiones (igual, menor, de dos partes, una
  pre-release anterior) y `diff()` (parche que cambia un default o solo textos y
  metadatos del editor, menor que añade, quitar con y sin `$deprecated`, `$deprecated:
  "0"`, migrar, cambiar el tipo, cambiar la escala, quitar una opción, quitar una
  migración, base nueva, todos los errores juntos y un válido con los cuatro tipos de
  cambio), más varios en modo desarrollo. El script falla si un caso no pasa el
  meta-esquema, así que `validateFull()` responde lo mismo que `validate()` en todos.

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
- El `$` de `preg_match()` también acepta un salto de línea final: los patrones que lo
  imitan terminan en `\n?$`. `\s` es solo ASCII.
- `version_compare()` (pre-releases como `26.1.0-beta`), el `(string)` de un float (14
  cifras, `1.0E+25`), `empty()` (`"0"` está vacío: un `$deprecated: "0"` no depreca),
  `strlen()` en bytes y `mb_strlen()` en caracteres.

Donde JavaScript no puede imitarlo: `JSON.parse()` lee `2.0` como el entero `2`, y PHP
como float. Un número entero sin parte decimal cuenta aquí como `int` de PHP; solo
cambia la respuesta en lo que PHP exige `is_int()` (el `offset` de un `step`, los
`hints` de una imagen, un `id`, un `weight` de tipografía, `format: 2`). También
cambian el orden de las claves numéricas de un mapa (JavaScript las pone primero) y,
por tanto, el orden de sus errores; ningún esquema real las tiene.

### Lo que no hace

- **No valida temas.** Supone un tema válido: api-core lo valida al guardarlo y, al
  servirlo, descarta lo que ya no encaja con el esquema (`ThemeResolver::valid()`).
  Un tema que el API no aceptaría puede resolver aquí distinto. Lo que sí hace, como
  `valid()`, es mover primero lo que el esquema migra (`co.ordering.migrate`).
- `resolve()` no valida el esquema (para eso está `validateFull()`), ni `validate()` el
  meta-esquema (ver arriba).
- No convierte ni proyecta temas v1 (`ThemeV1Bridge`), no optimiza imágenes ni trae
  los ajustes del sitio (`values.site`): eso es del API.
- Solo lee esquemas en nodos (los registrados en tuplas los convierte api-core).

## Desarrollo

```sh
nvm use            # Node 22.23.1
pnpm install
pnpm test          # vitest: casos dorados, esquema actual, validate(), el comando, semántica de PHP
pnpm typecheck
pnpm build         # dist/: ESM, .d.ts, el validador del meta-esquema y el comando
```

`src/shape-validator.js`, el meta-esquema compilado, no se versiona: lo escriben
`pnpm build` y el arranque de las pruebas (`test/global-setup.ts`). Ajv y esbuild solo
se usan para generarlo; el paquete publicado no depende de nada.

### Refrescar las fixtures

Las fixtures salen de api-core, nunca se escriben a mano. Con el contenedor de
desarrollo de api-core levantado (`docker compose -f docker-compose.dev.yml up -d`):

```sh
pnpm sync-fixtures                          # api-core en ../api-core
API_CORE=/ruta/a/api-core pnpm sync-fixtures
```

El script solo lee api-core (incluido lo que no está commiteado): copia los casos, el
esquema, el mapa v1 y el meta-esquema (a `meta/`), y corre en el contenedor la
conversión a nodos, `scripts/resolve-empty.php`, `scripts/php-round.php`,
`scripts/php-strings.php` y `scripts/validate-cases.php`, que escriben en el `/tmp` del
contenedor o en la salida estándar. Una fixture solo se reemplaza si el comando termina bien y devuelve JSON.
Después, `pnpm test` dice si el motor sigue respondiendo lo mismo que api-core.

## Distribución

Se publica en npmjs, público (decidido el 2026-10-20; antes, en GitHub Packages): quien
lo usa no necesita token ni `.npmrc`. El nombre conserva el scope de la organización,
`@finitless-com`.

### Publicar una versión

1. Subir `version` en `package.json` (semver: un cambio en lo que resuelve es minor, y
   uno que rompe la API pública es major) y mergear a `master`.
2. Crear el tag `v<version>` sobre ese commit y subirlo (`git tag v0.3.0 && git push
   origin v0.3.0`).
3. `.github/workflows/publish.yml` comprueba que el tag coincide con `package.json`,
   corre los tipos y las pruebas, compila y publica con `npm publish --provenance`. Se
   autentica con la publicación de confianza de npm (OIDC): no hay token en los
   secretos. Una versión publicada no se reemplaza: si ya existe, el workflow falla.

La primera versión en npmjs también sale del workflow, pero con el secreto `NPM_TOKEN`
del repo (un token granular de npm con permiso de publicar en `@finitless-com`): npm
solo deja configurar el publicador de confianza (*Settings → Trusted publishing*: este
repo y `publish.yml`) en un paquete que ya existe. Configurado, el secreto se borra y npm
publica por OIDC.

`.github/workflows/ci.yml` corre los tipos, las pruebas y la compilación en cada PR y
en cada push a `master`.

### Pasar de GitHub Packages a npmjs

Un repo que lo instalaba de GitHub Packages quita del `.npmrc` del proyecto la línea
`@finitless-com:registry=https://npm.pkg.github.com` (si no, sigue buscando el paquete
ahí), el token de `~/.npmrc` si no lo usa para nada más y, en GitHub Actions, el
`registry-url`/`scope` de `actions/setup-node` y `permissions: packages: read`.
