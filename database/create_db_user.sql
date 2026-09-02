-- ============================================================
-- FlexArena — create_db_user.sql
-- Crear usuario MySQL con permisos mínimos necesarios.
-- Ejecutar como root ANTES de levantar el proyecto.
-- ============================================================

-- Ajustar los valores según el .env del proyecto
SET @db_name = 'flexarena';
SET @db_user = 'flexarena_user';
SET @db_pass = 'FlexArena-2026_Db';

CREATE DATABASE IF NOT EXISTS flexarena
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

-- Crear usuario (si no existe)
CREATE USER IF NOT EXISTS 'flexarena_user'@'%' IDENTIFIED BY 'FlexArena-2026_Db';

-- Otorgar solo los permisos necesarios (principio de mínimo privilegio)
GRANT SELECT, INSERT, UPDATE, DELETE ON flexarena.* TO 'flexarena_user'@'%';

-- Revocar permisos administrativos innecesarios
REVOKE CREATE, DROP, ALTER, INDEX, REFERENCES ON flexarena.* FROM 'flexarena_user'@'%';

FLUSH PRIVILEGES;

SELECT 'Usuario flexarena_user creado con permisos mínimos.' AS resultado;
