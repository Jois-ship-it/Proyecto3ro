# Manual de Administrador — FlexArena

## Administrar usuarios

**Crear usuario:**
1. Panel Admin → Usuarios → Nuevo usuario
2. Completar nombre, email, rol, contraseña
3. Guardar

**Roles disponibles:** administrador, organizador, participante

**Desactivar usuario:**
1. Clic en "Desactivar" en la fila del usuario
2. El usuario no puede iniciar sesión (estado = inactivo)
3. No se elimina para preservar auditoría

## Asignar organizadores a torneos

1. Admin → Torneos → [torneo] → Gestionar
2. Usar el botón "Asignar organizador" (si está disponible) o crear el torneo con organizador

## Revisar auditoría

Panel Admin → Sistema → Auditoría:
- Filtrar por fecha (usar paginación)
- Ver acciones críticas: logins, cambios de resultados, creaciones

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
| `DB_ROOT_PASS` | Contraseña de root MySQL (para restauración) |
