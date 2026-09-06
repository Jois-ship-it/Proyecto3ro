-- ============================================================
-- Corrección de integridad: partidos con fecha_programada FUERA del
-- rango de fechas de su torneo (creados antes de la validación 2026-06).
-- Estrategia: limpiar la fecha (queda "sin programar") para que el
-- organizador la reprograme dentro del rango. Reversible y no destructiva.
-- Idempotente: si no hay inconsistencias, no cambia nada.
-- ============================================================

SET NAMES utf8mb4;

UPDATE enfrentamientos e
JOIN torneos t ON t.id = e.torneo_id
   SET e.fecha_programada = NULL,
       e.updated_at = NOW()
 WHERE e.fecha_programada IS NOT NULL
   AND t.fecha_inicio IS NOT NULL
   AND t.fecha_fin    IS NOT NULL
   AND (e.fecha_programada < CONCAT(t.fecha_inicio, ' 00:00:00')
        OR e.fecha_programada > CONCAT(t.fecha_fin, ' 23:59:59'));

SELECT ROW_COUNT() AS partidos_corregidos;
