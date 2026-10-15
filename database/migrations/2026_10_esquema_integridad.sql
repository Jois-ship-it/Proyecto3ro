-- ============================================================
-- Migración: integridad de datos y ajustes de plataforma
--
-- Lleva una base de septiembre al esquema actual de schema.sql:
--
--   1. usuarios.bloqueada_hasta      bloqueo por intentos que vence solo.
--   2. configuracion_sistema         ajustes generales de la plataforma.
--   3. intentos_login                límite de intentos por IP.
--   4. Índices únicos                equipos.nombre y participantes.documento.
--   5. CHECK                         puntos no negativos, rango de fechas del
--                                    torneo y UN competidor por inscripción y
--                                    por fila de posiciones.
--
-- Lo que el servicio ya validaba, ahora lo garantiza también el motor: una
-- escritura que se salte la capa de servicio (un script, phpMyAdmin) no puede
-- dejar datos que la aplicación considera imposibles.
--
-- Si la base tiene datos que violan alguna regla (dos equipos con el mismo
-- nombre, un resultado negativo), la sentencia correspondiente FALLA y el error
-- dice cuál es. No se borra ni se corrige nada por cuenta propia: se arregla el
-- dato a mano y se vuelve a correr, que es seguro.
--
-- Idempotente: se puede correr las veces que haga falta. Todo lo que cambia
-- estructura pasa por una comprobación previa (PREPARE/EXECUTE), porque MySQL no
-- tiene «ADD COLUMN IF NOT EXISTS».
--
-- Ejecutar:
--   docker compose exec -T db mysql -u root -p"$DB_ROOT_PASS" flexarena < database/migrations/2026_10_esquema_integridad.sql
-- ============================================================
SET NAMES utf8mb4;

-- ── 1) usuarios.bloqueada_hasta ─────────────────────────────────────────────
SET @existe := (SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                   AND table_name   = 'usuarios'
                   AND column_name  = 'bloqueada_hasta');
SET @sql := IF(@existe = 0,
    'ALTER TABLE usuarios ADD COLUMN bloqueada_hasta DATETIME DEFAULT NULL AFTER failed_attempts',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── 2) configuracion_sistema ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS configuracion_sistema (
    clave       VARCHAR(60)  NOT NULL PRIMARY KEY,
    valor       TEXT         DEFAULT NULL,
    descripcion VARCHAR(255) DEFAULT NULL,
    updated_by  INT UNSIGNED DEFAULT NULL,
    updated_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Valores por omisión. INSERT IGNORE: si el administrador ya cambió alguno, no se pisa.
INSERT IGNORE INTO configuracion_sistema (clave, valor, descripcion) VALUES
('sitio_nombre',          'FlexArena', 'Nombre de la plataforma, tal como aparece en el sitio.'),
('sitio_contacto',        '',          'Correo de contacto que se muestra en el pie de página. Vacío = no se muestra.'),
('perfiles_publicos',     '1',         '1 = los perfiles de participantes y equipos se ven sin iniciar sesión; 0 = solo con sesión.'),
('login_max_intentos',    '5',         'Intentos de inicio de sesión fallidos antes de bloquear la cuenta.'),
('login_minutos_bloqueo', '15',        'Minutos que dura el bloqueo por intentos fallidos. 0 = hasta que un administrador la desbloquee.'),
('login_max_intentos_ip', '20',        'Intentos fallidos desde una misma IP, dentro de la ventana, antes de rechazar sus pedidos.'),
('login_ventana_minutos', '15',        'Ventana, en minutos, sobre la que se cuentan los intentos fallidos por IP.');

-- ── 3) intentos_login ───────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS intentos_login (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip         VARCHAR(45)  NOT NULL,
    email      VARCHAR(150) DEFAULT NULL,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip_fecha    (ip, created_at),
    INDEX idx_email_fecha (email, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 4) Índices únicos ───────────────────────────────────────────────────────

-- Un participante sin documento es NULL, nunca ''. El índice único tolera varios
-- NULL pero no dos cadenas vacías, y la edición desde el panel guardaba ''.
UPDATE participantes SET documento = NULL WHERE TRIM(documento) = '';

SET @existe := (SELECT COUNT(*) FROM information_schema.statistics
                 WHERE table_schema = DATABASE()
                   AND table_name   = 'participantes'
                   AND index_name   = 'uq_participantes_documento');
SET @sql := IF(@existe = 0,
    'ALTER TABLE participantes ADD UNIQUE KEY uq_participantes_documento (documento)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @existe := (SELECT COUNT(*) FROM information_schema.statistics
                 WHERE table_schema = DATABASE()
                   AND table_name   = 'equipos'
                   AND index_name   = 'uq_equipos_nombre');
SET @sql := IF(@existe = 0,
    'ALTER TABLE equipos ADD UNIQUE KEY uq_equipos_nombre (nombre)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- El índice único ya sirve para buscar por nombre: el común sobra. Va DESPUÉS de
-- crear el único para que la columna no quede sin índice en ningún momento.
SET @existe := (SELECT COUNT(*) FROM information_schema.statistics
                 WHERE table_schema = DATABASE()
                   AND table_name   = 'equipos'
                   AND index_name   = 'idx_nombre');
SET @sql := IF(@existe > 0, 'ALTER TABLE equipos DROP INDEX idx_nombre', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── 5) Restricciones CHECK ──────────────────────────────────────────────────

-- Puntos de un resultado: no negativos.
SET @existe := (SELECT COUNT(*) FROM information_schema.table_constraints
                 WHERE table_schema = DATABASE() AND constraint_type = 'CHECK'
                   AND constraint_name = 'chk_resultados_puntos');
SET @sql := IF(@existe = 0,
    'ALTER TABLE resultados ADD CONSTRAINT chk_resultados_puntos CHECK (puntos_a >= 0 AND puntos_b >= 0)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Lo mismo para los puntos de una solicitud de corrección.
SET @existe := (SELECT COUNT(*) FROM information_schema.table_constraints
                 WHERE table_schema = DATABASE() AND constraint_type = 'CHECK'
                   AND constraint_name = 'chk_solicitudes_puntos');
SET @sql := IF(@existe = 0,
    'ALTER TABLE solicitudes_correccion ADD CONSTRAINT chk_solicitudes_puntos CHECK (puntos_a >= 0 AND puntos_b >= 0)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Un torneo no termina antes de empezar.
SET @existe := (SELECT COUNT(*) FROM information_schema.table_constraints
                 WHERE table_schema = DATABASE() AND constraint_type = 'CHECK'
                   AND constraint_name = 'chk_torneos_fechas');
SET @sql := IF(@existe = 0,
    'ALTER TABLE torneos ADD CONSTRAINT chk_torneos_fechas
       CHECK (fecha_inicio IS NULL OR fecha_fin IS NULL OR fecha_fin >= fecha_inicio)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Una inscripción es de UN competidor. La restricción anterior (chk_inscripcion)
-- pedía «participante O equipo» y dejaba pasar las filas con los dos.
SET @existe := (SELECT COUNT(*) FROM information_schema.table_constraints
                 WHERE table_schema = DATABASE() AND constraint_type = 'CHECK'
                   AND constraint_name = 'chk_inscripcion_un_competidor');
SET @sql := IF(@existe = 0,
    'ALTER TABLE inscripciones ADD CONSTRAINT chk_inscripcion_un_competidor CHECK (
        (participante_id IS NOT NULL AND equipo_id IS NULL)
     OR (participante_id IS NULL AND equipo_id IS NOT NULL))',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @existe := (SELECT COUNT(*) FROM information_schema.table_constraints
                 WHERE table_schema = DATABASE() AND constraint_type = 'CHECK'
                   AND constraint_name = 'chk_inscripcion');
SET @sql := IF(@existe > 0, 'ALTER TABLE inscripciones DROP CONSTRAINT chk_inscripcion', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Lo mismo para la tabla de posiciones.
SET @existe := (SELECT COUNT(*) FROM information_schema.table_constraints
                 WHERE table_schema = DATABASE() AND constraint_type = 'CHECK'
                   AND constraint_name = 'chk_tabla_un_competidor');
SET @sql := IF(@existe = 0,
    'ALTER TABLE tabla_posiciones ADD CONSTRAINT chk_tabla_un_competidor CHECK (
        (participante_id IS NOT NULL AND equipo_id IS NULL)
     OR (participante_id IS NULL AND equipo_id IS NOT NULL))',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @existe := (SELECT COUNT(*) FROM information_schema.table_constraints
                 WHERE table_schema = DATABASE() AND constraint_type = 'CHECK'
                   AND constraint_name = 'chk_tabla');
SET @sql := IF(@existe > 0, 'ALTER TABLE tabla_posiciones DROP CONSTRAINT chk_tabla', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT 'Esquema de integridad aplicado.' AS resultado;
