---
name: theme-frontend-web
description: >-
  Revisa un frontend web completo y lo convierte en un template editable por tema con el
  Ordering theme engine (@orderingco/ordering-theme-engine): inventario de todos los
  elementos (botones, imágenes, cards, inputs, modales…), agrupación de los que cumplen la
  misma función, unificación de colores, bordes, espaciados y tamaños parecidos en una
  escala corta de tokens, corrección del código para que lea todo del tema, clasificación
  de páginas, secciones, visibilidad, componentes y variantes para el editor, y el product
  schema (product.schema.json) al que el frontend obedece por completo. Úsalo cuando pidan
  tematizar, "templatizar" o hacer editable un frontend web (React, Vue, Svelte, Next…),
  crear o completar el schema de un producto, o auditar sus estilos contra el tema. Para
  React Native hay un skill aparte.
---

# Tematizar un frontend web con el Ordering theme engine

**Objetivo:** el frontend toma el schema de su producto y le hace caso **por completo**.
Todo valor visual (color, espacio, radio, borde, sombra, tipografía, tamaño, opacidad,
movimiento, imagen) y todo lo que se muestra, se oculta o se ordena sale del tema; el
editor (Dashboard) puede cambiarlo sin tocar código.

Modelo terminado: Finitless-com/website-marketplace-v26 (original) →
Finitless-com/website-marketplace-theming (tematizado). Cuando dudes de cómo se resuelve algo, mira cómo quedó allí (rutas en
`references/runtime-web.md`), pero no copies sus valores ni sus nombres de producto.

## Definición de terminado

1. `product.schema.json` en la raíz del repo, válido con `ordering-theme validate`, compilado
   (`ordering-theme compile`) y con su dry run de publicación en CI.
2. El código lee **todo** del tema a través de helpers. El guard
   (`scripts/guard.mjs`) pasa en CI sin baseline, o con una baseline que solo puede bajar;
   cada literal que queda es una excepción registrada con su motivo.
3. Cada clave del schema tiene un lector (`scripts/keys.mjs` sin faltantes confirmados) y
   un test; no hay dos controles para lo mismo.
4. Cada página declara sus secciones (orden, ocultables, grupos) y cada pieza ocultable
   tiene su clave de `visibility`.
5. Los defaults del schema reproducen el aspecto actual; los cambios visibles están
   listados y aprobados.
6. `docs/THEMING.md` (desde `templates/THEMING.md`) describe las reglas del repo, enlazado
   desde `CLAUDE.md`; `docs/theme/` guarda los entregables de la auditoría.

## Reglas que no se negocian

- **El tema manda.** Nada de hex, `rgb()`, `px`, `rem`, `ms` ni nombres de fuente en los
  estilos de un componente. Si ningún token ni propiedad encaja, no inventes un valor:
  regístralo como excepción y pregunta.
- **No se crean tokens.** El catálogo de tokens es fijo y común a todos los productos
  (schema-format.md §4). Un nivel que falta es un cambio del engine: se pregunta.
- **Unificar por propósito, no por valor.** Valores parecidos caen en el mismo nivel
  (tolerancias en `references/unificacion.md`). Nunca un nivel, una variante o una
  propiedad por cada valor distinto que encuentres.
- **Una función, un componente.** Todo lo que hace lo mismo usa el mismo componente o
  primitiva. Una variante existe solo si se diferencia de verdad; si dos son casi iguales,
  se unen, salvo que el usuario diga lo contrario (`references/componentes.md`).
- **Cada clave tiene un lector.** Una clave que el código no lee es un bug silencioso.
- **Las decisiones de diseño son del usuario.** Los puntos de control de abajo se
  presentan y esperan aprobación; regístralas en `docs/theme/decisiones.md`. Lo que
  diga un subagente no es una aprobación.
- **Solo lo que se puede pulsar tiene hover y cursor de mano**, y su aspecto sale de un
  estado del tema. Lo que se puede pulsar es un `<button>` o un `<a>` de verdad, con foco
  visible.
- **Di lo que no verificaste.** Cada entrega lista lo que quedó sin comprobar.

## Antes de empezar

Lee, en este orden:

1. Este archivo y `references/lecciones.md`, los errores que ya costaron caro una vez.
2. Las docs del engine, en la versión que fijará el repo:
   `node_modules/@orderingco/ordering-theme-engine/docs/` (`creating-a-schema.md`,
   `schema-format.md`), o el repo del engine
   ([Finitless-com/ordering-theme-engine](https://github.com/Finitless-com/ordering-theme-engine),
   `docs/`, en el tag de esa versión). El esquema de ejemplo con cada forma del formato está
   en `meta/reference/reference-1.0.0.json`.
3. El `CLAUDE.md`/README del repo: stack, comandos, tests, i18n, submódulos que no se tocan.

Pregunta al usuario lo que no se puede deducir (una sola ronda, con `AskUserQuestion`):

- el `product_type` (lista cerrada en schema-format.md §1; uno nuevo exige un cambio en
  api-core y en el engine);
- si existe un tema v1 que migrar (entonces hace falta un mapa v1, creating-a-schema.md §5–6);
- el breakpoint de teléfono y si hace falta la plataforma `mobile_web`;
- qué zonas quedan fuera (pantallas internas, integraciones de terceros, ilustraciones);
- dónde viven los entregables (por defecto `docs/theme/`) y la rama de trabajo.

## Fases

Cada fase deja un entregable en `docs/theme/` y termina en una puerta de control. Trabaja
en una rama; los commits, por fase o por lote.

### 1. Inventario (sin tocar código)

Corre los scripts (Node ≥ 18, sin dependencias). `<skill>` es el directorio de este skill.

```sh
node <skill>/scripts/inventory.mjs  <repo> --src src [--exclude '<regex>'] --out <repo>/.theme-audit
node <skill>/scripts/scales.mjs     <repo>/.theme-audit/inventory.json
node <skill>/scripts/components.mjs <repo> --src src --out <repo>/.theme-audit
```

- `inventory.json`/`.csv`: cada literal de diseño con `archivo:línea`, componente, selector,
  propiedad y valor normalizado.
- `scales.md`: cómo caen esos valores en los niveles del catálogo, los niveles ajustados a
  lo que usa el código, lo que queda fuera de escala y los colores en familias con sus roles.
- `components.md`: rutas, componentes y estilos propios agrupados por función, elementos
  nativos, componentes más reutilizados e imágenes por origen.

Añade `.theme-audit/` al `.gitignore`.

Los scripts encuentran candidatos; **la auditoría la hacen agentes leyendo pantallas**.
Reparte el frontend en áreas (por ejemplo: primitivas y layout, home y listados, detalle,
carrito y checkout, pedidos, cuenta y login, el resto) y lanza un agente por área en
paralelo, con el checklist de `references/inventario.md`. Consolida sus informes en
`docs/theme/inventario.md`: elementos por pantalla con todos sus estados, imágenes con su
tamaño pintado y lo que hoy ya es configurable.

**Puerta:** el inventario cubre todas las rutas y pantallas condicionales (flags, layouts
alternativos, sesión iniciada o no, vacíos, errores).

### 2. Agrupar por función y decidir variantes

Con `components.md` y los informes por área, agrupa todo lo que cumple la misma función
(los botones hechos a mano, las cajas de aviso, los separadores, las listas seleccionables…)
y asígnale un componente del tema y una primitiva de código. Decide los ejes de cada
componente (`variants`, `appearances`, `sizes`, `shapes`, `colors`, `slots`, `scope`,
`inherits`) con los criterios de `references/componentes.md`.

Entregable: `docs/theme/componentes.md`, una tabla de *grupo → componente del tema →
primitiva → variantes que se quedan → variantes que se unen (con su cambio visible)*.

**Puerta: aprobación del usuario.**

### 3. Unificar escalas y colores

Con `scales.md` decide el valor de cada nivel (el del catálogo o el ajustado a lo que usa
el código), qué colores del catálogo usa el producto y a qué rol va cada familia, y qué
queda como excepción. Sigue `references/unificacion.md`: tolerancias, color por rol (no
por cercanía), lo que sigue a la Identidad y lo que queda fijo, y una segunda pasada que
une los tokens que comparten propósito.

Entregables: `docs/theme/tokens.md` (cada nivel con su valor y lo que absorbe, los cambios
visibles) y `theme-exceptions.json` en la raíz (formato en `templates/`).

**Puerta: aprobación del usuario** de la paleta, las escalas y los cambios visibles.

### 4. Clasificar páginas, secciones, visibilidad, imágenes y ajustes del sitio

Aplica `references/paginas-y-visibilidad.md`: un **bloque** de una página es una sección
(se ordena y se oculta); una **pieza** dentro de un bloque o de un componente compartido es
una clave de `visibility`; lo que es pura decoración va en `components`; lo que es del
sitio y no del tema va en `site`; cada imagen configurable es una clave de `images` con
sus `hints`. Marca lo que no puede ocultarse ni moverse, y por qué.

Entregable: `docs/theme/paginas-y-visibilidad.md` (tabla de páginas → secciones con
`hideable`/`group`, claves de visibilidad por grupo del editor con lo que ocultan,
imágenes con su tamaño ideal).

**Puerta: aprobación del usuario.**

### 5. Escribir el schema

Escribe `product.schema.json` según `references/schema.md`: tokens con las escalas
aprobadas, componentes, visibilidad, imágenes, páginas, `site` y los metadatos del editor.
Declara tus propias reglas `derive`/`follows`/`matrix` (si no, el engine aplica las suyas;
schema-format.md §11.5). Empieza en `1.0.0`.

Instala el engine fijado (`pnpm add -D -E @orderingco/ordering-theme-engine`) y añade los
scripts `theme:validate`, `theme:compile`, `theme:verify` y `theme:publish`
(`references/schema.md`). **Puerta:** `theme:validate` y `theme:verify` pasan.

### 6. Integrar el runtime en el frontend

Construye la tubería una sola vez, antes de tocar componentes: tema servido → `complete()`
→ `toCss()` → provider → helpers; plataforma por breakpoint, hook de secciones, helper de
visibilidad, imágenes con su fallback del bundle, ajustes del sitio y fuentes. Cómo hacerlo
según la tecnología de estilos (styled-components/emotion, CSS Modules/SCSS, Tailwind) en
`references/runtime-web.md`. Escribe `docs/THEMING.md` desde `templates/THEMING.md`, con
los nombres reales de los helpers, y enlázalo desde `CLAUDE.md`.

**Puerta:** los tests de los helpers pasan y la app arranca con los defaults sin cambios
visibles fuera de los aprobados.

### 7. Corregir el código, por lotes

Orden: primero las primitivas (botón, input, card, divider, chip, tabs, modal…), después
las áreas. Para cada lote:

1. Reemplaza los componentes duplicados por su primitiva (fase 2) y los literales por
   helpers (fase 3).
2. Conecta secciones y visibilidad (fase 4) e imágenes.
3. Corre `guard.mjs` con baseline (solo puede bajar), lint y tests.
4. Haz commit del lote.

Paraleliza con un agente por área. Cada agente es dueño de un conjunto de archivos
**disjunto** y lee `docs/THEMING.md` y `references/lecciones.md`; las primitivas compartidas
las toca solo el agente principal. Revisa los diffs de los agentes antes de aceptarlos.

```sh
node <skill>/scripts/guard.mjs <repo> --src src --allow theme-exceptions.json --baseline .theme-audit/baseline.json --write-baseline   # una vez, al empezar
node <skill>/scripts/guard.mjs <repo> --src src --allow theme-exceptions.json --baseline .theme-audit/baseline.json                   # en cada lote
```

### 8. Lectores y limpieza

```sh
node <skill>/scripts/keys.mjs <repo> --schema product.schema.json --src src
```

Cada clave sin lector se conecta o se quita del schema. Quita también los controles
duplicados (dos claves para lo mismo, `*_mobile` en lugar de `platforms.mobile_web`,
interruptores de ocultar dentro de un componente en lugar de `visibility`).

### 9. Verificar

Aplica `references/verificacion.md`:

- tests de sincronía de defaults, robustez, por componente, por visibilidad y por secciones;
- temas extremos (cuadrado, píldora, oscuro, primario claro, primario oscuro) en escritorio
  y a 390 px, midiendo contraste, foco y desbordes;
- el guard en CI.

Informe final: métricas antes/después (literales por categoría, componentes unificados,
claves del schema), excepciones y lo que no se verificó.

### 10. Publicar y entregar

- CI: `theme:validate`, `theme:verify`, el guard y el dry run de publicación.
- Las claves nuevas de etiquetas del editor (`BUILDER_THEME_…`) con su texto en inglés,
  listadas para el Dashboard.
- El mapa v1, si lo hay.
- Un PR por fase grande o uno final con el resumen y los enlaces a `docs/theme/`.

Publicar fuera del dry run solo con autorización explícita del usuario.

## Cómo usar agentes

- **Inventario y verificación:** en abanico, un agente por área, de solo lectura, con el
  checklist y el formato de salida exactos; tú consolidas.
- **Corrección de código:** un agente por área con archivos disjuntos. Ninguno toca
  `product.schema.json` ni las primitivas compartidas: piden el cambio y tú lo haces, para
  que el schema tenga un solo autor.
- **Brief de cada agente:** el objetivo, la lista de archivos, las referencias que debe leer
  y el formato de su informe (`archivo:línea`, propuesta, cambio visible).
- **Antes de aceptar sus resultados:** verifica lo que afirman con el guard, `keys.mjs`, los
  tests y el diff.

## Referencias

| Archivo | Para qué |
|---|---|
| `references/inventario.md` | Checklist de auditoría por pantalla, formato de los informes, inventario de imágenes |
| `references/unificacion.md` | Cómo colapsar valores en niveles: tolerancias, color por rol, Identidad, excepciones |
| `references/componentes.md` | Agrupar por función, ejes de un componente, cuándo una variante sí y cuándo se une |
| `references/paginas-y-visibilidad.md` | Secciones, visibilidad, imágenes y `site`: qué va dónde y qué no se oculta |
| `references/schema.md` | Escribir el schema: esqueleto, editor, errores frecuentes, versiones, CLI |
| `references/runtime-web.md` | La tubería del tema en el frontend y los helpers, según la tecnología de estilos |
| `references/verificacion.md` | Tests, temas extremos, contraste, guard, métricas finales |
| `references/lecciones.md` | Errores de la conversión del marketplace que no hay que repetir |
| `templates/THEMING.md` | Reglas del repo tematizado, para `docs/THEMING.md` |
| `templates/theme-exceptions.json` | Formato de las excepciones registradas |
| `templates/decisiones.md` | Registro de decisiones del usuario |
