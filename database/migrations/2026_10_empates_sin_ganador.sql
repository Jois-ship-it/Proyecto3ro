-- ============================================================
-- Migración: un empate no tiene ganador ni perdedor
--
-- Hasta ahora, al cargar (o corregir) un partido empatado el sistema guardaba al lado A
-- como «ganador» y al lado B como «perdedor», tanto en `enfrentamientos` como en
-- `resultados`. Es un dato falso: la tabla de posiciones se calcula por los puntos y nunca
-- lo leyó, pero cualquier lista que mostrara la columna (el calendario, el reporte, la
-- API) lo habría presentado como una victoria del lado A.
--
-- El código ya deja esas columnas en NULL cuando hay empate (ResultadoService). Esta
-- migración hace lo mismo con los empates que ya estaban guardados.
--
-- Solo toca partidos que se jugaron y terminaron con el mismo puntaje en los dos lados.
-- No toca los bye: en un bye, quien pasa de ronda SÍ queda como ganador aunque sus puntos
-- (0 o los de una victoria) coincidan con los del lado vacío. Tampoco toca ningún partido
-- con ganador por puntos, ni cambia `updated_at`.
--
-- Idempotente: la segunda vez no encuentra nada que cambiar.
--
-- Ejecutar:
--   docker compose exec -T db mysql -u root -p"$DB_ROOT_PASS" flexarena < database/migrations/2026_10_empates_sin_ganador.sql
-- ============================================================
SET NAMES utf8mb4;

UPDATE enfrentamientos e
  JOIN resultados r ON r.enfrentamiento_id = e.id
   SET e.ganador_participante_id  = NULL,
       e.ganador_equipo_id        = NULL,
       e.perdedor_participante_id = NULL,
       e.perdedor_equipo_id       = NULL,
       e.updated_at               = e.updated_at
 WHERE e.es_bye = 0
   AND r.puntos_a = r.puntos_b
   AND (e.ganador_participante_id  IS NOT NULL OR e.ganador_equipo_id  IS NOT NULL
     OR e.perdedor_participante_id IS NOT NULL OR e.perdedor_equipo_id IS NOT NULL);

SELECT ROW_COUNT() AS enfrentamientos_empatados_corregidos;

UPDATE resultados r
  JOIN enfrentamientos e ON e.id = r.enfrentamiento_id
   SET r.ganador_participante_id = NULL,
       r.ganador_equipo_id       = NULL
 WHERE e.es_bye = 0
   AND r.puntos_a = r.puntos_b
   AND (r.ganador_participante_id IS NOT NULL OR r.ganador_equipo_id IS NOT NULL);

SELECT ROW_COUNT() AS resultados_empatados_corregidos;
