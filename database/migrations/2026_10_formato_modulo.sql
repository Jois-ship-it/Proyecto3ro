-- ============================================================
-- Migración: cada formato de torneo pertenece a un módulo de competencia
--
-- `tipos_torneo` (liga, eliminación directa, suizo) y `modulos` (los que el
-- administrador enciende y apaga) estaban relacionados solo por convención: el
-- slug de las dos tablas coincidía y el código lo daba por sentado
-- (TipoTorneoModel::findDisponibles, ModuloActivoTrait). Nada impedía borrar un
-- módulo con formatos, renombrarlo y dejar el formato huérfano, o cargar un formato
-- sin módulo.
--
-- Esta migración lo vuelve una relación del modelo: `tipos_torneo.modulo_slug` con
-- clave foránea a `modulos.slug`. Cada formato existente queda asociado al módulo
-- con su mismo slug, que es lo que el sistema asumía.
--
-- Si algún formato no tiene un módulo con ese slug, la clave foránea FALLA y el
-- error lo dice: hay que crear el módulo (o corregir el slug) y volver a correr.
--
-- Idempotente.
--
-- Ejecutar:
--   docker compose exec -T db mysql -u root -p"$DB_ROOT_PASS" flexarena < database/migrations/2026_10_formato_modulo.sql
-- ============================================================
SET NAMES utf8mb4;

-- 1) La columna, nullable mientras se llena.
SET @existe := (SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                   AND table_name   = 'tipos_torneo'
                   AND column_name  = 'modulo_slug');
SET @sql := IF(@existe = 0,
    'ALTER TABLE tipos_torneo ADD COLUMN modulo_slug VARCHAR(40) NULL AFTER slug',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2) Cada formato queda en el módulo que ya llevaba su mismo slug.
UPDATE tipos_torneo SET modulo_slug = slug WHERE modulo_slug IS NULL;

-- 3) Ya llena, pasa a obligatoria. Redefinir una columna con la definición que ya
--    tiene no cambia nada, así que no necesita guarda.
ALTER TABLE tipos_torneo MODIFY COLUMN modulo_slug VARCHAR(40) NOT NULL;

-- 4) La clave foránea, solo si falta.
SET @existe := (SELECT COUNT(*) FROM information_schema.table_constraints
                 WHERE table_schema    = DATABASE()
                   AND table_name      = 'tipos_torneo'
                   AND constraint_name = 'fk_tipos_torneo_modulo'
                   AND constraint_type = 'FOREIGN KEY');
SET @sql := IF(@existe = 0,
    'ALTER TABLE tipos_torneo ADD CONSTRAINT fk_tipos_torneo_modulo
       FOREIGN KEY (modulo_slug) REFERENCES modulos(slug) ON UPDATE CASCADE',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT 'Cada formato de torneo quedó asociado a su módulo.' AS resultado;
