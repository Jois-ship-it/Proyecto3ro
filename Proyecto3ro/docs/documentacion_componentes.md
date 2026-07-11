# Documentación de Componentes Visuales Reutilizables

[← Volver al documento maestro](mockups_documentacion.md)

Cada componente se describe con: **Propósito**, **Ubicación**, **Estructura**,
**Estilos** (clases de `_shared.css`) y **JavaScript asociado**.

---

## 1. Navbar pública

- **Propósito:** navegación superior de las vistas públicas; marca + accesos.
- **Ubicación:** `01-home.html`, `02-torneos-publico.html`, `17-torneo-detalle.html`.
- **Estructura:** `nav.public-nav` → `a.brand` (logo SVG) + `div.nav-links`
  (enlaces `a.nav-link`, el activo con `.active`).
- **Estilos:** `.public-nav` (sticky, blur), `.brand`, `.logo-mark`, `.nav-link`.
- **JavaScript:** ninguno (navegación por enlaces).

---

## 2. Sidebar

- **Propósito:** navegación principal de los paneles internos; varía según rol.
- **Ubicación:** todas las vistas de panel (05–16).
- **Estructura:** `aside.sidebar[data-sidebar]` → `a.brand` + grupos
  (`div.side-section-title` + enlaces `a.side-link`, activo `.active`,
  con chips de conteo en algunos casos).
- **Estilos:** `.sidebar`, `.side-section-title`, `.side-link`, `.side-link.active`.
- **JavaScript:** `sidebar.js` (off-canvas en mobile, overlay, Escape, cierre al
  navegar).

---

## 3. Topbar

- **Propósito:** barra superior de los paneles; alberga la hamburguesa y la
  identidad del usuario.
- **Ubicación:** todas las vistas de panel.
- **Estructura:** `div.topbar` → `div.topbar-left` (`button.menu-toggle` +
  título) + bloque de usuario (nombre + avatar circular).
- **Estilos:** `.topbar`, `.menu-toggle` (oculto en desktop), `.topbar-left`.
- **JavaScript:** `sidebar.js` (la hamburguesa abre/cierra el sidebar).

---

## 4. Mensajes flash / toast

- **Propósito:** feedback efímero de acciones (éxito, advertencia, error, info).
- **Ubicación:** se inyecta dinámicamente en cualquier pantalla.
- **Estructura:** `div.flash-stack` (contenedor fijo) → varios
  `div.flash-toast.flash-<tipo>` con icono, texto y botón cerrar.
- **Estilos:** `.flash-stack`, `.flash-toast`, `.flash-icon`, `.flash-close` y
  variantes por tipo.
- **JavaScript:** `app.js` (`FA.flash`). Lo disparan `confirm.js`, `forms.js`,
  `resultados.js`, `liga.js`, `suizo.js`, `dashboard.js`.

---

## 5. Tarjetas KPI (stat-card)

- **Propósito:** mostrar una métrica destacada (número + etiqueta).
- **Ubicación:** Home, dashboards (admin/organizador/participante), detalle de
  torneo, perfil.
- **Estructura:** `div.stat-card` → `strong[data-count]` (número) + `span` (etiqueta).
- **Estilos:** `.stat-card`, agrupadas en `.kpi-row`.
- **JavaScript:** `dashboard.js` anima el conteo al entrar en viewport.

---

## 6. Badges / chips

- **Propósito:** etiquetar estados (en curso, finalizado…), formatos (Liga,
  Suizo…) y conteos.
- **Ubicación:** prácticamente todas las pantallas.
- **Estructura:** `span.chip` (+ `.success` / `.warning` / `.danger`).
- **Estilos:** `.chip` y variantes.
- **JavaScript:** `dashboard.js` puede cambiar texto/clase de un
  `[data-state-badge]` al activar/desactivar.

---

## 7. Tabla responsive

- **Propósito:** listar datos tabulares de forma usable en cualquier ancho.
- **Ubicación:** Torneos, Participantes, Equipos, Organizadores, Auditoría,
  Ranking, Tabla de posiciones.
- **Estructura:** `div.table-wrap` → `div.table-scroll` → `table` (con
  `thead`/`tbody`). Encabezados ordenables con `th[data-sort]`; buscador
  externo `input[data-table-search="#id"]`.
- **Estilos:** `.table-wrap`, `.table-scroll` (scroll horizontal), `th.sortable`.
- **JavaScript:** `tables.js` (búsqueda + orden).

---

## 8. Bracket de Eliminación

- **Propósito:** visualizar la llave del torneo y el camino al campeón.
- **Ubicación:** `17-torneo-detalle.html`.
- **Estructura:** `div.bk-scroll[data-bracket]` → `div.bracket-flex` → columnas
  `div.round-col` (con `h4` de ronda) → `div.match-card` → filas `div.match-row`
  (ganador `.win`); columna final con `.match-card.champion`.
- **Estilos:** `.bk-scroll`, `.bracket-flex`, `.round-col`, `.match-card`,
  `.match-row`, `.bracket-nav` (botones inyectados), `.bracket-grab`.
- **JavaScript:** `eliminacion.js` (scroll por arrastre, rueda, teclado, botones).

---

## 9. Tabla de posiciones (Liga)

- **Propósito:** ranking de Liga con puntos y diferencia de goles.
- **Ubicación:** `12-organizador-gestion.html`.
- **Estructura:** `table[data-liga-tabla]` → filas `tr[data-pts]` con celda de
  posición `span.pos-badge[data-pos]` (`.gold/.silver/.bronze`).
- **Estilos:** `.pos-badge` y variantes; `.liga-leader`, `.liga-podium`.
- **JavaScript:** `liga.js` (reordena y renumera ante `fa:resultado-cargado`).

---

## 10. Ranking Suizo + pestañas de ronda

- **Propósito:** ranking por puntaje/Buchholz y navegación entre rondas.
- **Ubicación:** `07-admin-gestion-torneo.html`.
- **Estructura:**
  - Ranking: `table[data-suizo-ranking]` → `tr[data-pts][data-buchholz]` con
    `[data-pos]`.
  - Pestañas: `div.ronda-tabs[data-ronda-tabs]` → `button.ronda-tab[data-ronda-tab]`;
    paneles `div[data-ronda-panel]`.
- **Estilos:** `.ronda-tabs`, `.ronda-tab`, `.ronda-tab.active`, `.suizo-leader`.
- **JavaScript:** `suizo.js` (tabs + reordenamiento del ranking).

---

## 11. Modal

- **Propósito:** capturar acciones puntuales (cargar/corregir resultado, crear
  organizador/equipo, editar perfil) sin cambiar de página.
- **Ubicación:** 07, 09, 12, 14, 15.
- **Estructura:** `div.modal-overlay#id` → `div.modal-card` (título + contenido +
  `.form-actions` con `[data-modal-close]`).
- **Estilos:** `.modal-overlay`, `.modal-overlay.open`, `.modal-card`.
- **JavaScript:** `modals.js` (apertura/cierre); a veces `forms.js`
  (validación) o `resultados.js` (precarga).

---

## 12. Formulario y campos

- **Propósito:** capturar datos con validación y feedback.
- **Ubicación:** Login, Registro, Form de torneo, modales.
- **Estructura:** `form[data-validate]` → `div.form-grid` → `div.field`
  (`label` + `input/select/textarea`). Reglas de contraseña en
  `ul[data-password-hints]`.
- **Estilos:** `.form-grid`, `.field`, estados `:focus`, `.field-error`,
  `li.rule-ok/.rule-pending`.
- **JavaScript:** `forms.js` (validación, contraseña, campos condicionales).

---

## 13. Confirmación de acción crítica

- **Propósito:** evitar acciones irreversibles accidentales.
- **Ubicación:** generación de competencia, finalizar torneo, activar/desactivar.
- **Estructura:** modal inyectado `#fa-confirm-dialog` (título + mensaje +
  Cancelar/Confirmar). Se activa con cualquier elemento `[data-confirm]`.
- **Estilos:** reutiliza `.modal-overlay` / `.modal-card`.
- **JavaScript:** `confirm.js`.

---

## 14. Timeline de actividad

- **Propósito:** mostrar eventos recientes del sistema.
- **Ubicación:** `05-admin-dashboard.html`.
- **Estructura:** `div.timeline` → `div.timeline-item` (acción + descripción +
  fecha).
- **Estilos:** `.timeline`, `.timeline-item`.
- **JavaScript:** ninguno.

---

## 15. Barra de navegación de mockup

- **Propósito:** facilitar la presentación saltando entre pantallas.
- **Ubicación:** todas las pantallas (no forma parte del producto real).
- **Estructura:** `nav.mockup-nav` con enlaces; el actual lleva `.current`.
- **Estilos:** `.mockup-nav`.
- **JavaScript:** ninguno.

[← Volver al documento maestro](mockups_documentacion.md)
