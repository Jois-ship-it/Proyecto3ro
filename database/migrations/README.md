# Migraciones

**Ninguna de estas migraciones hace falta para instalar FlexArena.**

Para una base nueva alcanza con:

```bash
mysql flexarena < database/schema.sql
mysql flexarena < database/seed.sql
```

`schema.sql` ya contiene todo lo que estas migraciones agregaron. Está
verificado, no asumido: se construyen dos bases —una con `schema.sql` + `seed.sql`
y otra con lo mismo más las diez migraciones en orden— y se comparan sus
estructuras con `mysqldump --no-data`. La comprobación está automatizada en
[`tests/migraciones_consolidadas_test.php`](../../tests/migraciones_consolidadas_test.php).

Docker hace exactamente eso: `docker-compose.yml` monta `schema.sql` y `seed.sql`
como `01_` y `02_` en `docker-entrypoint-initdb.d`, y nada más.

## Entonces, ¿para qué están?

Son el registro de cómo evolucionó el esquema durante el desarrollo, y el camino
de actualización para una base que ya exista y esté atrasada. Al 2026-09-17 el
sistema todavía no está instalado en el servidor principal, así que en la
práctica no hay ninguna base en esa situación.

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

## La trampa del orden

`2026_06_registro_participantes.sql` hace un `MODIFY` de `usuarios.estado`
**sin** incluir `bloqueada` — cuando se escribió, ese estado todavía no existía.
Aplicada sobre un esquema actual, se lo lleva puesto.

`2026_09_add_failed_attempts_and_bloqueada.sql` lo devuelve, así que el orden por
fecha lo resuelve. Pero si alguien corre una sola migración suelta, o las corre
en otro orden, el bloqueo de cuentas deja de funcionar **en silencio**: un ENUM
no da error al recibir un valor que no está en su lista, guarda cadena vacía. Ya
pasó una vez.

## Lo que NO está acá

Los cambios de esquema posteriores al 2026-09-17 —por ejemplo el recorte de los
valores de ENUM que el sistema no sabía producir— van directo a `schema.sql` y no
tienen migración, justamente porque no hay ninguna base instalada que actualizar.
Si eso cambia, la primera instalación en un servidor real es el punto a partir
del cual vuelve a hacer falta escribirlas.
