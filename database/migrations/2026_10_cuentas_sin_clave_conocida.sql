-- ============================================================
-- Migración: las cuentas del seed dejan de tener contraseñas publicadas
--
-- Hasta ahora seed.sql traía las contraseñas de todas las cuentas escritas en un
-- comentario (y los hashes, que son de esas mismas contraseñas). Toda
-- instalación nacía con un administrador cuya clave conocía cualquiera que
-- pudiera leer el repositorio, y las demás cuentas usaban una que además
-- incumple la política de contraseñas del propio sistema.
--
-- Esta migración deja sin contraseña a las cuentas que conserven alguno de esos
-- dos hashes. «Sin contraseña» es un valor que no es un hash válido, así que
-- ninguna clave lo verifica: la cuenta existe y no se puede entrar con ella.
-- Las contraseñas nuevas las asigna el arranque del contenedor `app` a partir de
-- ADMIN_PASS y DEMO_PASS del .env (database/inicializar_cuentas.php).
--
-- Una cuenta cuya clave alguien ya cambió desde la aplicación tiene OTRO hash y
-- no se toca.
--
-- Idempotente: la segunda vez no encuentra nada que cambiar.
--
-- Ejecutar:
--   docker compose exec -T db mysql -u root -p"$DB_ROOT_PASS" flexarena < database/migrations/2026_10_cuentas_sin_clave_conocida.sql
-- ============================================================
SET NAMES utf8mb4;

UPDATE usuarios
   SET password_hash = '!sin-clave-definida'
 WHERE password_hash IN (
        '$2y$12$cVKpHtHbJHPytOM0SZtX2ua24uvjAMSmXehWHzptO6j4Qe8UGYEXi',   -- administrador del seed
        '$2y$12$K6HKG8mWOpQwFqVxnHaJVebhJkDDc0Koz7r.lrDXr8cjuwCB/DCiG'    -- resto de las cuentas del seed
 );

SELECT ROW_COUNT() AS cuentas_dejadas_sin_clave;
