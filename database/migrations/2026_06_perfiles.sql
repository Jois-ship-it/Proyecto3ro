-- ============================================================
-- Migración: perfiles ampliados (participante y equipo)
-- Agrega imagen de perfil/logo y descripción de equipo.
-- No destructiva (columnas nuevas, nullable). Idempotente.
-- ============================================================
SET NAMES utf8mb4;

-- participantes.foto
SET @c := (SELECT COUNT(*) FROM information_schema.columns
           WHERE table_schema=DATABASE() AND table_name='participantes' AND column_name='foto');
SET @s := IF(@c=0, 'ALTER TABLE participantes ADD COLUMN foto VARCHAR(160) DEFAULT NULL AFTER nick',
                   'SELECT "participantes.foto ya existe"');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- equipos.logo
SET @c := (SELECT COUNT(*) FROM information_schema.columns
           WHERE table_schema=DATABASE() AND table_name='equipos' AND column_name='logo');
SET @s := IF(@c=0, 'ALTER TABLE equipos ADD COLUMN logo VARCHAR(160) DEFAULT NULL AFTER nombre',
                   'SELECT "equipos.logo ya existe"');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- equipos.descripcion
SET @c := (SELECT COUNT(*) FROM information_schema.columns
           WHERE table_schema=DATABASE() AND table_name='equipos' AND column_name='descripcion');
SET @s := IF(@c=0, 'ALTER TABLE equipos ADD COLUMN descripcion TEXT DEFAULT NULL AFTER logo',
                   'SELECT "equipos.descripcion ya existe"');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SELECT 'Perfiles: columnas foto/logo/descripcion agregadas.' AS resultado;
