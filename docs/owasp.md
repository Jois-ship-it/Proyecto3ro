# OWASP Top 10 — FlexArena

## A01 — Broken Access Control
**Medidas aplicadas:**
- `Auth::requireRole()` en cada endpoint sensible
- Los organizadores solo acceden a torneos asignados (verificado en servicio)
- Vista pública no expone botones de edición
- Participantes no pueden modificar resultados ni crear torneos

## A02 — Cryptographic Failures
**Medidas aplicadas:**
- Contraseñas con `password_hash(PASSWORD_BCRYPT, cost:12)` — no reversible
- Tokens CSRF con `bin2hex(random_bytes(32))` — 256 bits de entropía
- Variables sensibles en `.env` (no en código fuente)

## A03 — Injection
**Medidas aplicadas:**
- Todas las queries usan PDO con parámetros enlazados (`prepare` + `execute`)
- Ninguna query concatena datos del usuario directamente
- Ejemplo: `$stmt = $this->db->prepare("SELECT * FROM tabla WHERE id = :id"); $stmt->execute([':id' => $id]);`

## A04 — Insecure Design
**Medidas aplicadas:**
- Separación de capas MVC + servicios
- Validación duplicada: frontend (JS) + backend (PHP) + BD (constraints)
- Corrección de resultados con bloqueo según estado del torneo

## A05 — Security Misconfiguration
**Medidas aplicadas:**
- `Options -Indexes` en `.htaccess` (no listado de directorios)
- `display_errors = Off` en producción
- `X-Frame-Options`, `X-Content-Type-Options`, `X-XSS-Protection` vía `.htaccess`
- phpMyAdmin solo en red Docker interna

## A06 — Vulnerable Components
**Medidas aplicadas:**
- Sin frameworks externos (menor superficie de ataque)
- PHP 8.2 con parches de seguridad actuales
- MySQL 8.0 con imagen oficial Docker

## A07 — Authentication Failures
**Medidas aplicadas:**
- Session regeneration en cada login y cada 30 minutos
- `session.cookie_httponly`, `session.use_strict_mode`
- Mensajes de error genéricos ("Email o contraseña incorrectos") sin revelar si el usuario existe
- Registro de intentos fallidos en auditoría

## A08 — Software Integrity Failures
**Medidas aplicadas:**
- Sin dependencias externas descargadas en tiempo de ejecución
- Assets servidos localmente desde `/public/assets/`

## A09 — Logging Failures
**Medidas aplicadas:**
- Tabla `auditoria` registra todas las acciones críticas
- Include: usuario, acción, tabla, registro, valores anterior/nuevo, IP, timestamp
- Logs de error PHP a `/var/log/apache2/php_errors.log`

## A10 — Server-Side Request Forgery
**Medidas aplicadas:**
- El sistema no realiza llamadas HTTP a recursos externos
- Sin funcionalidad de webhook o proxy
