-- ============================================================
-- Migración: refactor de torneos, organizadores y correcciones
-- Fecha: 2026-06
-- Compatible con datos existentes (no destructiva salvo rol 'publico' sin uso).
-- ============================================================

SET NAMES utf8mb4;

-- ── 1. Mínimo de integrantes por equipo (solo modalidad equipos) ──
-- Se agrega de forma idempotente.
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'torneos'
    AND column_name = 'min_integrantes_equipo'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE torneos ADD COLUMN min_integrantes_equipo INT UNSIGNED DEFAULT NULL AFTER modalidad',
  'SELECT "min_integrantes_equipo ya existe" AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── 2. Tabla de solicitudes de corrección (flujo de aprobación) ──
CREATE TABLE IF NOT EXISTS solicitudes_correccion (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    enfrentamiento_id INT UNSIGNED NOT NULL,
    torneo_id         INT UNSIGNED NOT NULL,
    puntos_a          DECIMAL(7,2) NOT NULL,
    puntos_b          DECIMAL(7,2) NOT NULL,
    motivo            TEXT NOT NULL,
    estado            ENUM('pendiente','aprobada','rechazada') NOT NULL DEFAULT 'pendiente',
    solicitado_por    INT UNSIGNED NOT NULL,
    revisado_por      INT UNSIGNED DEFAULT NULL,
    motivo_rechazo    TEXT DEFAULT NULL,
    created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    resuelto_at       DATETIME DEFAULT NULL,
    FOREIGN KEY (enfrentamiento_id) REFERENCES enfrentamientos(id) ON DELETE CASCADE,
    FOREIGN KEY (torneo_id)         REFERENCES torneos(id)         ON DELETE CASCADE,
    FOREIGN KEY (solicitado_por)    REFERENCES usuarios(id)        ON DELETE CASCADE,
    FOREIGN KEY (revisado_por)      REFERENCES usuarios(id)        ON DELETE SET NULL,
    INDEX idx_estado (estado),
    INDEX idx_torneo (torneo_id),
    INDEX idx_enf (enfrentamiento_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3. Eliminar el rol 'publico' (el usuario público no es una entidad) ──
-- Reasignar por seguridad cualquier usuario que lo tuviera (no debería haber).
UPDATE usuarios u
   JOIN roles r ON r.id = u.rol_id
   SET u.rol_id = (SELECT id FROM roles WHERE nombre = 'participante' LIMIT 1)
 WHERE r.nombre = 'publico';

DELETE FROM permisos WHERE rol_id = (SELECT id FROM roles WHERE nombre = 'publico');
DELETE FROM roles WHERE nombre = 'publico';

SELECT 'Migración aplicada correctamente.' AS resultado;
