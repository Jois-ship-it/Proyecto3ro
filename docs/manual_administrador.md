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
- Activar/desactivar módulos del sistema
- Los módulos desactivados no permiten acciones relacionadas

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
