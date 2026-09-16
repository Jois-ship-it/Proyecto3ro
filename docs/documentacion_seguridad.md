# Documentación de Seguridad — FlexArena

## Riesgos identificados y medidas aplicadas

| Riesgo | Medida |
|--------|--------|
| SQL Injection | PDO con consultas preparadas (`prepare` + `execute`) en todos los modelos |
| XSS | `View::e()` aplica `htmlspecialchars` en todas las salidas al usuario |
| CSRF | Token único por sesión, validado en todos los formularios POST (`Csrf::checkOrFail()`) |
| Autenticación débil | `password_hash(PASSWORD_BCRYPT, cost:12)` + `password_verify` |
| Sesión fija (fixation) | `session_regenerate_id(true)` en login y cada 30 minutos |
| Acceso no autorizado | `Auth::requireRole()` en cada acción sensible del controlador |
| Exposición de errores | `display_errors = Off` en producción; errores van a log interno |
| Credenciales expuestas | Variables de entorno vía `.env` (excluido del repositorio) |
| Clickjacking | Header `X-Frame-Options: SAMEORIGIN` vía `.htaccess` |
| Sniffing de tipo | Header `X-Content-Type-Options: nosniff` |
| Índices en tablas | Email, estado, torneo_id indexados para evitar full scans |

## Control de acceso por rol

```php
// En cada método de controlador:
$this->requireAdmin();          // solo administrador
$this->requireOrganizador();    // admin + organizador
$this->requireRole(['participante']); // rol específico
```

El middleware verifica la sesión activa y el rol antes de ejecutar cualquier lógica.

## Protección CSRF

Todos los formularios `POST` incluyen:
```html
<input type="hidden" name="csrf_token" value="<?= Csrf::generate() ?>">
```

El servidor valida con `hash_equals()` para prevenir timing attacks.

## Sesiones

- `session.cookie_httponly = 1` (inaccesible desde JavaScript)
- `session.cookie_samesite = Strict` (previene CSRF cross-site)
- `session.use_strict_mode = 1` (previene session fixation)
- En producción: `session.cookie_secure = 1` (solo HTTPS)

## Roles y permisos

El acceso se decide con **tres comprobaciones independientes**, y las tres tienen
que dar verde. Ninguna reemplaza a las otras:

| Compuerta | Pregunta que responde | Dónde vive |
|---|---|---|
| Rol | ¿Es administrador / organizador / participante? | `Auth::requireRole()` |
| Permiso de módulo | ¿Ese rol puede hacer esa acción en ese módulo? | tabla `permisos` → `PermisoService` |
| Propiedad | ¿Es **su** torneo? | `BaseController::requireTorneoOwnership()` |

A eso se suma `modulos.estado`, que apaga un módulo para **todo** el sistema sin
importar qué diga la matriz de permisos.

Detalles de la tabla `permisos`:

- 4 roles definidos en BD: administrador, organizador, participante, público
- Una fila por combinación rol × módulo, con cuatro acciones: ver, crear,
  editar, eliminar
- **Se niega por omisión**: sin fila, el rol no puede nada. Agregar un módulo
  nuevo no le abre la puerta a nadie por accidente
- El administrador tiene acceso total sin pasar por la tabla, y **no se le
  guardan filas**: es lo que impide dejarlo sin acceso editando la matriz
- La matriz se edita desde el panel (Sistema → Permisos) y cada cambio queda
  auditado con el estado anterior y el nuevo
- El contenido sembrado reproduce la sección 5 de la letra del proyecto. En
  particular, «corregir resultados si cuenta con autorización» (§5.2) es
  exactamente la casilla `resultados / editar` del organizador

## Auditoría

Se registran todas las acciones críticas:
- Logins exitosos y fallidos
- Creación/edición/eliminación de usuarios, participantes, equipos
- Creación/configuración de torneos
- Generación de fixture/bracket/rondas
- Carga y corrección de resultados
- Asignación de campeón
- Cambios de estado de torneos

Cada registro incluye: usuario, acción, tabla afectada, ID del registro, descripción, IP, user agent, timestamp.

## Validaciones

- Frontend: JavaScript para UX (no confiable como única validación)
- Backend: validaciones en servicios (`validar()`) antes de toda operación
- Base de datos: constraints (`UNIQUE`, `CHECK`, `NOT NULL`, `ENUM`) como última línea de defensa
