-- ============================================================
-- Migración: vincular permisos con modulos vía FK por slug
-- permisos.modulo_slug → modulos.slug (ON UPDATE/DELETE CASCADE)
-- No destructiva. Idempotente.
-- ============================================================
SET NAMES utf8mb4;

-- 1) Asegurar el módulo 'torneos' (usado por permisos pero ausente del catálogo)
INSERT INTO modulos (nombre, slug, estado, descripcion)
SELECT 'Torneos', 'torneos', 'activo', 'Gestión general de torneos'
WHERE NOT EXISTS (SELECT 1 FROM modulos WHERE slug = 'torneos');

-- 2) Crear cualquier otro módulo referenciado por permisos pero inexistente
--    (evita que la FK falle por datos huérfanos preexistentes)
INSERT INTO modulos (nombre, slug, estado, descripcion)
SELECT p.modulo_slug, p.modulo_slug, 'activo', 'Generado por migración FK'
FROM (SELECT DISTINCT modulo_slug FROM permisos) p
WHERE NOT EXISTS (SELECT 1 FROM modulos m WHERE m.slug = p.modulo_slug);

-- 3) Agregar la FK solo si aún no existe
SET @fk := (SELECT COUNT(*) FROM information_schema.table_constraints
            WHERE table_schema=DATABASE() AND table_name='permisos'
              AND constraint_name='fk_permisos_modulo');
SET @s := IF(@fk=0,
  'ALTER TABLE permisos ADD CONSTRAINT fk_permisos_modulo
     FOREIGN KEY (modulo_slug) REFERENCES modulos(slug)
     ON UPDATE CASCADE ON DELETE CASCADE',
  'SELECT "fk_permisos_modulo ya existe"');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SELECT 'FK permisos.modulo_slug → modulos.slug aplicada.' AS resultado;
