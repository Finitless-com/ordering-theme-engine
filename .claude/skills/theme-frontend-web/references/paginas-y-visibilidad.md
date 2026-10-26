# Páginas, secciones, visibilidad, imágenes y ajustes del sitio

## 1. Qué va dónde

| Es… | Va en | Ejemplo |
|---|---|---|
| Un **bloque** de una página (se oculta o se reordena dentro de la página) | `pages.<página>.sections` | `home.sections: hero, featured, promo_banners` |
| Una **pieza** dentro de un bloque, o de un componente que aparece en varias páginas | `visibility.<clave>` | `header.cart`, `business_card.fee` |
| Pura decoración (color, tamaño, borde de algo que siempre está) | `components.*` | `business_card.base.logo_size` |
| Una imagen que el tema puede cambiar | `images.<clave>` | `home_hero`, `empty.orders` |
| Algo del **sitio** que no cambia al cambiar de tema (nombre, textos HTML, códigos de terceros, modo) | `site` | `site.name`, `site.third_party_code.head` |
| Un texto de interfaz | i18n, no el tema | títulos, botones |

Un elemento tiene un solo interruptor. Una clave con default `false` sustituye a una
constante fija `hide*` o `show*` del código. Lo que cambia por dispositivo es el **valor**
de la clave (`platforms.mobile_web`), no una segunda clave.

## 2. Páginas y secciones

Cada página del editor es una ruta o grupo de rutas con layout propio. Su nodo tiene
exactamente `footer` y `sections` (schema-format.md §8).

```json
"home": {
  "footer": { "$type": "boolean", "$value": null },
  "sections": { "$type": "sections", "$value": [
    { "key": "hero",          "visible": true, "hideable": false, "group": null },
    { "key": "featured",      "visible": true, "hideable": true,  "group": "main" },
    { "key": "promo_banners", "visible": true, "hideable": true,  "group": "main" }
  ],
  "$extensions": { "co.ordering.editor": {
    "control": "sortable_sections",
    "section_groups": [{ "key": "main", "label": { "key": "BUILDER_THEME_SECTION_GROUP_MAIN", "default": "Under the banner" } }]
  } } }
}
```

- **Orden de la lista** = orden por defecto.
- **`group`**: la sección solo se mueve entre las de su grupo, que son hermanas en un mismo
  contenedor (columna principal y lateral del checkout, por ejemplo). Con `null` queda fija
  en su sitio. Un grupo necesita al menos dos secciones.
- **`hideable: false`**: la página no funciona sin ella (entonces `visible: true`). El
  editor la muestra con candado.
- **`footer`**: `null` sigue a `visibility.footer`; `true`/`false` lo fuerzan en esa página.
- El nodo `sections` declara en el editor `control: sortable_sections`, las etiquetas de
  cada sección (`options`) y `section_groups` con exactamente los grupos que usa, en orden
  de aparición.
- En el código, la página pinta sus secciones **en el orden del tema** con un hook
  (`usePageSections(page).render({...}, { group })`, ver `runtime-web.md`). Una sección del
  schema sin renderer, o un renderer sin sección, es un error.

**No se puede ocultar ni mover:**

- lo que tiene layout absoluto o a sangre y asume estar arriba;
- un scroll infinito (con secciones debajo, la paginación se retrasa);
- lo necesario para pagar o completar el flujo;
- el orden que impone el backend;
- las filas de una tabla o de una factura (falsearía el total);
- los elementos legales y globales;
- los precios, el "agotado" y otros datos reales;
- los botones de marca de terceros.

## 3. Visibilidad

- **Claves planas con puntos**, hasta 8 segmentos: `<página|componente|zona>.<pieza>`,
  por ejemplo `header.cart`, `business.header.reviews`, `checkout.business.phone`,
  `confirmation.driver.photo`. Una clave sin punto es el interruptor maestro de algo
  (`bottom_nav`, `footer`).
- **Siempre booleanas**, default `true` (se ve), salvo lo que hoy está oculto.
- **Default por plataforma** en `co.ordering.platforms: { "mobile_web": false }`, solo en
  claves de visibilidad.
- **Editor:** cada clave en **exactamente un** grupo del editor (`in_no_editor_group`,
  `in_several_editor_groups`), por zona (header, menú, card de negocio, checkout…). Un grupo
  puede mostrarse dentro de la página o del componente al que pertenece (`pages` o
  `components` en el grupo).
- **Dependencias:** usa `disabled_if` (no se puede ocultar el contador si el carrito ya
  está oculto) e `info` con la consecuencia ("sin menú no se llega al perfil").
- **Controles que cambian opciones del pedido** (tipo, momento, dirección) se tematizan,
  pero no se ocultan.
- **Accesibilidad:** ocultar un `h1` lo deja solo para lectores de pantalla, no lo borra.
- **Robustez del código:**
  - el helper devuelve `true` para una clave desconocida;
  - nada de `?? true` encima del default;
  - nada de typos en la clave (`keys.mjs` lo detecta);
  - una clave no oculta más de lo que dice.

## 4. Imágenes

Cada clave de `images` lleva `formats` y `hints` (schema-format.md §7):

- **`size: [w, h]` ideal:** el mayor tamaño al que se pinta × 2 (pantallas 2x), o × 3 si
  mide ≤ 100 px. El ideal no es el tamaño actual del archivo.
- **`ratio`:** la relación de la caja. Si la caja recorta (`cover`), usa una relación
  intermedia entre las de los breakpoints e indica la **zona segura** en `safe`.
- **`alpha`:** si necesita transparencia (logos, ilustraciones de estados vacíos).
- **`max_kb`:** estricto en imágenes pequeñas y repetidas, holgado en héroes. Hasta 5000.
- **`formats`:** por defecto jpg, png, webp y avif; svg para logos; ico/png/svg para favicon.

Reglas que conviene fijar en el inventario:

- los logos nunca se deforman: `contain`, con la caja definida por la altura;
- un mismo logo se pinta con la misma caja en todas partes;
- los estados vacíos usan una caja fija con `contain`;
- un marcador de mapa es cuadrado, anclado abajo al centro.

Una clave vacía (`""`) usa la imagen del bundle. Ese fallback vive en un único módulo del
código; el schema no lo copia.

Organiza las imágenes en grupos del editor: logos (logotipo, isotipo y sus versiones
inversas), iconos (favicon, icono de app), banners, estados vacíos, placeholders y mapas.

## 5. Ajustes del sitio (`site`)

Lo que pertenece al sitio y no al tema:

- nombre y descripción, con `max_length`;
- HTML de home y footer (`html`);
- código de terceros en head y body;
- modos de operación (`option`);
- IDs de apps con su `pattern`.

Cada nodo declara `$type`. El editor lo muestra en una sección con `store: "site"`.
Algunos campos se calculan (`mode`, slugs): decláralos solo para tiparlos (schema-format.md
§9).

## 6. Teléfono

Una plataforma `mobile_web` (por debajo de un único breakpoint, por ejemplo 576 px)
permite valores distintos en:

- propiedades de `base` de componentes (`platforms.mobile_web` del componente);
- claves de visibilidad;
- páginas (el tema puede guardar `platforms.mobile_web.pages`).

Usa **un** breakpoint en todo el código. En el marketplace había 576 en JS y 567 en CSS, y
fue un bug.
