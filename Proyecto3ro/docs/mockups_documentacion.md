# Documentación de Maquetación — Mockups FlexArena / SGDM

> Documento maestro de la maquetación de presentación del **Sistema de Gestión
> Deportiva Modular (SGDM / FlexArena)**, desarrollado por **Guemanelli Tech**.
>
> Esta documentación cubre **exclusivamente** la capa de presentación: UI, UX,
> componentes visuales y comportamiento frontend. **No** describe la lógica de
> backend, base de datos ni el framework PHP del proyecto real. Los mockups son
> una recreación estática (HTML + CSS + JavaScript vanilla) que vive aislada en
> la carpeta `mockups/`, sin interferir con el proyecto en `sgdm/`.

---

## 1. Índice de la documentación

| Documento | Contenido |
|-----------|-----------|
| **mockups_documentacion.md** (este archivo) | Visión general, mapa de archivos, explicación pantalla por pantalla y comportamiento responsive. |
| [documentacion_css.md](documentacion_css.md) | Sistema de diseño: paleta, variables, tipografía, layout y componentes CSS. |
| [documentacion_javascript.md](documentacion_javascript.md) | Arquitectura JavaScript modular: cada archivo, funciones, eventos, interacciones y flujo de usuario. |
| [documentacion_componentes.md](documentacion_componentes.md) | Componentes visuales reutilizables (navbar, sidebar, topbar, flash, KPIs, badges, bracket, tablas, ranking). |
| [diagramas.md](diagramas.md) | Diagramas Mermaid: arquitectura visual por rol, flujos de navegación y relación entre componentes. |

---

## 2. Objetivo general del sistema

FlexArena permite **organizar y visualizar torneos** deportivos, mentales y
electrónicos bajo tres formatos de competencia:

- **Liga** — todos contra todos, con tabla de posiciones y puntos configurables.
- **Eliminación Directa** — llave/bracket donde el ganador avanza; admite *byes*.
- **Sistema Suizo** — emparejamiento por puntaje acumulado, sin eliminación.

El sistema maneja **cuatro roles**, cada uno con su propio layout y permisos:

1. **Público** (sin login) — explora torneos y resultados.
2. **Administrador** — control total: usuarios, participantes, equipos, torneos, auditoría.
3. **Organizador** — gestiona los torneos que tiene asignados.
4. **Participante** — ve su panel, su perfil y sus torneos.

---

## 3. Mapa de archivos de la maquetación

```
mockups/
├── index.html                       Hub de navegación entre todas las pantallas
├── _shared.css                      Hoja de estilos compartida (sistema de diseño)
│
├── 01-home.html                     [Público]        Landing / inicio
├── 02-torneos-publico.html          [Público]        Listado de torneos con filtros
├── 17-torneo-detalle.html           [Público]        Detalle de torneo + bracket
│
├── 03-login.html                    [Auth]           Iniciar sesión
├── 04-registro.html                 [Auth]           Registro de participante
│
├── 05-admin-dashboard.html          [Admin]          Panel general (KPIs + actividad)
├── 06-admin-torneos.html            [Admin]          Listado de torneos
├── 16-admin-torneo-form.html        [Admin]          Crear/editar torneo (campos condicionales)
├── 07-admin-gestion-torneo.html     [Admin]          Gestión de torneo (Suizo)
├── 08-admin-participantes.html      [Admin]          Gestión de participantes + registros
├── 15-admin-equipos.html            [Admin]          Gestión de equipos
├── 09-admin-usuarios.html           [Admin]          Gestión de organizadores
├── 10-admin-auditoria.html          [Admin]          Historial de auditoría
│
├── 11-organizador-dashboard.html    [Organizador]    Panel y torneos asignados
├── 12-organizador-gestion.html      [Organizador]    Gestión de torneo (Liga)
│
├── 13-participante-dashboard.html   [Participante]   Mi panel
├── 14-participante-perfil.html      [Participante]   Mi perfil + historial
│
├── 18-error-403.html                [Error]          Acceso denegado
│
├── js/                              Capa JavaScript modular (ver documentacion_javascript.md)
│   ├── app.js          sidebar.js   modals.js     confirm.js
│   ├── forms.js        tables.js    torneos.js    resultados.js
│   ├── eliminacion.js  liga.js      suizo.js      dashboard.js
│
└── docs/                            Esta documentación
```

### Cómo abrir los mockups

- **Opción simple:** abrir `mockups/index.html` directamente en el navegador
  (doble clic). Toda la navegación funciona con rutas relativas.
- **Opción servidor:** servir la **raíz del proyecto** con cualquier servidor
  estático (`python -m http.server`) y entrar a `/mockups/index.html`. Así el
  logo (`../sgdm/public/assets/img/flexarena-logo.svg`) también resuelve.
- Cada pantalla incluye una **barra flotante inferior** (`.mockup-nav`) para
  saltar entre vistas durante la presentación.

---

## 4. Estructura visual común (layouts)

Existen **tres layouts** base, descritos en detalle en
[documentacion_componentes.md](documentacion_componentes.md):

### 4.1 Layout público (`.public-shell`)
Usado por Home, Torneos y Detalle de torneo.
```
┌───────────────────────────────────────────┐
│ public-nav  (logo + enlaces + Ingresar)     │  ← sticky
├───────────────────────────────────────────┤
│ Contenido (hero / grid de cards / bracket)  │
├───────────────────────────────────────────┤
│ footer  (© Guemanelli Tech)                 │
└───────────────────────────────────────────┘
```

### 4.2 Layout de autenticación (`.auth-layout`)
Usado por Login, Registro y Error 403. Tarjeta centrada (`.auth-card`) sobre
fondo con gradientes radiales.

### 4.3 Layout de panel (`.app-shell`)
Usado por todas las vistas internas (admin, organizador, participante).
```
┌───────────┬───────────────────────────────┐
│           │ topbar (hamburguesa + título +  │ ← sticky
│  sidebar  │         usuario)                │
│  (sticky) ├───────────────────────────────┤
│           │ page  (page-header + contenido) │
│           │                                 │
└───────────┴───────────────────────────────┘
```
En mobile el `sidebar` se vuelve *off-canvas* (deslizable) y aparece el botón
hamburguesa (ver sección 6).

---

## 5. Explicación pantalla por pantalla

Para cada pantalla se documenta: **Objetivo**, **Estructura visual**,
**Componentes reutilizados**, **Interacciones JS** y **Responsive**.

---

### 5.1 Home pública — `01-home.html`

- **Objetivo:** presentar el sistema y destacar un torneo en curso; puerta de
  entrada para el público.
- **Estructura visual:**
  - *Navbar* público (`.public-nav`): logo + enlaces (Inicio, Torneos, Ingresar).
  - *Hero* (`.hero`): título grande, descripción, botones de acción y una
    *card destacada* con el torneo en curso (stat-cards de inscritos y rondas).
  - *Sección de features* (`.grid.cols-3`): tres cards explicando Liga,
    Eliminación Directa y Sistema Suizo.
  - *KPIs* (`.kpi-row`): cuatro `stat-card` con métricas globales.
  - *Footer* con el nombre de la empresa.
- **Componentes reutilizados:** navbar público, cards, chips, stat-cards, footer.
- **Interacciones JS:** `app.js` + `dashboard.js` → los 4 KPIs animan un
  conteo (count-up) al entrar en viewport (`data-count`).
- **Responsive:** el hero y las grillas colapsan a 1 columna ≤ 980px.

---

### 5.2 Listado público de torneos — `02-torneos-publico.html`

- **Objetivo:** explorar todos los torneos públicos y filtrarlos.
- **Estructura visual:**
  - Navbar público + encabezado con controles de filtro (búsqueda, formato, estado).
  - Contador de resultados (`[data-torneos-count]`).
  - Grilla de cards de torneo (`[data-torneos-grid]`), cada una con
    `data-tipo`, `data-estado`, `data-nombre`.
  - Footer con CTA de registro.
- **Componentes reutilizados:** navbar, cards, chips de estado/formato, footer.
- **Interacciones JS (`torneos.js`):** filtrado dinámico **sin recargar** por
  formato y estado (selects) y búsqueda por nombre en vivo; muestra mensaje de
  "sin resultados" y actualiza el contador.
- **Responsive:** grilla 3→1 columnas; los filtros hacen *wrap*.

---

### 5.3 Detalle público de torneo — `17-torneo-detalle.html`

- **Objetivo:** mostrar la información de un torneo y su **bracket de
  Eliminación Directa** con el campeón.
- **Estructura visual:**
  - Navbar público + cabecera con nombre, chips (formato/modalidad/estado) y
    card del campeón.
  - KPIs (participantes, rondas, partidos).
  - Panel del **bracket** (`.bk-scroll[data-bracket]`) con columnas por ronda
    (Cuartos, Semifinales, Final, Campeón) y match-cards; la fila ganadora se
    resalta en verde.
  - Footer.
- **Componentes reutilizados:** navbar, KPIs, panel, bracket, footer.
- **Interacciones JS (`eliminacion.js`):** **scroll horizontal inteligente** del
  bracket: arrastre con mouse, rueda → horizontal, flechas del teclado y botones
  laterales ‹ › que aparecen/ocultan según el desplazamiento.
- **Responsive:** el bracket siempre permite scroll horizontal; el resto colapsa.

---

### 5.4 Iniciar sesión — `03-login.html`

- **Objetivo:** autenticación de cualquier rol.
- **Estructura visual:** layout auth, `auth-card` con logo, formulario
  (email + contraseña) y enlace a registro.
- **Componentes reutilizados:** auth-card, campos de formulario, botones.
- **Interacciones JS (`forms.js`):** validación de campos requeridos con
  resaltado de error; al validar correctamente, redirige al dashboard admin
  (`data-redirect`, simulación de login).
- **Responsive:** la tarjeta se adapta al ancho con `min()`.

---

### 5.5 Registro de participante — `04-registro.html`

- **Objetivo:** alta de un participante (queda *pendiente de aprobación*).
- **Estructura visual:** auth-card ancha, formulario en grilla de 2 columnas y
  lista de reglas de contraseña en vivo.
- **Componentes reutilizados:** auth-card, form-grid, lista de reglas.
- **Interacciones JS (`forms.js`):**
  - **Medidor de fortaleza de contraseña** en tiempo real: cada regla (longitud,
    mayúscula, minúscula, número, símbolo, coincidencia) se marca en verde
    (`rule-ok`) o gris (`rule-pending`); el botón se habilita solo si se cumplen.
  - Validación + feedback flash al enviar (mockup).
- **Responsive:** la grilla pasa a 1 columna ≤ 720px.

---

### 5.6 Dashboard administrador — `05-admin-dashboard.html`

- **Objetivo:** resumen operativo del sistema.
- **Estructura visual:**
  - *Sidebar* admin (todas las secciones) + *topbar* (hamburguesa + usuario).
  - *page-header* con título.
  - *KPIs* (`data-count`): torneos, participantes, equipos, acciones.
  - Grilla de 2 columnas: *timeline* de actividad reciente y *accesos rápidos*
    (con torneos en curso).
- **Componentes reutilizados:** sidebar, topbar, KPIs, cards, timeline, chips.
- **Interacciones JS:** `dashboard.js` anima los KPIs; `sidebar.js` controla el
  menú en mobile.
- **Responsive:** grilla 2→1; sidebar off-canvas ≤ 980px.

---

### 5.7 Listado de torneos (admin) — `06-admin-torneos.html`

- **Objetivo:** ver y administrar todos los torneos.
- **Estructura visual:** sidebar + topbar + page-header con botón "+ Nuevo
  torneo"; campo de búsqueda; tabla con columnas ordenables.
- **Componentes reutilizados:** sidebar, topbar, tabla responsive, chips, botones.
- **Interacciones JS (`tables.js`):** búsqueda en vivo sobre la tabla
  (`data-table-search`) y ordenamiento por columna al hacer clic en los
  encabezados (`data-sort`, numérico o texto). "+ Nuevo torneo" enlaza a `16`.
- **Responsive:** la tabla scrollea horizontalmente (`.table-scroll`).

---

### 5.8 Crear / editar torneo — `16-admin-torneo-form.html`

- **Objetivo:** formulario de alta de torneo cuyos campos **cambian según el
  formato** elegido.
- **Estructura visual:** sidebar + topbar + `form-card` con datos generales y
  paneles condicionales (puntos para Liga, rondas para Suizo, nota de byes para
  Eliminación).
- **Componentes reutilizados:** sidebar, topbar, form-card, paneles, chips.
- **Interacciones JS (`forms.js`):**
  - **Campos condicionales:** `data-format-select` controla la visibilidad de
    cada `[data-format-field][data-format-show="…"]`; los inputs ocultos se
    deshabilitan.
  - Validación de requeridos + feedback flash al "crear".
- **Responsive:** form-grid 2→1; paneles a ancho completo.

---

### 5.9 Gestión de torneo — Suizo — `07-admin-gestion-torneo.html`

- **Objetivo:** operar un torneo Suizo en curso: inscriptos, ranking, rondas y
  resultados.
- **Estructura visual:**
  - Cabecera con chips de estado/formato/rondas y acciones (Editar, **Finalizar
    torneo**).
  - Panel "Siguiente ronda" con botón **Generar ronda**.
  - Panel de **inscriptos**.
  - Panel **Ranking** (tabla `data-suizo-ranking`).
  - Panel **Partidas por ronda** con **pestañas** (Ronda 5 / Ronda 4) y, por
    partido, botones Cargar/Corregir.
  - Dos **modales**: cargar y corregir resultado.
- **Componentes reutilizados:** sidebar, topbar, paneles, tablas, ranking Suizo,
  pestañas de ronda, modales, chips.
- **Interacciones JS:**
  - `confirm.js` → confirmación antes de **generar ronda** y **finalizar torneo**.
  - `suizo.js` → cambio de pestañas de ronda y reordenamiento del ranking
    (por puntos y Buchholz) ante cambios.
  - `resultados.js` → abre el modal correcto, lo precarga y valida el motivo de
    corrección (mín. 10 caracteres); emite `fa:resultado-cargado`.
  - `modals.js` → apertura/cierre genérico de modales.
- **Responsive:** tablas scrollean; cabecera apila acciones.

---

### 5.10 Gestión de participantes — `08-admin-participantes.html`

- **Objetivo:** aprobar registros pendientes y administrar participantes.
- **Estructura visual:**
  - Panel destacado de **registros pendientes** (cards con Aprobar/Rechazar).
  - page-header + búsqueda + tabla de participantes (ordenable) con badge de
    estado y acciones Activar/Desactivar.
- **Componentes reutilizados:** sidebar, topbar, paneles, tabla, chips, badges.
- **Interacciones JS:**
  - `dashboard.js` → Aprobar/Rechazar (`data-state-action`) retira la card con
    animación y emite flash.
  - `confirm.js` → Desactivar/Activar piden confirmación.
  - `tables.js` → búsqueda y orden.
- **Responsive:** las cards de pendientes y la tabla se adaptan / scrollean.

---

### 5.11 Gestión de equipos — `15-admin-equipos.html`

- **Objetivo:** administrar los equipos y sus capitanes.
- **Estructura visual:** sidebar + topbar + page-header con "+ Nuevo equipo";
  búsqueda; tabla ordenable con badge de estado y acciones; **modal** de alta.
- **Componentes reutilizados:** sidebar, topbar, tabla, badges, modal, formulario.
- **Interacciones JS:**
  - `modals.js` → abre el modal "Nuevo equipo".
  - `forms.js` → valida el formulario del modal y lo cierra al "crear".
  - `dashboard.js` → Activar/Desactivar actualiza el badge en vivo.
  - `tables.js` → búsqueda y orden.
- **Responsive:** tabla scrollea; modal a ancho `min()`.

---

### 5.12 Gestión de organizadores — `09-admin-usuarios.html`

- **Objetivo:** crear y administrar usuarios organizadores.
- **Estructura visual:** sidebar + topbar + page-header con "+ Nuevo
  organizador"; búsqueda; tabla ordenable con badge de estado; **modal** de alta.
- **Componentes reutilizados:** idénticos a Equipos (tabla + modal + badges).
- **Interacciones JS:** `modals.js` (modal), `forms.js` (validación + cierre),
  `dashboard.js` (badge Activar/Desactivar **en vivo**), `tables.js` (búsqueda/orden).
- **Responsive:** tabla scrollea; sidebar off-canvas.

---

### 5.13 Auditoría — `10-admin-auditoria.html`

- **Objetivo:** consultar el historial de acciones del sistema.
- **Estructura visual:** sidebar + topbar + page-header; búsqueda; tabla de
  registros (fecha, usuario, acción, tabla, descripción, IP) ordenable;
  paginación con botones.
- **Componentes reutilizados:** sidebar, topbar, tabla, chips de acción, botones.
- **Interacciones JS (`tables.js`):** búsqueda global en la tabla y orden por
  columnas.
- **Responsive:** tabla scrollea horizontalmente.

---

### 5.14 Dashboard organizador — `11-organizador-dashboard.html`

- **Objetivo:** ver los torneos asignados y tareas pendientes.
- **Estructura visual:**
  - Sidebar reducido (solo opciones de organizador) + topbar.
  - Grilla de cards de torneos asignados (con mini stat-cards).
  - Grilla de 2 columnas: **partidas pendientes** y **correcciones solicitadas**
    (con Aprobar/Rechazar).
- **Componentes reutilizados:** sidebar, topbar, cards, stat-cards, chips.
- **Interacciones JS:** `dashboard.js` → Aprobar/Rechazar correcciones retira la
  solicitud y emite flash; `sidebar.js` para mobile.
- **Responsive:** grillas 3/2 → 1 columna.

---

### 5.15 Gestión de torneo — Liga — `12-organizador-gestion.html`

- **Objetivo:** operar un torneo Liga: tabla de posiciones, inscriptos y fixture.
- **Estructura visual:**
  - Cabecera con chips de estado/formato.
  - Grilla de 2 columnas: **tabla de posiciones** (`data-liga-tabla`, con
    badges de podio) e **inscriptos**.
  - Panel **Fixture** con filas de partido (resultado, estado) y botones
    Cargar/Corregir.
  - Modales de cargar y corregir resultado.
- **Componentes reutilizados:** sidebar, topbar, paneles, tabla de posiciones,
  fixture, modales, chips, badges de posición.
- **Interacciones JS:**
  - `resultados.js` → modales de carga/corrección con precarga y validación.
  - `liga.js` → reordena la tabla por puntos y renumera posiciones al recibir
    `fa:resultado-cargado`.
  - `modals.js` / `forms.js` → soporte de modales y validación.
- **Responsive:** grilla 2→1; fixture y tabla se adaptan.

---

### 5.16 Dashboard participante — `13-participante-dashboard.html`

- **Objetivo:** vista personal del participante.
- **Estructura visual:** sidebar (participante) + topbar; KPIs personales
  (`data-count`, con sufijo `%` y prefijo 🏆); grilla de 2 columnas (perfil
  resumido + mis torneos); panel de próximos partidos.
- **Componentes reutilizados:** sidebar, topbar, KPIs, cards, chips.
- **Interacciones JS:** `dashboard.js` (KPIs animados), `sidebar.js` (mobile).
- **Responsive:** grilla 2→1; KPIs se reacomodan.

---

### 5.17 Perfil participante — `14-participante-perfil.html`

- **Objetivo:** perfil completo con estadísticas e historial.
- **Estructura visual:**
  - page-header con botón **Editar perfil**.
  - Grilla 2 columnas: datos personales (avatar + filas) y **estadísticas
    globales** (KPIs + win rate + campeonatos).
  - Card **historial de torneos** (posición, puntos, estado por torneo).
  - **Modal** de edición de perfil.
- **Componentes reutilizados:** sidebar, topbar, cards, KPIs, chips, modal, formulario.
- **Interacciones JS:** `modals.js` (abre modal), `forms.js` (valida y cierra al
  guardar).
- **Responsive:** grilla 2→1; historial apila columnas internas.

---

### 5.18 Error 403 — `18-error-403.html`

- **Objetivo:** informar acceso denegado por permiso insuficiente.
- **Estructura visual:** layout auth centrado; código "403" grande, mensaje,
  chip de bloqueo y botones (Inicio / Iniciar sesión).
- **Componentes reutilizados:** auth-card, chips, botones.
- **Interacciones JS:** solo `app.js` (base; no requiere lógica adicional).
- **Responsive:** tarjeta centrada que se adapta al ancho.

---

## 6. Comportamiento responsive (resumen global)

| Breakpoint | Comportamiento |
|------------|----------------|
| **Desktop** (> 980px) | Sidebar fijo a la izquierda; grillas a 2/3/4 columnas; hero a 2 columnas; hamburguesa oculta. |
| **Tablet** (≤ 980px) | Grillas y hero colapsan a 1 columna; el sidebar se vuelve *off-canvas* (oculto, se abre con la hamburguesa y un overlay); `page-header` apila título y acciones. |
| **Mobile** (≤ 720px) | `form-grid` a 1 columna; `topbar` permite *wrap*; navegación 100% táctil; tablas siempre con scroll horizontal contenido. |

Detalles técnicos de los breakpoints y clases en
[documentacion_css.md](documentacion_css.md#responsive).

---

## 7. Documentación de archivos HTML/PHP de maquetación

> Los mockups son HTML estático. A continuación se resume la **función**,
> **estructura** y **relación** de cada archivo. La equivalencia con las vistas
> reales del proyecto (`sgdm/app/views/…`) se indica como referencia.

| Archivo mockup | Función | Componentes incluidos | Vista real equivalente |
|----------------|---------|-----------------------|------------------------|
| `index.html` | Hub de navegación de la demo | grid de tarjetas | — (solo demo) |
| `01-home.html` | Landing pública | navbar, hero, features, KPIs, footer | `views/public/home.php` |
| `02-torneos-publico.html` | Listado público | navbar, filtros, grid de cards, footer | `views/public/torneos.php` |
| `17-torneo-detalle.html` | Detalle + bracket | navbar, KPIs, bracket, footer | `views/public/*` + `partials/eliminacion_bracket.php` |
| `03-login.html` | Login | auth-card, formulario | `views/auth/login.php` |
| `04-registro.html` | Registro | auth-card, formulario, reglas | `views/auth/registro.php` |
| `05-admin-dashboard.html` | Panel admin | sidebar, topbar, KPIs, timeline | `views/admin/dashboard.php` |
| `06-admin-torneos.html` | Torneos (admin) | sidebar, topbar, tabla | `views/admin/torneos.php` |
| `16-admin-torneo-form.html` | Alta de torneo | sidebar, topbar, form-card | `views/admin/torneo_form.php` |
| `07-admin-gestion-torneo.html` | Gestión Suizo | sidebar, topbar, ranking, modales | `views/admin/torneo_gestion.php` + partials Suizo |
| `08-admin-participantes.html` | Participantes | sidebar, topbar, tabla, registros | `views/admin/participantes.php` |
| `15-admin-equipos.html` | Equipos | sidebar, topbar, tabla, modal | `views/admin/*equipos*` |
| `09-admin-usuarios.html` | Organizadores | sidebar, topbar, tabla, modal | `views/admin/usuarios.php` |
| `10-admin-auditoria.html` | Auditoría | sidebar, topbar, tabla, paginación | `views/admin/auditoria.php` |
| `11-organizador-dashboard.html` | Panel organizador | sidebar, topbar, cards | `views/organizador/dashboard.php` |
| `12-organizador-gestion.html` | Gestión Liga | sidebar, topbar, tabla posiciones, fixture, modales | `views/organizador/torneo_gestion.php` + partials Liga |
| `13-participante-dashboard.html` | Panel participante | sidebar, topbar, KPIs, cards | `views/participante/dashboard.php` |
| `14-participante-perfil.html` | Perfil | sidebar, topbar, KPIs, historial, modal | `views/participante/perfil.php` |
| `18-error-403.html` | Error 403 | auth-card | `views/shared/403.php` |

**Relación entre vistas:** todas las páginas de panel comparten el mismo
*shell* (sidebar + topbar). Las páginas públicas comparten navbar + footer. La
navegación entre roles está enlazada (el sidebar admin incluye accesos a los
paneles de organizador y participante). El hub `index.html` enlaza a todo.

---

## 8. Checklist de completitud por pantalla

Cada pantalla cumple los cinco requisitos exigidos:

| Pantalla | HTML | CSS | JavaScript | Doc. visual | Doc. interacción |
|----------|:----:|:---:|:----------:|:-----------:|:----------------:|
| Home | ✅ | ✅ | ✅ (KPIs) | ✅ | ✅ |
| Torneos público | ✅ | ✅ | ✅ (filtros) | ✅ | ✅ |
| Detalle torneo | ✅ | ✅ | ✅ (bracket) | ✅ | ✅ |
| Login | ✅ | ✅ | ✅ (validación) | ✅ | ✅ |
| Registro | ✅ | ✅ | ✅ (password) | ✅ | ✅ |
| Dashboard admin | ✅ | ✅ | ✅ (KPIs) | ✅ | ✅ |
| Torneos admin | ✅ | ✅ | ✅ (tabla) | ✅ | ✅ |
| Form torneo | ✅ | ✅ | ✅ (condicional) | ✅ | ✅ |
| Gestión Suizo | ✅ | ✅ | ✅ (suizo+result) | ✅ | ✅ |
| Participantes | ✅ | ✅ | ✅ (estados) | ✅ | ✅ |
| Equipos | ✅ | ✅ | ✅ (modal+tabla) | ✅ | ✅ |
| Organizadores | ✅ | ✅ | ✅ (modal+estados) | ✅ | ✅ |
| Auditoría | ✅ | ✅ | ✅ (tabla) | ✅ | ✅ |
| Dashboard organizador | ✅ | ✅ | ✅ (estados) | ✅ | ✅ |
| Gestión Liga | ✅ | ✅ | ✅ (liga+result) | ✅ | ✅ |
| Dashboard participante | ✅ | ✅ | ✅ (KPIs) | ✅ | ✅ |
| Perfil participante | ✅ | ✅ | ✅ (modal) | ✅ | ✅ |
| Error 403 | ✅ | ✅ | ✅ (base) | ✅ | ✅ |

---

*Continúa en: [documentacion_css.md](documentacion_css.md) ·
[documentacion_javascript.md](documentacion_javascript.md) ·
[documentacion_componentes.md](documentacion_componentes.md) ·
[diagramas.md](diagramas.md)*
