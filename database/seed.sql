-- ============================================================
-- FlexArena — seed.sql
-- Datos de prueba completos (50+ registros por componente)
-- Incluye datos de demostración del sistema de bloqueo de cuentas
-- (failed_attempts / estado 'bloqueada') y del flujo de registro
-- público con aprobación (estado 'pendiente').
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
--
-- Cuentas de demostración del sistema de bloqueo / registro (nuevas):
--   id 7 (roberto@flexarena.com)         -> bloqueada tras 5 intentos fallidos
--                                            (failed_attempts=5, estado='bloqueada').
--                                            Password real: admin123 (queda sin usar
--                                            hasta que el admin la desbloquee).
--   id 8 (camila.pendiente@example.com)  -> autorregistro pendiente de aprobación
--                                            (estado='pendiente'). Password: admin123.
INSERT INTO usuarios (id, rol_id, nombre, email, password_hash, failed_attempts, estado) VALUES
(1, 1, 'Administrador General', 'admin@flexarena.com',           '$2y$12$cVKpHtHbJHPytOM0SZtX2ua24uvjAMSmXehWHzptO6j4Qe8UGYEXi', 0, 'activo'),
(2, 2, 'Carlos Organizador',    'org1@flexarena.com',            '$2y$12$K6HKG8mWOpQwFqVxnHaJVebhJkDDc0Koz7r.lrDXr8cjuwCB/DCiG', 0, 'activo'),
(3, 2, 'Laura Organizadora',    'org2@flexarena.com',            '$2y$12$K6HKG8mWOpQwFqVxnHaJVebhJkDDc0Koz7r.lrDXr8cjuwCB/DCiG', 0, 'activo'),
(4, 3, 'Matías Jugador',        'matias@example.com',            '$2y$12$K6HKG8mWOpQwFqVxnHaJVebhJkDDc0Koz7r.lrDXr8cjuwCB/DCiG', 0, 'activo'),
(5, 3, 'Valentina García',      'vale@example.com',              '$2y$12$K6HKG8mWOpQwFqVxnHaJVebhJkDDc0Koz7r.lrDXr8cjuwCB/DCiG', 0, 'activo'),
(6, 3, 'Nicolás Pérez',         'nico@example.com',              '$2y$12$K6HKG8mWOpQwFqVxnHaJVebhJkDDc0Koz7r.lrDXr8cjuwCB/DCiG', 0, 'activo'),
(7, 2, 'Roberto Ibáñez',        'roberto@flexarena.com',         '$2y$12$K6HKG8mWOpQwFqVxnHaJVebhJkDDc0Koz7r.lrDXr8cjuwCB/DCiG', 5, 'bloqueada'),
(8, 3, 'Camila Ortiz',          'camila.pendiente@example.com',  '$2y$12$K6HKG8mWOpQwFqVxnHaJVebhJkDDc0Koz7r.lrDXr8cjuwCB/DCiG', 0, 'pendiente'),
(9, 3, 'Pablo Castro',          'pablo@example.com',             '$2y$12$K6HKG8mWOpQwFqVxnHaJVebhJkDDc0Koz7r.lrDXr8cjuwCB/DCiG', 0, 'activo'),
(10, 3, 'Patricia Campos',      'patri@example.com',             '$2y$12$K6HKG8mWOpQwFqVxnHaJVebhJkDDc0Koz7r.lrDXr8cjuwCB/DCiG', 0, 'activo'),
(11, 3, 'Sofía Méndez',         'sofia@example.com',             '$2y$12$K6HKG8mWOpQwFqVxnHaJVebhJkDDc0Koz7r.lrDXr8cjuwCB/DCiG', 0, 'activo'),
(12, 3, 'Camila Torres',        'cami@example.com',              '$2y$12$K6HKG8mWOpQwFqVxnHaJVebhJkDDc0Koz7r.lrDXr8cjuwCB/DCiG', 0, 'activo'),
(13, 3, 'Lucas Romero',         'lucas@example.com',             '$2y$12$K6HKG8mWOpQwFqVxnHaJVebhJkDDc0Koz7r.lrDXr8cjuwCB/DCiG', 0, 'activo'),
(14, 3, 'Diego Alvarez',        'diego@example.com',             '$2y$12$K6HKG8mWOpQwFqVxnHaJVebhJkDDc0Koz7r.lrDXr8cjuwCB/DCiG', 0, 'activo'),
(15, 3, 'Ana Fernández',        'ana@example.com',               '$2y$12$K6HKG8mWOpQwFqVxnHaJVebhJkDDc0Koz7r.lrDXr8cjuwCB/DCiG', 0, 'activo'),
(16, 3, 'Martín Suárez',        'martin@example.com',            '$2y$12$K6HKG8mWOpQwFqVxnHaJVebhJkDDc0Koz7r.lrDXr8cjuwCB/DCiG', 0, 'activo'),
(17, 3, 'Julia Herrera',        'julia@example.com',             '$2y$12$K6HKG8mWOpQwFqVxnHaJVebhJkDDc0Koz7r.lrDXr8cjuwCB/DCiG', 0, 'activo'),
(18, 3, 'Florencia Reyes',      'flo@example.com',               '$2y$12$K6HKG8mWOpQwFqVxnHaJVebhJkDDc0Koz7r.lrDXr8cjuwCB/DCiG', 0, 'activo'),
(19, 3, 'Sebastián Mora',       'seba@example.com',              '$2y$12$K6HKG8mWOpQwFqVxnHaJVebhJkDDc0Koz7r.lrDXr8cjuwCB/DCiG', 0, 'activo'),
(20, 3, 'Gabriela Silva',       'gabi@example.com',              '$2y$12$K6HKG8mWOpQwFqVxnHaJVebhJkDDc0Koz7r.lrDXr8cjuwCB/DCiG', 0, 'activo'),
(21, 3, 'Rodrigo Jiménez',      'rodri@example.com',             '$2y$12$K6HKG8mWOpQwFqVxnHaJVebhJkDDc0Koz7r.lrDXr8cjuwCB/DCiG', 0, 'activo'),
(22, 3, 'Natalia Ruiz',         'nata@example.com',              '$2y$12$K6HKG8mWOpQwFqVxnHaJVebhJkDDc0Koz7r.lrDXr8cjuwCB/DCiG', 0, 'activo'),
(23, 3, 'Franco Núñez',         'franco@example.com',            '$2y$12$K6HKG8mWOpQwFqVxnHaJVebhJkDDc0Koz7r.lrDXr8cjuwCB/DCiG', 0, 'activo'),
(24, 3, 'Cecilia Blanco',       'ceci@example.com',              '$2y$12$K6HKG8mWOpQwFqVxnHaJVebhJkDDc0Koz7r.lrDXr8cjuwCB/DCiG', 0, 'activo'),
(25, 3, 'Tomás González',       'tomas@example.com',             '$2y$12$K6HKG8mWOpQwFqVxnHaJVebhJkDDc0Koz7r.lrDXr8cjuwCB/DCiG', 0, 'activo'),
(26, 3, 'Silvana Martínez',     'silv@example.com',              '$2y$12$K6HKG8mWOpQwFqVxnHaJVebhJkDDc0Koz7r.lrDXr8cjuwCB/DCiG', 0, 'activo'),
(27, 3, 'Agustín Vargas',       'agus@example.com',              '$2y$12$K6HKG8mWOpQwFqVxnHaJVebhJkDDc0Koz7r.lrDXr8cjuwCB/DCiG', 0, 'activo'),
(28, 3, 'Mariana Sosa',         'mari@example.com',              '$2y$12$K6HKG8mWOpQwFqVxnHaJVebhJkDDc0Koz7r.lrDXr8cjuwCB/DCiG', 0, 'activo'),
(29, 3, 'Eduardo Flores',       'edu@example.com',               '$2y$12$K6HKG8mWOpQwFqVxnHaJVebhJkDDc0Koz7r.lrDXr8cjuwCB/DCiG', 0, 'activo'),
-- Cuentas adicionales para llegar al mínimo de registros que pide la letra
-- del proyecto (50 por componente). Misma contraseña que el resto: admin123.
(30, 2, 'Verónica Sanguinetti',    'vero.org@flexarena.com',          '$2y$12$K6HKG8mWOpQwFqVxnHaJVebhJkDDc0Koz7r.lrDXr8cjuwCB/DCiG', 0, 'activo'),
(31, 2, 'Gustavo Peirano',         'gustavo.org@flexarena.com',       '$2y$12$K6HKG8mWOpQwFqVxnHaJVebhJkDDc0Koz7r.lrDXr8cjuwCB/DCiG', 0, 'activo'),
(32, 2, 'Mariela Bentancor',       'mariela.org@flexarena.com',       '$2y$12$K6HKG8mWOpQwFqVxnHaJVebhJkDDc0Koz7r.lrDXr8cjuwCB/DCiG', 0, 'activo'),
(33, 2, 'Alejandro Píriz',         'ale.org@flexarena.com',           '$2y$12$K6HKG8mWOpQwFqVxnHaJVebhJkDDc0Koz7r.lrDXr8cjuwCB/DCiG', 0, 'activo'),
(34, 3, 'Joaquín Ramos',           'joaco@example.com',               '$2y$12$K6HKG8mWOpQwFqVxnHaJVebhJkDDc0Koz7r.lrDXr8cjuwCB/DCiG', 0, 'activo'),
(35, 3, 'Lucía Benítez',           'lucia@example.com',               '$2y$12$K6HKG8mWOpQwFqVxnHaJVebhJkDDc0Koz7r.lrDXr8cjuwCB/DCiG', 0, 'activo'),
(36, 3, 'Hernán Ortiz',            'hernan@example.com',              '$2y$12$K6HKG8mWOpQwFqVxnHaJVebhJkDDc0Koz7r.lrDXr8cjuwCB/DCiG', 0, 'activo'),
(37, 3, 'Renata Aguirre',          'renata@example.com',              '$2y$12$K6HKG8mWOpQwFqVxnHaJVebhJkDDc0Koz7r.lrDXr8cjuwCB/DCiG', 0, 'activo'),
(38, 3, 'Bruno Medina',            'bruno@example.com',               '$2y$12$K6HKG8mWOpQwFqVxnHaJVebhJkDDc0Koz7r.lrDXr8cjuwCB/DCiG', 0, 'activo'),
(39, 3, 'Micaela Píriz',           'mica@example.com',                '$2y$12$K6HKG8mWOpQwFqVxnHaJVebhJkDDc0Koz7r.lrDXr8cjuwCB/DCiG', 0, 'activo'),
(40, 3, 'Emiliano Cabrera',        'emi@example.com',                 '$2y$12$K6HKG8mWOpQwFqVxnHaJVebhJkDDc0Koz7r.lrDXr8cjuwCB/DCiG', 0, 'activo'),
(41, 3, 'Rocío Pereyra',           'rocio@example.com',               '$2y$12$K6HKG8mWOpQwFqVxnHaJVebhJkDDc0Koz7r.lrDXr8cjuwCB/DCiG', 0, 'activo'),
(42, 3, 'Santiago Duarte',         'santi@example.com',               '$2y$12$K6HKG8mWOpQwFqVxnHaJVebhJkDDc0Koz7r.lrDXr8cjuwCB/DCiG', 0, 'activo'),
(43, 3, 'Victoria Lemos',          'vicky@example.com',               '$2y$12$K6HKG8mWOpQwFqVxnHaJVebhJkDDc0Koz7r.lrDXr8cjuwCB/DCiG', 0, 'activo'),
(44, 3, 'Facundo Olivera',         'facu@example.com',                '$2y$12$K6HKG8mWOpQwFqVxnHaJVebhJkDDc0Koz7r.lrDXr8cjuwCB/DCiG', 0, 'activo'),
(45, 3, 'Antonella Ríos',          'anto@example.com',                '$2y$12$K6HKG8mWOpQwFqVxnHaJVebhJkDDc0Koz7r.lrDXr8cjuwCB/DCiG', 0, 'activo'),
(46, 3, 'Maximiliano Barrios',     'maxi@example.com',                '$2y$12$K6HKG8mWOpQwFqVxnHaJVebhJkDDc0Koz7r.lrDXr8cjuwCB/DCiG', 0, 'activo'),
(47, 3, 'Carolina Techera',        'caro@example.com',                '$2y$12$K6HKG8mWOpQwFqVxnHaJVebhJkDDc0Koz7r.lrDXr8cjuwCB/DCiG', 0, 'activo'),
(48, 3, 'Ignacio Fagúndez',        'nacho@example.com',               '$2y$12$K6HKG8mWOpQwFqVxnHaJVebhJkDDc0Koz7r.lrDXr8cjuwCB/DCiG', 0, 'activo'),
(49, 3, 'Delfina Umpiérrez',       'delfi@example.com',               '$2y$12$K6HKG8mWOpQwFqVxnHaJVebhJkDDc0Koz7r.lrDXr8cjuwCB/DCiG', 0, 'activo'),
(50, 3, 'Thiago Cardozo',          'thiago@example.com',              '$2y$12$K6HKG8mWOpQwFqVxnHaJVebhJkDDc0Koz7r.lrDXr8cjuwCB/DCiG', 0, 'activo'),
(51, 3, 'Guadalupe Sosa',          'guada@example.com',               '$2y$12$K6HKG8mWOpQwFqVxnHaJVebhJkDDc0Koz7r.lrDXr8cjuwCB/DCiG', 0, 'activo'),
(52, 3, 'Leandro Viera',           'lean@example.com',                '$2y$12$K6HKG8mWOpQwFqVxnHaJVebhJkDDc0Koz7r.lrDXr8cjuwCB/DCiG', 0, 'activo'),
(53, 3, 'Pilar Etcheverry',        'pilar@example.com',               '$2y$12$K6HKG8mWOpQwFqVxnHaJVebhJkDDc0Koz7r.lrDXr8cjuwCB/DCiG', 0, 'activo'),
(54, 3, 'Nahuel Machado',          'nahuel@example.com',              '$2y$12$K6HKG8mWOpQwFqVxnHaJVebhJkDDc0Koz7r.lrDXr8cjuwCB/DCiG', 0, 'activo'),
(55, 3, 'Abril Cáceres',           'abril@example.com',               '$2y$12$K6HKG8mWOpQwFqVxnHaJVebhJkDDc0Koz7r.lrDXr8cjuwCB/DCiG', 0, 'activo'),
(56, 3, 'Benjamín Rivero',         'benja@example.com',               '$2y$12$K6HKG8mWOpQwFqVxnHaJVebhJkDDc0Koz7r.lrDXr8cjuwCB/DCiG', 0, 'activo'),
(57, 3, 'Malena Corbo',            'male@example.com',                '$2y$12$K6HKG8mWOpQwFqVxnHaJVebhJkDDc0Koz7r.lrDXr8cjuwCB/DCiG', 0, 'activo'),
(58, 3, 'Álvaro Trinidad',         'alvaro@example.com',              '$2y$12$K6HKG8mWOpQwFqVxnHaJVebhJkDDc0Koz7r.lrDXr8cjuwCB/DCiG', 0, 'activo'),
(59, 3, 'Julieta Falero',          'juli@example.com',                '$2y$12$K6HKG8mWOpQwFqVxnHaJVebhJkDDc0Koz7r.lrDXr8cjuwCB/DCiG', 0, 'activo'),
(60, 3, 'Ramiro Ferreira',         'ramiro@example.com',              '$2y$12$K6HKG8mWOpQwFqVxnHaJVebhJkDDc0Koz7r.lrDXr8cjuwCB/DCiG', 0, 'activo')
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), password_hash = VALUES(password_hash), failed_attempts = VALUES(failed_attempts), estado = VALUES(estado);

-- ─── PARTICIPANTES (20+) ─────────────────────────────────────
-- id 25: perfil vinculado al autorregistro pendiente (usuario 8).
-- Todos los participantes están vinculados a una cuenta de usuario (rol participante,
-- password admin123) para poder probar "Bloquear cuenta" sobre cualquiera de ellos.
INSERT INTO participantes (id, usuario_id, nombre, documento, nick, email, estado) VALUES
(1,  4,  'Matías López',       '30001001', 'MatiasL',  'matias@example.com',  'activo'),
(2,  5,  'Valentina García',   '30001002', 'ValeG',    'vale@example.com',    'activo'),
(3,  6,  'Nicolás Pérez',      '30001003', 'NicoP',    'nico@example.com',    'activo'),
(4,  12, 'Camila Torres',      '30001004', 'CamiT',    'cami@example.com',    'activo'),
(5,  13, 'Lucas Romero',       '30001005', 'LucasR',   'lucas@example.com',   'activo'),
(6,  11, 'Sofía Méndez',       '30001006', 'SofiaM',   'sofia@example.com',   'activo'),
(7,  14, 'Diego Alvarez',      '30001007', 'DiegoA',   'diego@example.com',   'activo'),
(8,  15, 'Ana Fernández',      '30001008', 'AnaF',     'ana@example.com',     'activo'),
(9,  16, 'Martín Suárez',      '30001009', 'MartinS',  'martin@example.com',  'activo'),
(10, 17, 'Julia Herrera',      '30001010', 'JuliaH',   'julia@example.com',   'activo'),
(11, 9,  'Pablo Castro',       '30001011', 'PabloC',   'pablo@example.com',   'activo'),
(12, 18, 'Florencia Reyes',    '30001012', 'FloR',     'flo@example.com',     'activo'),
(13, 19, 'Sebastián Mora',     '30001013', 'SebaM',    'seba@example.com',    'activo'),
(14, 20, 'Gabriela Silva',     '30001014', 'GabiS',    'gabi@example.com',    'activo'),
(15, 21, 'Rodrigo Jiménez',    '30001015', 'RodrJ',    'rodri@example.com',   'activo'),
(16, 22, 'Natalia Ruiz',       '30001016', 'NataR',    'nata@example.com',    'activo'),
(17, 23, 'Franco Núñez',       '30001017', 'FranN',    'franco@example.com',  'activo'),
(18, 24, 'Cecilia Blanco',     '30001018', 'CeciB',    'ceci@example.com',    'activo'),
(19, 25, 'Tomás González',     '30001019', 'TomasG',   'tomas@example.com',   'activo'),
(20, 26, 'Silvana Martínez',   '30001020', 'SilM',     'silv@example.com',    'activo'),
(21, 27, 'Agustín Vargas',     '30001021', 'AgusV',    'agus@example.com',    'activo'),
(22, 28, 'Mariana Sosa',       '30001022', 'MariS',    'mari@example.com',    'activo'),
(23, 29, 'Eduardo Flores',     '30001023', 'EduF',     'edu@example.com',     'activo'),
(24, 10, 'Patricia Campos',    '30001024', 'PatriC',   'patri@example.com',   'activo'),
(25, 8,  'Camila Ortiz',       '30001025', 'CamiO',    'camila.pendiente@example.com', 'pendiente')
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), usuario_id = VALUES(usuario_id);

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
-- (el organizador id=7 está bloqueado, por eso no se le asigna ningún torneo)
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
(1, 'inscribir_participante','inscripciones',1,'Participante 2 inscrito en torneo 1', '127.0.0.1'),
-- Demostración: bloqueo de cuenta tras intentos fallidos (usuario id 7)
(7, 'login_fallido',       'usuarios',    7, 'Intento fallido para usuario id: 7',    '203.0.113.9'),
(7, 'login_fallido',       'usuarios',    7, 'Intento fallido para usuario id: 7',    '203.0.113.9'),
(7, 'cuenta_bloqueada',    'usuarios',    7, 'Cuenta bloqueada por excesivos intentos de login', '203.0.113.9'),
-- Demostración: autorregistro pendiente de aprobación (usuario id 8)
(8, 'registro_participante','usuarios',   8, 'Auto-registro de participante: camila.pendiente@example.com (pendiente de aprobación)', '198.51.100.4');

SET FOREIGN_KEY_CHECKS = 1;
