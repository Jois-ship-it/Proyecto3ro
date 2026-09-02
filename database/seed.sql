-- ============================================================
-- FlexArena — seed.sql
-- Datos de prueba completos (50+ registros por componente)
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ─── ROLES ───────────────────────────────────────────────────
-- El usuario público NO es una entidad del sistema (es un visitante sin sesión),
-- por lo tanto NO existe como rol en la base de datos.
INSERT INTO roles (id, nombre, descripcion) VALUES
(1, 'administrador', 'Acceso total al sistema'),
(2, 'organizador',   'Gestiona torneos asignados'),
(3, 'participante',  'Accede a sus torneos y resultados')
ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion);

-- ─── TIPOS DE TORNEO ─────────────────────────────────────────
INSERT INTO tipos_torneo (id, nombre, slug, descripcion) VALUES
(1, 'Liga',                'liga',                'Todos contra todos con tabla de posiciones'),
(2, 'Eliminación Directa', 'eliminacion_directa', 'Llave de eliminación directa con bracket'),
(3, 'Sistema Suizo',       'suizo',               'Rondas por rendimiento acumulado')
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre);

-- ─── MÓDULOS ─────────────────────────────────────────────────
INSERT INTO modulos (id, nombre, slug, estado, descripcion) VALUES
(1,  'Liga',            'liga',             'activo', 'Módulo de torneos tipo liga'),
(2,  'Eliminación Directa','eliminacion_directa','activo','Módulo de brackets'),
(3,  'Sistema Suizo',   'suizo',            'activo', 'Módulo de rondas suizas'),
(4,  'Participantes',   'participantes',    'activo', 'Gestión de participantes individuales'),
(5,  'Equipos',         'equipos',          'activo', 'Gestión de equipos'),
(6,  'Auditoría',       'auditoria',        'activo', 'Registro de actividad del sistema'),
(7,  'Consulta pública','consulta_publica', 'activo', 'Vista pública sin autenticación'),
(8,  'Resultados',      'resultados',       'activo', 'Carga y corrección de resultados'),
(9,  'Torneos',         'torneos',          'activo', 'Gestión general de torneos')
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre);

-- ─── USUARIOS ────────────────────────────────────────────────
-- Contraseñas (bcrypt cost 12):
--   admin@flexarena.com  ->  Adm!n-Flex2026#Tech
--   resto de usuarios    ->  admin123
INSERT INTO usuarios (id, rol_id, nombre, email, password_hash, estado) VALUES
(1, 1, 'Administrador General', 'admin@flexarena.com',   '$2y$12$cVKpHtHbJHPytOM0SZtX2ua24uvjAMSmXehWHzptO6j4Qe8UGYEXi', 'activo'),
(2, 2, 'Carlos Organizador',    'org1@flexarena.com',    '$2y$12$K6HKG8mWOpQwFqVxnHaJVebhJkDDc0Koz7r.lrDXr8cjuwCB/DCiG', 'activo'),
(3, 2, 'Laura Organizadora',    'org2@flexarena.com',    '$2y$12$K6HKG8mWOpQwFqVxnHaJVebhJkDDc0Koz7r.lrDXr8cjuwCB/DCiG', 'activo'),
(4, 3, 'Matías Jugador',        'matias@example.com',    '$2y$12$K6HKG8mWOpQwFqVxnHaJVebhJkDDc0Koz7r.lrDXr8cjuwCB/DCiG', 'activo'),
(5, 3, 'Valentina García',      'vale@example.com',      '$2y$12$K6HKG8mWOpQwFqVxnHaJVebhJkDDc0Koz7r.lrDXr8cjuwCB/DCiG', 'activo'),
(6, 3, 'Nicolás Pérez',         'nico@example.com',      '$2y$12$K6HKG8mWOpQwFqVxnHaJVebhJkDDc0Koz7r.lrDXr8cjuwCB/DCiG', 'activo')
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), password_hash = VALUES(password_hash);

-- ─── PARTICIPANTES (20+) ─────────────────────────────────────
INSERT INTO participantes (id, usuario_id, nombre, documento, nick, email, estado) VALUES
(1,  4, 'Matías López',       '30001001', 'MatiasL',  'matias@example.com',  'activo'),
(2,  5, 'Valentina García',   '30001002', 'ValeG',    'vale@example.com',    'activo'),
(3,  6, 'Nicolás Pérez',      '30001003', 'NicoP',    'nico@example.com',    'activo'),
(4,  NULL,'Camila Torres',    '30001004', 'CamiT',    'cami@example.com',    'activo'),
(5,  NULL,'Lucas Romero',     '30001005', 'LucasR',   'lucas@example.com',   'activo'),
(6,  NULL,'Sofía Méndez',     '30001006', 'SofiaM',   'sofia@example.com',   'activo'),
(7,  NULL,'Diego Alvarez',    '30001007', 'DiegoA',   'diego@example.com',   'activo'),
(8,  NULL,'Ana Fernández',    '30001008', 'AnaF',     'ana@example.com',     'activo'),
(9,  NULL,'Martín Suárez',    '30001009', 'MartinS',  'martin@example.com',  'activo'),
(10, NULL,'Julia Herrera',    '30001010', 'JuliaH',   'julia@example.com',   'activo'),
(11, NULL,'Pablo Castro',     '30001011', 'PabloC',   'pablo@example.com',   'activo'),
(12, NULL,'Florencia Reyes',  '30001012', 'FloR',     'flo@example.com',     'activo'),
(13, NULL,'Sebastián Mora',   '30001013', 'SebaM',    'seba@example.com',    'activo'),
(14, NULL,'Gabriela Silva',   '30001014', 'GabiS',    'gabi@example.com',    'activo'),
(15, NULL,'Rodrigo Jiménez',  '30001015', 'RodrJ',    'rodri@example.com',   'activo'),
(16, NULL,'Natalia Ruiz',     '30001016', 'NataR',    'nata@example.com',    'activo'),
(17, NULL,'Franco Núñez',     '30001017', 'FranN',    'franco@example.com',  'activo'),
(18, NULL,'Cecilia Blanco',   '30001018', 'CeciB',    'ceci@example.com',    'activo'),
(19, NULL,'Tomás González',   '30001019', 'TomasG',   'tomas@example.com',   'activo'),
(20, NULL,'Silvana Martínez', '30001020', 'SilM',     'silv@example.com',    'activo'),
(21, NULL,'Agustín Vargas',   '30001021', 'AgusV',    'agus@example.com',    'activo'),
(22, NULL,'Mariana Sosa',     '30001022', 'MariS',    'mari@example.com',    'activo'),
(23, NULL,'Eduardo Flores',   '30001023', 'EduF',     'edu@example.com',     'activo'),
(24, NULL,'Patricia Campos',  '30001024', 'PatriC',   'patri@example.com',   'activo')
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre);

-- ─── EQUIPOS (6+) ────────────────────────────────────────────
INSERT INTO equipos (id, nombre, categoria, disciplina, estado) VALUES
(1, 'Aqua Academy',    'A', 'Fútbol',      'activo'),
(2, 'Team Atlas',      'A', 'Fútbol',      'activo'),
(3, 'Nexo Gaming',     'B', 'Esports',     'activo'),
(4, 'Río Negro Club',  'A', 'Fútbol',      'activo'),
(5, 'Storm Raiders',   'B', 'Esports',     'activo'),
(6, 'Iron Phoenix',    'A', 'Ajedrez',     'activo'),
(7, 'Silver Wolves',   'B', 'Esports',     'activo'),
(8, 'Delta Force',     'A', 'Baloncesto',  'activo')
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre);

-- ─── EQUIPO-PARTICIPANTES ────────────────────────────────────
INSERT INTO equipo_participantes (equipo_id, participante_id, rol_en_equipo) VALUES
(1, 1,  'capitan'), (1, 2,  'jugador'), (1, 3,  'jugador'),
(2, 4,  'capitan'), (2, 5,  'jugador'), (2, 6,  'jugador'),
(3, 7,  'capitan'), (3, 8,  'jugador'), (3, 9,  'jugador'),
(4, 10, 'capitan'), (4, 11, 'jugador'), (4, 12, 'jugador'),
(5, 13, 'capitan'), (5, 14, 'jugador'),
(6, 15, 'capitan'), (6, 16, 'jugador'),
(7, 17, 'capitan'), (7, 18, 'jugador'),
(8, 19, 'capitan'), (8, 20, 'jugador')
ON DUPLICATE KEY UPDATE rol_en_equipo = VALUES(rol_en_equipo);

-- ─── PERMISOS ─────────────────────────────────────────────────
INSERT INTO permisos (rol_id, modulo_slug, puede_ver, puede_crear, puede_editar, puede_eliminar) VALUES
-- Organizador
(2, 'torneos',          1, 0, 1, 0),
(2, 'resultados',       1, 1, 1, 0),
(2, 'participantes',    1, 1, 1, 0),
(2, 'equipos',          1, 0, 0, 0),
(2, 'liga',             1, 1, 1, 0),
(2, 'eliminacion_directa', 1, 1, 1, 0),
(2, 'suizo',            1, 1, 1, 0),
-- Participante
(3, 'consulta_publica', 1, 0, 0, 0),
(3, 'resultados',       1, 0, 0, 0)
ON DUPLICATE KEY UPDATE puede_ver = VALUES(puede_ver);

-- ─── TORNEOS (3 completos, uno por formato) ───────────────────
INSERT INTO torneos (id, nombre, descripcion, tipo_torneo_id, modalidad, estado, fecha_inicio, fecha_fin, publico,
                     permite_empates, puntos_victoria, puntos_empate, puntos_derrota, usa_puntos_favor,
                     nombre_puntos, rondas_suizo, bye_suizo, creado_por) VALUES
(1, 'Liga Campus 2026',      'Liga de fútbol universitario temporada 2026.',         1, 'individual', 'en_curso',  '2026-03-01', '2026-06-30', 1, 1, 3, 1, 0, 1, 'puntos', NULL, NULL, 1),
(2, 'Torneo Esports CUP',    'Eliminación directa de esports, equipos.',              2, 'equipos',    'en_curso',  '2026-04-01', '2026-04-30', 1, 0, 1, 0, 0, 1, 'mapas',  NULL, NULL, 1),
(3, 'Campus Cup Ajedrez',    'Sistema Suizo de ajedrez, 5 rondas.',                  3, 'individual', 'en_curso',  '2026-05-01', '2026-05-31', 1, 1, 1, 0, 0, 0, 'puntos', 5, 'victoria', 1),
(4, 'Liga Baloncesto Open',  'Liga open de baloncesto, todos contra todos.',         1, 'equipos',    'inscripcion','2026-07-01', '2026-09-30', 1, 0, 2, 0, 0, 1, 'puntos', NULL, NULL, 2),
(5, 'Torneo Ping Pong',      'Eliminación directa de ping pong individual.',         2, 'individual', 'inscripcion','2026-06-15', '2026-07-15', 1, 0, 1, 0, 0, 1, 'sets',   NULL, NULL, 2),
(6, 'Copa Mental Suizo',     'Suizo de juegos de estrategia, 4 rondas.',             3, 'individual', 'borrador',  '2026-08-01', '2026-08-31', 1, 1, 1, 0, 0, 0, 'puntos', 4, 'sin_puntos', 1)
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre);

-- Mínimo de integrantes para torneos por equipos (modalidad equipos)
UPDATE torneos SET min_integrantes_equipo = 3 WHERE modalidad = 'equipos';

-- ─── TORNEO ORGANIZADORES ────────────────────────────────────
INSERT INTO torneo_organizadores (torneo_id, usuario_id) VALUES
(1, 2), (2, 2), (3, 3), (4, 2), (5, 3), (6, 1)
ON DUPLICATE KEY UPDATE torneo_id = VALUES(torneo_id);

-- ─── INSCRIPCIONES — Torneo 1 (Liga individual, 8 participantes) ──
INSERT INTO inscripciones (torneo_id, participante_id, equipo_id, estado, orden_seed) VALUES
(1,  1, NULL, 'activa', 1),
(1,  2, NULL, 'activa', 2),
(1,  3, NULL, 'activa', 3),
(1,  4, NULL, 'activa', 4),
(1,  5, NULL, 'activa', 5),
(1,  6, NULL, 'activa', 6),
(1,  7, NULL, 'activa', 7),
(1,  8, NULL, 'activa', 8),
-- Torneo 2 (Eliminación equipos, 4 equipos)
(2, NULL, 1, 'activa', 1),
(2, NULL, 2, 'activa', 2),
(2, NULL, 3, 'activa', 3),
(2, NULL, 4, 'activa', 4),
-- Torneo 3 (Suizo individual, 7 participantes — impar para probar bye)
(3,  9, NULL, 'activa', 1),
(3, 10, NULL, 'activa', 2),
(3, 11, NULL, 'activa', 3),
(3, 12, NULL, 'activa', 4),
(3, 13, NULL, 'activa', 5),
(3, 14, NULL, 'activa', 6),
(3, 15, NULL, 'activa', 7),
-- Torneo 4 (inscripcion, equipos)
(4, NULL, 5, 'activa', 1),
(4, NULL, 6, 'activa', 2),
(4, NULL, 7, 'activa', 3),
(4, NULL, 8, 'activa', 4),
-- Torneo 5 (inscripcion, individual)
(5, 16, NULL, 'activa', 1),
(5, 17, NULL, 'activa', 2),
(5, 18, NULL, 'activa', 3),
(5, 19, NULL, 'activa', 4)
ON DUPLICATE KEY UPDATE estado = VALUES(estado);

-- ─── RONDAS — Torneo 1 Liga (7 rondas, primera con resultados) ───
INSERT INTO rondas (id, torneo_id, numero, nombre, estado) VALUES
(1, 1, 1, 'Fecha 1', 'cerrada'),
(2, 1, 2, 'Fecha 2', 'cerrada'),
(3, 1, 3, 'Fecha 3', 'en_curso'),
(4, 1, 4, 'Fecha 4', 'pendiente'),
(5, 1, 5, 'Fecha 5', 'pendiente'),
(6, 1, 6, 'Fecha 6', 'pendiente'),
(7, 1, 7, 'Fecha 7', 'pendiente'),
-- Torneo 2 Eliminación (Semifinales + Final)
(8,  2, 1, 'Semifinales', 'cerrada'),
(9,  2, 2, 'Final',       'en_curso'),
-- Torneo 3 Suizo (2 rondas generadas de 5)
(10, 3, 1, 'Ronda 1', 'cerrada'),
(11, 3, 2, 'Ronda 2', 'en_curso')
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre);

-- ─── ENFRENTAMIENTOS — Torneo 1, Fecha 1 (4 partidos) ───────
INSERT INTO enfrentamientos (id, torneo_id, ronda_id, participante_a_id, participante_b_id, ganador_participante_id, perdedor_participante_id, estado, es_bye, orden) VALUES
(1,  1, 1, 1, 5, 1, 5, 'finalizado', 0, 1),
(2,  1, 1, 2, 6, 2, 6, 'finalizado', 0, 2),
(3,  1, 1, 3, 7, 7, 3, 'finalizado', 0, 3),
(4,  1, 1, 4, 8, 4, 8, 'finalizado', 0, 4),
-- Fecha 2 (4 partidos)
(5,  1, 2, 1, 6, 1, 6, 'finalizado', 0, 1),
(6,  1, 2, 2, 7, 7, 2, 'finalizado', 0, 2),
(7,  1, 2, 3, 8, 3, 8, 'finalizado', 0, 3),
(8,  1, 2, 4, 5, 4, 5, 'finalizado', 0, 4),
-- Fecha 3 (en curso, pendientes)
(9,  1, 3, 1, 7, NULL, NULL, 'pendiente', 0, 1),
(10, 1, 3, 2, 8, NULL, NULL, 'pendiente', 0, 2),
(11, 1, 3, 3, 5, NULL, NULL, 'pendiente', 0, 3),
(12, 1, 3, 4, 6, NULL, NULL, 'pendiente', 0, 4),
-- Torneo 2, Semifinales
(13, 2, 8, NULL, NULL, NULL, NULL, 'finalizado', 0, 1),  -- equipo_a=1, equipo_b=4
(14, 2, 8, NULL, NULL, NULL, NULL, 'finalizado', 0, 2),  -- equipo_a=2, equipo_b=3
-- Torneo 2, Final
(15, 2, 9, NULL, NULL, NULL, NULL, 'pendiente', 0, 1),
-- Torneo 3, Ronda 1 (3 partidos + 1 bye para 7 participantes)
(16, 3, 10, 9,  13, 9,  13, 'finalizado', 0, 1),
(17, 3, 10, 10, 14, 14, 10, 'finalizado', 0, 2),
(18, 3, 10, 11, 12, 11, 12, 'finalizado', 0, 3),
(19, 3, 10, 15, NULL, 15, NULL, 'bye', 1, 4),
-- Torneo 3, Ronda 2
(20, 3, 11, 9,  14, NULL, NULL, 'pendiente', 0, 1),
(21, 3, 11, 15, 11, NULL, NULL, 'pendiente', 0, 2),
(22, 3, 11, 10, NULL, 10, NULL, 'bye', 1, 3)
ON DUPLICATE KEY UPDATE estado = VALUES(estado);

-- Actualizar equipos en enfrentamientos de torneo 2
UPDATE enfrentamientos SET equipo_a_id=1, equipo_b_id=4, ganador_equipo_id=1, perdedor_equipo_id=4 WHERE id=13;
UPDATE enfrentamientos SET equipo_a_id=2, equipo_b_id=3, ganador_equipo_id=2, perdedor_equipo_id=3 WHERE id=14;
UPDATE enfrentamientos SET equipo_a_id=1, equipo_b_id=2 WHERE id=15;

-- ─── RESULTADOS ───────────────────────────────────────────────
INSERT INTO resultados (enfrentamiento_id, puntos_a, puntos_b, ganador_participante_id, ganador_equipo_id, estado, cargado_por) VALUES
-- Fecha 1
(1, 3, 1, 1, NULL, 'cargado', 1),
(2, 2, 0, 2, NULL, 'cargado', 1),
(3, 1, 2, 7, NULL, 'cargado', 1),
(4, 3, 1, 4, NULL, 'cargado', 1),
-- Fecha 2
(5, 2, 1, 1, NULL, 'cargado', 1),
(6, 0, 3, 7, NULL, 'cargado', 1),
(7, 2, 1, 3, NULL, 'cargado', 1),
(8, 4, 0, 4, NULL, 'cargado', 1),
-- Torneo 2 Semifinales
(13, 2, 1, NULL, 1, 'cargado', 1),
(14, 2, 0, NULL, 2, 'cargado', 1),
-- Torneo 3 Ronda 1
(16, 2, 1, 9,  NULL, 'cargado', 1),
(17, 0, 1, 14, NULL, 'cargado', 1),
(18, 1, 0, 11, NULL, 'cargado', 1),
-- Bye Ronda 1 suizo (participante 15)
(19, 1, 0, 15, NULL, 'cargado', 1),
-- Bye Ronda 2 suizo (participante 10)
(22, 1, 0, 10, NULL, 'cargado', 1)
ON DUPLICATE KEY UPDATE estado = VALUES(estado);

-- ─── TABLA POSICIONES — Torneo 1 (calculada) ─────────────────
INSERT INTO tabla_posiciones (torneo_id, participante_id, pj, pg, pe, pp, pf, pc, diferencia, puntos, posicion) VALUES
(1, 1, 2, 2, 0, 0,  5, 2,  3, 6, 1),
(1, 4, 2, 2, 0, 0,  7, 1,  6, 6, 2),
(1, 7, 2, 1, 0, 1,  5, 4,  1, 3, 3),
(1, 3, 2, 1, 0, 1,  3, 2,  1, 3, 4),
(1, 2, 2, 1, 0, 1,  2, 2,  0, 3, 5),
(1, 8, 2, 0, 0, 2,  2, 8, -6, 0, 6),
(1, 5, 2, 0, 0, 2,  1, 6, -5, 0, 7),
(1, 6, 2, 0, 0, 2,  0, 5, -5, 0, 8)
ON DUPLICATE KEY UPDATE posicion = VALUES(posicion);

-- ─── AUDITORÍA (muestra de registros) ────────────────────────
INSERT INTO auditoria (usuario_id, accion, tabla_afectada, registro_id, descripcion, ip) VALUES
(1, 'login_exitoso',       'usuarios',    1, 'Inicio de sesión: admin@flexarena.com', '127.0.0.1'),
(1, 'crear_torneo',        'torneos',     1, 'Torneo creado: Liga Campus 2026',       '127.0.0.1'),
(1, 'crear_torneo',        'torneos',     2, 'Torneo creado: Torneo Esports CUP',     '127.0.0.1'),
(1, 'crear_torneo',        'torneos',     3, 'Torneo creado: Campus Cup Ajedrez',     '127.0.0.1'),
(1, 'generar_fixture',     'torneos',     1, 'Fixture Liga generado para torneo 1',   '127.0.0.1'),
(1, 'generar_bracket',     'torneos',     2, 'Bracket Eliminación generado',          '127.0.0.1'),
(1, 'generar_ronda_suiza', 'torneos',     3, 'Ronda 1 (Suizo) generada',              '127.0.0.1'),
(1, 'cargar_resultado',    'resultados',  1, 'Resultado: Enfrentamiento 1: 3-1',      '127.0.0.1'),
(1, 'cargar_resultado',    'resultados',  2, 'Resultado: Enfrentamiento 2: 2-0',      '127.0.0.1'),
(2, 'login_exitoso',       'usuarios',    2, 'Inicio de sesión: org1@flexarena.com',  '127.0.0.1'),
(2, 'crear_usuario',       'usuarios',    5, 'Usuario creado: vale@example.com',      '127.0.0.1'),
(1, 'inscribir_participante','inscripciones',1,'Participante 1 inscrito en torneo 1', '127.0.0.1'),
(1, 'inscribir_participante','inscripciones',1,'Participante 2 inscrito en torneo 1', '127.0.0.1');

SET FOREIGN_KEY_CHECKS = 1;
