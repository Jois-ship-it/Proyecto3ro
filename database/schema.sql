-- ============================================================
-- FlexArena — schema.sql
-- MySQL 8.0 · utf8mb4_unicode_ci
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO';

-- ------------------------------------------------------------
-- roles
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS roles (
    id          TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre      VARCHAR(50)  NOT NULL UNIQUE,
    descripcion VARCHAR(255) DEFAULT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- usuarios
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS usuarios (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rol_id        TINYINT UNSIGNED NOT NULL,
    nombre        VARCHAR(100) NOT NULL,
    email         VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    failed_attempts INT NOT NULL DEFAULT 0,
    -- Hasta cuándo dura un bloqueo por intentos fallidos. Con estado 'bloqueada':
    -- una fecha futura es un bloqueo temporal (se levanta solo al vencer) y NULL
    -- es un bloqueo manual, que solo levanta un administrador.
    bloqueada_hasta DATETIME DEFAULT NULL,
    estado        ENUM('pendiente','activo','inactivo','suspendido','rechazado','bloqueada') NOT NULL DEFAULT 'activo',
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (rol_id) REFERENCES roles(id) ON UPDATE CASCADE,
    INDEX idx_email (email),
    INDEX idx_rol   (rol_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- participantes
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS participantes (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id  INT UNSIGNED DEFAULT NULL,
    nombre      VARCHAR(100) NOT NULL,
    documento   VARCHAR(50)  DEFAULT NULL,
    nick        VARCHAR(80)  DEFAULT NULL,
    foto        VARCHAR(160) DEFAULT NULL,
    email       VARCHAR(150) DEFAULT NULL,
    telefono    VARCHAR(30)  DEFAULT NULL,
    estado      ENUM('pendiente','activo','inactivo','suspendido','rechazado') NOT NULL DEFAULT 'activo',
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_nombre (nombre),
    INDEX idx_usuario (usuario_id),
    -- Un documento identifica a una persona. Sin documento es NULL (nunca ''),
    -- porque el índice único tolera varios NULL pero no dos cadenas vacías.
    UNIQUE KEY uq_participantes_documento (documento)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- equipos
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS equipos (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre      VARCHAR(100) NOT NULL,
    logo        VARCHAR(160) DEFAULT NULL,
    descripcion TEXT         DEFAULT NULL,
    categoria   VARCHAR(80)  DEFAULT NULL,
    disciplina  VARCHAR(80)  DEFAULT NULL,
    estado      ENUM('activo','inactivo') NOT NULL DEFAULT 'activo',
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    -- Dos equipos no pueden llamarse igual (EquipoService ya lo valida; esto lo
    -- garantiza el motor). Reemplaza al índice común idx_nombre: uno único
    -- también sirve para buscar por nombre.
    UNIQUE KEY uq_equipos_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- equipo_participantes
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS equipo_participantes (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    equipo_id       INT UNSIGNED NOT NULL,
    participante_id INT UNSIGNED NOT NULL,
    rol_en_equipo   VARCHAR(60) NOT NULL DEFAULT 'jugador',
    fecha_ingreso   DATE DEFAULT NULL,
    UNIQUE KEY uq_ep (equipo_id, participante_id),
    FOREIGN KEY (equipo_id)       REFERENCES equipos(id)       ON DELETE CASCADE,
    FOREIGN KEY (participante_id) REFERENCES participantes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- modulos
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS modulos (
    id          TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre      VARCHAR(80) NOT NULL,
    slug        VARCHAR(40) NOT NULL UNIQUE,
    estado      ENUM('activo','inactivo') NOT NULL DEFAULT 'activo',
    descripcion VARCHAR(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- tipos_torneo
-- Cada formato (liga, eliminacion directa, suizo) lo implementa un modulo de
-- competencia. Antes la relacion era implicita: el slug de las dos tablas coincidia
-- y nada lo garantizaba. Ahora es una clave foranea: no hay formato sin modulo, un
-- modulo con formatos no se puede borrar, y si cambia su slug el formato lo sigue.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tipos_torneo (
    id          TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre      VARCHAR(60) NOT NULL,
    slug        VARCHAR(40) NOT NULL UNIQUE,
    modulo_slug VARCHAR(40) NOT NULL,
    descripcion TEXT DEFAULT NULL,
    CONSTRAINT fk_tipos_torneo_modulo FOREIGN KEY (modulo_slug) REFERENCES modulos(slug) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- torneos
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS torneos (
    id                       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre                   VARCHAR(150) NOT NULL,
    descripcion              TEXT DEFAULT NULL,
    tipo_torneo_id           TINYINT UNSIGNED NOT NULL,
    modalidad                ENUM('individual','equipos') NOT NULL DEFAULT 'individual',
    min_integrantes_equipo   INT UNSIGNED DEFAULT NULL,
    estado                   ENUM('borrador','inscripcion','en_curso','finalizado','cancelado') NOT NULL DEFAULT 'borrador',
    fecha_inicio             DATE DEFAULT NULL,
    fecha_fin                DATE DEFAULT NULL,
    publico                  TINYINT(1) NOT NULL DEFAULT 1,
    permite_empates          TINYINT(1) NOT NULL DEFAULT 0,
    puntos_victoria          TINYINT NOT NULL DEFAULT 3,
    puntos_empate            TINYINT NOT NULL DEFAULT 1,
    puntos_derrota           TINYINT NOT NULL DEFAULT 0,
    -- Si vale 1, la tabla de posiciones desempata por diferencia y puntos a
    -- favor antes de mirar partidos ganados. Se apaga en formatos donde el
    -- marcador no mide rendimiento (ajedrez: 1, medio punto o 0).
    usa_puntos_favor         TINYINT(1) NOT NULL DEFAULT 1,
    rondas_suizo             TINYINT DEFAULT NULL,
    bye_suizo                ENUM('sin_puntos','victoria','personalizado') DEFAULT 'sin_puntos',
    puntos_bye_suizo         DECIMAL(5,2) NOT NULL DEFAULT 0,
    nombre_puntos            VARCHAR(30) NOT NULL DEFAULT 'puntos',
    campeon_participante_id  INT UNSIGNED DEFAULT NULL,
    campeon_equipo_id        INT UNSIGNED DEFAULT NULL,
    creado_por               INT UNSIGNED NOT NULL,
    created_at               TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at               TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tipo_torneo_id)          REFERENCES tipos_torneo(id) ON UPDATE CASCADE,
    FOREIGN KEY (campeon_participante_id) REFERENCES participantes(id) ON DELETE SET NULL,
    FOREIGN KEY (campeon_equipo_id)       REFERENCES equipos(id)       ON DELETE SET NULL,
    FOREIGN KEY (creado_por)              REFERENCES usuarios(id)       ON UPDATE CASCADE,
    CONSTRAINT chk_torneos_fechas CHECK (fecha_inicio IS NULL OR fecha_fin IS NULL OR fecha_fin >= fecha_inicio),
    INDEX idx_estado (estado),
    INDEX idx_publico (publico)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- torneo_organizadores
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS torneo_organizadores (
    torneo_id  INT UNSIGNED NOT NULL,
    usuario_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (torneo_id, usuario_id),
    FOREIGN KEY (torneo_id)  REFERENCES torneos(id)  ON DELETE CASCADE,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- inscripciones
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS inscripciones (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    torneo_id       INT UNSIGNED NOT NULL,
    participante_id INT UNSIGNED DEFAULT NULL,
    equipo_id       INT UNSIGNED DEFAULT NULL,
    estado          ENUM('activa','retirada') NOT NULL DEFAULT 'activa',
    orden_seed      INT UNSIGNED DEFAULT NULL,
    fecha_inscripcion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_inscripcion_part  (torneo_id, participante_id),
    UNIQUE KEY uq_inscripcion_equipo (torneo_id, equipo_id),
    -- Una inscripción es de UN competidor: un participante o un equipo, no ambos
    -- ni ninguno (el torneo es individual o por equipos).
    CONSTRAINT chk_inscripcion_un_competidor CHECK (
        (participante_id IS NOT NULL AND equipo_id IS NULL)
     OR (participante_id IS NULL AND equipo_id IS NOT NULL)),
    FOREIGN KEY (torneo_id)       REFERENCES torneos(id)       ON DELETE CASCADE,
    FOREIGN KEY (participante_id) REFERENCES participantes(id) ON DELETE CASCADE,
    FOREIGN KEY (equipo_id)       REFERENCES equipos(id)       ON DELETE CASCADE,
    INDEX idx_torneo (torneo_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- rondas
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS rondas (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    torneo_id   INT UNSIGNED NOT NULL,
    numero      TINYINT NOT NULL,
    nombre      VARCHAR(80) NOT NULL,
    -- pendiente: le faltan cruces por definir | en_curso: jugable, con partidos
    -- por resolver | cerrada: no admite mas carga (terminó sola o la cerró el
    -- organizador). Ver app/services/RondaService.php.
    estado      ENUM('pendiente','en_curso','cerrada') NOT NULL DEFAULT 'pendiente',
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ronda (torneo_id, numero),
    FOREIGN KEY (torneo_id) REFERENCES torneos(id) ON DELETE CASCADE,
    INDEX idx_torneo (torneo_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- enfrentamientos
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS enfrentamientos (
    id                       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    torneo_id                INT UNSIGNED NOT NULL,
    ronda_id                 INT UNSIGNED NOT NULL,
    participante_a_id        INT UNSIGNED DEFAULT NULL,
    participante_b_id        INT UNSIGNED DEFAULT NULL,
    equipo_a_id              INT UNSIGNED DEFAULT NULL,
    equipo_b_id              INT UNSIGNED DEFAULT NULL,
    ganador_participante_id  INT UNSIGNED DEFAULT NULL,
    ganador_equipo_id        INT UNSIGNED DEFAULT NULL,
    perdedor_participante_id INT UNSIGNED DEFAULT NULL,
    perdedor_equipo_id       INT UNSIGNED DEFAULT NULL,
    estado                   ENUM('pendiente','en_curso','finalizado','bye','cancelado') NOT NULL DEFAULT 'pendiente',
    es_bye                   TINYINT(1) NOT NULL DEFAULT 0,
    orden                    SMALLINT   NOT NULL DEFAULT 1,
    fecha_programada         DATETIME   DEFAULT NULL,
    fecha_inicio_real        DATETIME   DEFAULT NULL,
    fecha_fin_real           DATETIME   DEFAULT NULL,
    created_at               TIMESTAMP  DEFAULT CURRENT_TIMESTAMP,
    updated_at               TIMESTAMP  DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (torneo_id)                REFERENCES torneos(id)       ON DELETE CASCADE,
    FOREIGN KEY (ronda_id)                 REFERENCES rondas(id)        ON DELETE CASCADE,
    FOREIGN KEY (participante_a_id)        REFERENCES participantes(id) ON DELETE SET NULL,
    FOREIGN KEY (participante_b_id)        REFERENCES participantes(id) ON DELETE SET NULL,
    FOREIGN KEY (equipo_a_id)              REFERENCES equipos(id)       ON DELETE SET NULL,
    FOREIGN KEY (equipo_b_id)              REFERENCES equipos(id)       ON DELETE SET NULL,
    FOREIGN KEY (ganador_participante_id)  REFERENCES participantes(id) ON DELETE SET NULL,
    FOREIGN KEY (ganador_equipo_id)        REFERENCES equipos(id)       ON DELETE SET NULL,
    FOREIGN KEY (perdedor_participante_id) REFERENCES participantes(id) ON DELETE SET NULL,
    FOREIGN KEY (perdedor_equipo_id)       REFERENCES equipos(id)       ON DELETE SET NULL,
    INDEX idx_torneo (torneo_id),
    INDEX idx_ronda  (ronda_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- resultados
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS resultados (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    enfrentamiento_id       INT UNSIGNED NOT NULL UNIQUE,
    puntos_a                DECIMAL(7,2) NOT NULL DEFAULT 0,
    puntos_b                DECIMAL(7,2) NOT NULL DEFAULT 0,
    ganador_participante_id INT UNSIGNED DEFAULT NULL,
    ganador_equipo_id       INT UNSIGNED DEFAULT NULL,
    estado                  ENUM('cargado','corregido') NOT NULL DEFAULT 'cargado',
    cargado_por             INT UNSIGNED DEFAULT NULL,
    corregido               TINYINT(1) NOT NULL DEFAULT 0,
    motivo_correccion       TEXT DEFAULT NULL,
    resultado_anterior_a    DECIMAL(7,2) DEFAULT NULL,
    resultado_anterior_b    DECIMAL(7,2) DEFAULT NULL,
    fecha_carga             TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    fecha_correccion        DATETIME DEFAULT NULL,
    usuario_correccion_id   INT UNSIGNED DEFAULT NULL,
    FOREIGN KEY (enfrentamiento_id)       REFERENCES enfrentamientos(id) ON DELETE CASCADE,
    FOREIGN KEY (ganador_participante_id) REFERENCES participantes(id)   ON DELETE SET NULL,
    FOREIGN KEY (ganador_equipo_id)       REFERENCES equipos(id)         ON DELETE SET NULL,
    FOREIGN KEY (cargado_por)             REFERENCES usuarios(id)        ON DELETE SET NULL,
    FOREIGN KEY (usuario_correccion_id)   REFERENCES usuarios(id)        ON DELETE SET NULL,
    CONSTRAINT chk_resultados_puntos CHECK (puntos_a >= 0 AND puntos_b >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- tabla_posiciones
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tabla_posiciones (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    torneo_id       INT UNSIGNED NOT NULL,
    participante_id INT UNSIGNED DEFAULT NULL,
    equipo_id       INT UNSIGNED DEFAULT NULL,
    pj              SMALLINT NOT NULL DEFAULT 0,
    pg              SMALLINT NOT NULL DEFAULT 0,
    pe              SMALLINT NOT NULL DEFAULT 0,
    pp              SMALLINT NOT NULL DEFAULT 0,
    pf              INT      NOT NULL DEFAULT 0,
    pc              INT      NOT NULL DEFAULT 0,
    diferencia      INT      NOT NULL DEFAULT 0,
    puntos          INT      NOT NULL DEFAULT 0,
    byes_recibidos  TINYINT  NOT NULL DEFAULT 0,
    buchholz        DECIMAL(7,2) NOT NULL DEFAULT 0,
    posicion        SMALLINT NOT NULL DEFAULT 0,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tabla_part  (torneo_id, participante_id),
    UNIQUE KEY uq_tabla_equipo (torneo_id, equipo_id),
    CONSTRAINT chk_tabla_un_competidor CHECK (
        (participante_id IS NOT NULL AND equipo_id IS NULL)
     OR (participante_id IS NULL AND equipo_id IS NOT NULL)),
    FOREIGN KEY (torneo_id)       REFERENCES torneos(id)       ON DELETE CASCADE,
    FOREIGN KEY (participante_id) REFERENCES participantes(id) ON DELETE CASCADE,
    FOREIGN KEY (equipo_id)       REFERENCES equipos(id)       ON DELETE CASCADE,
    INDEX idx_torneo_pos (torneo_id, posicion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- auditoria
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS auditoria (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id     INT UNSIGNED DEFAULT NULL,
    accion         VARCHAR(80)  NOT NULL,
    tabla_afectada VARCHAR(60)  DEFAULT NULL,
    registro_id    INT UNSIGNED DEFAULT NULL,
    descripcion    TEXT         DEFAULT NULL,
    valor_anterior JSON         DEFAULT NULL,
    valor_nuevo    JSON         DEFAULT NULL,
    ip             VARCHAR(45)  DEFAULT NULL,
    user_agent     VARCHAR(255) DEFAULT NULL,
    created_at     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
    INDEX idx_usuario  (usuario_id),
    INDEX idx_accion   (accion),
    INDEX idx_created  (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- permisos
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS permisos (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rol_id         TINYINT UNSIGNED NOT NULL,
    modulo_slug    VARCHAR(40) NOT NULL,
    puede_ver      TINYINT(1) NOT NULL DEFAULT 1,
    puede_crear    TINYINT(1) NOT NULL DEFAULT 0,
    puede_editar   TINYINT(1) NOT NULL DEFAULT 0,
    puede_eliminar TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY uq_permiso (rol_id, modulo_slug),
    FOREIGN KEY (rol_id)      REFERENCES roles(id)     ON DELETE CASCADE,
    FOREIGN KEY (modulo_slug) REFERENCES modulos(slug) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- configuraciones_torneo
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS configuraciones_torneo (
    id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    torneo_id INT UNSIGNED NOT NULL,
    clave     VARCHAR(60)  NOT NULL,
    valor     TEXT         DEFAULT NULL,
    UNIQUE KEY uq_config (torneo_id, clave),
    FOREIGN KEY (torneo_id) REFERENCES torneos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- solicitudes_correccion (flujo de revisión/aprobación de correcciones)
-- ------------------------------------------------------------
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
    CONSTRAINT chk_solicitudes_puntos CHECK (puntos_a >= 0 AND puntos_b >= 0),
    INDEX idx_estado (estado),
    INDEX idx_torneo (torneo_id),
    INDEX idx_enf (enfrentamiento_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- configuracion_sistema
-- Ajustes generales de la plataforma (clave/valor), editables por el
-- administrador. No es la configuración de un torneo: esa vive en
-- configuraciones_torneo. Las claves válidas y sus valores por omisión están en
-- seed.sql; qué acepta cada una lo decide la capa de servicio.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS configuracion_sistema (
    clave       VARCHAR(60)  NOT NULL PRIMARY KEY,
    valor       TEXT         DEFAULT NULL,
    descripcion VARCHAR(255) DEFAULT NULL,
    updated_by  INT UNSIGNED DEFAULT NULL,
    updated_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- intentos_login
-- Inicios de sesión FALLIDOS, para limitar los intentos por dirección IP además
-- de por cuenta. Es un registro operativo: se consulta por ventana de tiempo y
-- se purga solo, por eso empieza vacío y no lleva datos de prueba.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS intentos_login (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip         VARCHAR(45)  NOT NULL,
    email      VARCHAR(150) DEFAULT NULL,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip_fecha    (ip, created_at),
    INDEX idx_email_fecha (email, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- historial_bd
-- Historia de los cambios en los DATOS, escrita por triggers: cada INSERT, UPDATE o DELETE
-- sobre una tabla de datos deja una fila con el registro antes y después. A diferencia de
-- `auditoria` (que registra desde la aplicación qué hizo una persona), esto también captura
-- lo que se haga por fuera de ella: un script, phpMyAdmin, el cliente mysql.
-- La aplicación solo puede LEERLA: la escriben los triggers con los privilegios de quien los
-- creó, así que ni una inyección SQL puede falsificarla ni borrarla.
-- No guarda contraseñas ni datos personales (ver database/generar_historial.php).
-- usuario_app_id no es clave foránea a propósito: el historial tiene que sobrevivir al
-- usuario. usuario_bd es la cuenta real de MySQL (no se puede falsear); usuario_app_id lo
-- fija la aplicación y es informativo.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS historial_bd (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tabla          VARCHAR(64)  NOT NULL,
    operacion      ENUM('INSERT','UPDATE','DELETE') NOT NULL,
    registro_id    VARCHAR(100) NOT NULL,
    valor_anterior JSON DEFAULT NULL,
    valor_nuevo    JSON DEFAULT NULL,
    usuario_app_id INT UNSIGNED DEFAULT NULL,
    usuario_bd     VARCHAR(300) NOT NULL,
    created_at     TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    INDEX idx_tabla_registro (tabla, registro_id),
    INDEX idx_created (created_at),
    INDEX idx_usuario_app (usuario_app_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- >>> HISTORIAL (generado por database/generar_historial.php; no editar a mano)

-- configuracion_sistema
DROP TRIGGER IF EXISTS trg_hist_configuracion_sistema_ai;
CREATE TRIGGER trg_hist_configuracion_sistema_ai AFTER INSERT ON configuracion_sistema FOR EACH ROW
  INSERT INTO historial_bd (tabla, operacion, registro_id, valor_anterior, valor_nuevo, usuario_app_id, usuario_bd)
  VALUES ('configuracion_sistema', 'INSERT', NEW.clave, NULL,
          JSON_OBJECT(
            'clave', NEW.clave,
            'valor', IF(NEW.clave = 'sitio_contacto', '(oculto)', NEW.valor),
            'descripcion', NEW.descripcion, 'updated_by', NEW.updated_by,
            'updated_at', NEW.updated_at),
          @id_usuario_app, USER());

DROP TRIGGER IF EXISTS trg_hist_configuracion_sistema_au;
CREATE TRIGGER trg_hist_configuracion_sistema_au AFTER UPDATE ON configuracion_sistema FOR EACH ROW
  INSERT INTO historial_bd (tabla, operacion, registro_id, valor_anterior, valor_nuevo, usuario_app_id, usuario_bd)
  SELECT 'configuracion_sistema', 'UPDATE', NEW.clave,
         JSON_OBJECT(
            'clave', OLD.clave,
            'valor', IF(OLD.clave = 'sitio_contacto', '(oculto)', OLD.valor),
            'descripcion', OLD.descripcion, 'updated_by', OLD.updated_by,
            'updated_at', OLD.updated_at),
         JSON_OBJECT(
            'clave', NEW.clave,
            'valor', IF(NEW.clave = 'sitio_contacto', '(oculto)', NEW.valor),
            'descripcion', NEW.descripcion, 'updated_by', NEW.updated_by,
            'updated_at', NEW.updated_at),
         @id_usuario_app, USER()
  FROM DUAL
  WHERE NOT (
          OLD.clave <=> NEW.clave AND OLD.valor <=> NEW.valor AND
          OLD.descripcion <=> NEW.descripcion AND OLD.updated_by <=> NEW.updated_by);

DROP TRIGGER IF EXISTS trg_hist_configuracion_sistema_ad;
CREATE TRIGGER trg_hist_configuracion_sistema_ad AFTER DELETE ON configuracion_sistema FOR EACH ROW
  INSERT INTO historial_bd (tabla, operacion, registro_id, valor_anterior, valor_nuevo, usuario_app_id, usuario_bd)
  VALUES ('configuracion_sistema', 'DELETE', OLD.clave,
          JSON_OBJECT(
            'clave', OLD.clave,
            'valor', IF(OLD.clave = 'sitio_contacto', '(oculto)', OLD.valor),
            'descripcion', OLD.descripcion, 'updated_by', OLD.updated_by,
            'updated_at', OLD.updated_at),
          NULL, @id_usuario_app, USER());

-- configuraciones_torneo
DROP TRIGGER IF EXISTS trg_hist_configuraciones_torneo_ai;
CREATE TRIGGER trg_hist_configuraciones_torneo_ai AFTER INSERT ON configuraciones_torneo FOR EACH ROW
  INSERT INTO historial_bd (tabla, operacion, registro_id, valor_anterior, valor_nuevo, usuario_app_id, usuario_bd)
  VALUES ('configuraciones_torneo', 'INSERT', NEW.id, NULL,
          JSON_OBJECT(
            'id', NEW.id, 'torneo_id', NEW.torneo_id, 'clave', NEW.clave,
            'valor', IF(NEW.clave = 'contacto', '(oculto)', NEW.valor)),
          @id_usuario_app, USER());

DROP TRIGGER IF EXISTS trg_hist_configuraciones_torneo_au;
CREATE TRIGGER trg_hist_configuraciones_torneo_au AFTER UPDATE ON configuraciones_torneo FOR EACH ROW
  INSERT INTO historial_bd (tabla, operacion, registro_id, valor_anterior, valor_nuevo, usuario_app_id, usuario_bd)
  SELECT 'configuraciones_torneo', 'UPDATE', NEW.id,
         JSON_OBJECT(
            'id', OLD.id, 'torneo_id', OLD.torneo_id, 'clave', OLD.clave,
            'valor', IF(OLD.clave = 'contacto', '(oculto)', OLD.valor)),
         JSON_OBJECT(
            'id', NEW.id, 'torneo_id', NEW.torneo_id, 'clave', NEW.clave,
            'valor', IF(NEW.clave = 'contacto', '(oculto)', NEW.valor)),
         @id_usuario_app, USER()
  FROM DUAL
  WHERE NOT (
          OLD.id <=> NEW.id AND OLD.torneo_id <=> NEW.torneo_id AND
          OLD.clave <=> NEW.clave AND OLD.valor <=> NEW.valor);

DROP TRIGGER IF EXISTS trg_hist_configuraciones_torneo_ad;
CREATE TRIGGER trg_hist_configuraciones_torneo_ad AFTER DELETE ON configuraciones_torneo FOR EACH ROW
  INSERT INTO historial_bd (tabla, operacion, registro_id, valor_anterior, valor_nuevo, usuario_app_id, usuario_bd)
  VALUES ('configuraciones_torneo', 'DELETE', OLD.id,
          JSON_OBJECT(
            'id', OLD.id, 'torneo_id', OLD.torneo_id, 'clave', OLD.clave,
            'valor', IF(OLD.clave = 'contacto', '(oculto)', OLD.valor)),
          NULL, @id_usuario_app, USER());

-- enfrentamientos
DROP TRIGGER IF EXISTS trg_hist_enfrentamientos_ai;
CREATE TRIGGER trg_hist_enfrentamientos_ai AFTER INSERT ON enfrentamientos FOR EACH ROW
  INSERT INTO historial_bd (tabla, operacion, registro_id, valor_anterior, valor_nuevo, usuario_app_id, usuario_bd)
  VALUES ('enfrentamientos', 'INSERT', NEW.id, NULL,
          JSON_OBJECT(
            'id', NEW.id, 'torneo_id', NEW.torneo_id, 'ronda_id', NEW.ronda_id,
            'participante_a_id', NEW.participante_a_id,
            'participante_b_id', NEW.participante_b_id, 'equipo_a_id', NEW.equipo_a_id,
            'equipo_b_id', NEW.equipo_b_id,
            'ganador_participante_id', NEW.ganador_participante_id,
            'ganador_equipo_id', NEW.ganador_equipo_id,
            'perdedor_participante_id', NEW.perdedor_participante_id,
            'perdedor_equipo_id', NEW.perdedor_equipo_id, 'estado', NEW.estado,
            'es_bye', NEW.es_bye, 'orden', NEW.orden,
            'fecha_programada', NEW.fecha_programada,
            'fecha_inicio_real', NEW.fecha_inicio_real,
            'fecha_fin_real', NEW.fecha_fin_real, 'created_at', NEW.created_at,
            'updated_at', NEW.updated_at),
          @id_usuario_app, USER());

DROP TRIGGER IF EXISTS trg_hist_enfrentamientos_au;
CREATE TRIGGER trg_hist_enfrentamientos_au AFTER UPDATE ON enfrentamientos FOR EACH ROW
  INSERT INTO historial_bd (tabla, operacion, registro_id, valor_anterior, valor_nuevo, usuario_app_id, usuario_bd)
  SELECT 'enfrentamientos', 'UPDATE', NEW.id,
         JSON_OBJECT(
            'id', OLD.id, 'torneo_id', OLD.torneo_id, 'ronda_id', OLD.ronda_id,
            'participante_a_id', OLD.participante_a_id,
            'participante_b_id', OLD.participante_b_id, 'equipo_a_id', OLD.equipo_a_id,
            'equipo_b_id', OLD.equipo_b_id,
            'ganador_participante_id', OLD.ganador_participante_id,
            'ganador_equipo_id', OLD.ganador_equipo_id,
            'perdedor_participante_id', OLD.perdedor_participante_id,
            'perdedor_equipo_id', OLD.perdedor_equipo_id, 'estado', OLD.estado,
            'es_bye', OLD.es_bye, 'orden', OLD.orden,
            'fecha_programada', OLD.fecha_programada,
            'fecha_inicio_real', OLD.fecha_inicio_real,
            'fecha_fin_real', OLD.fecha_fin_real, 'created_at', OLD.created_at,
            'updated_at', OLD.updated_at),
         JSON_OBJECT(
            'id', NEW.id, 'torneo_id', NEW.torneo_id, 'ronda_id', NEW.ronda_id,
            'participante_a_id', NEW.participante_a_id,
            'participante_b_id', NEW.participante_b_id, 'equipo_a_id', NEW.equipo_a_id,
            'equipo_b_id', NEW.equipo_b_id,
            'ganador_participante_id', NEW.ganador_participante_id,
            'ganador_equipo_id', NEW.ganador_equipo_id,
            'perdedor_participante_id', NEW.perdedor_participante_id,
            'perdedor_equipo_id', NEW.perdedor_equipo_id, 'estado', NEW.estado,
            'es_bye', NEW.es_bye, 'orden', NEW.orden,
            'fecha_programada', NEW.fecha_programada,
            'fecha_inicio_real', NEW.fecha_inicio_real,
            'fecha_fin_real', NEW.fecha_fin_real, 'created_at', NEW.created_at,
            'updated_at', NEW.updated_at),
         @id_usuario_app, USER()
  FROM DUAL
  WHERE NOT (
          OLD.id <=> NEW.id AND OLD.torneo_id <=> NEW.torneo_id AND
          OLD.ronda_id <=> NEW.ronda_id AND
          OLD.participante_a_id <=> NEW.participante_a_id AND
          OLD.participante_b_id <=> NEW.participante_b_id AND
          OLD.equipo_a_id <=> NEW.equipo_a_id AND
          OLD.equipo_b_id <=> NEW.equipo_b_id AND
          OLD.ganador_participante_id <=> NEW.ganador_participante_id AND
          OLD.ganador_equipo_id <=> NEW.ganador_equipo_id AND
          OLD.perdedor_participante_id <=> NEW.perdedor_participante_id AND
          OLD.perdedor_equipo_id <=> NEW.perdedor_equipo_id AND
          OLD.estado <=> NEW.estado AND OLD.es_bye <=> NEW.es_bye AND
          OLD.orden <=> NEW.orden AND OLD.fecha_programada <=> NEW.fecha_programada AND
          OLD.fecha_inicio_real <=> NEW.fecha_inicio_real AND
          OLD.fecha_fin_real <=> NEW.fecha_fin_real AND
          OLD.created_at <=> NEW.created_at);

DROP TRIGGER IF EXISTS trg_hist_enfrentamientos_ad;
CREATE TRIGGER trg_hist_enfrentamientos_ad AFTER DELETE ON enfrentamientos FOR EACH ROW
  INSERT INTO historial_bd (tabla, operacion, registro_id, valor_anterior, valor_nuevo, usuario_app_id, usuario_bd)
  VALUES ('enfrentamientos', 'DELETE', OLD.id,
          JSON_OBJECT(
            'id', OLD.id, 'torneo_id', OLD.torneo_id, 'ronda_id', OLD.ronda_id,
            'participante_a_id', OLD.participante_a_id,
            'participante_b_id', OLD.participante_b_id, 'equipo_a_id', OLD.equipo_a_id,
            'equipo_b_id', OLD.equipo_b_id,
            'ganador_participante_id', OLD.ganador_participante_id,
            'ganador_equipo_id', OLD.ganador_equipo_id,
            'perdedor_participante_id', OLD.perdedor_participante_id,
            'perdedor_equipo_id', OLD.perdedor_equipo_id, 'estado', OLD.estado,
            'es_bye', OLD.es_bye, 'orden', OLD.orden,
            'fecha_programada', OLD.fecha_programada,
            'fecha_inicio_real', OLD.fecha_inicio_real,
            'fecha_fin_real', OLD.fecha_fin_real, 'created_at', OLD.created_at,
            'updated_at', OLD.updated_at),
          NULL, @id_usuario_app, USER());

-- equipo_participantes
DROP TRIGGER IF EXISTS trg_hist_equipo_participantes_ai;
CREATE TRIGGER trg_hist_equipo_participantes_ai AFTER INSERT ON equipo_participantes FOR EACH ROW
  INSERT INTO historial_bd (tabla, operacion, registro_id, valor_anterior, valor_nuevo, usuario_app_id, usuario_bd)
  VALUES ('equipo_participantes', 'INSERT', NEW.id, NULL,
          JSON_OBJECT(
            'id', NEW.id, 'equipo_id', NEW.equipo_id,
            'participante_id', NEW.participante_id, 'rol_en_equipo', NEW.rol_en_equipo,
            'fecha_ingreso', NEW.fecha_ingreso),
          @id_usuario_app, USER());

DROP TRIGGER IF EXISTS trg_hist_equipo_participantes_au;
CREATE TRIGGER trg_hist_equipo_participantes_au AFTER UPDATE ON equipo_participantes FOR EACH ROW
  INSERT INTO historial_bd (tabla, operacion, registro_id, valor_anterior, valor_nuevo, usuario_app_id, usuario_bd)
  SELECT 'equipo_participantes', 'UPDATE', NEW.id,
         JSON_OBJECT(
            'id', OLD.id, 'equipo_id', OLD.equipo_id,
            'participante_id', OLD.participante_id, 'rol_en_equipo', OLD.rol_en_equipo,
            'fecha_ingreso', OLD.fecha_ingreso),
         JSON_OBJECT(
            'id', NEW.id, 'equipo_id', NEW.equipo_id,
            'participante_id', NEW.participante_id, 'rol_en_equipo', NEW.rol_en_equipo,
            'fecha_ingreso', NEW.fecha_ingreso),
         @id_usuario_app, USER()
  FROM DUAL
  WHERE NOT (
          OLD.id <=> NEW.id AND OLD.equipo_id <=> NEW.equipo_id AND
          OLD.participante_id <=> NEW.participante_id AND
          OLD.rol_en_equipo <=> NEW.rol_en_equipo AND
          OLD.fecha_ingreso <=> NEW.fecha_ingreso);

DROP TRIGGER IF EXISTS trg_hist_equipo_participantes_ad;
CREATE TRIGGER trg_hist_equipo_participantes_ad AFTER DELETE ON equipo_participantes FOR EACH ROW
  INSERT INTO historial_bd (tabla, operacion, registro_id, valor_anterior, valor_nuevo, usuario_app_id, usuario_bd)
  VALUES ('equipo_participantes', 'DELETE', OLD.id,
          JSON_OBJECT(
            'id', OLD.id, 'equipo_id', OLD.equipo_id,
            'participante_id', OLD.participante_id, 'rol_en_equipo', OLD.rol_en_equipo,
            'fecha_ingreso', OLD.fecha_ingreso),
          NULL, @id_usuario_app, USER());

-- equipos
DROP TRIGGER IF EXISTS trg_hist_equipos_ai;
CREATE TRIGGER trg_hist_equipos_ai AFTER INSERT ON equipos FOR EACH ROW
  INSERT INTO historial_bd (tabla, operacion, registro_id, valor_anterior, valor_nuevo, usuario_app_id, usuario_bd)
  VALUES ('equipos', 'INSERT', NEW.id, NULL,
          JSON_OBJECT(
            'id', NEW.id, 'nombre', NEW.nombre, 'logo', NEW.logo,
            'descripcion', NEW.descripcion, 'categoria', NEW.categoria,
            'disciplina', NEW.disciplina, 'estado', NEW.estado,
            'created_at', NEW.created_at, 'updated_at', NEW.updated_at),
          @id_usuario_app, USER());

DROP TRIGGER IF EXISTS trg_hist_equipos_au;
CREATE TRIGGER trg_hist_equipos_au AFTER UPDATE ON equipos FOR EACH ROW
  INSERT INTO historial_bd (tabla, operacion, registro_id, valor_anterior, valor_nuevo, usuario_app_id, usuario_bd)
  SELECT 'equipos', 'UPDATE', NEW.id,
         JSON_OBJECT(
            'id', OLD.id, 'nombre', OLD.nombre, 'logo', OLD.logo,
            'descripcion', OLD.descripcion, 'categoria', OLD.categoria,
            'disciplina', OLD.disciplina, 'estado', OLD.estado,
            'created_at', OLD.created_at, 'updated_at', OLD.updated_at),
         JSON_OBJECT(
            'id', NEW.id, 'nombre', NEW.nombre, 'logo', NEW.logo,
            'descripcion', NEW.descripcion, 'categoria', NEW.categoria,
            'disciplina', NEW.disciplina, 'estado', NEW.estado,
            'created_at', NEW.created_at, 'updated_at', NEW.updated_at),
         @id_usuario_app, USER()
  FROM DUAL
  WHERE NOT (
          OLD.id <=> NEW.id AND OLD.nombre <=> NEW.nombre AND OLD.logo <=> NEW.logo AND
          OLD.descripcion <=> NEW.descripcion AND OLD.categoria <=> NEW.categoria AND
          OLD.disciplina <=> NEW.disciplina AND OLD.estado <=> NEW.estado AND
          OLD.created_at <=> NEW.created_at);

DROP TRIGGER IF EXISTS trg_hist_equipos_ad;
CREATE TRIGGER trg_hist_equipos_ad AFTER DELETE ON equipos FOR EACH ROW
  INSERT INTO historial_bd (tabla, operacion, registro_id, valor_anterior, valor_nuevo, usuario_app_id, usuario_bd)
  VALUES ('equipos', 'DELETE', OLD.id,
          JSON_OBJECT(
            'id', OLD.id, 'nombre', OLD.nombre, 'logo', OLD.logo,
            'descripcion', OLD.descripcion, 'categoria', OLD.categoria,
            'disciplina', OLD.disciplina, 'estado', OLD.estado,
            'created_at', OLD.created_at, 'updated_at', OLD.updated_at),
          NULL, @id_usuario_app, USER());

-- inscripciones
DROP TRIGGER IF EXISTS trg_hist_inscripciones_ai;
CREATE TRIGGER trg_hist_inscripciones_ai AFTER INSERT ON inscripciones FOR EACH ROW
  INSERT INTO historial_bd (tabla, operacion, registro_id, valor_anterior, valor_nuevo, usuario_app_id, usuario_bd)
  VALUES ('inscripciones', 'INSERT', NEW.id, NULL,
          JSON_OBJECT(
            'id', NEW.id, 'torneo_id', NEW.torneo_id,
            'participante_id', NEW.participante_id, 'equipo_id', NEW.equipo_id,
            'estado', NEW.estado, 'orden_seed', NEW.orden_seed,
            'fecha_inscripcion', NEW.fecha_inscripcion),
          @id_usuario_app, USER());

DROP TRIGGER IF EXISTS trg_hist_inscripciones_au;
CREATE TRIGGER trg_hist_inscripciones_au AFTER UPDATE ON inscripciones FOR EACH ROW
  INSERT INTO historial_bd (tabla, operacion, registro_id, valor_anterior, valor_nuevo, usuario_app_id, usuario_bd)
  SELECT 'inscripciones', 'UPDATE', NEW.id,
         JSON_OBJECT(
            'id', OLD.id, 'torneo_id', OLD.torneo_id,
            'participante_id', OLD.participante_id, 'equipo_id', OLD.equipo_id,
            'estado', OLD.estado, 'orden_seed', OLD.orden_seed,
            'fecha_inscripcion', OLD.fecha_inscripcion),
         JSON_OBJECT(
            'id', NEW.id, 'torneo_id', NEW.torneo_id,
            'participante_id', NEW.participante_id, 'equipo_id', NEW.equipo_id,
            'estado', NEW.estado, 'orden_seed', NEW.orden_seed,
            'fecha_inscripcion', NEW.fecha_inscripcion),
         @id_usuario_app, USER()
  FROM DUAL
  WHERE NOT (
          OLD.id <=> NEW.id AND OLD.torneo_id <=> NEW.torneo_id AND
          OLD.participante_id <=> NEW.participante_id AND
          OLD.equipo_id <=> NEW.equipo_id AND OLD.estado <=> NEW.estado AND
          OLD.orden_seed <=> NEW.orden_seed AND
          OLD.fecha_inscripcion <=> NEW.fecha_inscripcion);

DROP TRIGGER IF EXISTS trg_hist_inscripciones_ad;
CREATE TRIGGER trg_hist_inscripciones_ad AFTER DELETE ON inscripciones FOR EACH ROW
  INSERT INTO historial_bd (tabla, operacion, registro_id, valor_anterior, valor_nuevo, usuario_app_id, usuario_bd)
  VALUES ('inscripciones', 'DELETE', OLD.id,
          JSON_OBJECT(
            'id', OLD.id, 'torneo_id', OLD.torneo_id,
            'participante_id', OLD.participante_id, 'equipo_id', OLD.equipo_id,
            'estado', OLD.estado, 'orden_seed', OLD.orden_seed,
            'fecha_inscripcion', OLD.fecha_inscripcion),
          NULL, @id_usuario_app, USER());

-- modulos
DROP TRIGGER IF EXISTS trg_hist_modulos_ai;
CREATE TRIGGER trg_hist_modulos_ai AFTER INSERT ON modulos FOR EACH ROW
  INSERT INTO historial_bd (tabla, operacion, registro_id, valor_anterior, valor_nuevo, usuario_app_id, usuario_bd)
  VALUES ('modulos', 'INSERT', NEW.id, NULL,
          JSON_OBJECT(
            'id', NEW.id, 'nombre', NEW.nombre, 'slug', NEW.slug, 'estado', NEW.estado,
            'descripcion', NEW.descripcion),
          @id_usuario_app, USER());

DROP TRIGGER IF EXISTS trg_hist_modulos_au;
CREATE TRIGGER trg_hist_modulos_au AFTER UPDATE ON modulos FOR EACH ROW
  INSERT INTO historial_bd (tabla, operacion, registro_id, valor_anterior, valor_nuevo, usuario_app_id, usuario_bd)
  SELECT 'modulos', 'UPDATE', NEW.id,
         JSON_OBJECT(
            'id', OLD.id, 'nombre', OLD.nombre, 'slug', OLD.slug, 'estado', OLD.estado,
            'descripcion', OLD.descripcion),
         JSON_OBJECT(
            'id', NEW.id, 'nombre', NEW.nombre, 'slug', NEW.slug, 'estado', NEW.estado,
            'descripcion', NEW.descripcion),
         @id_usuario_app, USER()
  FROM DUAL
  WHERE NOT (
          OLD.id <=> NEW.id AND OLD.nombre <=> NEW.nombre AND OLD.slug <=> NEW.slug AND
          OLD.estado <=> NEW.estado AND OLD.descripcion <=> NEW.descripcion);

DROP TRIGGER IF EXISTS trg_hist_modulos_ad;
CREATE TRIGGER trg_hist_modulos_ad AFTER DELETE ON modulos FOR EACH ROW
  INSERT INTO historial_bd (tabla, operacion, registro_id, valor_anterior, valor_nuevo, usuario_app_id, usuario_bd)
  VALUES ('modulos', 'DELETE', OLD.id,
          JSON_OBJECT(
            'id', OLD.id, 'nombre', OLD.nombre, 'slug', OLD.slug, 'estado', OLD.estado,
            'descripcion', OLD.descripcion),
          NULL, @id_usuario_app, USER());

-- participantes  (no copia: nombre, documento, nick, email, telefono, foto)
DROP TRIGGER IF EXISTS trg_hist_participantes_ai;
CREATE TRIGGER trg_hist_participantes_ai AFTER INSERT ON participantes FOR EACH ROW
  INSERT INTO historial_bd (tabla, operacion, registro_id, valor_anterior, valor_nuevo, usuario_app_id, usuario_bd)
  VALUES ('participantes', 'INSERT', NEW.id, NULL,
          JSON_OBJECT(
            'id', NEW.id, 'usuario_id', NEW.usuario_id, 'estado', NEW.estado,
            'created_at', NEW.created_at, 'updated_at', NEW.updated_at),
          @id_usuario_app, USER());

DROP TRIGGER IF EXISTS trg_hist_participantes_au;
CREATE TRIGGER trg_hist_participantes_au AFTER UPDATE ON participantes FOR EACH ROW
  INSERT INTO historial_bd (tabla, operacion, registro_id, valor_anterior, valor_nuevo, usuario_app_id, usuario_bd)
  SELECT 'participantes', 'UPDATE', NEW.id,
         JSON_OBJECT(
            'id', OLD.id, 'usuario_id', OLD.usuario_id, 'estado', OLD.estado,
            'created_at', OLD.created_at, 'updated_at', OLD.updated_at),
         JSON_OBJECT(
            'id', NEW.id, 'usuario_id', NEW.usuario_id, 'estado', NEW.estado,
            'created_at', NEW.created_at, 'updated_at', NEW.updated_at,
            'datos_reservados_modificados', NOT (OLD.nombre <=> NEW.nombre AND OLD.documento <=> NEW.documento AND OLD.nick <=> NEW.nick AND OLD.email <=> NEW.email AND OLD.telefono <=> NEW.telefono AND OLD.foto <=> NEW.foto)),
         @id_usuario_app, USER()
  FROM DUAL
  WHERE NOT (
          OLD.id <=> NEW.id AND OLD.usuario_id <=> NEW.usuario_id AND
          OLD.estado <=> NEW.estado AND OLD.created_at <=> NEW.created_at AND
          OLD.nombre <=> NEW.nombre AND OLD.documento <=> NEW.documento AND
          OLD.nick <=> NEW.nick AND OLD.email <=> NEW.email AND
          OLD.telefono <=> NEW.telefono AND OLD.foto <=> NEW.foto);

DROP TRIGGER IF EXISTS trg_hist_participantes_ad;
CREATE TRIGGER trg_hist_participantes_ad AFTER DELETE ON participantes FOR EACH ROW
  INSERT INTO historial_bd (tabla, operacion, registro_id, valor_anterior, valor_nuevo, usuario_app_id, usuario_bd)
  VALUES ('participantes', 'DELETE', OLD.id,
          JSON_OBJECT(
            'id', OLD.id, 'usuario_id', OLD.usuario_id, 'estado', OLD.estado,
            'created_at', OLD.created_at, 'updated_at', OLD.updated_at),
          NULL, @id_usuario_app, USER());

-- permisos
DROP TRIGGER IF EXISTS trg_hist_permisos_ai;
CREATE TRIGGER trg_hist_permisos_ai AFTER INSERT ON permisos FOR EACH ROW
  INSERT INTO historial_bd (tabla, operacion, registro_id, valor_anterior, valor_nuevo, usuario_app_id, usuario_bd)
  VALUES ('permisos', 'INSERT', NEW.id, NULL,
          JSON_OBJECT(
            'id', NEW.id, 'rol_id', NEW.rol_id, 'modulo_slug', NEW.modulo_slug,
            'puede_ver', NEW.puede_ver, 'puede_crear', NEW.puede_crear,
            'puede_editar', NEW.puede_editar, 'puede_eliminar', NEW.puede_eliminar),
          @id_usuario_app, USER());

DROP TRIGGER IF EXISTS trg_hist_permisos_au;
CREATE TRIGGER trg_hist_permisos_au AFTER UPDATE ON permisos FOR EACH ROW
  INSERT INTO historial_bd (tabla, operacion, registro_id, valor_anterior, valor_nuevo, usuario_app_id, usuario_bd)
  SELECT 'permisos', 'UPDATE', NEW.id,
         JSON_OBJECT(
            'id', OLD.id, 'rol_id', OLD.rol_id, 'modulo_slug', OLD.modulo_slug,
            'puede_ver', OLD.puede_ver, 'puede_crear', OLD.puede_crear,
            'puede_editar', OLD.puede_editar, 'puede_eliminar', OLD.puede_eliminar),
         JSON_OBJECT(
            'id', NEW.id, 'rol_id', NEW.rol_id, 'modulo_slug', NEW.modulo_slug,
            'puede_ver', NEW.puede_ver, 'puede_crear', NEW.puede_crear,
            'puede_editar', NEW.puede_editar, 'puede_eliminar', NEW.puede_eliminar),
         @id_usuario_app, USER()
  FROM DUAL
  WHERE NOT (
          OLD.id <=> NEW.id AND OLD.rol_id <=> NEW.rol_id AND
          OLD.modulo_slug <=> NEW.modulo_slug AND OLD.puede_ver <=> NEW.puede_ver AND
          OLD.puede_crear <=> NEW.puede_crear AND
          OLD.puede_editar <=> NEW.puede_editar AND
          OLD.puede_eliminar <=> NEW.puede_eliminar);

DROP TRIGGER IF EXISTS trg_hist_permisos_ad;
CREATE TRIGGER trg_hist_permisos_ad AFTER DELETE ON permisos FOR EACH ROW
  INSERT INTO historial_bd (tabla, operacion, registro_id, valor_anterior, valor_nuevo, usuario_app_id, usuario_bd)
  VALUES ('permisos', 'DELETE', OLD.id,
          JSON_OBJECT(
            'id', OLD.id, 'rol_id', OLD.rol_id, 'modulo_slug', OLD.modulo_slug,
            'puede_ver', OLD.puede_ver, 'puede_crear', OLD.puede_crear,
            'puede_editar', OLD.puede_editar, 'puede_eliminar', OLD.puede_eliminar),
          NULL, @id_usuario_app, USER());

-- resultados
DROP TRIGGER IF EXISTS trg_hist_resultados_ai;
CREATE TRIGGER trg_hist_resultados_ai AFTER INSERT ON resultados FOR EACH ROW
  INSERT INTO historial_bd (tabla, operacion, registro_id, valor_anterior, valor_nuevo, usuario_app_id, usuario_bd)
  VALUES ('resultados', 'INSERT', NEW.id, NULL,
          JSON_OBJECT(
            'id', NEW.id, 'enfrentamiento_id', NEW.enfrentamiento_id,
            'puntos_a', NEW.puntos_a, 'puntos_b', NEW.puntos_b,
            'ganador_participante_id', NEW.ganador_participante_id,
            'ganador_equipo_id', NEW.ganador_equipo_id, 'estado', NEW.estado,
            'cargado_por', NEW.cargado_por, 'corregido', NEW.corregido,
            'motivo_correccion', NEW.motivo_correccion,
            'resultado_anterior_a', NEW.resultado_anterior_a,
            'resultado_anterior_b', NEW.resultado_anterior_b,
            'fecha_carga', NEW.fecha_carga, 'fecha_correccion', NEW.fecha_correccion,
            'usuario_correccion_id', NEW.usuario_correccion_id),
          @id_usuario_app, USER());

DROP TRIGGER IF EXISTS trg_hist_resultados_au;
CREATE TRIGGER trg_hist_resultados_au AFTER UPDATE ON resultados FOR EACH ROW
  INSERT INTO historial_bd (tabla, operacion, registro_id, valor_anterior, valor_nuevo, usuario_app_id, usuario_bd)
  SELECT 'resultados', 'UPDATE', NEW.id,
         JSON_OBJECT(
            'id', OLD.id, 'enfrentamiento_id', OLD.enfrentamiento_id,
            'puntos_a', OLD.puntos_a, 'puntos_b', OLD.puntos_b,
            'ganador_participante_id', OLD.ganador_participante_id,
            'ganador_equipo_id', OLD.ganador_equipo_id, 'estado', OLD.estado,
            'cargado_por', OLD.cargado_por, 'corregido', OLD.corregido,
            'motivo_correccion', OLD.motivo_correccion,
            'resultado_anterior_a', OLD.resultado_anterior_a,
            'resultado_anterior_b', OLD.resultado_anterior_b,
            'fecha_carga', OLD.fecha_carga, 'fecha_correccion', OLD.fecha_correccion,
            'usuario_correccion_id', OLD.usuario_correccion_id),
         JSON_OBJECT(
            'id', NEW.id, 'enfrentamiento_id', NEW.enfrentamiento_id,
            'puntos_a', NEW.puntos_a, 'puntos_b', NEW.puntos_b,
            'ganador_participante_id', NEW.ganador_participante_id,
            'ganador_equipo_id', NEW.ganador_equipo_id, 'estado', NEW.estado,
            'cargado_por', NEW.cargado_por, 'corregido', NEW.corregido,
            'motivo_correccion', NEW.motivo_correccion,
            'resultado_anterior_a', NEW.resultado_anterior_a,
            'resultado_anterior_b', NEW.resultado_anterior_b,
            'fecha_carga', NEW.fecha_carga, 'fecha_correccion', NEW.fecha_correccion,
            'usuario_correccion_id', NEW.usuario_correccion_id),
         @id_usuario_app, USER()
  FROM DUAL
  WHERE NOT (
          OLD.id <=> NEW.id AND OLD.enfrentamiento_id <=> NEW.enfrentamiento_id AND
          OLD.puntos_a <=> NEW.puntos_a AND OLD.puntos_b <=> NEW.puntos_b AND
          OLD.ganador_participante_id <=> NEW.ganador_participante_id AND
          OLD.ganador_equipo_id <=> NEW.ganador_equipo_id AND
          OLD.estado <=> NEW.estado AND OLD.cargado_por <=> NEW.cargado_por AND
          OLD.corregido <=> NEW.corregido AND
          OLD.motivo_correccion <=> NEW.motivo_correccion AND
          OLD.resultado_anterior_a <=> NEW.resultado_anterior_a AND
          OLD.resultado_anterior_b <=> NEW.resultado_anterior_b AND
          OLD.fecha_carga <=> NEW.fecha_carga AND
          OLD.fecha_correccion <=> NEW.fecha_correccion AND
          OLD.usuario_correccion_id <=> NEW.usuario_correccion_id);

DROP TRIGGER IF EXISTS trg_hist_resultados_ad;
CREATE TRIGGER trg_hist_resultados_ad AFTER DELETE ON resultados FOR EACH ROW
  INSERT INTO historial_bd (tabla, operacion, registro_id, valor_anterior, valor_nuevo, usuario_app_id, usuario_bd)
  VALUES ('resultados', 'DELETE', OLD.id,
          JSON_OBJECT(
            'id', OLD.id, 'enfrentamiento_id', OLD.enfrentamiento_id,
            'puntos_a', OLD.puntos_a, 'puntos_b', OLD.puntos_b,
            'ganador_participante_id', OLD.ganador_participante_id,
            'ganador_equipo_id', OLD.ganador_equipo_id, 'estado', OLD.estado,
            'cargado_por', OLD.cargado_por, 'corregido', OLD.corregido,
            'motivo_correccion', OLD.motivo_correccion,
            'resultado_anterior_a', OLD.resultado_anterior_a,
            'resultado_anterior_b', OLD.resultado_anterior_b,
            'fecha_carga', OLD.fecha_carga, 'fecha_correccion', OLD.fecha_correccion,
            'usuario_correccion_id', OLD.usuario_correccion_id),
          NULL, @id_usuario_app, USER());

-- roles
DROP TRIGGER IF EXISTS trg_hist_roles_ai;
CREATE TRIGGER trg_hist_roles_ai AFTER INSERT ON roles FOR EACH ROW
  INSERT INTO historial_bd (tabla, operacion, registro_id, valor_anterior, valor_nuevo, usuario_app_id, usuario_bd)
  VALUES ('roles', 'INSERT', NEW.id, NULL,
          JSON_OBJECT(
            'id', NEW.id, 'nombre', NEW.nombre, 'descripcion', NEW.descripcion,
            'created_at', NEW.created_at),
          @id_usuario_app, USER());

DROP TRIGGER IF EXISTS trg_hist_roles_au;
CREATE TRIGGER trg_hist_roles_au AFTER UPDATE ON roles FOR EACH ROW
  INSERT INTO historial_bd (tabla, operacion, registro_id, valor_anterior, valor_nuevo, usuario_app_id, usuario_bd)
  SELECT 'roles', 'UPDATE', NEW.id,
         JSON_OBJECT(
            'id', OLD.id, 'nombre', OLD.nombre, 'descripcion', OLD.descripcion,
            'created_at', OLD.created_at),
         JSON_OBJECT(
            'id', NEW.id, 'nombre', NEW.nombre, 'descripcion', NEW.descripcion,
            'created_at', NEW.created_at),
         @id_usuario_app, USER()
  FROM DUAL
  WHERE NOT (
          OLD.id <=> NEW.id AND OLD.nombre <=> NEW.nombre AND
          OLD.descripcion <=> NEW.descripcion AND OLD.created_at <=> NEW.created_at);

DROP TRIGGER IF EXISTS trg_hist_roles_ad;
CREATE TRIGGER trg_hist_roles_ad AFTER DELETE ON roles FOR EACH ROW
  INSERT INTO historial_bd (tabla, operacion, registro_id, valor_anterior, valor_nuevo, usuario_app_id, usuario_bd)
  VALUES ('roles', 'DELETE', OLD.id,
          JSON_OBJECT(
            'id', OLD.id, 'nombre', OLD.nombre, 'descripcion', OLD.descripcion,
            'created_at', OLD.created_at),
          NULL, @id_usuario_app, USER());

-- rondas
DROP TRIGGER IF EXISTS trg_hist_rondas_ai;
CREATE TRIGGER trg_hist_rondas_ai AFTER INSERT ON rondas FOR EACH ROW
  INSERT INTO historial_bd (tabla, operacion, registro_id, valor_anterior, valor_nuevo, usuario_app_id, usuario_bd)
  VALUES ('rondas', 'INSERT', NEW.id, NULL,
          JSON_OBJECT(
            'id', NEW.id, 'torneo_id', NEW.torneo_id, 'numero', NEW.numero,
            'nombre', NEW.nombre, 'estado', NEW.estado, 'created_at', NEW.created_at),
          @id_usuario_app, USER());

DROP TRIGGER IF EXISTS trg_hist_rondas_au;
CREATE TRIGGER trg_hist_rondas_au AFTER UPDATE ON rondas FOR EACH ROW
  INSERT INTO historial_bd (tabla, operacion, registro_id, valor_anterior, valor_nuevo, usuario_app_id, usuario_bd)
  SELECT 'rondas', 'UPDATE', NEW.id,
         JSON_OBJECT(
            'id', OLD.id, 'torneo_id', OLD.torneo_id, 'numero', OLD.numero,
            'nombre', OLD.nombre, 'estado', OLD.estado, 'created_at', OLD.created_at),
         JSON_OBJECT(
            'id', NEW.id, 'torneo_id', NEW.torneo_id, 'numero', NEW.numero,
            'nombre', NEW.nombre, 'estado', NEW.estado, 'created_at', NEW.created_at),
         @id_usuario_app, USER()
  FROM DUAL
  WHERE NOT (
          OLD.id <=> NEW.id AND OLD.torneo_id <=> NEW.torneo_id AND
          OLD.numero <=> NEW.numero AND OLD.nombre <=> NEW.nombre AND
          OLD.estado <=> NEW.estado AND OLD.created_at <=> NEW.created_at);

DROP TRIGGER IF EXISTS trg_hist_rondas_ad;
CREATE TRIGGER trg_hist_rondas_ad AFTER DELETE ON rondas FOR EACH ROW
  INSERT INTO historial_bd (tabla, operacion, registro_id, valor_anterior, valor_nuevo, usuario_app_id, usuario_bd)
  VALUES ('rondas', 'DELETE', OLD.id,
          JSON_OBJECT(
            'id', OLD.id, 'torneo_id', OLD.torneo_id, 'numero', OLD.numero,
            'nombre', OLD.nombre, 'estado', OLD.estado, 'created_at', OLD.created_at),
          NULL, @id_usuario_app, USER());

-- solicitudes_correccion
DROP TRIGGER IF EXISTS trg_hist_solicitudes_correccion_ai;
CREATE TRIGGER trg_hist_solicitudes_correccion_ai AFTER INSERT ON solicitudes_correccion FOR EACH ROW
  INSERT INTO historial_bd (tabla, operacion, registro_id, valor_anterior, valor_nuevo, usuario_app_id, usuario_bd)
  VALUES ('solicitudes_correccion', 'INSERT', NEW.id, NULL,
          JSON_OBJECT(
            'id', NEW.id, 'enfrentamiento_id', NEW.enfrentamiento_id,
            'torneo_id', NEW.torneo_id, 'puntos_a', NEW.puntos_a, 'puntos_b', NEW.puntos_b,
            'motivo', NEW.motivo, 'estado', NEW.estado,
            'solicitado_por', NEW.solicitado_por, 'revisado_por', NEW.revisado_por,
            'motivo_rechazo', NEW.motivo_rechazo, 'created_at', NEW.created_at,
            'resuelto_at', NEW.resuelto_at),
          @id_usuario_app, USER());

DROP TRIGGER IF EXISTS trg_hist_solicitudes_correccion_au;
CREATE TRIGGER trg_hist_solicitudes_correccion_au AFTER UPDATE ON solicitudes_correccion FOR EACH ROW
  INSERT INTO historial_bd (tabla, operacion, registro_id, valor_anterior, valor_nuevo, usuario_app_id, usuario_bd)
  SELECT 'solicitudes_correccion', 'UPDATE', NEW.id,
         JSON_OBJECT(
            'id', OLD.id, 'enfrentamiento_id', OLD.enfrentamiento_id,
            'torneo_id', OLD.torneo_id, 'puntos_a', OLD.puntos_a, 'puntos_b', OLD.puntos_b,
            'motivo', OLD.motivo, 'estado', OLD.estado,
            'solicitado_por', OLD.solicitado_por, 'revisado_por', OLD.revisado_por,
            'motivo_rechazo', OLD.motivo_rechazo, 'created_at', OLD.created_at,
            'resuelto_at', OLD.resuelto_at),
         JSON_OBJECT(
            'id', NEW.id, 'enfrentamiento_id', NEW.enfrentamiento_id,
            'torneo_id', NEW.torneo_id, 'puntos_a', NEW.puntos_a, 'puntos_b', NEW.puntos_b,
            'motivo', NEW.motivo, 'estado', NEW.estado,
            'solicitado_por', NEW.solicitado_por, 'revisado_por', NEW.revisado_por,
            'motivo_rechazo', NEW.motivo_rechazo, 'created_at', NEW.created_at,
            'resuelto_at', NEW.resuelto_at),
         @id_usuario_app, USER()
  FROM DUAL
  WHERE NOT (
          OLD.id <=> NEW.id AND OLD.enfrentamiento_id <=> NEW.enfrentamiento_id AND
          OLD.torneo_id <=> NEW.torneo_id AND OLD.puntos_a <=> NEW.puntos_a AND
          OLD.puntos_b <=> NEW.puntos_b AND OLD.motivo <=> NEW.motivo AND
          OLD.estado <=> NEW.estado AND OLD.solicitado_por <=> NEW.solicitado_por AND
          OLD.revisado_por <=> NEW.revisado_por AND
          OLD.motivo_rechazo <=> NEW.motivo_rechazo AND
          OLD.created_at <=> NEW.created_at AND OLD.resuelto_at <=> NEW.resuelto_at);

DROP TRIGGER IF EXISTS trg_hist_solicitudes_correccion_ad;
CREATE TRIGGER trg_hist_solicitudes_correccion_ad AFTER DELETE ON solicitudes_correccion FOR EACH ROW
  INSERT INTO historial_bd (tabla, operacion, registro_id, valor_anterior, valor_nuevo, usuario_app_id, usuario_bd)
  VALUES ('solicitudes_correccion', 'DELETE', OLD.id,
          JSON_OBJECT(
            'id', OLD.id, 'enfrentamiento_id', OLD.enfrentamiento_id,
            'torneo_id', OLD.torneo_id, 'puntos_a', OLD.puntos_a, 'puntos_b', OLD.puntos_b,
            'motivo', OLD.motivo, 'estado', OLD.estado,
            'solicitado_por', OLD.solicitado_por, 'revisado_por', OLD.revisado_por,
            'motivo_rechazo', OLD.motivo_rechazo, 'created_at', OLD.created_at,
            'resuelto_at', OLD.resuelto_at),
          NULL, @id_usuario_app, USER());

-- tipos_torneo
DROP TRIGGER IF EXISTS trg_hist_tipos_torneo_ai;
CREATE TRIGGER trg_hist_tipos_torneo_ai AFTER INSERT ON tipos_torneo FOR EACH ROW
  INSERT INTO historial_bd (tabla, operacion, registro_id, valor_anterior, valor_nuevo, usuario_app_id, usuario_bd)
  VALUES ('tipos_torneo', 'INSERT', NEW.id, NULL,
          JSON_OBJECT(
            'id', NEW.id, 'nombre', NEW.nombre, 'slug', NEW.slug,
            'modulo_slug', NEW.modulo_slug, 'descripcion', NEW.descripcion),
          @id_usuario_app, USER());

DROP TRIGGER IF EXISTS trg_hist_tipos_torneo_au;
CREATE TRIGGER trg_hist_tipos_torneo_au AFTER UPDATE ON tipos_torneo FOR EACH ROW
  INSERT INTO historial_bd (tabla, operacion, registro_id, valor_anterior, valor_nuevo, usuario_app_id, usuario_bd)
  SELECT 'tipos_torneo', 'UPDATE', NEW.id,
         JSON_OBJECT(
            'id', OLD.id, 'nombre', OLD.nombre, 'slug', OLD.slug,
            'modulo_slug', OLD.modulo_slug, 'descripcion', OLD.descripcion),
         JSON_OBJECT(
            'id', NEW.id, 'nombre', NEW.nombre, 'slug', NEW.slug,
            'modulo_slug', NEW.modulo_slug, 'descripcion', NEW.descripcion),
         @id_usuario_app, USER()
  FROM DUAL
  WHERE NOT (
          OLD.id <=> NEW.id AND OLD.nombre <=> NEW.nombre AND OLD.slug <=> NEW.slug AND
          OLD.modulo_slug <=> NEW.modulo_slug AND OLD.descripcion <=> NEW.descripcion);

DROP TRIGGER IF EXISTS trg_hist_tipos_torneo_ad;
CREATE TRIGGER trg_hist_tipos_torneo_ad AFTER DELETE ON tipos_torneo FOR EACH ROW
  INSERT INTO historial_bd (tabla, operacion, registro_id, valor_anterior, valor_nuevo, usuario_app_id, usuario_bd)
  VALUES ('tipos_torneo', 'DELETE', OLD.id,
          JSON_OBJECT(
            'id', OLD.id, 'nombre', OLD.nombre, 'slug', OLD.slug,
            'modulo_slug', OLD.modulo_slug, 'descripcion', OLD.descripcion),
          NULL, @id_usuario_app, USER());

-- torneo_organizadores
DROP TRIGGER IF EXISTS trg_hist_torneo_organizadores_ai;
CREATE TRIGGER trg_hist_torneo_organizadores_ai AFTER INSERT ON torneo_organizadores FOR EACH ROW
  INSERT INTO historial_bd (tabla, operacion, registro_id, valor_anterior, valor_nuevo, usuario_app_id, usuario_bd)
  VALUES ('torneo_organizadores', 'INSERT', CONCAT_WS('-', NEW.torneo_id, NEW.usuario_id), NULL,
          JSON_OBJECT(
            'torneo_id', NEW.torneo_id, 'usuario_id', NEW.usuario_id),
          @id_usuario_app, USER());

DROP TRIGGER IF EXISTS trg_hist_torneo_organizadores_au;
CREATE TRIGGER trg_hist_torneo_organizadores_au AFTER UPDATE ON torneo_organizadores FOR EACH ROW
  INSERT INTO historial_bd (tabla, operacion, registro_id, valor_anterior, valor_nuevo, usuario_app_id, usuario_bd)
  SELECT 'torneo_organizadores', 'UPDATE', CONCAT_WS('-', NEW.torneo_id, NEW.usuario_id),
         JSON_OBJECT(
            'torneo_id', OLD.torneo_id, 'usuario_id', OLD.usuario_id),
         JSON_OBJECT(
            'torneo_id', NEW.torneo_id, 'usuario_id', NEW.usuario_id),
         @id_usuario_app, USER()
  FROM DUAL
  WHERE NOT (
          OLD.torneo_id <=> NEW.torneo_id AND OLD.usuario_id <=> NEW.usuario_id);

DROP TRIGGER IF EXISTS trg_hist_torneo_organizadores_ad;
CREATE TRIGGER trg_hist_torneo_organizadores_ad AFTER DELETE ON torneo_organizadores FOR EACH ROW
  INSERT INTO historial_bd (tabla, operacion, registro_id, valor_anterior, valor_nuevo, usuario_app_id, usuario_bd)
  VALUES ('torneo_organizadores', 'DELETE', CONCAT_WS('-', OLD.torneo_id, OLD.usuario_id),
          JSON_OBJECT(
            'torneo_id', OLD.torneo_id, 'usuario_id', OLD.usuario_id),
          NULL, @id_usuario_app, USER());

-- torneos
DROP TRIGGER IF EXISTS trg_hist_torneos_ai;
CREATE TRIGGER trg_hist_torneos_ai AFTER INSERT ON torneos FOR EACH ROW
  INSERT INTO historial_bd (tabla, operacion, registro_id, valor_anterior, valor_nuevo, usuario_app_id, usuario_bd)
  VALUES ('torneos', 'INSERT', NEW.id, NULL,
          JSON_OBJECT(
            'id', NEW.id, 'nombre', NEW.nombre, 'descripcion', NEW.descripcion,
            'tipo_torneo_id', NEW.tipo_torneo_id, 'modalidad', NEW.modalidad,
            'min_integrantes_equipo', NEW.min_integrantes_equipo, 'estado', NEW.estado,
            'fecha_inicio', NEW.fecha_inicio, 'fecha_fin', NEW.fecha_fin,
            'publico', NEW.publico, 'permite_empates', NEW.permite_empates,
            'puntos_victoria', NEW.puntos_victoria, 'puntos_empate', NEW.puntos_empate,
            'puntos_derrota', NEW.puntos_derrota, 'usa_puntos_favor', NEW.usa_puntos_favor,
            'rondas_suizo', NEW.rondas_suizo, 'bye_suizo', NEW.bye_suizo,
            'puntos_bye_suizo', NEW.puntos_bye_suizo, 'nombre_puntos', NEW.nombre_puntos,
            'campeon_participante_id', NEW.campeon_participante_id,
            'campeon_equipo_id', NEW.campeon_equipo_id, 'creado_por', NEW.creado_por,
            'created_at', NEW.created_at, 'updated_at', NEW.updated_at),
          @id_usuario_app, USER());

DROP TRIGGER IF EXISTS trg_hist_torneos_au;
CREATE TRIGGER trg_hist_torneos_au AFTER UPDATE ON torneos FOR EACH ROW
  INSERT INTO historial_bd (tabla, operacion, registro_id, valor_anterior, valor_nuevo, usuario_app_id, usuario_bd)
  SELECT 'torneos', 'UPDATE', NEW.id,
         JSON_OBJECT(
            'id', OLD.id, 'nombre', OLD.nombre, 'descripcion', OLD.descripcion,
            'tipo_torneo_id', OLD.tipo_torneo_id, 'modalidad', OLD.modalidad,
            'min_integrantes_equipo', OLD.min_integrantes_equipo, 'estado', OLD.estado,
            'fecha_inicio', OLD.fecha_inicio, 'fecha_fin', OLD.fecha_fin,
            'publico', OLD.publico, 'permite_empates', OLD.permite_empates,
            'puntos_victoria', OLD.puntos_victoria, 'puntos_empate', OLD.puntos_empate,
            'puntos_derrota', OLD.puntos_derrota, 'usa_puntos_favor', OLD.usa_puntos_favor,
            'rondas_suizo', OLD.rondas_suizo, 'bye_suizo', OLD.bye_suizo,
            'puntos_bye_suizo', OLD.puntos_bye_suizo, 'nombre_puntos', OLD.nombre_puntos,
            'campeon_participante_id', OLD.campeon_participante_id,
            'campeon_equipo_id', OLD.campeon_equipo_id, 'creado_por', OLD.creado_por,
            'created_at', OLD.created_at, 'updated_at', OLD.updated_at),
         JSON_OBJECT(
            'id', NEW.id, 'nombre', NEW.nombre, 'descripcion', NEW.descripcion,
            'tipo_torneo_id', NEW.tipo_torneo_id, 'modalidad', NEW.modalidad,
            'min_integrantes_equipo', NEW.min_integrantes_equipo, 'estado', NEW.estado,
            'fecha_inicio', NEW.fecha_inicio, 'fecha_fin', NEW.fecha_fin,
            'publico', NEW.publico, 'permite_empates', NEW.permite_empates,
            'puntos_victoria', NEW.puntos_victoria, 'puntos_empate', NEW.puntos_empate,
            'puntos_derrota', NEW.puntos_derrota, 'usa_puntos_favor', NEW.usa_puntos_favor,
            'rondas_suizo', NEW.rondas_suizo, 'bye_suizo', NEW.bye_suizo,
            'puntos_bye_suizo', NEW.puntos_bye_suizo, 'nombre_puntos', NEW.nombre_puntos,
            'campeon_participante_id', NEW.campeon_participante_id,
            'campeon_equipo_id', NEW.campeon_equipo_id, 'creado_por', NEW.creado_por,
            'created_at', NEW.created_at, 'updated_at', NEW.updated_at),
         @id_usuario_app, USER()
  FROM DUAL
  WHERE NOT (
          OLD.id <=> NEW.id AND OLD.nombre <=> NEW.nombre AND
          OLD.descripcion <=> NEW.descripcion AND
          OLD.tipo_torneo_id <=> NEW.tipo_torneo_id AND
          OLD.modalidad <=> NEW.modalidad AND
          OLD.min_integrantes_equipo <=> NEW.min_integrantes_equipo AND
          OLD.estado <=> NEW.estado AND OLD.fecha_inicio <=> NEW.fecha_inicio AND
          OLD.fecha_fin <=> NEW.fecha_fin AND OLD.publico <=> NEW.publico AND
          OLD.permite_empates <=> NEW.permite_empates AND
          OLD.puntos_victoria <=> NEW.puntos_victoria AND
          OLD.puntos_empate <=> NEW.puntos_empate AND
          OLD.puntos_derrota <=> NEW.puntos_derrota AND
          OLD.usa_puntos_favor <=> NEW.usa_puntos_favor AND
          OLD.rondas_suizo <=> NEW.rondas_suizo AND OLD.bye_suizo <=> NEW.bye_suizo AND
          OLD.puntos_bye_suizo <=> NEW.puntos_bye_suizo AND
          OLD.nombre_puntos <=> NEW.nombre_puntos AND
          OLD.campeon_participante_id <=> NEW.campeon_participante_id AND
          OLD.campeon_equipo_id <=> NEW.campeon_equipo_id AND
          OLD.creado_por <=> NEW.creado_por AND OLD.created_at <=> NEW.created_at);

DROP TRIGGER IF EXISTS trg_hist_torneos_ad;
CREATE TRIGGER trg_hist_torneos_ad AFTER DELETE ON torneos FOR EACH ROW
  INSERT INTO historial_bd (tabla, operacion, registro_id, valor_anterior, valor_nuevo, usuario_app_id, usuario_bd)
  VALUES ('torneos', 'DELETE', OLD.id,
          JSON_OBJECT(
            'id', OLD.id, 'nombre', OLD.nombre, 'descripcion', OLD.descripcion,
            'tipo_torneo_id', OLD.tipo_torneo_id, 'modalidad', OLD.modalidad,
            'min_integrantes_equipo', OLD.min_integrantes_equipo, 'estado', OLD.estado,
            'fecha_inicio', OLD.fecha_inicio, 'fecha_fin', OLD.fecha_fin,
            'publico', OLD.publico, 'permite_empates', OLD.permite_empates,
            'puntos_victoria', OLD.puntos_victoria, 'puntos_empate', OLD.puntos_empate,
            'puntos_derrota', OLD.puntos_derrota, 'usa_puntos_favor', OLD.usa_puntos_favor,
            'rondas_suizo', OLD.rondas_suizo, 'bye_suizo', OLD.bye_suizo,
            'puntos_bye_suizo', OLD.puntos_bye_suizo, 'nombre_puntos', OLD.nombre_puntos,
            'campeon_participante_id', OLD.campeon_participante_id,
            'campeon_equipo_id', OLD.campeon_equipo_id, 'creado_por', OLD.creado_por,
            'created_at', OLD.created_at, 'updated_at', OLD.updated_at),
          NULL, @id_usuario_app, USER());

-- usuarios  (no copia: password_hash, nombre, email)
DROP TRIGGER IF EXISTS trg_hist_usuarios_ai;
CREATE TRIGGER trg_hist_usuarios_ai AFTER INSERT ON usuarios FOR EACH ROW
  INSERT INTO historial_bd (tabla, operacion, registro_id, valor_anterior, valor_nuevo, usuario_app_id, usuario_bd)
  VALUES ('usuarios', 'INSERT', NEW.id, NULL,
          JSON_OBJECT(
            'id', NEW.id, 'rol_id', NEW.rol_id, 'failed_attempts', NEW.failed_attempts,
            'bloqueada_hasta', NEW.bloqueada_hasta, 'estado', NEW.estado,
            'created_at', NEW.created_at, 'updated_at', NEW.updated_at),
          @id_usuario_app, USER());

DROP TRIGGER IF EXISTS trg_hist_usuarios_au;
CREATE TRIGGER trg_hist_usuarios_au AFTER UPDATE ON usuarios FOR EACH ROW
  INSERT INTO historial_bd (tabla, operacion, registro_id, valor_anterior, valor_nuevo, usuario_app_id, usuario_bd)
  SELECT 'usuarios', 'UPDATE', NEW.id,
         JSON_OBJECT(
            'id', OLD.id, 'rol_id', OLD.rol_id, 'failed_attempts', OLD.failed_attempts,
            'bloqueada_hasta', OLD.bloqueada_hasta, 'estado', OLD.estado,
            'created_at', OLD.created_at, 'updated_at', OLD.updated_at),
         JSON_OBJECT(
            'id', NEW.id, 'rol_id', NEW.rol_id, 'failed_attempts', NEW.failed_attempts,
            'bloqueada_hasta', NEW.bloqueada_hasta, 'estado', NEW.estado,
            'created_at', NEW.created_at, 'updated_at', NEW.updated_at,
            'datos_reservados_modificados', NOT (OLD.password_hash <=> NEW.password_hash AND OLD.nombre <=> NEW.nombre AND OLD.email <=> NEW.email)),
         @id_usuario_app, USER()
  FROM DUAL
  WHERE NOT (
          OLD.id <=> NEW.id AND OLD.rol_id <=> NEW.rol_id AND
          OLD.failed_attempts <=> NEW.failed_attempts AND
          OLD.bloqueada_hasta <=> NEW.bloqueada_hasta AND OLD.estado <=> NEW.estado AND
          OLD.created_at <=> NEW.created_at AND
          OLD.password_hash <=> NEW.password_hash AND OLD.nombre <=> NEW.nombre AND
          OLD.email <=> NEW.email);

DROP TRIGGER IF EXISTS trg_hist_usuarios_ad;
CREATE TRIGGER trg_hist_usuarios_ad AFTER DELETE ON usuarios FOR EACH ROW
  INSERT INTO historial_bd (tabla, operacion, registro_id, valor_anterior, valor_nuevo, usuario_app_id, usuario_bd)
  VALUES ('usuarios', 'DELETE', OLD.id,
          JSON_OBJECT(
            'id', OLD.id, 'rol_id', OLD.rol_id, 'failed_attempts', OLD.failed_attempts,
            'bloqueada_hasta', OLD.bloqueada_hasta, 'estado', OLD.estado,
            'created_at', OLD.created_at, 'updated_at', OLD.updated_at),
          NULL, @id_usuario_app, USER());

-- <<< HISTORIAL

-- >>> VISTAS (este bloque es idéntico en database/schema.sql)

-- ------------------------------------------------------------
-- v_torneos_publicos
-- Es la ÚNICA definición de «torneo público»: marcado como público y fuera de borrador
-- (un torneo que todavía se está armando no se muestra). Los cancelados siguen siendo
-- visibles por su enlace; el listado los omite por su cuenta. `inscritos` cuenta solo las
-- inscripciones activas, o sea las que de verdad van a competir.
-- ------------------------------------------------------------
CREATE OR REPLACE VIEW v_torneos_publicos AS
SELECT t.*,
       tt.nombre AS tipo_nombre,
       tt.slug   AS tipo_slug,
       (SELECT COUNT(*) FROM inscripciones i
         WHERE i.torneo_id = t.id AND i.estado = 'activa') AS inscritos
  FROM torneos t
  JOIN tipos_torneo tt ON tt.id = t.tipo_torneo_id
 WHERE t.publico = 1
   AND t.estado <> 'borrador';

-- ------------------------------------------------------------
-- v_calendario
-- Un partido con todo lo que hace falta para mostrarlo: el torneo y la ronda a la que
-- pertenece, el nombre de sus dos lados (participante o equipo), el ganador y, si ya se
-- jugó, el resultado. Reemplaza a los cuatro LEFT JOIN que armaba EnfrentamientoModel.
-- ------------------------------------------------------------
CREATE OR REPLACE VIEW v_calendario AS
SELECT enf.*,
       t.nombre    AS torneo_nombre,
       t.modalidad AS torneo_modalidad,
       t.publico   AS torneo_publico,
       r.numero    AS ronda_numero,
       r.nombre    AS ronda_nombre,
       pa.nombre   AS participante_a_nombre,
       pa.nick     AS nick_a,
       pb.nombre   AS participante_b_nombre,
       pb.nick     AS nick_b,
       ea.nombre   AS equipo_a_nombre,
       eb.nombre   AS equipo_b_nombre,
       COALESCE(pa.nombre, ea.nombre) AS lado_a,
       COALESCE(pb.nombre, eb.nombre) AS lado_b,
       COALESCE(pg.nombre, eg.nombre) AS ganador,
       res.puntos_a,
       res.puntos_b,
       res.estado  AS resultado_estado
  FROM enfrentamientos enf
  JOIN torneos t  ON t.id = enf.torneo_id
  JOIN rondas  r  ON r.id = enf.ronda_id
  LEFT JOIN participantes pa ON pa.id = enf.participante_a_id
  LEFT JOIN participantes pb ON pb.id = enf.participante_b_id
  LEFT JOIN equipos       ea ON ea.id = enf.equipo_a_id
  LEFT JOIN equipos       eb ON eb.id = enf.equipo_b_id
  LEFT JOIN participantes pg ON pg.id = enf.ganador_participante_id
  LEFT JOIN equipos       eg ON eg.id = enf.ganador_equipo_id
  LEFT JOIN resultados   res ON res.enfrentamiento_id = enf.id;

-- ------------------------------------------------------------
-- v_posiciones
-- La tabla de posiciones con el nombre de cada competidor, sea participante o equipo.
-- ------------------------------------------------------------
CREATE OR REPLACE VIEW v_posiciones AS
SELECT tp.*,
       t.nombre AS torneo_nombre,
       p.nombre AS participante_nombre,
       p.nick   AS nick,
       e.nombre AS equipo_nombre,
       COALESCE(p.nombre, e.nombre) AS competidor
  FROM tabla_posiciones tp
  JOIN torneos t ON t.id = tp.torneo_id
  LEFT JOIN participantes p ON p.id = tp.participante_id
  LEFT JOIN equipos       e ON e.id = tp.equipo_id;

-- ------------------------------------------------------------
-- v_estadisticas_sistema
-- Los contadores del panel de administración, en una sola fila.
-- ------------------------------------------------------------
CREATE OR REPLACE VIEW v_estadisticas_sistema AS
SELECT
    (SELECT COUNT(*) FROM torneos)                                              AS torneos_total,
    (SELECT COUNT(*) FROM torneos WHERE estado = 'borrador')                    AS torneos_borrador,
    (SELECT COUNT(*) FROM torneos WHERE estado = 'inscripcion')                 AS torneos_inscripcion,
    (SELECT COUNT(*) FROM torneos WHERE estado = 'en_curso')                    AS torneos_en_curso,
    (SELECT COUNT(*) FROM torneos WHERE estado = 'finalizado')                  AS torneos_finalizados,
    (SELECT COUNT(*) FROM torneos WHERE estado = 'cancelado')                   AS torneos_cancelados,
    (SELECT COUNT(*) FROM participantes)                                        AS participantes_total,
    (SELECT COUNT(*) FROM participantes WHERE estado = 'activo')                AS participantes_activos,
    (SELECT COUNT(*) FROM equipos)                                              AS equipos_total,
    (SELECT COUNT(*) FROM equipos WHERE estado = 'activo')                      AS equipos_activos,
    (SELECT COUNT(*) FROM usuarios WHERE estado = 'activo')                     AS usuarios_activos,
    (SELECT COUNT(*) FROM usuarios WHERE estado = 'pendiente')                  AS registros_pendientes,
    (SELECT COUNT(*) FROM enfrentamientos WHERE estado = 'finalizado')          AS partidos_jugados,
    (SELECT COUNT(*) FROM solicitudes_correccion WHERE estado = 'pendiente')    AS correcciones_pendientes;

-- ------------------------------------------------------------
-- v_participantes_publicos
-- Lo único que un visitante puede saber de un participante: su nombre, su apodo, su foto
-- y su estado. Deliberadamente SIN documento, email ni teléfono: las páginas públicas
-- leen de acá, así que ni un error en una plantilla puede mostrar esos datos.
-- ------------------------------------------------------------
CREATE OR REPLACE VIEW v_participantes_publicos AS
SELECT id, nombre, nick, foto, estado, created_at
  FROM participantes
 WHERE estado IN ('activo', 'inactivo', 'suspendido');

-- <<< VISTAS

SET FOREIGN_KEY_CHECKS = 1;
