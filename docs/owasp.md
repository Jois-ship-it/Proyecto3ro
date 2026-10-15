# OWASP Top 10 — FlexArena

## A01 — Broken Access Control
**Medidas aplicadas:**
- `Auth::requireRole()` en cada endpoint sensible
- Permiso por módulo y acción en la tabla `permisos`, vía `PermisoService`
- Los organizadores solo acceden a torneos asignados (verificado en servicio)
- Vista pública no expone botones de edición
- Participantes no pueden modificar resultados ni crear torneos
- Aplicar una corrección de resultado es solo del administrador; el organizador la
  solicita, y solo sobre partidos de su torneo (`BaseController::requirePartidoPropio()`).
  Las dos reglas se repiten en el servicio, que no depende de la sesión
- Una compuerta que falla lanza `AccesoDenegado` o `SesionRequerida` en lugar de cortar con
  `exit`; el acceso denegado queda registrado (`ACCESO_DENEGADO`) y todo POST exige token CSRF.
  `tests/autorizacion_test.php` recorre toda la tabla de rutas con cada rol
- Los apartados de cuentas por rol (Organizadores, Administradores) solo tocan cuentas de su
  rol: no se puede degradar a un administrador por la ruta de organizadores
- Los parámetros `{id}` de las rutas se validan como enteros positivos: una URL
  inválida es 404 y no llega a ningún controlador (`Router::PATRONES`)
- Un POST solo puede cambiar su método por `PUT`, `PATCH` o `DELETE` (`_method`); no puede
  hacerse pasar por GET para entrar por rutas que no piden token CSRF (`Router::metodoEfectivo()`)
- El sitio público lee los participantes por la vista `v_participantes_publicos`, que solo
  trae nombre, nick, foto y estado: documento, email y teléfono no salen de la base hacia
  una página pública
- El detalle público de un torneo exige que sea público y no esté en borrador
  (`v_torneos_publicos`): un borrador no se ve conociendo su URL

### Redirecciones no validadas

Varios controladores devuelven al usuario a la pantalla de la que vino usando un
valor del pedido: la cabecera `Referer` (carga, corrección y programación de
resultados, solicitud de corrección) o un campo `return` del formulario (bloqueo
y desbloqueo de cuentas). Esos valores los controla quien manda el pedido.

Sin filtrar, el sistema queda como trampolín: un pedido preparado hace que
FlexArena rebote al visitante hacia un sitio ajeno, y ese salto le presta
credibilidad a una página de phishing —el usuario vio que venía de FlexArena—.

**Medidas aplicadas:**
- Todo destino pasa por `Url::interna()`, que acepta únicamente rutas del propio
  sitio y devuelve el destino de reserva en cualquier otro caso
- Se rechazan las formas habituales de disfrazar un destino externo:
  `//otro-sitio`, `/\otro-sitio`, `javascript:`, `data:`, subdominios que
  empiezan igual (`flexarena.local.sitio-falso.com`) y saltos de línea
- `BaseController::redirect()` aplica el mismo filtro por su cuenta, así que un
  controlador nuevo que se olvide de hacerlo no abre un agujero
- Cubierto por `tests/redireccion_segura_test.php`, que además comprueba que
  ningún controlador pase el valor crudo y que nadie llame a
  `header('Location:')` salteando `redirect()`

## A02 — Cryptographic Failures
**Medidas aplicadas:**
- Contraseñas con `password_hash(PASSWORD_BCRYPT, cost:12)` — no reversible
- Tokens CSRF con `bin2hex(random_bytes(32))` — 256 bits de entropía
- Variables sensibles en `.env` (no en código fuente)
- `seed.sql` no trae contraseñas ni hashes: las cuentas nacen sin clave (`!sin-clave-definida`,
  que ninguna contraseña verifica) y `database/inicializar_cuentas.php` les asigna `ADMIN_PASS`
  y `DEMO_PASS` del `.env`. Si falta la del administrador se genera una al azar y se imprime
  una sola vez; si faltan las demás, quedan sin clave
- El historial de la base (`historial_bd`) no guarda contraseñas ni datos personales

## A03 — Injection
**Medidas aplicadas:**
- Todas las queries usan PDO con parámetros enlazados (`prepare` + `execute`)
- Ninguna query concatena datos del usuario directamente
- Ejemplo: `$stmt = $this->db->prepare("SELECT * FROM tabla WHERE id = :id"); $stmt->execute([':id' => $id]);`
- Búsqueda en las listas: el texto viaja enlazado, se acota y se le escapan los comodines del `LIKE`
  (`Filtros::patronLike()`); los filtros de opción o fecha inválidos se descartan. `LIMIT`/`OFFSET` son enteros
  convertidos. `tests/listados_test.php` prueba textos con `' OR '1'='1` y `UNION SELECT`.
- **CSV injection** en la exportación de reportes: una celda de texto que empieza con `=`, `+`, `-` o `@`
  se neutraliza (`Csv::celda()`), para que un nombre como `=HYPERLINK(...)` no se ejecute en la planilla de quien
  exporta. `tests/csv_test.php` y `tests/reportes_test.php`.

### La API pública y los fetch

- La API `/api` solo lee, sin sesión, y no expone datos personales (`tests/api_test.php`). Otro método es 405.
- Los fetch llevan el token CSRF en la cabecera `X-CSRF-Token`; las compuertas de acceso responden 401/403 en
  JSON, no redirigen, y no se aflojan (`tests/fetch_resultados_test.php`).

### Imágenes subidas y configuración

- Las imágenes se vuelven a codificar y tienen un tope de 16 megapíxeles antes de decodificarse: sin metadatos ni
  código pegado, y sin agotar la memoria (`tests/imagen_test.php`).
- La configuración del sistema es solo del administrador, con CSRF, validación por tipo y rango y auditoría con antes y
  después (`tests/configuracion_sistema_test.php`, `tests/configuracion_pantalla_test.php`).

### Política de seguridad de contenido (CSP)

Sin `'unsafe-inline'` ni `'unsafe-eval'`, con un nonce por pedido (`core/Csp.php`): el navegador no ejecuta código ni
aplica estilos escritos en la página. Eso cierra la puerta que usaba un XSS almacenado hallado en las confirmaciones
(`confirm('¿Aprobar a <nombre>?')`, con el nombre dentro de un literal de JavaScript), que además se eliminó de raíz.
Ver «Política de seguridad de contenido» en `docs/documentacion_seguridad.md` y `tests/frontend_estatico_test.php`.

## A04 — Insecure Design
**Medidas aplicadas:**
- Separación de capas MVC + servicios
- Validación duplicada: frontend (JS) + backend (PHP) + BD (constraints)
- Los textos se validan contra el largo de su columna antes de llegar a la base, con un aviso que
  nombra el campo (`Validator::assertLargos()`)
- Corrección de resultados con bloqueo según estado del torneo
- Las operaciones que escriben varias filas (generar un fixture, registrar una cuenta con su
  perfil, guardar la matriz de permisos, cargar un resultado con su auditoría) corren en una
  transacción, `Database::transaccion()`: o queda todo o no queda nada. Lo prueba
  `tests/transacciones_test.php` cortando cada operación a la mitad
- Reglas de integridad en la propia base (claves foráneas, `CHECK`, índices únicos), que
  siguen valiendo para quien escriba sin pasar por la aplicación

## A05 — Security Misconfiguration
**Medidas aplicadas:**
- `Options -Indexes` en `.htaccess` (no listado de directorios)
- `display_errors = Off` en producción, pero los errores **siempre** se registran: antes
  `error_reporting(0)` apagaba también el log
- Una excepción sin atrapar o un error fatal nunca deja una página en blanco ni muestra detalles
  técnicos: página del sitio con un código de referencia (JSON si el pedido es de la API); el
  detalle técnico solo se ve en desarrollo (`ManejadorErrores`)
- El mensaje de un error de la base de datos no llega a la persona: solo los `ErrorNegocio` muestran
  su texto; el resto se registra con un código y se muestra un aviso genérico
- `X-Frame-Options`, `X-Content-Type-Options`, `X-XSS-Protection` vía `.htaccess`
- phpMyAdmin publicado solo en `127.0.0.1`: funciona desde la propia máquina y no responde
  desde la red. Para administrar un servidor, un túnel SSH
- La base de datos no se publica al host
- La cuenta de la aplicación tiene mínimo privilegio (`database/aplicar_privilegios.php`):
  lee y escribe las tablas de negocio, pero no puede crear ni borrar tablas, ni triggers, ni
  escribir en `historial_bd`. Las credenciales de administrador solo las usa el arranque del
  contenedor y se descartan antes de iniciar Apache
- Los respaldos (`scripts/backup.sh`) se hacen como root dentro del contenedor de la base,
  tomando la clave del propio contenedor; no usan la cuenta de la aplicación

## A06 — Vulnerable Components
**Medidas aplicadas:**
- Sin frameworks externos (menor superficie de ataque)
- PHP 8.2 con parches de seguridad actuales
- MySQL 8.0 con imagen oficial Docker

## A07 — Authentication Failures
**Medidas aplicadas:**
- Session regeneration en cada login y cada 15 minutos; la sesión vence a los 60 minutos de inactividad
- `session.cookie_httponly`, `session.use_strict_mode`, `session.cookie_samesite = Lax`
- La sesión se compara con la base en cada pedido: una cuenta bloqueada, desactivada o con otro rol
  deja de operar en el acto, no cuando expira la sesión (`Auth`)
- Mensajes de error genéricos ("Email o contraseña incorrectos") sin revelar si el usuario existe, y
  sin que el tiempo de respuesta lo delate (con un email inexistente se verifica contra un hash falso)
- Bloqueo temporal de la cuenta tras 5 intentos fallidos, que vence solo, y límite de intentos por IP
  (`intentos_login`); los números son ajustes de `configuracion_sistema`
- No se puede dejar al sistema sin administrador activo
- Registro de intentos fallidos en auditoría

## A08 — Software Integrity Failures
**Medidas aplicadas:**
- Sin dependencias externas descargadas en tiempo de ejecución
- Assets servidos localmente desde `/public/assets/`

## A09 — Logging Failures
**Medidas aplicadas:**
- Tabla `auditoria` registra todas las acciones críticas
- Include: usuario, acción, tabla, registro, valores anterior/nuevo, IP, timestamp
- Tabla `historial_bd`, escrita por triggers de la base: cada INSERT, UPDATE y DELETE de las
  17 tablas de datos queda con el valor anterior, el nuevo, la hora y quién lo hizo (la cuenta
  de la aplicación atribuida al usuario de la sesión). Cubre también lo que no pasa por la
  aplicación —un script, phpMyAdmin—, y la aplicación solo puede leerla, no modificarla
- Logs de error PHP a `/var/log/apache2/php_errors.log`, con un código de referencia por error, el
  pedido (sin query string) y la traza **sin argumentos** (no se filtran contraseñas); el entrypoint deja
  el archivo escribible por `www-data`, que es quien ejecuta PHP

## A10 — Server-Side Request Forgery
**Medidas aplicadas:**
- El sistema no realiza llamadas HTTP a recursos externos
- Sin funcionalidad de webhook o proxy
