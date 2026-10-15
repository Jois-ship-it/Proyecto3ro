# Manual de Administrador — FlexArena

## Administrar usuarios

Cada rol tiene su apartado en el menú lateral, y los de **administradores** y **organizadores**
son la misma pantalla: lista, alta, edición, activar o desactivar, bloquear y desbloquear. Los
participantes se registran solos (Registros pendientes) y se gestionan en **Participantes**.

**Crear un administrador u organizador:**
1. Menú lateral → Gestión → **Administradores** (o **Organizadores**) → «+ Nuevo administrador»
2. Completar nombre, email y contraseña (mínimo 8 caracteres, con mayúscula, minúscula, número y símbolo)
3. Guardar

El **rol lo fija el apartado**, no el formulario: lo que se crea en Administradores es administrador
y lo que se crea en Organizadores es organizador. Cada apartado solo muestra y modifica las cuentas
de su rol; una cuenta de otro rol no se puede editar, activar ni desactivar desde ahí.

**Editar:** «Editar» en la fila. Dejar la contraseña en blanco para no cambiarla. El estado de una
cuenta bloqueada no se cambia desde el formulario: se desbloquea con «Desbloquear».

**Desactivar usuario:**
1. Clic en "Desactivar" en la fila del usuario (se vuelve «Activar» para reactivarla)
2. El usuario no puede iniciar sesión (estado = inactivo)
3. No se elimina para preservar auditoría

En la lista, la cuenta propia lleva la marca **Vos** y la fila dice «Es tu cuenta» en lugar de
ofrecer Desactivar o Bloquear; lo mismo pasa con el **único administrador activo** («Único
administrador activo»). Una cuenta bloqueada muestra hasta cuándo, y solo ofrece Desbloquear.

## Cuentas bloqueadas

Una cuenta se bloquea sola tras **5 intentos fallidos** de iniciar sesión (o a mano, desde el panel). El
bloqueo automático dura **15 minutos** y se levanta solo: pasado ese tiempo la persona puede volver a
entrar con su clave. El bloqueo manual dura hasta que alguien lo desbloquee.

- **Desbloquear** antes de tiempo: en la fila de la cuenta, «Desbloquear». Solo funciona con cuentas
  bloqueadas; desbloquear no activa una cuenta pendiente de aprobación ni una rechazada.
- Una persona cuya cuenta se bloquea, desactiva o cambia de rol **mientras tiene la sesión abierta** pierde
  la sesión en su siguiente pedido (con un aviso), no cuando la sesión vence.
- La sesión se cierra sola tras **60 minutos sin actividad**.
- No se puede bloquear ni desactivar al **único administrador activo**, ni a la propia cuenta: antes de
  hacerlo hay que crear o activar otro administrador.
- Una misma conexión (IP) que acumula **20 intentos fallidos en 15 minutos** queda rechazada un rato,
  aunque use la clave correcta. Si la rechazada es la de una sala compartida, esperar pasada la ventana.

Los tres números (5 intentos, 15 minutos de bloqueo, 20 por IP) son ajustes de la tabla
`configuracion_sistema` (`login_max_intentos`, `login_minutos_bloqueo`, `login_max_intentos_ip`,
`login_ventana_minutos`). Con `login_minutos_bloqueo = 0` el bloqueo automático dura hasta que un
administrador lo levante, salvo el del único administrador, que siempre vence.

## Asignar organizadores a torneos

1. Admin → Torneos → [torneo] → Gestionar
2. Usar el botón "Asignar organizador" (si está disponible) o crear el torneo con organizador

## Revisar auditoría

Panel Admin → Sistema → Auditoría:
- Filtrar por **acción**, por **rango de fechas** (el día «Hasta» entra completo) y buscar por usuario,
  descripción o tabla. 50 registros por página.
- Ver acciones críticas: logins, cambios de resultados, creaciones y **exportaciones de reportes**
  (`exportar_reporte`: quién descargó qué archivo y cuántas filas tenía).

## Buscar y filtrar las listas

Las listas largas (Torneos, Participantes, Equipos, Organizadores, Administradores y Auditoría) tienen
arriba una barra de búsqueda:

- Escribí parte del nombre y apretá **Buscar** (o Enter). No importan las mayúsculas ni las tildes:
  «avila» encuentra «Ávila». Participantes también busca por apodo, documento y correo; Equipos, por
  categoría y disciplina; las cuentas, por correo.
- Los **selectores** (formato, estado) se combinan con el texto.
- **Limpiar** vuelve a la lista completa. Abajo, «Mostrando 16–30 de 52» y los botones de página; al
  cambiar de página los filtros se conservan.
- La dirección de la página lleva los filtros (`/admin/torneos?q=liga&estado=en_curso&page=2`), así que
  se puede copiar y mandar.

## Reportes

**Sistema → Reportes** (solo administrador) muestra el estado general: torneos, participantes, equipos y
partidos jugados; torneos por estado y por formato; correcciones pendientes, aprobadas y rechazadas; cuentas
por rol, pendientes y bloqueadas; y una tabla con todos los torneos y su avance (se filtra igual que
las listas).

- **Imprimir / PDF** abre el diálogo del navegador con la hoja ya preparada (sin menús ni botones). Para
  guardar en PDF, elegir «Guardar como PDF» como impresora.
- **Descargar CSV** baja la tabla de torneos con el filtro aplicado, completa (no solo la página que se ve).
  Abre en Excel con doble clic: separador `;`, tildes bien y decimales con coma.

Cada torneo tiene además su propio reporte (botón **Reporte** en la lista y en su pantalla de gestión):
resumen, avance, tabla de posiciones (liga y suizo), todos los partidos, y tres descargas CSV
(posiciones, partidos e inscritos). Los CSV no llevan datos de contacto. Cada descarga queda en la
auditoría.

## Configuración del sistema

Panel Admin → Sistema → **Configuración**. Dos secciones:

- **Sitio**: el *nombre del sitio* (sale en el menú, el título de cada página y el pie), el *correo de contacto* (se
  muestra en el pie; vacío = no se muestra) y el interruptor de **perfiles públicos**. Con los perfiles
  desactivados, los perfiles de participantes y equipos solo los ve quien inició sesión; un visitante que intenta
  abrirlos va a la pantalla de inicio de sesión. Los nombres siguen apareciendo en las tablas de los torneos.
- **Inicio de sesión**: intentos fallidos antes de bloquear una cuenta (1 a 20), minutos que dura el bloqueo (0 = hasta que
  un administrador la desbloquee), intentos fallidos por dirección IP (1 a 1000) y la ventana, en minutos, en que se
  cuentan.

Al **Guardar cambios** la pantalla dice qué cambió. Si algún valor no sirve, no se guarda ninguno y se explica el
motivo sin perder lo que escribiste. Cada cambio queda en Auditoría (acción `actualizar_configuracion`) con los
valores de antes y de después.

## Gestionar módulos

Panel Admin → Sistema → Módulos:
- Activar/desactivar módulos del sistema.
- Cada cambio de estado queda registrado en Auditoría (acción `toggle_modulo`,
  con el estado anterior y el nuevo).

### Qué pasa al deshabilitar un módulo de formato

Los módulos `liga`, `eliminacion_directa` y `suizo` controlan los tres formatos de
competencia. La regla es **no se empiezan cosas nuevas, pero lo empezado se termina**:

| Acción | Módulo activo | Módulo deshabilitado |
|--------|---------------|----------------------|
| Crear un torneo de ese formato | Sí | **No** — el formato ni siquiera se ofrece en el formulario |
| Generar fixture / bracket / ronda 1 | Sí | **No** — el servicio rechaza la operación con un mensaje explicativo |
| Generar la siguiente ronda de un suizo ya arrancado | Sí | Sí |
| Cargar y corregir resultados de un torneo en curso | Sí | Sí |
| Avanzar ganadores del bracket y coronar campeón | Sí | Sí |
| Consultar públicamente torneos de ese formato | Sí | Sí |

El motivo de dejar terminar los torneos en curso es que ya tienen partidos jugados y
una tabla de posiciones: cortarlos por un cambio de configuración dejaría competencias
reales a mitad de camino, sin forma de cerrarlas salvo reactivando el módulo.

Al editar un torneo cuyo módulo fue deshabilitado después de crearlo, el formulario
sigue mostrando su formato actual (marcado como *módulo deshabilitado*) para no
cambiarlo en silencio al guardar. Lo que no se puede es **pasar** un torneo a un
formato deshabilitado.

Para los demás módulos (`participantes`, `equipos`, `auditoria`,
`consulta_publica`, `resultados`, `torneos`), el **interruptor activo/inactivo**
sigue sin tener efecto: su estado se guarda pero ningún flujo lo consulta. Lo que
sí decide en todos ellos son los **permisos por rol**, que son otra cosa y se
manejan en otra pantalla (ver abajo).

## Permisos por rol

Panel Admin → Sistema → **Permisos**.

Una tabla por rol, una fila por módulo y una casilla por acción (ver, crear,
editar, eliminar). Se guarda todo junto con un botón, así un cambio queda
aplicado de una sola vez y no a medias.

Para qué sirve, en concreto:

- **Sacarle a un organizador la posibilidad de corregir resultados** sin quitarle
  la de cargarlos: destildar `resultados / editar` y dejar `resultados / crear`.
  Es la «autorización» que menciona la letra del proyecto en §5.2.
- **Habilitar a un organizador en un formato y no en otro**: tildar
  `liga / crear` y destildar `suizo / crear`.
- **Dejar un rol solo de lectura**: dejar únicamente las casillas `ver`.

Tres cosas para tener presentes:

1. **Sin ninguna casilla marcada, ese módulo queda negado** para el rol. No hace
   falta «prohibir» explícitamente: lo que no está habilitado, no se puede.
2. **El administrador no aparece con casillas.** Tiene control completo y no pasa
   por esta tabla. Es a propósito: si se pudiera recortar su acceso desde acá,
   un descuido dejaría el sistema sin nadie que pueda entrar a arreglarlo.
3. **No reemplaza a las otras comprobaciones.** Un organizador con
   `torneos / editar` sigue alcanzando solo **sus** torneos, y un módulo de
   formato desactivado en la pantalla de Módulos queda cerrado para todos, tenga
   el permiso que tenga.

Cada guardado queda en **Auditoría** con la matriz anterior y la nueva, en
formato `rol:modulo` → `VCEX` (una letra por acción habilitada, guión donde no).

## Cerrar y reabrir rondas

Panel Admin → Torneos → (un torneo) → en la cabecera de cada ronda hay un botón
**Cerrar ronda** / **Reabrir ronda**. El organizador dueño del torneo ve lo mismo
en su propio panel. Es la facultad de "publicar o cerrar rondas" del §5.2 de la
letra del proyecto.

Estados posibles de una ronda:

| Estado | Qué significa | Cómo se llega |
|--------|---------------|---------------|
| `pendiente` | Le faltan cruces por definir. | Automático: ronda siguiente de un bracket mientras avanzan los ganadores. |
| `en_curso` | Jugable, con partidos por resolver. | Automático: al generarse el fixture / la ronda, o al completarse sus cruces. |
| `cerrada` | No admite más carga de resultados. | Automático cuando terminan todos sus partidos, o manual con **Cerrar ronda**. |

Cosas a tener en cuenta:

- Cerrar una ronda con partidos sin jugar es válido (sirve para congelar una
  fecha), y esos partidos dejan de ofrecer el botón "Cargar resultado".
- El recálculo automático **nunca reabre** una ronda: un cierre manual se
  deshace únicamente con **Reabrir ronda**.
- Una ronda cuyos partidos terminaron todos no se puede reabrir. Si hay que
  cambiar un resultado, se usa la corrección de resultados.
- La **corrección** de resultados sigue funcionando en rondas cerradas: si no,
  un error detectado tarde quedaría sin arreglo.
- Cada cierre y cada reapertura quedan en Auditoría (`cerrar_ronda` /
  `reabrir_ronda`), con el estado anterior y el nuevo.

## Respaldo de la base de datos

```bash
# Desde el servidor o contenedor
./scripts/backup.sh
# Genera: backups/flexarena_YYYYMMDD_HHMMSS.sql.gz
```

El script **verifica el volcado antes de darlo por bueno**: tamaño mínimo,
integridad del gzip, presencia de sentencias `CREATE TABLE`, de las tablas
propias de FlexArena y de la línea final de `mysqldump` (`-- Dump completed`).
Si algo de eso falla, borra el archivo parcial y termina con código distinto de
cero, en vez de dejar en `backups/` un `.gz` vacío con nombre de respaldo bueno
(que es lo que pasaba antes cuando el `mysqldump` no llegaba a correr).

El respaldo incluye las **vistas** y los **triggers** —o sea, también el historial de la
base de datos (`historial_bd`) y las reglas que lo siguen escribiendo— y se hace como
administrador **dentro** del contenedor de la base, con la clave que ese contenedor ya
tiene: no hace falta definir ninguna contraseña en el servidor para respaldar. Al
restaurar, las vistas y los triggers vuelven con él y la restauración en sí no agrega
filas al historial.

Conviene revisar el código de salida en cualquier tarea programada:

```bash
./scripts/backup.sh || echo "EL RESPALDO FALLÓ" | mail -s "FlexArena" admin@...
```

## Restaurar respaldo

`restore.sh` aplica exactamente la misma verificación **antes** de pedir
confirmación y antes de tocar la base: restaurar desde un respaldo vacío o
truncado deja la base peor que como estaba.

```bash
./scripts/restore.sh backups/flexarena_20260601_120000.sql.gz
```

## Cuando alguien ve «Ocurrió un error inesperado»

La persona ve un mensaje con un **código de referencia** de 8 caracteres, por ejemplo `a1b2c3d4`. Con ese
código se encuentra el detalle en el registro de PHP: la clase del error, el archivo, qué pedido lo
provocó (ruta, IP y usuario) y la traza.

```bash
docker compose exec app grep -A14 "a1b2c3d4" /var/log/apache2/php_errors.log
```

Los mensajes que explican qué corregir («El nick no puede superar los 80 caracteres», «Ya existe un equipo
con ese nombre») **no** llevan código: son reglas que no se cumplieron, no errores del sistema.

## Monitoreo de la BD

```bash
./scripts/monitor_db.sh
# Muestra conteos por tabla, torneos por estado, últimas acciones
```

## Gestión de contenedores Docker

```bash
# Iniciar
./scripts/server_management.sh start

# Ver logs en tiempo real
./scripts/server_management.sh logs

# Acceder al contenedor de PHP
./scripts/server_management.sh shell-app

# Acceder a MySQL
./scripts/server_management.sh shell-db
```

## Quién corrige un resultado

Un resultado ya cargado se corrige de dos maneras, y solo una es directa:

- **El administrador corrige** desde el partido («Corregir»): el cambio se aplica en el acto, con
  motivo obligatorio y registro del valor anterior y el nuevo.
- **El organizador solicita**: ve «Solicitar corrección», solo en los partidos de **sus** torneos, y
  el cambio no se aplica hasta que un administrador lo aprueba en **Correcciones** (o lo rechaza,
  indicando el motivo).

El permiso `resultados / editar` de la matriz de Permisos habilita al organizador a *solicitar*; no
le da la corrección directa, que es solo del administrador. Un pedido armado a mano hacia la
corrección directa, o hacia el partido de otro organizador, se rechaza con «Sin permisos» y queda
anotado en el registro del servidor (`ACCESO_DENEGADO`).

## Corrección de resultados bloqueados

La corrección queda bloqueada cuando el torneo ya construyó algo encima de ese
resultado:

- **Eliminación Directa**: el ganador ya avanzó a la siguiente ronda.
- **Sistema Suizo**: la ronda del partido no es la última generada, porque el
  emparejamiento de la siguiente se armó con esos puntajes.
- **Liga**: nunca se bloquea. El fixture está completo desde el arranque y la
  tabla se recalcula entera.

El bloqueo se aplica igual al organizador que pide la corrección y al
administrador que la aplica, así que una solicitud bloqueada no se registra: el
organizador ve el motivo en el momento.

**Si una solicitud quedó pendiente y ya no se puede aprobar** (era válida cuando
se pidió y el torneo avanzó mientras esperaba), al aprobarla aparece el motivo
del bloqueo y la solicitud sigue pendiente. Rechazala indicando ese motivo: el
sistema no la resuelve por vos a propósito.

Para corregir igual, sabiendo que se rehace el torneo desde ahí:
1. Identificar el enfrentamiento en la ronda posterior
2. Eliminar el enfrentamiento posterior desde MySQL con acceso de root
3. Luego corregir el resultado original

Esta operación debe registrarse manualmente en auditoría.

## Variables de entorno importantes

| Variable | Descripción |
|----------|-------------|
| `APP_ENV` | `development` o `production` |
| `DB_PASS` | Contraseña del usuario flexarena_user |
| `DB_ROOT_PASS` | Contraseña de root MySQL (arranque del contenedor `app`, que la usa para ajustar privilegios y sembrar datos). El respaldo y la restauración no la necesitan en el servidor: usan la del propio contenedor `db` |
| `ADMIN_PASS` | Contraseña inicial del administrador. Vacía = se genera una y se imprime una sola vez en `docker compose logs app` |
| `DEMO_PASS` | Contraseña común de las cuentas de demostración. Vacía = esas cuentas quedan sin clave (lo correcto en producción) |
