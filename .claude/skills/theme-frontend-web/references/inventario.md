# Inventario

El inventario es la base de todo lo demás: lo que no se encuentra aquí acaba como un
literal suelto, una pieza que no se puede ocultar o una imagen fija. Se hace en dos capas:
los scripts encuentran candidatos en el código y los agentes recorren las pantallas.

## 1. Scripts

| Script | Salida | Qué mirar |
|---|---|---|
| `inventory.mjs` | `inventory.json`, `inventory.csv` | Cuántos literales hay por categoría y dónde se concentran |
| `scales.mjs` | `scales.md`, `scales.json` | Cobertura del catálogo, niveles ajustados, valores fuera de escala, familias de color |
| `components.mjs` | `components.md`, `components.json` | Rutas, componentes y estilos propios por función, `styled.button` sueltos, imágenes |

Columnas de `inventory.csv`: `loc, component, selector, category, property, value, norm, fallback`.

- `category`: `color`, `space`, `radius`, `border`, `shadow`, `font_size`, `font_weight`,
  `font_family`, `line_height`, `letter_spacing`, `text_transform`, `size`, `motion`,
  `opacity`, `z_index` o `breakpoint`.
- `norm`: el valor normalizado (`#RRGGBB[AA]`, px o ms).
- `fallback`: `true` cuando el valor va detrás de `||`/`??`. Un fallback nunca se ve si el
  tema llega completo, así que se borra en vez de tokenizarse.
- En el JSON, `icon: true` marca valores dentro de un `svg` o de un icono. Esos son tamaños
  de icono, no tipografía.

Ajusta `--src` y `--exclude` para dejar fuera el código que no es de la app: submódulos,
SDKs, ilustraciones SVG en línea y archivos generados. Anota lo que excluiste en el
inventario.

**Tailwind:** las clases salen como `property: tw:<prefijo>`. Los pasos se convierten con la
escala por defecto de Tailwind (`p-4` = 16 px, `rounded-lg` = 8 px, `text-sm` = 14 px); si
`tailwind.config` redefine `theme`, léelo antes de confiar en esos números. Las clases de
color no llevan hex:

- `tailwind_palette` en `scales.md` agrupa las de la paleta propia de Tailwind
  (`bg-gray-200`), que son literales;
- `tailwind_semantic` agrupa las semánticas (`text-muted-foreground`, típicas de shadcn),
  que ya apuntan a variables CSS y se mapean a un token del catálogo.

Los valores arbitrarios (`p-[13px]`, `text-[#333]`) son literales.

## 2. Auditoría por pantalla (agentes)

Reparte el frontend en áreas que se puedan revisar en paralelo, con 6–12 áreas según el
tamaño. Cada agente recibe su lista de rutas y componentes, este checklist y el formato de
salida. Es de solo lectura.

Cobertura: **todas** las pantallas y componentes que la app puede mostrar, incluidos los que
dependen de:

- flags;
- layouts alternativos;
- sesión iniciada o no;
- estados vacíos, de error y de carga;
- modales, popovers, menús y hojas;
- tamaños de teléfono, tablet y escritorio;
- idioma de derecha a izquierda, si aplica.

Por cada elemento revisa:

- **Tipografía:** familia, tamaño, peso, interlineado, mayúsculas, y si es encabezado
  (`h1`–`h6` real) o texto.
- **Color:** de texto, fondo, borde e icono, y con qué propósito (texto principal o
  secundario, superficie, línea, estado, marca).
- **Espaciado:** padding, margin y gap, y si es separación entre bloques de página, ritmo
  de formulario o interior de componente.
- **Caja:** radio, borde (ancho y lados), sombra y altura de control.
- **Estados:** `hover`, `focus`, `active`/`pressed`, `disabled` y `selected`. Comprueba si
  el hover está en algo que de verdad se puede pulsar.
- **Movimiento:** transiciones, animaciones y duraciones.
- **Imágenes:** origen, caja pintada (ancho × alto en cada breakpoint), `object-fit`,
  fallback y `alt`.
- **Qué se puede ocultar o mover** y qué rompería la página si se quitara.
- **Duplicados:** otro elemento que hace lo mismo con su propio CSS.

### Formato del informe de cada área

```md
## <Área>
### Elementos
| Pantalla / componente | Elemento | archivo:línea | Valores hoy | Función | Propuesta (componente, token o clave) |
### Duplicados (misma función, distinta implementación)
| Función | Implementaciones (archivo:línea) | Diferencias visibles |
### Bloques y piezas
| Página | Bloque o pieza | ¿Ocultable? ¿Movible? | Por qué |
### Imágenes
| Clave o origen | archivo:línea | Caja pintada | Fit | Fallback | alt |
### Fuera de escala o sin token
| Valor | Dónde | Por qué no encaja |
### No verificado
```

Al consolidar, agrupa las propuestas por patrón y cuenta cuántas áreas piden lo mismo. Un
nivel o un componente nuevo se justifica cuando **varias áreas** lo necesitan por separado,
no por un único caso.

## 3. Imágenes

Haz una tabla por origen:

- claves del tema (si ya hay un tema) y quién las lee;
- datos de la API (logos de negocio, fotos de producto): no son del tema;
- assets del bundle importados sin clave;
- SVG en línea o data URI: normalmente iconos, no imágenes;
- URLs externas fijas;
- `public/` y PWA (favicon, iconos de app, manifest).

Hallazgos a registrar:

- claves sin uso;
- imágenes fijas que deberían ser configurables;
- archivos rotos o enormes para su tamaño pintado;
- falta de `alt`;
- duplicados;
- fallbacks incoherentes (un mismo logo pintado en cajas distintas).

Para la especificación de cada clave, ver `paginas-y-visibilidad.md` §4.

## 4. Lo que ya es configurable

Si el frontend ya lee un tema (v1, un JSON de cliente, variables CSS), inventaria cada
clave que lee, con su ruta y su lector. Ese inventario se convierte en el mapa v1 del
schema (`schema.md` §6), y ninguna clave que hoy funciona puede perderse sin una decisión
del usuario.

## 5. Métricas de partida

Guarda los números iniciales para el informe final:

- literales por categoría;
- colores distintos;
- archivos con literales;
- componentes por función;
- `styled.button` y overrides `styled(Button)`;
- imágenes por origen.

El guard con `--write-baseline` deja la línea base por archivo.
