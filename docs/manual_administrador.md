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

Los demás módulos del panel (`participantes`, `equipos`, `auditoria`,
`consulta_publica`, `resultados`, `torneos`) todavía no tienen efecto funcional:
su estado se guarda pero ningún flujo lo consulta.

## Respaldo de la base de datos

```bash
# Desde el servidor o contenedor
./scripts/backup.sh
# Genera: backups/flexarena_YYYYMMDD_HHMMSS.sql.gz
```

## Restaurar respaldo

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

En Eliminación Directa: si el ganador ya avanzó a la siguiente ronda, la corrección queda bloqueada. Para corregir manualmente:
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
| `APP_SECRET` | Clave secreta de la aplicación (cambiar en producción) |
