# Documentación CSS — Sistema de Diseño FlexArena

[← Volver al documento maestro](mockups_documentacion.md)

Toda la maquetación usa una **única hoja de estilos compartida**: `_shared.css`.
No se usan frameworks (sin Bootstrap, Tailwind ni similares). Solo CSS moderno:
variables personalizadas, Grid, Flexbox y `clamp()`.

---

## 1. Sistema de diseño

### 1.1 Paleta de colores y variables CSS

Todas las decisiones de color viven en `:root` como **custom properties**, lo que
permite cambiar el tema desde un solo lugar. El esquema es **oscuro** (`color-scheme: dark`).

| Variable | Valor | Uso |
|----------|-------|-----|
| `--bg` | `#07111f` | Fondo base |
| `--bg-soft` | `#0d1b2e` | Fondo de listas desplegables |
| `--surface` / `--surface-2` | `#10243b` / `#14304f` | Superficies y modales |
| `--card` / `--card-strong` | `rgba(255,255,255,.055/.09)` | Relleno de tarjetas |
| `--line` | `rgba(255,255,255,.12)` | Bordes y separadores |
| `--text` | `#eaf4ff` | Texto principal |
| `--muted` | `#a8b8cc` | Texto secundario |
| `--primary` | `#3498db` | Color de marca (azul) |
| `--primary-strong` | `#6bc5ff` | Acentos / enlaces / foco |
| `--primary-dark` | `#1876b5` | Gradientes de botón |
| `--success` | `#25c281` | Estados positivos (verde) |
| `--warning` | `#ffcc66` | Advertencias (amarillo) |
| `--danger` | `#ff6b6b` | Errores / acciones destructivas (rojo) |

**Fondo del `body`:** combinación de dos gradientes radiales (azules) sobre un
gradiente lineal oscuro, dando profundidad sin imágenes.

### 1.2 Tipografía

- **Familia:** `Inter, ui-sans-serif, system-ui, "Segoe UI", sans-serif`.
- **Escala fluida con `clamp()`** para que los títulos se adapten al viewport:
  - `h1`: `clamp(2.3rem, 7vw, 5.4rem)`, interletraje `-.07em` (muy ajustado).
  - `h2`: `clamp(1.7rem, 4vw, 2.6rem)`.
  - `h3`: `1.15rem`.
- **`.eyebrow`:** etiqueta superior en mayúsculas, color `--primary-strong`,
  `letter-spacing: .16em` — usada encima de los títulos de sección.
- **`.muted`:** texto en color `--muted` para descripciones.

### 1.3 Espaciados, bordes y sombras

| Concepto | Variable / valor | Detalle |
|----------|------------------|---------|
| Radio general | `--radius: 18px` | Tarjetas, paneles, formularios |
| Radios menores | `12–14px` | Inputs, badges internos, match-cards |
| Píldoras | `999px` | Botones, chips, enlaces de nav |
| Sombra | `--shadow: 0 22px 70px rgba(0,0,0,.28)` | Elevación de tarjetas |
| Sidebar | `--sidebar: 280px` | Ancho de la columna lateral |
| Espaciados | `clamp()` + `rem` | Padding fluido en secciones (`clamp(1rem,4vw,4rem)`) |

---

## 2. Layout

### 2.1 CSS Grid

- **`.app-shell`** — layout de panel: `grid-template-columns: var(--sidebar) 1fr`.
- **`.grid.cols-2 / .cols-3 / .cols-4`** — grillas de tarjetas con
  `repeat(N, minmax(0,1fr))`.
- **`.kpi-row`** — `repeat(4, minmax(0,1fr))` para las tarjetas KPI.
- **`.hero`** — dos columnas asimétricas (`1.15fr` / `.85fr`).
- **`.form-grid`** — `repeat(2, minmax(0,1fr))` para formularios.
- **`.field`** — `display:grid; gap:.45rem` (label sobre input).

### 2.2 Flexbox

- **`.public-nav` / `.topbar`** — `flex` con `justify-content: space-between`.
- **`.btn` / `.chip`** — `inline-flex` centrado para alinear icono + texto.
- **`.side-link`** — `flex` con `space-between` (texto + chevron/badge).
- **`.actions` / `.hero-actions` / `.form-actions`** — `flex` con `wrap`.
- **`.fixture-match`** (Liga) — `flex` alineando equipos, marcador y acciones.

### 2.3 Responsive {#responsive}

Definido con dos `@media`:

```css
@media (max-width: 980px) {
  /* grillas y hero → 1 columna; app-shell → 1 columna */
  /* sidebar pasa a off-canvas: position:fixed + translateX(-105%) */
  .sidebar.open { transform: translateX(0); }
  .menu-toggle { display: inline-flex !important; } /* aparece hamburguesa */
  .page-header { flex-direction: column; }
}
@media (max-width: 720px) {
  .form-grid { grid-template-columns: 1fr; }
  .topbar { flex-wrap: wrap; }
}
```

Además se respeta `@media (prefers-reduced-motion: reduce)` desactivando
transiciones y animaciones, y `:focus-visible` dibuja un contorno accesible.

---

## 3. Componentes (clases CSS y su función)

### 3.1 Botones — `.btn`
Base tipo píldora con borde sutil y transición de elevación al *hover*.
Variantes:
- `.btn.primary` — gradiente azul (`--primary` → `--primary-dark`).
- `.btn.ghost` — transparente.
- `.btn.danger` — rojo translúcido para acciones destructivas.
- `.btn.success` — verde translúcido.
- `.btn.small` — versión compacta.

### 3.2 Chips / badges — `.chip`
Etiquetas tipo píldora para estado/formato. Variantes `.success`, `.warning`,
`.danger` cambian fondo, borde y color. Se usan para estados de torneo,
formatos, contadores en el sidebar, etc.

### 3.3 Tarjetas — `.card`, `.panel`, `.stat-card`, `.form-card`, `.auth-card`, `.hero-card`
Comparten borde, gradiente de relleno, `--radius` y `--shadow`.
- **`.stat-card`** — KPI: número grande (`strong`, `2rem`) + etiqueta (`span`).
- **`.panel`** — bloque de contenido dentro de una página.
- **`.form-card` / `.auth-card`** — contenedores de formularios.

### 3.4 Sidebar — `.sidebar`, `.side-link`, `.side-section-title`
Columna fija con `backdrop-filter: blur`. `.side-section-title` son los títulos
de grupo en mayúsculas; `.side-link` los enlaces (con estado `.active`
resaltado en azul). En mobile se vuelve off-canvas (ver responsive).

### 3.5 Navbar pública — `.public-nav`, `.brand`, `.logo-mark`, `.nav-link`
Barra superior *sticky* con `backdrop-filter`. `.nav-link.active` resalta la
sección actual.

### 3.6 Topbar — `.topbar`, `.menu-toggle`, `.topbar-left`
Barra superior de los paneles. Incluye el botón hamburguesa (`.menu-toggle`,
oculto en desktop) y la identidad del usuario.

### 3.7 Tablas — `.table-wrap`, `.table-scroll`, `table`, `th.sortable`
- `.table-wrap` recorta con `overflow:hidden`; `.table-scroll` habilita scroll
  horizontal (`overflow-x:auto`) para mantener la tabla usable en mobile.
- `th, td` con padding y borde inferior; `tr:hover td` resalta la fila.
- **`th.sortable`** (añadida por `tables.js`) muestra el cursor e indicadores
  `⇅ / ↑ / ↓` vía `::after` según `data-sort-dir`.

### 3.8 Bracket — `.bk-scroll`, `.bk-card`, `.bk-row`, `.bracket-nav`
- `.bk-scroll[data-bracket]` es el contenedor desplazable.
- `.match-card` / `.bk-card` son las tarjetas de cruce; `.match-row.win` /
  `.bk-row.win` resaltan al ganador en verde; `.champion` marca al campeón.
- **`.bracket-nav.prev/.next`** son los botones circulares laterales que inyecta
  `eliminacion.js`; `.bracket-grab`/`.grabbing` cambian el cursor al arrastrar.

### 3.9 Tabla de posiciones (Liga) — `.pos-badge`, `.liga-leader`, `.liga-podium`
- `.pos-badge` es el círculo con el número de posición; `.gold/.silver/.bronze`
  colorean el podio.
- `.liga-leader td` / `.suizo-leader td` (añadidas por JS) resaltan al líder.

### 3.10 Ranking Suizo — `.ronda-tabs`, `.ronda-tab`
Pestañas para cambiar de ronda; `.ronda-tab.active` marca la ronda visible.
La tabla `[data-suizo-ranking]` reusa los estilos de `table`.

### 3.11 Formularios — `.field`, `label`, `input/select/textarea`, `.field-error`
Inputs con fondo oscuro translúcido y foco resaltado (borde + halo azul).
**`.field-error`** (añadida por `forms.js`) pinta borde y halo rojos. Las
reglas de contraseña usan `[data-password-hints] li.rule-ok / .rule-pending`.

### 3.12 Mensajes flash / toast — `.flash-stack`, `.flash-toast`
Pila fija arriba a la derecha (`.flash-stack`). Cada `.flash-toast` entra desde
la derecha (transform + opacity) y se colorea por tipo (`.flash-success`,
`.flash-warning`, `.flash-danger`, `.flash-info`). Inyectados por `app.js`.

### 3.13 Modales — `.modal-overlay`, `.modal-card`
`.modal-overlay` cubre la pantalla con fondo oscuro; `.modal-overlay.open` lo
muestra (`display:grid; place-items:center`). `.modal-card` es la tarjeta
sólida con gradiente. Gestionados por `modals.js`.

### 3.14 Timeline — `.timeline`, `.timeline-item`
Lista vertical de eventos (actividad reciente del dashboard admin).

### 3.15 Navegación de mockup — `.mockup-nav`
Barra flotante inferior, exclusiva de la demo, para saltar entre pantallas.

---

## 4. Convenciones

- **Mobile-first en intención**, con *overrides* en breakpoints descendentes.
- **Sin estilos en línea para temas**: los colores siempre vienen de variables.
  (Hay algunos `style="…"` puntuales de maquetación para posicionar elementos de
  demo; los estilos *reutilizables* viven en `_shared.css`.)
- **Clases utilitarias mínimas**: `.muted`, `.grid`, `.cols-N`, `.actions`.
- **Accesibilidad:** foco visible, `prefers-reduced-motion`, contraste alto.

[← Volver al documento maestro](mockups_documentacion.md)
