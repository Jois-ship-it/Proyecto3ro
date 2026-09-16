-- ============================================================
-- Migración: contador de intentos fallidos y estado 'bloqueada' en usuarios
--
-- La versión anterior no era idempotente y por eso no cumplía su propósito:
-- arrancaba con un `ADD COLUMN failed_attempts` sin guarda, que aborta con
-- «Duplicate column name» si la columna ya está. Como el script se corta ahí,
-- la SEGUNDA sentencia —la que agrega 'bloqueada' al ENUM— nunca llegaba a
-- correr. Es decir: la migración fallaba justo antes de hacer lo que le da
-- nombre.
--
-- El efecto era silencioso y feo. `usuarios.estado` es un ENUM, así que
-- guardar un valor que no está en la lista no da error: MySQL almacena la
-- cadena vacía. El bloqueo por intentos fallidos dejaba las cuentas en '' en
-- vez de 'bloqueada', y todo lo que compara contra ese valor —desbloquear, el
-- toggle de estado, la edición que debe ignorar el estado posteado, el chip de
-- la interfaz— dejaba de funcionar.
--
-- Por qué hace falta tocar el ENUM aunque `schema.sql` ya lo traiga bien:
-- `2026_06_registro_participantes.sql` hace un MODIFY de esa misma columna SIN
-- 'bloqueada' (cuando se escribió, el estado todavía no existía). Aplicada
-- sobre un esquema actual, esa migración se lo lleva puesto. Esta es la que lo
-- devuelve, así que tiene que correr después — el orden por fecha ya lo
-- garantiza.
--
-- Idempotente: se puede correr las veces que haga falta, sobre una base vieja
-- o sobre un esquema recién creado.
--
-- Ejecutar:
--   docker compose exec -T db mysql -u root -p"$DB_ROOT_PASS" flexarena < database/migrations/2026_09_add_failed_attempts_and_bloqueada.sql
-- ============================================================

-- 1) El contador de intentos, solo si falta.
SET @existe := (SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                   AND table_name   = 'usuarios'
                   AND column_name  = 'failed_attempts');

SET @sql := IF(@existe = 0,
    'ALTER TABLE usuarios ADD COLUMN failed_attempts INT NOT NULL DEFAULT 0 AFTER password_hash',
    'SELECT "failed_attempts ya existe" AS resultado');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2) El ENUM con 'bloqueada'. Va siempre: redefinir una columna con la misma
--    definición que ya tiene no cambia nada, así que no necesita guarda.
ALTER TABLE usuarios
  MODIFY COLUMN estado ENUM('pendiente','activo','inactivo','suspendido','rechazado','bloqueada')
  NOT NULL DEFAULT 'activo';

-- 3) Reparar las cuentas que quedaron en '' por la versión rota.
--    La cadena vacía en un ENUM solo aparece cuando se intentó guardar un valor
--    que no estaba en la lista, y el único que la aplicación escribe fuera de
--    la lista era 'bloqueada'. Va DESPUÉS del paso 2, porque antes de ampliar
--    el ENUM ese valor seguiría siendo inválido.
UPDATE usuarios SET estado = 'bloqueada' WHERE estado = '';

-- 4) Verificación: el ENUM que quedó y cuántas cuentas hay en cada estado.
SELECT COLUMN_TYPE AS enum_de_estado
  FROM information_schema.columns
 WHERE table_schema = DATABASE()
   AND table_name   = 'usuarios'
   AND column_name  = 'estado';

SELECT estado, COUNT(*) AS cuentas FROM usuarios GROUP BY estado ORDER BY estado;

SELECT 'failed_attempts y estado bloqueada aplicados.' AS resultado;
