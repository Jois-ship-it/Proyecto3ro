-- ============================================================
-- Migración: información temporal de los partidos (enfrentamientos)
-- Fecha: 2026-06
-- Agrega fecha de inicio y fin reales. Idempotente y compatible
-- con datos existentes (backfill desde created_at/updated_at).
-- ============================================================

SET NAMES utf8mb4;

-- ── 1. fecha_inicio_real ────────────────────────────────────
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'enfrentamientos'
    AND column_name = 'fecha_inicio_real'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE enfrentamientos ADD COLUMN fecha_inicio_real DATETIME DEFAULT NULL AFTER fecha_programada',
  'SELECT "fecha_inicio_real ya existe" AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── 2. fecha_fin_real ───────────────────────────────────────
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'enfrentamientos'
    AND column_name = 'fecha_fin_real'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE enfrentamientos ADD COLUMN fecha_fin_real DATETIME DEFAULT NULL AFTER fecha_inicio_real',
  'SELECT "fecha_fin_real ya existe" AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── 3. Backfill de partidos ya finalizados/bye ──────────────
-- Sin dato real previo: aproximamos con created_at (inicio) y updated_at (fin).
UPDATE enfrentamientos
   SET fecha_inicio_real = created_at
 WHERE estado IN ('finalizado','bye') AND fecha_inicio_real IS NULL;

UPDATE enfrentamientos
   SET fecha_fin_real = updated_at
 WHERE estado IN ('finalizado','bye') AND fecha_fin_real IS NULL;

SELECT 'Migración partidos_fechas aplicada correctamente.' AS resultado;
