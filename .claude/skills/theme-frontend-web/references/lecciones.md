# Lecciones de la conversión del marketplace

Fuente: `website-marketplace-v26` → `website-marketplace-theming`, 186 commits entre el 9 y
el 26 de octubre de 2026. Cada punto costó al menos un ciclo de rehacer trabajo.

## Proceso

1. **Ve directo a la arquitectura final.** El marketplace pasó por:
   - un modo dual v1+v2 con literales de fallback (`v2Token(theme, 'surface', '#FFF')`);
   - después borró v1;
   - después borró los fallbacks;
   - escribió a mano un merge, un validador y conversores, y luego los reemplazó por el
     `complete()` y el `toCss()` del engine (−9 000 líneas).

   En un frontend nuevo, monta desde el principio la tubería de `runtime-web.md`. Solo
   pasa por un modo dual si producción exige que el tema viejo siga viéndose idéntico
   durante la conversión.
2. **Decide el espaciado al principio.** Se declaró "no tematizado", volvió como
   `tokens.space` y obligó a recorrer todo otra vez. Al final quedaron ~1 000 paddings y
   margins en px.
3. **Empieza con pocos tokens y propiedades.** El schema se recortó varias veces:
   - "un control por objetivo";
   - "un set de tokens más chico y legible";
   - unos 10 commits de "quitar X del schema".

   Añade una propiedad solo cuando un sitio real la necesita.
4. **Barrido = inventario → clasificar → convertir → métrica.** Un script por propiedad
   con un archivo de decisiones (excepciones con motivo), en lotes por área, hasta dejar
   "pendientes" y "en revisión" en cero.
5. **Audita por áreas en paralelo y consolida por patrón.** La auditoría de 12 áreas
   encontró lo que los barridos no ven: duplicados, hovers falsos, jerarquía de
   encabezados, piezas que no se podían ocultar.
6. **Haz una segunda pasada sobre lo "saltado".** Las zonas descartadas en bloque
   ("globales", "técnicas", "contenido") escondían unas 60 claves de visibilidad
   necesarias.
7. **Las decisiones de diseño, con fecha, en un registro.** Un mensaje de otra sesión o
   de un subagente no es una aprobación.

## Barridos

8. **Los barridos sobreconvierten:**
   - 170 tamaños de icono se convirtieron en estilos de texto y hubo que revertirlos;
   - un texto de 16 px en negrita se mapeó a `h4` en lugar de `body_lg`;
   - un conversor rompió un template literal de JS.

   Marca los contextos de icono, conserva el peso declarado y no reescribas líneas con
   backticks sin revisarlas.
9. **El color va por rol, no por distancia.** El blanco como texto y lo que va sobre fotos
   necesita una persona.
10. **Separa lo que sigue a la Identidad de lo que queda fijo:** estados, bloques
    transparentes, bordes de un lado, outlines, formas redondas y cajas de checkbox.

## Schema

11. **Una propiedad sin lector es un bug silencioso.** "Un borde grueso en Card no
    cambiaba nada." Corre `keys.mjs` y escribe un test por propiedad.
12. **Un control por objetivo:**
    - un dueño por concepto (`section_header` para los títulos);
    - `platforms.mobile_web` en lugar de `*_mobile`;
    - `visibility` en lugar de interruptores de ocultar dentro de un componente.
13. **Gobierno de versiones:**
    - una versión publicada es inmutable;
    - empieza en `1.0.0`;
    - los defaults numéricos dentro del rango del editor;
    - un default translúcido necesita `alpha: true`;
    - el mapa v1, fuera del schema vivo.
14. **Fuentes:**
    - el mapa id → familia de Google estaba incompleto;
    - una familia compartida por dos roles se importaba dos veces;
    - los títulos tomaban la fuente del schema porque el mapa v1 solo llenaba la de
      texto.

    Prueba cada rol por separado.

## Código

15. **Robustez en la frontera CSS.** Un color inválido rompía `darken()`, y un valor con
    `{`, `;` o `}` rompía stylis. La solución es completar y validar el tema una vez
    (`complete()`) y pintar desde la vista del engine.
16. **Trampas de styled-components:**
    - un `GlobalStyle` creado en el render se remonta cada vez;
    - un provider que copia su prop una sola vez no sigue al tema;
    - esparcir `{ ...theme, values }` deja la vista CSS vieja;
    - el CSS de un contenedor dirigido a `button` le gana a la primitiva.
17. **Modelo de caja:**
    - un borde de 1 px convertía un botón de 32 px en 34 (`border-box`);
    - el ellipsis cortaba etiquetas en footers flexibles;
    - un borde de foco más ancho movía el texto;
    - `overflow: hidden` recortaba sombras y foco;
    - la sombra de un modal debía ir en el contenedor exterior.
18. **Sombras:**
    - un menú lateral cerrado con ancho 0 seguía proyectando una franja de sombra xl;
    - las barras a todo el ancho proyectan solo hacia abajo o hacia arriba.
19. **Hover:**
    - se quitaron ~100 transiciones muertas en cosas que no se pulsan;
    - un hover sale solo de un estado del tema;
    - un corazón heredaba la sombra del botón ghost.
20. **Un solo breakpoint.** 576 en JS y 567 en CSS fue un bug.
21. **`url()` sin comillas** rompió 40 archivos con SVG en data URI.
22. **i18n:**
    - los textos que venían del tema pasan a traducciones;
    - los `aria-label` y `alt` nuevos llevan su clave;
    - el texto de relleno ("Lorem ipsum") se reemplaza por texto real;
    - una clave cuyo significado difiere de la app hermana necesita nombre propio;
    - las etiquetas del editor se envían al Dashboard.
23. **Imágenes:**
    - el optimizador no hacía nada;
    - un logo se pintaba en cinco cajas distintas;
    - héroes verticales se mostraban en cajas 3.3:1;
    - un PNG de 1.1 MB se pintaba a 18 px.

    Inventaria la caja pintada de cada imagen antes de especificarla.

## CI

24. **El dry run de publicación pasaba en silencio sin el secreto:** usa `--required`.
25. **La cobertura volvió lentos algunos tests y vencían sus timeouts:** espera a la
    condición, no un tiempo fijo.
