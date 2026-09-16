-- ============================================================
-- Migración: rondas.estado deja de ser metadata muerta
--
-- 1) Saca 'bloqueada' del ENUM. Significaba lo mismo que 'cerrada' (ronda que
--    ya no admite carga de resultados) y nunca se escribió: dos valores para
--    un mismo estado solo invitan a que se desincronicen.
-- 2) Pone al día los estados existentes según cómo están los partidos de cada
--    ronda, porque hasta ahora solo se escribían 'pendiente' y 'en_curso' y
--    quedaron torneos finalizados con rondas sin cerrar.
--
-- Significado que queda (ver app/services/RondaService.php):
--   pendiente  le faltan cruces por definir (bracket a medio armar)
--   en_curso   jugable, con partidos por resolver
--   cerrada    no admite más carga: o terminaron todos sus partidos,
--              o el organizador la cerró a mano
--
-- No destructiva. Idempotente.
-- ============================================================
SET NAMES utf8mb4;

-- 1) Las rondas que estuvieran en 'bloqueada' pasan a 'cerrada' ANTES de tocar
--    el ENUM (si no, MySQL las convertiría en cadena vacía).
UPDATE rondas SET estado = 'cerrada' WHERE estado = 'bloqueada';

-- 2) Achicar el ENUM.
ALTER TABLE rondas
  MODIFY COLUMN estado ENUM('pendiente','en_curso','cerrada')
  NOT NULL DEFAULT 'pendiente';

-- 3) Cerrar las rondas cuyos partidos ya terminaron todos.
UPDATE rondas r
SET r.estado = 'cerrada'
WHERE r.estado <> 'cerrada'
  AND EXISTS (SELECT 1 FROM enfrentamientos e WHERE e.ronda_id = r.id)
  AND NOT EXISTS (
        SELECT 1 FROM enfrentamientos e
        WHERE e.ronda_id = r.id
          AND e.estado NOT IN ('finalizado','bye','cancelado')
      );

-- 4) Marcar como 'en_curso' las que son jugables (todos sus cruces definidos)
--    y todavía tienen partidos por resolver.
UPDATE rondas r
SET r.estado = 'en_curso'
WHERE r.estado = 'pendiente'
  AND EXISTS (SELECT 1 FROM enfrentamientos e WHERE e.ronda_id = r.id)
  AND NOT EXISTS (
        SELECT 1 FROM enfrentamientos e
        WHERE e.ronda_id = r.id
          AND e.es_bye = 0
          AND ((e.participante_a_id IS NULL AND e.equipo_a_id IS NULL)
            OR (e.participante_b_id IS NULL AND e.equipo_b_id IS NULL))
      );

SELECT 'rondas.estado normalizado' AS resultado;
