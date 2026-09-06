# Pasaje a Tablas y Normalización — FlexArena

Documento que describe (1) el **pasaje del modelo entidad-relación al modelo
relacional** (mapeo lógico → físico) y (2) el **análisis de normalización**
(1FN, 2FN, 3FN/BCNF) del esquema implementado en `database/schema.sql`.

> Motor: MySQL 8.0 · InnoDB · utf8mb4_unicode_ci.

---

## Parte 1 — Pasaje a Tablas

El pasaje aplica las reglas clásicas de transformación del MER al modelo
relacional. Se documentan por tipo de construcción.

### 1.1 Entidades fuertes → tablas

Cada entidad fuerte se convierte en una tabla con clave primaria sustituta
(surrogate key) `id` autoincremental.

| Entidad | Tabla | PK |
|---------|-------|----|
| Rol | `roles` | `id` |
| Usuario | `usuarios` | `id` |
| Participante | `participantes` | `id` |
| Equipo | `equipos` | `id` |
| Tipo de torneo | `tipos_torneo` | `id` |
| Módulo | `modulos` | `id` |
| Torneo | `torneos` | `id` |
| Inscripción | `inscripciones` | `id` |
| Ronda | `rondas` | `id` |
| Enfrentamiento | `enfrentamientos` | `id` |
| Resultado | `resultados` | `id` |
| Tabla de posiciones | `tabla_posiciones` | `id` |
| Auditoría | `auditoria` | `id` |
| Permiso | `permisos` | `id` |
| Configuración de torneo | `configuraciones_torneo` | `id` |
| Solicitud de corrección | `solicitudes_correccion` | `id` |

Se eligió **clave sustituta** en lugar de claves naturales (documento, email,
slug) para: estabilidad ante cambios, joins más simples y compatibilidad con
ORM/consultas. Las claves naturales se preservan como `UNIQUE` (ver 1.6).

### 1.2 Relaciones 1:N → clave foránea en el lado "N"

La clave de la entidad del lado "1" se propaga como FK al lado "N".

| Relación (1 — N) | FK resultante |
|------------------|---------------|
| roles 1—N usuarios | `usuarios.rol_id` |
| usuarios 1—N participantes | `participantes.usuario_id` (nullable) |
| tipos_torneo 1—N torneos | `torneos.tipo_torneo_id` |
| usuarios 1—N torneos (creador) | `torneos.creado_por` |
| torneos 1—N inscripciones | `inscripciones.torneo_id` |
| torneos 1—N rondas | `rondas.torneo_id` |
| torneos 1—N enfrentamientos | `enfrentamientos.torneo_id` |
| rondas 1—N enfrentamientos | `enfrentamientos.ronda_id` |
| torneos 1—N tabla_posiciones | `tabla_posiciones.torneo_id` |
| roles 1—N permisos | `permisos.rol_id` |
| modulos 1—N permisos | `permisos.modulo_slug` → `modulos.slug` |
| torneos 1—N configuraciones_torneo | `configuraciones_torneo.torneo_id` |
| usuarios 1—N auditoria | `auditoria.usuario_id` (nullable) |

### 1.3 Relaciones 1:1 → FK con UNIQUE

| Relación (1 — 1) | Implementación |
|------------------|----------------|
| enfrentamiento 1—1 resultado | `resultados.enfrentamiento_id UNIQUE` |

El resultado se modeló en tabla aparte (y no como columnas del enfrentamiento)
para aislar el ciclo de vida de la carga/corrección (estado, auditoría de
cambios, valores anteriores).

### 1.4 Relaciones N:M → tablas intermedias (puente)

Cada relación muchos-a-muchos genera una tabla asociativa con las dos FK y
clave única compuesta.

| Relación (N — M) | Tabla puente | Clave |
|------------------|--------------|-------|
| equipos N—M participantes | `equipo_participantes` | `UNIQUE (equipo_id, participante_id)` |
| torneos N—M usuarios (organizadores) | `torneo_organizadores` | `PK (torneo_id, usuario_id)` |

`equipo_participantes` además porta **atributos de la relación**
(`rol_en_equipo`, `fecha_ingreso`), lo que confirma su existencia como entidad
asociativa con datos propios.

### 1.5 Roles múltiples sobre la misma entidad (FK repetidas)

`enfrentamientos` referencia a competidores en distintos papeles, generando
varias FK hacia la misma tabla:

- Competidores: `participante_a_id`, `participante_b_id` / `equipo_a_id`, `equipo_b_id`
- Ganador / perdedor: `ganador_participante_id`, `perdedor_participante_id`, `ganador_equipo_id`, `perdedor_equipo_id`

Lo mismo en `torneos` con `campeon_participante_id` / `campeon_equipo_id`.

### 1.6 Claves naturales preservadas como UNIQUE

Para mantener identidad de negocio sin usarlas como PK:

- `usuarios.email`, `roles.nombre`, `tipos_torneo.slug`, `modulos.slug`
- `inscripciones (torneo_id, participante_id)` y `(torneo_id, equipo_id)`
- `rondas (torneo_id, numero)`, `permisos (rol_id, modulo_slug)`,
  `configuraciones_torneo (torneo_id, clave)`

### 1.7 Especialización / modalidad dual (participante u equipo)

El sistema soporta torneos **individuales** y **por equipos**. En lugar de
tablas separadas se aplicó **una sola tabla con dos FK opcionales** y un CHECK
de presencia:

- `inscripciones`, `tabla_posiciones`, `enfrentamientos`, `resultados`
  llevan a la vez `participante_id` (nullable) y `equipo_id` (nullable).
- `CHECK (participante_id IS NOT NULL OR equipo_id IS NOT NULL)` garantiza que
  haya al menos un competidor.

> Limitación conocida: el CHECK no es XOR exclusivo (ver normalización 2.5 y
> el documento de RNE).

### 1.8 Atributos de tipo enumerado

Los dominios cerrados se modelaron con `ENUM` (estado de torneo, inscripción,
ronda, enfrentamiento, resultado, modalidad, etc.) en lugar de tablas catálogo,
por ser conjuntos pequeños y estables.

### 1.9 Acciones referenciales (integridad en cascada)

El pasaje también definió la política de borrado:

- `ON DELETE CASCADE`: hijos dependientes del torneo (inscripciones, rondas,
  enfrentamientos, resultados, posiciones, configuraciones, organizadores,
  solicitudes) y `equipo_participantes`.
- `ON DELETE SET NULL`: referencias históricas (campeón, ganador/perdedor,
  `cargado_por`, `participantes.usuario_id`, `auditoria.usuario_id`) para
  **no perder el registro histórico** del partido al borrar un competidor.

---

## Parte 2 — Normalización

Se analiza el esquema contra las formas normales. El diseño cumple **3FN**
(y BCNF en la práctica) en todas las tablas. A continuación el razonamiento.

### 2.1 Primera Forma Normal (1FN)

> Todos los atributos son atómicos; no hay grupos repetitivos ni listas.

- Ninguna columna almacena listas separadas por comas.
- Los integrantes de un equipo **no** se guardan como campo multivaluado: se
  normalizaron en la tabla puente `equipo_participantes` (una fila por miembro).
- Los organizadores de un torneo se normalizaron en `torneo_organizadores`.
- Los permisos por módulo son filas en `permisos`, no un blob por rol.

**Excepción controlada:** `auditoria.valor_anterior` / `valor_nuevo` son `JSON`.
Es una decisión deliberada: la auditoría es un *log* genérico que debe capturar
el snapshot de cualquier tabla, por lo que un esquema rígido columna-a-columna
no aplica. No se consulta relacionalmente por esos campos → no rompe el modelo
operativo. Se documenta como desviación justificada de 1FN estricta.

### 2.2 Segunda Forma Normal (2FN)

> Está en 1FN y todo atributo no clave depende de la **clave completa**
> (no de parte de una clave compuesta).

Como casi todas las tablas usan **PK simple sustituta (`id`)**, la 2FN se
cumple trivialmente (no hay clave compuesta de la que depender parcialmente).

Las tablas con clave compuesta real son las puente:

- `torneo_organizadores (torneo_id, usuario_id)`: no tiene atributos no clave →
  2FN trivial.
- `equipo_participantes`: usa `id` como PK y `(equipo_id, participante_id)` como
  UNIQUE; sus atributos (`rol_en_equipo`, `fecha_ingreso`) dependen de la
  combinación completa equipo+participante, **no** de uno solo → cumple 2FN.

### 2.3 Tercera Forma Normal (3FN)

> Está en 2FN y no hay dependencias transitivas: ningún atributo no clave
> depende de otro atributo no clave.

Verificación por tabla representativa:

- **usuarios**: `nombre`, `email`, `password_hash`, `estado` dependen solo de
  `id`. `rol_id` es FK (la descripción del rol vive en `roles`, no se duplica).
- **torneos**: el nombre/descr. del tipo de torneo **no** se copian aquí; se
  referencian vía `tipo_torneo_id` → sin transitividad.
- **inscripciones / enfrentamientos / tabla_posiciones**: los datos del
  participante o equipo (nombre, etc.) no se duplican; se accede por FK.
- **resultados**: `puntos_a/b`, `estado`, `motivo` dependen del `id` del
  resultado; el dato del enfrentamiento se referencia, no se repite.
- **permisos**: el nombre del módulo no se almacena; se referencia por
  `modulo_slug` → `modulos`.

No se detectan dependencias transitivas → **3FN cumplida**.

### 2.4 Datos derivados / desnormalización deliberada

Algunos campos son **calculables** a partir de otras tablas y se almacenan por
rendimiento (evitar recomputar agregados en cada consulta de tabla de
posiciones). Es **desnormalización controlada**, no una violación de 3FN
estructural, pero se documenta como tal:

| Campo redundante | Se deriva de | Motivo |
|------------------|--------------|--------|
| `tabla_posiciones.pj/pg/pe/pp/pf/pc/puntos/diferencia/posicion` | agregación de `enfrentamientos`+`resultados` | rendimiento de ranking |
| `tabla_posiciones.buchholz`, `byes_recibidos` | cálculo Suizo | rendimiento |
| `torneos.campeon_*` | resultado final del torneo | acceso directo al campeón |
| `enfrentamientos.ganador_*/perdedor_*` | comparación de `resultados.puntos_a/b` | evitar recálculo por partido |

Estos valores se mantienen consistentes desde la capa de servicios
(`TablaPosicionesService`, `ResultadoService`), que es la **fuente de verdad**
de su actualización.

### 2.5 Observaciones y mejoras potenciales

- **XOR participante/equipo:** el CHECK actual exige "al menos uno" pero permite
  ambos. Para BCNF semántica estricta podría endurecerse a exclusividad
  (CHECK XOR) — hoy se garantiza por código (ver RNE #21).
- **`auditoria` (JSON):** desviación justificada de 1FN para log genérico.
- **Desnormalización de la tabla de posiciones:** aceptada por rendimiento;
  requiere disciplina de la capa de servicios para evitar inconsistencias.

---

## Resumen

- **Pasaje a tablas:** 16 entidades fuertes + 2 tablas puente (N:M), con claves
  sustitutas, claves naturales preservadas como UNIQUE, modalidad dual mediante
  FK opcionales + CHECK, y políticas referenciales CASCADE/SET NULL definidas.
- **Normalización:** el esquema cumple **3FN** en todas las tablas; las únicas
  desviaciones son deliberadas y documentadas (JSON de auditoría y campos
  derivados de la tabla de posiciones por rendimiento).

Documento al 2026-06-17 · basado en `database/schema.sql`.
