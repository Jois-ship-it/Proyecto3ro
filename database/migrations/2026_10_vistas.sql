-- ============================================================
-- Migración: vistas SQL
--
-- Las consultas que la aplicación repetía en varios lugares pasan a ser vistas:
-- una sola definición de qué es «público», cómo se arma un partido con los nombres
-- de sus dos lados, y cómo se ve la tabla de posiciones.
--
--   v_torneos_publicos          qué torneos puede ver un visitante, con su cantidad
--                               de inscritos (antes: una consulta por tarjeta)
--   v_calendario                cada partido con su torneo, ronda, nombres y resultado
--   v_posiciones                la tabla de posiciones con el nombre de cada competidor
--   v_estadisticas_sistema      los contadores del panel, en una sola fila
--   v_participantes_publicos    el perfil público de un participante: sin documento,
--                               email ni teléfono
--
-- Se pueden crear de nuevo cuantas veces haga falta (CREATE OR REPLACE VIEW).
--
-- Una vista que usa `SELECT t.*` toma las columnas que la tabla tenía al crearla:
-- si la tabla gana una columna, hay que volver a correr esta migración.
-- tests/vistas_test.php lo verifica.
--
-- Ejecutar:
--   docker compose exec -T db mysql -u root -p"$DB_ROOT_PASS" flexarena < database/migrations/2026_10_vistas.sql
-- ============================================================
SET NAMES utf8mb4;

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

SELECT 'Vistas SQL creadas.' AS resultado;
