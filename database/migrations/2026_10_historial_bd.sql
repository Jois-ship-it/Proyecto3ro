-- ============================================================
-- Migración: historial de la base de datos (tabla `historial_bd` y sus triggers)
--
-- La letra pide que quede registrado cualquier cambio en la información de la base.
-- `auditoria` registra lo que hace una persona desde la aplicación; esto registra TODO lo
-- que cambie en las tablas de datos, venga de donde venga: un script, phpMyAdmin, el cliente
-- mysql. Cada INSERT, UPDATE o DELETE deja una fila con el registro antes y después.
--
-- Los triggers los genera database/generar_historial.php: son iguales salvo por las columnas
-- de cada tabla. NO se editan a mano; si una tabla gana una columna se vuelve a generar
-- (tests/historial_bd_test.php avisa). El mismo bloque está en database/schema.sql.
--
-- No guarda contraseñas ni datos personales (nombre, documento, email, teléfono…): el
-- historial no lo puede modificar la aplicación y la anonimización que pide la ley de datos
-- personales no serviría de nada si ahí quedaran. De esas columnas solo deja constancia de
-- que cambiaron.
--
-- La aplicación solo puede LEER la tabla; la escriben los triggers con los privilegios de
-- quien los creó, así que ni una inyección SQL puede falsificarla.
--
-- Idempotente: la tabla se crea si falta y los triggers se recrean.
--
-- Ejecutar:
--   docker compose exec -T db mysql -u root -p"$DB_ROOT_PASS" flexarena < database/migrations/2026_10_historial_bd.sql
-- ============================================================
SET NAMES utf8mb4;

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

SELECT 'Historial de la base de datos instalado.' AS resultado;
