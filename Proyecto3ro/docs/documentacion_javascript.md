# Documentación JavaScript — Arquitectura Modular

[← Volver al documento maestro](mockups_documentacion.md)

## 1. Principios de la arquitectura

- **Vanilla JavaScript ES6+**, sin frameworks ni librerías. **Sin jQuery.**
- **Separado del HTML:** no existe ningún `onclick`/`onsubmit` inline ni
  `<script>` embebido con lógica. Todo el comportamiento se declara con
  atributos `data-*` y se conecta desde los módulos.
- **Modular por responsabilidad:** un archivo por dominio funcional.
- **Espacio de nombres único `window.FA`:** definido en `app.js`; el resto de
  módulos cuelgan de él o usan sus utilidades. Evita variables globales sueltas.
- **Inicialización centralizada:** cada módulo registra su `init` con
  `FA.ready(fn)`; `app.js` ejecuta la cola en `DOMContentLoaded`, capturando
  errores para que un módulo no rompa al resto.
- **Detección de características:** cada módulo verifica que existan sus
  elementos antes de actuar, por lo que es seguro cargar de más.
- **Delegación de eventos** para acciones comunes (modales, confirmaciones,
  estados), de modo que funcionen incluso con contenido añadido dinámicamente.
- **Comunicación por eventos:** `resultados.js` emite el evento
  `fa:resultado-cargado`, al que reaccionan `liga.js` y `suizo.js`.

### Orden de carga
`app.js` **siempre primero**. Luego, según la página, los módulos necesarios.
Ejemplo (gestión Suizo):
```html
<script src="js/app.js"></script>
<script src="js/sidebar.js"></script>
<script src="js/modals.js"></script>
<script src="js/confirm.js"></script>
<script src="js/forms.js"></script>
<script src="js/suizo.js"></script>
<script src="js/resultados.js"></script>
```

---

## 2. `app.js` — Núcleo

**Propósito:** crear el namespace `FA`, utilidades compartidas, sistema de flash
y orquestar la inicialización.

**API expuesta (`window.FA`):**

| Función | Descripción |
|---------|-------------|
| `FA.$(sel, ctx?)` | `querySelector` abreviado. |
| `FA.$$(sel, ctx?)` | `querySelectorAll` como **array**. |
| `FA.on(el, evt, fn, opts?)` | `addEventListener` seguro (ignora nulos). |
| `FA.ready(fn)` | Encola una función para `DOMContentLoaded`. |
| `FA.flash(msg, type?, timeout?)` | Muestra un toast (`info`/`success`/`warning`/`danger`). |
| `FA.normalize(txt)` | Minúsculas + sin acentos (para búsquedas). |
| `FA.debounce(fn, ms)` | Limita la frecuencia de ejecución. |

**Eventos que escucha:** `DOMContentLoaded` (arranque); clic en `.flash-close`
(cerrar toast).

**Interacción / flujo:** al cargar, ejecuta todos los `init` registrados. Cuando
otro módulo llama `FA.flash(...)`, se crea (una vez) el contenedor `.flash-stack`
y se inserta un toast que entra animado y se autodescarta tras el *timeout* (o
al pulsar ×).

---

## 3. `sidebar.js` — Navegación lateral responsive

**Propósito:** abrir/cerrar el sidebar en mobile y gestionar el menú táctil.

**Funciones:** `open()`, `close()`, `toggle()`.

**Eventos que escucha:**
- `click` en `[data-sidebar-toggle]` (hamburguesa) → `toggle()`.
- `click` en el overlay → `close()`.
- `keydown` Escape → `close()`.
- `click` en cada `.side-link` → cierra si el ancho ≤ 980px.
- `resize` (con `debounce`) → cierra al volver a desktop.

**Interacciones:** crea dinámicamente el `.sidebar-overlay`; añade/quita la clase
`.open` al sidebar y `.show` al overlay; bloquea el scroll de fondo mientras
está abierto; sincroniza `aria-expanded`.

**Flujo de usuario:** en mobile el usuario toca la hamburguesa → el sidebar
entra desde la izquierda con un overlay; toca un enlace, el overlay o Escape →
se cierra.

---

## 4. `modals.js` — Sistema de modales reutilizable

**Propósito:** abrir/cerrar cualquier modal de forma declarativa.

**API:** `FA.modal.open(id)`, `FA.modal.close(id)`, `FA.modal.closeAll()`.

**Marcado:** `[data-modal-open="idModal"]` abre; `[data-modal-close]` cierra;
clic en el `.modal-overlay` (fuera de la tarjeta) cierra; Escape cierra todo.

**Eventos que escucha:** `click` (delegado en `document`) y `keydown` Escape.

**Interacciones:** alterna la clase `.open` y `aria-hidden`; mueve el foco al
primer control del modal al abrir y lo devuelve al disparador al cerrar.

**Flujo:** clic en un botón con `data-modal-open` → aparece el modal centrado;
Cancelar / clic fuera / Escape → se cierra.

---

## 5. `confirm.js` — Confirmaciones de acciones críticas

**Propósito:** interceptar acciones peligrosas y pedir confirmación con un modal
propio (no el `confirm()` nativo).

**Acciones cubiertas:** generar fixture, generar bracket, generar ronda Suiza,
cargar resultado, corregir resultado, **finalizar torneo**, activar/desactivar.

**Marcado:**
```html
<button data-confirm="¿Generar la ronda 6?"
        data-confirm-type="danger|primary"
        data-confirm-ok="Generar"
        data-flash="Ronda generada">…</button>
```
También funciona sobre `<a href>` (navega tras confirmar).

**Funciones:** `buildDialog()` (crea el modal una vez), `show(trigger)`,
`hide()`, `cancel()`, `accept()`.

**Eventos que escucha:** `click` delegado en `[data-confirm]` (hace
`preventDefault` hasta confirmar); clic en aceptar/cancelar/overlay.

**Interacciones / flujo:** al pulsar una acción crítica, aparece el diálogo con
el mensaje y un botón cuyo color depende de `data-confirm-type`. Si el usuario
acepta: muestra el flash de `data-flash` y, si era un enlace, navega; si cancela:
no ocurre nada.

---

## 6. `forms.js` — Formularios y validación visual

**Propósito:** medidor de contraseña, validación de requeridos y campos
condicionales por formato.

**Funciones / responsabilidades:**

1. **`initPasswordStrength()`** — evalúa en vivo las reglas de la contraseña.
   - *Marcado:* `[data-password]`, `[data-password-confirm]`,
     `[data-password-hints] li[data-rule]`, `[data-password-submit]`.
   - *Eventos:* `input` en los campos de contraseña.
   - *Interacción:* marca cada regla `rule-ok`/`rule-pending` y habilita el botón
     solo cuando se cumplen todas (longitud, mayúscula, minúscula, número,
     símbolo y coincidencia).

2. **`initRequiredValidation()`** — valida formularios `form[data-validate]`.
   - *Eventos:* `submit` del formulario; `input` para limpiar el error.
   - *Interacción:* resalta los `[required]` vacíos con `.field-error`, frena el
     envío y avisa con flash. Si el form es `data-mock`, en vez de enviar:
     muestra el flash de `data-flash`, o redirige si tiene `data-redirect`
     (login), y cierra el modal padre si está dentro de uno.

3. **`initConditionalFields()`** — muestra/oculta campos según el formato.
   - *Marcado:* `[data-format-select]` y `[data-format-field][data-format-show="liga,suizo,…"]`.
   - *Eventos:* `change` del select.
   - *Interacción:* muestra solo los paneles del formato elegido y deshabilita
     los inputs ocultos (para que no afecten el envío).

**Flujo de usuario:** en *Registro* la contraseña se valida tecla a tecla; en
*Nuevo torneo* cambiar el formato revela los campos pertinentes (puntos para
Liga, rondas para Suizo, nota de byes para Eliminación); en *Login* el envío
válido "inicia sesión".

---

## 7. `tables.js` — Búsqueda y ordenamiento en tablas

**Propósito:** filtrar filas en vivo y ordenar por columnas.

**Funciones:** `initSearch()`, `initSort()`, `cellValue(row, i, type)`.

**Marcado:** `input[data-table-search="#idTabla"]`; encabezados
`th[data-sort]` (texto) o `th[data-sort="number"]` (numérico).

**Eventos que escucha:** `input` en el buscador (con `debounce`); `click` en los
encabezados ordenables.

**Interacciones:** la búsqueda oculta filas que no coinciden (texto normalizado
sin acentos) y muestra una fila "Sin coincidencias"; el orden alterna
ascendente/descendente y dibuja el indicador `↑/↓` en la columna activa.

**Flujo:** se usa en Torneos (admin), Participantes, Equipos, Organizadores y
Auditoría.

---

## 8. `torneos.js` — Filtros del listado público

**Propósito:** filtrar las tarjetas de torneo sin recargar la página.

**Marcado:** `[data-torneos-grid]` con cards `[data-tipo][data-estado][data-nombre]`;
selects `[data-torneo-filter="tipo|estado"]`; `input[data-torneo-search]`;
`[data-torneos-count]`.

**Función principal:** `apply()` — combina formato + estado + búsqueda y oculta
las cards que no cumplen.

**Eventos:** `change` en los selects; `input` (con `debounce`) en el buscador.

**Interacciones:** actualiza el contador de resultados y muestra un mensaje
"Ningún torneo coincide…" cuando no hay coincidencias.

**Flujo:** el visitante elige formato/estado o escribe un nombre → la grilla se
filtra al instante.

---

## 9. `resultados.js` — Carga y corrección de resultados

**Propósito:** poblar y validar los modales de resultado.

**Marcado de disparadores:**
```html
<button data-result-load data-jugador-a="…" data-jugador-b="…">Cargar</button>
<button data-result-fix  data-jugador-a="…" data-jugador-b="…"
        data-puntos-a="1" data-puntos-b="0">Corregir</button>
```
Modales `#modalCargar` / `#modalCorregir` con campos `data-titulo`,
`data-label-a/b`, `data-input-a/b`, `data-input-motivo`, y botones
`data-confirm-cargar` / `data-confirm-corregir`.

**Eventos que escucha:** `click` en los disparadores y en los botones de
confirmación de cada modal.

**Interacciones:** precarga nombres/puntajes en el modal correspondiente y lo
abre vía `FA.modal`; al confirmar una **corrección**, exige un motivo de ≥ 10
caracteres (si no, resalta el textarea y avisa). Tras confirmar, emite el evento
`fa:resultado-cargado` y muestra un flash.

**Flujo:** el organizador/admin pulsa "Cargar"/"Corregir" → el modal aparece con
los datos del cruce → confirma → la tabla/ranking se actualiza (ver módulos
liga/suizo).

---

## 10. `eliminacion.js` — Scroll horizontal del bracket

**Propósito:** desplazamiento inteligente del bracket de Eliminación Directa.

**Marcado:** `.bk-scroll[data-bracket]`.

**Funciones:** `setupBracket(scroller)`, `makeNavButton()`, `smoothScroll()`,
`updateButtons()`.

**Eventos que escucha:** `mousedown/mousemove/mouseup/mouseleave` (arrastre),
`wheel` (rueda → horizontal, `passive:false`), `keydown` (flechas ← →),
`click` en los botones ‹ ›, y `scroll`/`resize` para actualizar los botones.

**Interacciones:** arrastrar mueve el bracket (cursor `grab`/`grabbing`); la
rueda lo desplaza horizontalmente; los botones laterales aparecen/ocultan según
si hay contenido a izquierda/derecha.

**Flujo:** en *Detalle de torneo* el usuario recorre rondas anchas sin barra de
scroll visible, con varias formas de interacción.

---

## 11. `liga.js` — Tabla de posiciones (Liga)

**Propósito:** reordenar y renumerar la tabla de posiciones en vivo.

**Marcado:** `table[data-liga-tabla]` con filas `tr[data-pts]` y celda
`[data-pos]`.

**Función:** `recalcular()` — ordena por puntos (desc) y renumera la posición;
marca líder/podio.

**Eventos que escucha:** `fa:resultado-cargado` (emitido por `resultados.js`).

**Interacciones / flujo:** al confirmar un resultado en la gestión Liga, la
tabla se reordena y un flash informa "Tabla de posiciones actualizada".

---

## 12. `suizo.js` — Sistema Suizo

**Propósito:** pestañas de ronda + ranking en vivo.

**Funciones:** `initRondaTabs()` y `initRanking()` (con `recalcular()` interno).

**Marcado:** pestañas `[data-ronda-tabs] > [data-ronda-tab="N"]` y paneles
`[data-ronda-panel="N"]`; ranking `table[data-suizo-ranking]` con filas
`tr[data-pts][data-buchholz]` y celda `[data-pos]`.

**Eventos que escucha:** `click` en las pestañas; `fa:resultado-cargado`.

**Interacciones:** cambiar de pestaña muestra las partidas de esa ronda; el
ranking se ordena por puntos y, a igualdad, por **Buchholz**, renumerando
posiciones. Un flash informa la actualización.

---

## 13. `dashboard.js` — Animaciones y gestión de estados

**Propósito:** contadores KPI animados y cambios de estado visual.

**Funciones:** `initCounters()` + `animate(el)`; `initStateActions()` +
`updateBadge()`.

**Marcado:**
- KPIs: `strong[data-count="N"]` (opcional `data-count-prefix` / `data-count-suffix`).
- Estados: `button[data-state-action="aprobar|rechazar|activar|desactivar"]`
  con `data-flash`; objetivo opcional `[data-state-badge]` o contenedor
  `[data-state-item]`.

**Eventos que escucha:** `IntersectionObserver` (anima el KPI al entrar en
pantalla, una sola vez); `click` delegado en `[data-state-action]`.

**Interacciones:**
- Los KPIs cuentan de 0 al valor objetivo con *easing* (easeOutCubic),
  conservando prefijos/sufijos (🏆, %).
- Aprobar/Rechazar oculta la tarjeta con animación; Activar/Desactivar cambia el
  texto y color del badge en vivo. Siempre acompañado de un flash.

**Flujo:** en los dashboards los KPIs "suben" al cargar; en participantes,
organizadores, equipos y usuarios, las acciones de estado dan feedback inmediato
sin recargar.

---

## 14. Tabla resumen módulo ↔ pantallas

| Módulo | Pantallas donde actúa |
|--------|-----------------------|
| `app.js` | **Todas** |
| `sidebar.js` | Todas las de panel (05–16) |
| `modals.js` | 07, 09, 12, 14, 15 |
| `confirm.js` | 07, 08 (y disponible en todas las de panel) |
| `forms.js` | 03, 04, 07, 09, 12, 14, 15, 16 |
| `tables.js` | 06, 08, 09, 10, 15 |
| `torneos.js` | 02 |
| `resultados.js` | 07, 12 |
| `eliminacion.js` | 17 (y 07 vía enlace) |
| `liga.js` | 12 |
| `suizo.js` | 07 |
| `dashboard.js` | 01, 05, 08, 11, 13, 15 |

[← Volver al documento maestro](mockups_documentacion.md)
