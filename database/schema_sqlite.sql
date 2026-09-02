CREATE TABLE IF NOT EXISTS roles (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    nombre      TEXT NOT NULL UNIQUE,
    descripcion TEXT DEFAULT NULL,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS usuarios (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    rol_id        INTEGER NOT NULL,
    nombre        TEXT NOT NULL,
    email         TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    estado        TEXT NOT NULL DEFAULT 'activo',
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (rol_id) REFERENCES roles(id)
);

CREATE TABLE IF NOT EXISTS tipos_torneo (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    nombre      TEXT NOT NULL,
    slug        TEXT NOT NULL UNIQUE,
    descripcion TEXT DEFAULT NULL
);

CREATE TABLE IF NOT EXISTS modulos (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    nombre      TEXT NOT NULL,
    slug        TEXT NOT NULL UNIQUE,
    estado      TEXT NOT NULL DEFAULT 'activo',
    descripcion TEXT DEFAULT NULL
);

CREATE TABLE IF NOT EXISTS torneos (
    id                       INTEGER PRIMARY KEY AUTOINCREMENT,
    nombre                   TEXT NOT NULL,
    descripcion              TEXT DEFAULT NULL,
    tipo_torneo_id           INTEGER NOT NULL,
    modalidad                TEXT NOT NULL DEFAULT 'individual',
    min_integrantes_equipo   INTEGER DEFAULT NULL,
    estado                   TEXT NOT NULL DEFAULT 'borrador',
    fecha_inicio             TEXT DEFAULT NULL,
    fecha_fin                TEXT DEFAULT NULL,
    publico                  INTEGER NOT NULL DEFAULT 1,
    permite_empates          INTEGER NOT NULL DEFAULT 0,
    puntos_victoria          INTEGER NOT NULL DEFAULT 3,
    puntos_empate            INTEGER NOT NULL DEFAULT 1,
    puntos_derrota           INTEGER NOT NULL DEFAULT 0,
    usa_puntos_favor         INTEGER NOT NULL DEFAULT 1,
    requiere_desempate_final INTEGER NOT NULL DEFAULT 0,
    rondas_suizo             INTEGER DEFAULT NULL,
    bye_suizo                TEXT DEFAULT 'sin_puntos',
    puntos_bye_suizo         REAL NOT NULL DEFAULT 0,
    nombre_puntos            TEXT NOT NULL DEFAULT 'puntos',
    campeon_participante_id  INTEGER DEFAULT NULL,
    campeon_equipo_id        INTEGER DEFAULT NULL,
    creado_por               INTEGER NOT NULL,
    created_at               DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at               DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tipo_torneo_id) REFERENCES tipos_torneo(id),
    FOREIGN KEY (creado_por)     REFERENCES usuarios(id)
);

CREATE TABLE IF NOT EXISTS participantes (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    usuario_id  INTEGER DEFAULT NULL,
    nombre      TEXT NOT NULL,
    documento   TEXT DEFAULT NULL,
    nick        TEXT DEFAULT NULL,
    foto        TEXT DEFAULT NULL,
    email       TEXT DEFAULT NULL,
    telefono    TEXT DEFAULT NULL,
    estado      TEXT NOT NULL DEFAULT 'activo',
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
);

CREATE TABLE IF NOT EXISTS equipos (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    nombre      TEXT NOT NULL,
    logo        TEXT DEFAULT NULL,
    descripcion TEXT DEFAULT NULL,
    categoria   TEXT DEFAULT NULL,
    disciplina  TEXT DEFAULT NULL,
    estado      TEXT NOT NULL DEFAULT 'activo',
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS equipo_participantes (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    equipo_id       INTEGER NOT NULL,
    participante_id INTEGER NOT NULL,
    rol_en_equipo   TEXT NOT NULL DEFAULT 'jugador',
    fecha_ingreso   TEXT DEFAULT NULL,
    FOREIGN KEY (equipo_id)       REFERENCES equipos(id),
    FOREIGN KEY (participante_id) REFERENCES participantes(id)
);

CREATE TABLE IF NOT EXISTS inscripciones (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    torneo_id         INTEGER NOT NULL,
    participante_id   INTEGER DEFAULT NULL,
    equipo_id         INTEGER DEFAULT NULL,
    estado            TEXT NOT NULL DEFAULT 'activa',
    orden_seed        INTEGER DEFAULT NULL,
    fecha_inscripcion DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (torneo_id)       REFERENCES torneos(id),
    FOREIGN KEY (participante_id) REFERENCES participantes(id),
    FOREIGN KEY (equipo_id)       REFERENCES equipos(id)
);

CREATE TABLE IF NOT EXISTS rondas (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    torneo_id  INTEGER NOT NULL,
    numero     INTEGER NOT NULL,
    nombre     TEXT NOT NULL,
    estado     TEXT NOT NULL DEFAULT 'pendiente',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (torneo_id) REFERENCES torneos(id)
);

CREATE TABLE IF NOT EXISTS enfrentamientos (
    id                       INTEGER PRIMARY KEY AUTOINCREMENT,
    torneo_id                INTEGER NOT NULL,
    ronda_id                 INTEGER NOT NULL,
    participante_a_id        INTEGER DEFAULT NULL,
    participante_b_id        INTEGER DEFAULT NULL,
    equipo_a_id              INTEGER DEFAULT NULL,
    equipo_b_id              INTEGER DEFAULT NULL,
    ganador_participante_id  INTEGER DEFAULT NULL,
    ganador_equipo_id        INTEGER DEFAULT NULL,
    perdedor_participante_id INTEGER DEFAULT NULL,
    perdedor_equipo_id       INTEGER DEFAULT NULL,
    estado                   TEXT NOT NULL DEFAULT 'pendiente',
    es_bye                   INTEGER NOT NULL DEFAULT 0,
    orden                    INTEGER NOT NULL DEFAULT 1,
    fecha_programada         TEXT DEFAULT NULL,
    created_at               DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (torneo_id) REFERENCES torneos(id),
    FOREIGN KEY (ronda_id)  REFERENCES rondas(id)
);

CREATE TABLE IF NOT EXISTS resultados (
    id                      INTEGER PRIMARY KEY AUTOINCREMENT,
    enfrentamiento_id       INTEGER NOT NULL UNIQUE,
    puntos_a                REAL NOT NULL DEFAULT 0,
    puntos_b                REAL NOT NULL DEFAULT 0,
    ganador_participante_id INTEGER DEFAULT NULL,
    ganador_equipo_id       INTEGER DEFAULT NULL,
    estado                  TEXT NOT NULL DEFAULT 'cargado',
    cargado_por             INTEGER DEFAULT NULL,
    fecha_carga             DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (enfrentamiento_id) REFERENCES enfrentamientos(id)
);

CREATE TABLE IF NOT EXISTS tabla_posiciones (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    torneo_id       INTEGER NOT NULL,
    participante_id INTEGER DEFAULT NULL,
    equipo_id       INTEGER DEFAULT NULL,
    pj              INTEGER NOT NULL DEFAULT 0,
    pg              INTEGER NOT NULL DEFAULT 0,
    pe              INTEGER NOT NULL DEFAULT 0,
    pp              INTEGER NOT NULL DEFAULT 0,
    pf              INTEGER NOT NULL DEFAULT 0,
    pc              INTEGER NOT NULL DEFAULT 0,
    diferencia      INTEGER NOT NULL DEFAULT 0,
    puntos          INTEGER NOT NULL DEFAULT 0,
    byes_recibidos  INTEGER NOT NULL DEFAULT 0,
    buchholz        REAL NOT NULL DEFAULT 0,
    posicion        INTEGER NOT NULL DEFAULT 0,
    FOREIGN KEY (torneo_id)       REFERENCES torneos(id),
    FOREIGN KEY (participante_id) REFERENCES participantes(id),
    FOREIGN KEY (equipo_id)       REFERENCES equipos(id)
);

CREATE TABLE IF NOT EXISTS permisos (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    rol_id         INTEGER NOT NULL,
    modulo_slug    TEXT NOT NULL,
    puede_ver      INTEGER NOT NULL DEFAULT 1,
    puede_crear    INTEGER NOT NULL DEFAULT 0,
    puede_editar   INTEGER NOT NULL DEFAULT 0,
    puede_eliminar INTEGER NOT NULL DEFAULT 0,
    FOREIGN KEY (rol_id) REFERENCES roles(id)
);

CREATE TABLE IF NOT EXISTS configuraciones_torneo (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    torneo_id INTEGER NOT NULL,
    clave     TEXT NOT NULL,
    valor     TEXT DEFAULT NULL,
    FOREIGN KEY (torneo_id) REFERENCES torneos(id)
);

-- Insert initial roles and admin user
INSERT OR IGNORE INTO roles (id, nombre, descripcion) VALUES (1, 'Administrador', 'Administrador general');
INSERT OR IGNORE INTO roles (id, nombre, descripcion) VALUES (2, 'Organizador', 'Organizador de torneos');
INSERT OR IGNORE INTO roles (id, nombre, descripcion) VALUES (3, 'Participante', 'Participante o jugador');

INSERT OR IGNORE INTO tipos_torneo (id, nombre, slug, descripcion) VALUES (1, 'Liga', 'liga', 'Sistema de todos contra todos');
INSERT OR IGNORE INTO tipos_torneo (id, nombre, slug, descripcion) VALUES (2, 'Eliminación Directa', 'eliminacion', 'Llaves de eliminación directa');
INSERT OR IGNORE INTO tipos_torneo (id, nombre, slug, descripcion) VALUES (3, 'Suizo', 'suizo', 'Sistema Suizo');

INSERT OR IGNORE INTO usuarios (id, rol_id, nombre, email, password_hash, estado) VALUES (1, 1, 'Administrador', 'admin@flexarena.local', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'activo');
