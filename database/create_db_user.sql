-- ============================================================
-- FlexArena — create_db_user.sql
-- Crear la base y el usuario MySQL de la aplicación con permisos mínimos.
-- Ejecutar como root ANTES de levantar el proyecto.
--
-- Este archivo NO contiene la contraseña: llega desde afuera en la variable
-- @db_pass, para que la credencial real viva solo en .env y nunca acá.
--
-- Uso (toma el valor del .env y no lo deja en la línea de comandos ni en el
-- historial del shell):
--
--   set -a; . ./.env; set +a
--   { printf "SET @db_pass='%s';\n" "${DB_PASS//\'/\'\'}"; cat database/create_db_user.sql; } \
--     | mysql -u root -p
--
-- El ${DB_PASS//...} duplica las comillas simples que pudiera traer la
-- contraseña, que es como se escapan dentro de un literal SQL.
--
-- Importante: pasarlo por la ENTRADA ESTÁNDAR como muestra el ejemplo, no con
-- "mysql -e 'SOURCE ...'". Con SOURCE el cliente sigue adelante después de un
-- error, y la guarda de abajo no alcanzaría a frenar nada.
--
-- Si preferís no hacer esto a mano, scripts/install.sh ya crea la base y el
-- usuario leyendo el .env (despliegue sin Docker sobre AlmaLinux).
-- ============================================================

SET @db_user = 'flexarena_user';
SET @db_pass = IFNULL(@db_pass, '');

-- ── Guarda: sin contraseña no se sigue ──────────────────────────────────────
-- Si @db_pass no llegó, se ejecuta un SELECT contra una tabla que no existe:
-- el cliente aborta ahí mismo y el nombre de la tabla es el propio mensaje
-- (se la califica con `mysql.` para que el error no sea «No database selected»).
-- Va ANTES de cualquier otra sentencia para que no quede nada a medio crear.
SET @guarda = IF(
    @db_pass = '',
    'SELECT * FROM mysql.`ERROR_falta_definir_db_pass_ver_encabezado_de_este_archivo`',
    'DO 1'
);
PREPARE chequeo FROM @guarda;
EXECUTE chequeo;
DEALLOCATE PREPARE chequeo;

-- ── Base de datos ───────────────────────────────────────────────────────────
CREATE DATABASE IF NOT EXISTS flexarena
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

-- ── Usuario ─────────────────────────────────────────────────────────────────
-- Se arma con PREPARE porque CREATE USER no acepta variables en IDENTIFIED BY.
-- El REPLACE duplica las comillas simples por si la contraseña trae alguna.
SET @sql = CONCAT(
    'CREATE USER IF NOT EXISTS ''', @db_user, '''@''%'' IDENTIFIED BY ''',
    REPLACE(@db_pass, '''', ''''''), ''''
);
PREPARE crear_usuario FROM @sql;
EXECUTE crear_usuario;
DEALLOCATE PREPARE crear_usuario;

-- Si el usuario ya existía, se le fija igual la contraseña indicada.
SET @sql = CONCAT(
    'ALTER USER ''', @db_user, '''@''%'' IDENTIFIED BY ''',
    REPLACE(@db_pass, '''', ''''''), ''''
);
PREPARE actualizar_pass FROM @sql;
EXECUTE actualizar_pass;
DEALLOCATE PREPARE actualizar_pass;

-- ── Permisos (principio de mínimo privilegio) ───────────────────────────────
GRANT SELECT, INSERT, UPDATE, DELETE ON flexarena.* TO 'flexarena_user'@'%';

-- Revocar permisos administrativos innecesarios
REVOKE CREATE, DROP, ALTER, INDEX, REFERENCES ON flexarena.* FROM 'flexarena_user'@'%';

FLUSH PRIVILEGES;

SELECT 'Usuario flexarena_user creado con permisos mínimos.' AS resultado;
