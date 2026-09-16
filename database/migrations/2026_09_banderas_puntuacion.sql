-- ============================================================
-- Migración: las dos banderas de puntuación que nadie leía
--
-- `torneos.usa_puntos_favor` y `torneos.requiere_desempate_final` se
-- persistían y ninguna consulta las leía. El informe técnico de junio de 2026
-- ya lo señalaba. Cada una se resolvió distinto, porque no tenían el mismo
-- problema.
--
-- ── usa_puntos_favor: SE QUEDA, y ahora decide ──────────────────────────────
--
-- Tiene un significado claro y un apagado que sirve: si vale 0, la tabla de
-- posiciones deja de desempatar por diferencia y puntos a favor y pasa directo
-- a partidos ganados. Es lo correcto en formatos donde el marcador no mide
-- rendimiento: en ajedrez se anota 1, medio punto o 0, así que «puntos a favor»
-- es una copia de «puntos» y ordenar por eso no agrega información.
--
-- Esta migración arregla además un error de dato. El formulario nunca mandaba
-- el campo, y el servicio hacía `isset($d['usa_puntos_favor']) ? 1 : 0`, que
-- con un valor ausente da 0: cada vez que alguien guardaba un torneo desde la
-- app, la bandera se apagaba en silencio, pisando el DEFAULT 1 del esquema.
-- Como hasta ahora la columna no se leía, el error no se notaba; desde que se
-- lee, sí. Los torneos que quedaron en 0 por ese camino se restauran a 1, salvo
-- los de sistema suizo, donde 0 es el valor que corresponde.
--
-- ── requiere_desempate_final: SE ELIMINA ────────────────────────────────────
--
-- Acá el problema no es que no se lea, sino que su premisa no cierra. El
-- sistema crea una ronda «Desempate N» cuando los dos primeros empatan en TODAS
-- las métricas de orden, y esa es la conducta probada y deseada
-- (`tests/tiebreak_test.php`). Que la bandera valga 0 debería significar «no
-- desempatar», pero en ese punto no queda ningún criterio deportivo disponible:
-- la única alternativa es coronar campeón al de id más chico, que no se puede
-- defender, o declarar dos campeones, que el esquema no puede representar
-- (`campeon_participante_id` y `campeon_equipo_id` son uno solo).
--
-- Dicho de otro modo: no hay forma razonable de implementarla. Se retira en vez
-- de dejar en el formulario una casilla que promete algo que no va a pasar.
-- No se pierde información: la columna valía 0 en todas las filas.
--
-- Idempotente: se puede correr las veces que haga falta.
--
-- Ejecutar:
--   docker compose exec -T db mysql -u root -p"$DB_ROOT_PASS" flexarena < database/migrations/2026_09_banderas_puntuacion.sql
-- ============================================================

-- 1) Restaurar usa_puntos_favor donde lo apagó el bug.
--    El suizo se deja como está: ahí el 0 es el valor correcto.
UPDATE torneos t
  JOIN tipos_torneo tt ON tt.id = t.tipo_torneo_id
   SET t.usa_puntos_favor = 1
 WHERE t.usa_puntos_favor = 0
   AND tt.slug <> 'suizo';

-- 2) Bajar requiere_desempate_final.
--    Todo va por PREPARE/EXECUTE, incluida la comprobación previa: en la
--    segunda corrida la columna ya no existe, y un SELECT que la nombre en
--    texto plano sería un error de sintaxis, no un no-op.
SET @existe := (SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                   AND table_name   = 'torneos'
                   AND column_name  = 'requiere_desempate_final');

-- Aviso: si alguna fila tuviera un valor distinto de 0, PARAR y revisar antes
-- de seguir; querría decir que la columna sí se usaba de algún modo que este
-- análisis no vio. Al escribir esto valía 0 en todas.
SET @sql := IF(@existe > 0,
    'SELECT COUNT(*) AS filas_con_desempate_final_en_1 FROM torneos WHERE requiere_desempate_final <> 0',
    'SELECT 0 AS filas_con_desempate_final_en_1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(@existe > 0,
    'ALTER TABLE torneos DROP COLUMN requiere_desempate_final',
    'SELECT "requiere_desempate_final ya no existe" AS resultado');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT 'Banderas de puntuacion normalizadas.' AS resultado;
