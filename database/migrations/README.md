# Migraciones

**Ninguna de estas migraciones hace falta para instalar FlexArena.**

Para una base nueva alcanza con:

```bash
mysql flexarena < database/schema.sql
mysql flexarena < database/seed.sql
```

`schema.sql` ya contiene todo lo que estas migraciones agregaron. Está
verificado, no asumido: se construyen dos bases —una con `schema.sql` + `seed.sql`
y otra con lo mismo más las dieciséis migraciones en orden— y se comparan sus
estructuras con `mysqldump --no-data`. La comprobación está automatizada en
[`tests/migraciones_consolidadas_test.php`](../../tests/migraciones_consolidadas_test.php).

Docker hace exactamente eso: `docker-compose.yml` monta `schema.sql` y `seed.sql`
como `01_` y `02_` en `docker-entrypoint-initdb.d`, y nada más.

## Entonces, ¿para qué están?

Son el registro de cómo evolucionó el esquema durante el desarrollo, y el camino
de actualización para una base que ya exista y esté atrasada. Al 2026-09-17 no había
ninguna en esa situación; desde octubre sí: hay instalaciones con datos que se
actualizan, así que **todo cambio de esquema vuelve a llevar su migración**, además
de reflejarse en `schema.sql`.

## Qué hizo cada una

Se aplican en orden de fecha. Todas son idempotentes salvo donde se aclare.

| Archivo | Qué hizo |
|---|---|
| `2026_06_refactor_torneos.sql` | Agregó `torneos.min_integrantes_equipo`, creó `solicitudes_correccion` y retiró el rol `publico` (un visitante sin sesión no es una entidad del sistema). |
| `2026_06_perfiles.sql` | `participantes.foto`, `equipos.logo`, `equipos.descripcion`. |
| `2026_06_registro_participantes.sql` | Agregó `pendiente` y `rechazado` a `usuarios.estado` y `participantes.estado`. **Ver la trampa abajo.** |
| `2026_06_partidos_fechas.sql` | `enfrentamientos.fecha_inicio_real` y `fecha_fin_real`, más un relleno de los partidos ya jugados. Ese relleno también les ponía horario a los BYE, que no se juegan: el sistema no lo hace. |
| `2026_06_fix_fechas_fuera_rango.sql` | Solo datos: anula las `fecha_programada` que hubieran quedado fuera del rango de su torneo. |
| `2026_06_fk_permisos_modulos.sql` | Agregó la FK `permisos.modulo_slug → modulos.slug`. **Ya no hace nada útil:** `schema.sql` crea esa FK, así que correr esta migración sobre un esquema actual deja la restricción por duplicado (`fk_permisos_modulo` además de `permisos_ibfk_2`). |
| `2026_09_rondas_estado.sql` | Dejó `rondas.estado` en `pendiente / en_curso / cerrada` y normalizó las filas existentes. |
| `2026_09_permisos_matriz.sql` | Solo datos: alineó la matriz de permisos con la sección 5 de la letra del proyecto. |
| `2026_09_banderas_puntuacion.sql` | Eliminó `torneos.requiere_desempate_final`, una bandera que se guardaba y ninguna consulta leía. |
| `2026_09_add_failed_attempts_and_bloqueada.sql` | `usuarios.failed_attempts` y el estado `bloqueada`. |
| `2026_10_esquema_integridad.sql` | `usuarios.bloqueada_hasta` (bloqueo que vence solo), las tablas `configuracion_sistema` e `intentos_login`, los índices únicos de `equipos.nombre` y `participantes.documento` (los documentos vacíos pasan a `NULL`) y los `CHECK`: puntos no negativos, rango de fechas del torneo y **un** competidor por inscripción y por fila de posiciones. Si hay datos que violan una regla **falla diciendo cuál** y no corrige nada por su cuenta. |
| `2026_10_cuentas_sin_clave_conocida.sql` | Solo datos: deja sin contraseña a las cuentas que conservan alguno de los dos hashes que el seed publicaba. Las contraseñas nuevas las asigna el arranque (`database/inicializar_cuentas.php`). |
| `2026_10_formato_modulo.sql` | `tipos_torneo.modulo_slug` (obligatoria) y la clave foránea `tipos_torneo → modulos`: hasta entonces cada formato se relacionaba con el módulo que lo implementa solo porque los dos se llamaban igual, y nada impedía borrar el módulo o cargar un formato sin él. Rellena la columna con el slug del formato, que es lo que el sistema asumía; si algún formato no tiene módulo, **falla diciendo cuál**. |
| `2026_10_empates_sin_ganador.sql` | Solo datos: un empate no tiene ganador ni perdedor. Hasta octubre el sistema guardaba al lado A como «ganador» de un partido empatado (la tabla de posiciones no lo leía, pero cualquier lista que mostrara la columna lo habría presentado como victoria). Deja en `NULL` esas columnas en los empates ya guardados, en `enfrentamientos` y en `resultados`. No toca los bye ni cambia `updated_at`. Idempotente. |
| `2026_10_vistas.sql` | Las cinco vistas (`v_torneos_publicos`, `v_calendario`, `v_posiciones`, `v_estadisticas_sistema`, `v_participantes_publicos`). Reemplazan consultas armadas a mano en los modelos. Se crean con `CREATE OR REPLACE`, así que correrla de nuevo es seguro (y necesario si una tabla gana una columna, porque las vistas con `SELECT t.*` fijan las que había al crearlas). |
| `2026_10_historial_bd.sql` | La tabla `historial_bd` y los 51 triggers (tres por cada una de las 17 tablas de datos) que escriben en ella cada `INSERT`, `UPDATE` y `DELETE`. **La genera** `database/generar_historial.php`: no se edita a mano. Arranca vacía; no reconstruye lo que pasó antes de aplicarla. |

## La trampa del orden

`2026_06_registro_participantes.sql` hace un `MODIFY` de `usuarios.estado`
**sin** incluir `bloqueada` — cuando se escribió, ese estado todavía no existía.
Aplicada sobre un esquema actual, se lo lleva puesto.

`2026_09_add_failed_attempts_and_bloqueada.sql` lo devuelve, así que el orden por
fecha lo resuelve. Pero si alguien corre una sola migración suelta, o las corre
en otro orden, el bloqueo de cuentas deja de funcionar **en silencio**: un ENUM
no da error al recibir un valor que no está en su lista, guarda cadena vacía. Ya
pasó una vez.

## Cómo se comprueba la actualización

`tests/fixtures/schema_2026_09_17.sql` es una foto del esquema antes de las migraciones
de octubre. `tests/migraciones_consolidadas_test.php` le aplica las migraciones
posteriores y exige que quede idéntica a una base creada con `schema.sql` (columnas, índices,
claves foráneas, `CHECK`, vistas y triggers), y
`tests/migracion_2026_10_test.php` lo hace con datos. Si agregás una migración, esos tests
fallan hasta que `schema.sql` y la migración digan exactamente lo mismo.

## Lo que NO está acá

El recorte de los valores de ENUM que el sistema no sabía producir (septiembre) fue directo
a `schema.sql` y no tiene migración: en ese momento no había ninguna base instalada que
actualizar. Está incluido en la foto de septiembre.
