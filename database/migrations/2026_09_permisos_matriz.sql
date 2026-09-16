-- ============================================================
-- Migración: la tabla `permisos` pasa a gobernar el acceso
--
-- Hasta ahora la tabla existía, tenía FK e índice único, y NINGUNA consulta la
-- leía: el control de acceso era solo por rol. Con PermisoService conectado,
-- cada fila decide de verdad, así que la matriz sembrada tiene que coincidir
-- con la letra del proyecto (sección 5, Roles del sistema).
--
-- Corrige una fila que contradecía la letra:
--   organizador × participantes pasaba crear/editar, pero §5.1 le da
--   "gestionar participantes y equipos" al ADMINISTRADOR y §5.2 le deja al
--   organizador solo "inscribir participantes" (acción sobre el torneo, no
--   sobre el padrón). Queda en solo lectura.
--
-- El `seed.sql` original traía ON DUPLICATE KEY UPDATE puede_ver = VALUES(...),
-- que solo refrescaba esa columna: en una base ya instalada, volver a correr el
-- seed NO habría corregido puede_crear ni puede_editar. De ahí que haga falta
-- esta migración y no alcance con el seed actualizado.
--
-- Idempotente: se puede correr las veces que haga falta.
--
-- Ejecutar:
--   docker compose exec -T db mysql -u root -p"$DB_ROOT_PASS" flexarena < database/migrations/2026_09_permisos_matriz.sql
-- ============================================================

-- 1) El organizador no gestiona el padrón de participantes (§5.1 / §5.2).
UPDATE permisos
   SET puede_crear = 0, puede_editar = 0, puede_eliminar = 0
 WHERE modulo_slug = 'participantes'
   AND rol_id = (SELECT id FROM roles WHERE nombre = 'organizador');

-- 2) El administrador no lleva filas: §5.1 le da control completo y
--    PermisoService lo deja pasar sin consultar la tabla. Si alguna quedó de
--    una carga anterior, se borra para que la pantalla no sugiera que se le
--    puede recortar el acceso.
DELETE FROM permisos
 WHERE rol_id = (SELECT id FROM roles WHERE nombre = 'administrador');

-- 3) Verificación: la matriz que queda, para revisarla de un vistazo.
SELECT r.nombre AS rol,
       p.modulo_slug AS modulo,
       p.puede_ver, p.puede_crear, p.puede_editar, p.puede_eliminar
  FROM permisos p
  JOIN roles r ON r.id = p.rol_id
 ORDER BY r.nombre, p.modulo_slug;

SELECT 'Matriz de permisos alineada con la letra (seccion 5).' AS resultado;
