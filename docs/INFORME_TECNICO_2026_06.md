# Informe técnico — Torneos (junio 2026)

Cambios aplicados de forma idéntica en **ambos** proyectos:
`Proyecto Tercero Claude` y `Proyecto Tercero Claude - copia`.

Índice:
1. Desempate ilimitado (serie hasta que haya ganador)
2. Auditoría de validaciones (existentes / faltantes / implementadas)
3. Fechas de los partidos
4. Base de datos, transacciones y concurrencia
5. Cómo aplicar las migraciones
6. Tests

---

## 1. Desempate ilimitado

### Problema
La versión anterior creaba **un solo** partido de desempate ante un empate exacto y,
si ese partido volvía a empatar, declaraba campeón al puesto 1 de forma arbitraria.

### Solución
El desempate ahora es una **serie que se repite hasta que exista un ganador real**:

- Al finalizar todos los partidos, si los dos primeros están empatados en **todas**
  las métricas de orden (puntos, diferencia, PF, PG, Buchholz) se genera una ronda
  `Desempate N` con un único partido entre ellos.
- El partido de desempate **suma a la tabla como uno más**. Por lo tanto:
  - si hay ganador, su puntaje rompe el empate y queda 1.º → **campeón**;
  - si vuelve a empatar, la siguiente verificación detecta el empate y genera
    `Desempate N+1`. **Sin límite de repeticiones.**
- La condición de fin **no** es "se jugó un desempate", sino "ya **no** hay empate
  exacto en la cima".

El bucle es natural porque `intentarFinalizar()` se vuelve a ejecutar cada vez que se
carga un resultado: mientras quede un partido (incluido el desempate) sin jugar,
retorna sin finalizar; cuando el desempate se carga, reevalúa y decide.

### Archivos
- `app/services/LigaService.php` — `intentarFinalizar()`, `hayEmpateEnCima()`, `crearRondaDesempate()`
- `app/services/SistemaSuizoService.php` — ídem (cuenta rondas suizas excluyendo desempates con `contarDesempates()`)
- `app/models/RondaModel.php` — `contarDesempates()`
- `app/services/ResultadoService.php` — un empate en un partido de desempate es válido (Liga/Suizo) para poder disparar el siguiente; sigue prohibido en Eliminación Directa.
- `app/controllers/TorneoController.php` y `OrganizadorController.php` — marcan `desempatePendiente` y excluyen los desempates del conteo de rondas suizas.
- `app/views/admin/torneo_gestion.php` y `app/views/organizador/torneo_gestion.php` — aviso "Desempate en curso".

### Eliminación Directa
No se toca: nunca usa tabla de puntajes y su empate sigue estando prohibido. La regla
de desempate **solo** aplica a Liga y Suizo, que son los formatos con tabla de posiciones.

### Frontend
Las rondas `Desempate N` se renderizan con los mismos parciales de fixture/ronda, por lo
que el partido aparece con su botón "Cargar resultado". El campeón se muestra por
`campeon_*_id` y por el puesto 1 de la tabla (que coincide, porque el ganador del
desempate acumula el puntaje).

---

## 2. Auditoría de validaciones

Leyenda de prioridad: **A** = Alta, **M** = Media, **B** = Baja.
Estado: ✅ implementada en este cambio · ⏳ recomendada (pendiente).

### Torneos
| Regla | Antes | Riesgo | Estado | Archivo |
|---|---|---|---|---|
| Nombre / formato / organizador obligatorios | ✅ | — | ✅ (se mantiene) | `TorneoService::validar` |
| Fecha inicio obligatoria | ❌ | Datos incompletos | ✅ A | `TorneoService::validar` + `torneo_form.php` |
| Fecha fin obligatoria | ❌ | Datos incompletos | ✅ A | ídem |
| `fecha_inicio <= fecha_fin` | ❌ | Torneo con fechas imposibles | ✅ A | `TorneoService::validar` |
| No modificar torneo **finalizado** | ❌ (permitía editar) | Alterar historial | ✅ A | `TorneoService::editar` |
| No modificar torneo cancelado | ❌ | Idem | ✅ A | `TorneoService::editar` |
| Modalidad equipos exige mínimo de integrantes | parcial | Equipos incompletos | ✅ A | `TorneoService::validar` |
| Mínimo de participantes para generar (≥2) | ✅ | — | ✅ (se mantiene) | `LigaService` / `Eliminacion` / `Suizo` |
| Máximo de participantes | ❌ | Carga abusiva / fixtures gigantes | ✅ M (Liga 64 / Elim 128 / Suizo 256) | generadores |
| Rango de rondas suizas (2–20) | parcial (form) | Config inválida | ✅ M | `TorneoService::validar` |

### Equipos
| Regla | Antes | Riesgo | Estado | Archivo |
|---|---|---|---|---|
| Nombre obligatorio | ✅ | — | ✅ | `EquipoService::validar` |
| Nombre duplicado | ❌ | Ambigüedad | ✅ A | `EquipoService` + `EquipoModel::nombreExiste` |
| Jugador repetido en el equipo | ✅ (UNIQUE + check) | — | ✅ | `EquipoModel` |
| Equipo sin jugadores no se inscribe (≥1) | ❌ | Equipo "fantasma" compitiendo | ✅ A | `InscripcionService::inscribirEquipo` |
| Equipo inactivo no se inscribe | ❌ | Datos inconsistentes | ✅ M | ídem |

### Jugadores (participantes)
| Regla | Antes | Riesgo | Estado | Archivo |
|---|---|---|---|---|
| Nombre obligatorio | ✅ | — | ✅ | `ParticipanteService::validar` |
| Documento duplicado | ❌ | Identidades duplicadas | ✅ A | `ParticipanteService` + `ParticipanteModel::documentoExiste` |
| Email único en cuenta vinculada | ✅ | — | ✅ | `ParticipanteService::sincronizarCuenta` |
| Participante inactivo no se inscribe | ❌ | Datos inconsistentes | ✅ M | `InscripcionService::inscribirParticipante` |

### Partidos / Resultados
| Regla | Antes | Riesgo | Estado | Archivo |
|---|---|---|---|---|
| Puntos no negativos | ✅ | — | ✅ | `ResultadoService` |
| No cargar en partido finalizado/cancelado/bye | ✅ | — | ✅ | `ResultadoService::cargar` |
| No cargar resultados si el torneo no está en curso | ❌ | Resultados en torneos borrador/cancelado/finalizado | ✅ A | `ResultadoService::cargar` |
| Empates según reglas (Liga/Suizo) | ❌ (solo Elim) | Empate cargado en torneo que no los permite | ✅ A | `ResultadoService::cargar` |
| Consistencia ganador/perdedor | ✅ | — | ✅ | `ResultadoService::determinarGanador` |
| Corrección bloqueada si ya avanzó la ronda | ✅ | — | ✅ | `ResultadoService::corregir` |

### Brackets / Llaves (Eliminación Directa)
| Regla | Estado | Nota |
|---|---|---|
| No avanzar equipos inexistentes | ⏳ M | El avance usa el ganador validado del partido; se recomienda validar explícitamente el slot destino. |
| No generar rondas inválidas | ✅ (byes/potencia de 2 controlados) | `EliminacionDirectaService::generarBracket` |
| Integridad ronda↔partido | ✅ (FK + `orden`) | esquema |
| Clasificados duplicados | ⏳ M | Cubierto por el flujo determinista; falta una verificación defensiva. |

### Categorías
No existe una entidad "categorías": `equipos.categoria` y `equipos.disciplina` son texto
libre. **Recomendación (B):** si se requiere catálogo, crear tabla `categorias` con
nombre único y FK desde `equipos`.

---

## 3. Fechas de los partidos

### Datos temporales expuestos
- `fecha_programada` — fecha/hora pactada (editable desde "Programar").
- `fecha_inicio_real` — se setea automáticamente al cargar el primer resultado.
- `fecha_fin_real` — se setea al finalizar el partido.
- `created_at` / `updated_at` — ya existían.

### Backend
- Migración `database/migrations/2026_06_partidos_fechas.sql`: agrega `fecha_inicio_real`
  y `fecha_fin_real` (idempotente) y hace **backfill** de los partidos ya finalizados
  (inicio←`created_at`, fin←`updated_at`).
- `EnfrentamientoModel::updateGanador*` setea `fecha_inicio_real`/`fecha_fin_real`.
- `EnfrentamientoModel::programar()` + `ResultadoService::programar()` +
  `ResultadoController::programar()` + rutas `*/resultados/programar`.

### Frontend
- `app/views/partials/match_list.php`: nueva columna **Fecha** (programada / inicio / fin)
  visible en TODAS las vistas que listan partidos (admin, organizador, pública).
- Botón **Programar** (admin y organizador) con modal de fecha/hora.
- Helper de formato consistente: `View::fechaHora()` y `View::fecha()`.

### Zona horaria
La sesión MySQL ahora fija `time_zone = '-03:00'` (America/Argentina/Buenos_Aires) en
`core/Database.php`, para que `NOW()`/`CURRENT_TIMESTAMP` coincidan con la zona de la app.

---

## 4. Base de datos, transacciones y concurrencia

- **Transacciones (✅ A):** `ResultadoService::cargar()` y `::corregir()` ejecutan
  persistencia + recálculo de tabla + avance/finalización dentro de una transacción
  (`beginTransaction`/`commit`/`rollBack`). Garantiza atomicidad del flujo (incluida la
  generación del desempate).
- **Generación de fixture/bracket/rondas (⏳ M):** se recomienda envolver también
  `generarFixture`, `generarBracket`, `generarPrimeraRonda` y `generarSiguienteRonda`
  en transacciones para evitar estructuras a medias ante un fallo.
- **Concurrencia (⏳ M):** dos organizadores cargando el último resultado a la vez
  podrían, en teoría, generar dos desempates. Recomendación: `SELECT ... FOR UPDATE`
  sobre el torneo al entrar a `intentarFinalizar`, o un lock a nivel de aplicación.
- **Constraints/índices existentes:** FKs completas en `enfrentamientos`, `inscripciones`,
  `tabla_posiciones`; UNIQUE en inscripciones y tabla por torneo. **Recomendado (B):**
  índice único `equipos(nombre)` y `participantes(documento)` si se quiere reforzar a
  nivel de motor las validaciones de unicidad que hoy están en la capa de servicio.

---

## 5. Cómo aplicar las migraciones

Migraciones nuevas (idempotentes, compatibles con datos existentes):

1. `2026_06_partidos_fechas.sql` — columnas `fecha_inicio_real` / `fecha_fin_real` + backfill.
2. `2026_06_fix_fechas_fuera_rango.sql` — corrige partidos con fecha programada fuera del rango (ver §8).

> **Importante:** este proyecto usa su propia base: `flexarena`.

XAMPP (despliegue activo) — usar `cmd` para no corromper la codificación:

```bat
cmd /c "mysql --default-character-set=utf8mb4 flexarena < sgdm\database\migrations\2026_06_partidos_fechas.sql"
```

Docker (alternativo): `docker compose exec -T db mysql -u flexarena_user -p flexarena < ...`.
`schema.sql` ya incluye las columnas nuevas para instalaciones desde cero.

**Estado:** las migraciones ya fueron aplicadas en `flexarena`.

---

## 6. Tests

`sgdm/tests/tiebreak_test.php` — prueba de lógica (sin base de datos) que reproduce la
regla de desempate y verifica los casos del enunciado:

```bash
php sgdm/tests/tiebreak_test.php
```

Cubre: Caso 1 (empate→gana A), Caso 2 (empate, empate→gana B), Caso 3 (4 empates→gana A)
y el caso sin empate previo. **Resultado: todos PASAN.**

`sgdm/tests/match_schedule_test.php` — prueba la validación de fecha de partido dentro del
rango del torneo (multi-día y de una sola jornada, límites inclusive). **Resultado: todos PASAN.**

```bash
php sgdm/tests/match_schedule_test.php
```

**Recomendación (⏳ M):** incorporar PHPUnit + una base de datos de test para cubrir
validaciones de servicio (fechas, duplicados, estados) y un test de integración de la
serie de desempate de punta a punta. Hoy el proyecto no tiene framework de tests.

---

## 7. Validación de fecha de partido dentro del rango del torneo (Parte 5)

### Regla
Todo partido **programado** debe caer en `[fecha_inicio 00:00, fecha_fin 23:59]` del torneo,
inclusive. Torneo de una sola jornada (`inicio == fin`): solo esa fecha, cualquier hora.

### Backend (autoritativo)
- `ResultadoService::assertFechaEnRangoTorneo()` — valida el timestamp contra el rango y
  lanza un mensaje claro (distingue el caso de jornada única). Se invoca desde
  `ResultadoService::programar()`. Si el torneo no tiene fechas (datos heredados), no valida.
- Mensajes:
  - "La fecha del partido debe estar dentro del rango del torneo (dd/mm/aaaa a dd/mm/aaaa)."
  - "Los torneos de una sola jornada solo permiten programar partidos en la fecha del torneo (dd/mm/aaaa)."

### Frontend (UX)
- El input `datetime-local` del modal "Programar" recibe `min`/`max` derivados de las fechas
  del torneo (en admin y organizador). Para jornada única, `min` y `max` caen el mismo día.
- Se muestra el rango permitido como ayuda. La validación de backend es la autoridad final
  (defensa ante manipulación del formulario).

### Generación automática
Brackets, rondas, desempates y byes **no asignan** `fecha_programada` (se programan aparte),
por lo que no pueden producir un partido fuera de rango. Los timestamps reales
(`fecha_inicio_real`/`fecha_fin_real`) son registros operativos (hora real de juego), no
"programación", y no se restringen.

### Integridad de datos existentes
Consulta de auditoría (`EnfrentamientoModel::getProgramadosFueraDeRango`) ejecutada en la base:

| Base | Partidos con fecha | Fuera de rango | Acción |
|---|---|---|---|
| `flexarena` | 2 | **2** (torneos #18 y #21, anteriores a la validación) | corregidos |

**Corrección aplicada:** `2026_06_fix_fechas_fuera_rango.sql` limpió la `fecha_programada`
inválida (queda "sin programar" para reprogramar dentro del rango). Reversible y no
destructiva. Verificación posterior: **0 inconsistencias** en ambas bases.
`seed_demo.php` no asigna `fecha_programada`, por lo que regenerar los datos demo no
reintroduce el problema.

### Tests
Caso de creación/edición dentro y fuera de rango, y torneo de un día: cubiertos por
`match_schedule_test.php` (todos PASAN).

---

## 8. Auditoría de edición de torneos (Parte 6)

### Bug reportado: cambios de configuración que no tenían efecto
**Causa raíz:** en un torneo `en_curso`, `TorneoService::editar()` solo persistía
`nombre/descripcion/publico`. Al cambiar "permitir empates" (o la puntuación) sobre un torneo
ya generado, la edición mostraba "guardado correctamente" pero **el campo no se persistía** →
el sistema seguía con la configuración anterior. No era caché ni lógica duplicada: era una
**persistencia parcial**.

**Corrección:** `editar()` ahora, en estado `en_curso`, persiste y aplica de inmediato:
`permite_empates`, `puntos_victoria/empate/derrota`, `nombre_puntos`, `fecha_inicio/fin`,
además de `nombre/descripcion/publico`. Si la puntuación cambió, **recalcula la tabla**
(`TablaPosicionesService::recalcular`) para que el efecto sea inmediato. Los empates pasan a
aceptarse/bloquearse al instante porque `ResultadoService::cargar` lee `permite_empates`
fresco de la base en cada carga (no hay caché).

**Campos estructurales bloqueados en `en_curso`** (la estructura ya está generada): formato
(`tipo_torneo_id`), modalidad, mínimo de integrantes, cantidad de rondas y configuración de
bye. Se bloquean en backend (no se persisten) y en el formulario (`disabled` + aviso). En
`borrador`/`inscripción` todo sigue siendo editable.

### Matriz de campos editables

| Campo | borrador/inscripción | en_curso | finalizado/cancelado |
|---|---|---|---|
| nombre, descripción, visible | ✅ | ✅ | 🔒 |
| fecha inicio / fin | ✅ (inicio≤fin) | ✅ (inicio≤fin, sin dejar partidos fuera de rango) | 🔒 |
| permitir empates | ✅ | ✅ (efecto inmediato) | 🔒 |
| puntuación V/E/D | ✅ | ✅ (recalcula tabla) | 🔒 |
| unidad de puntos | ✅ | ✅ | 🔒 |
| organizador | ✅ | ✅ | 🔒 |
| formato / modalidad / mín. integrantes / rondas / bye | ✅ | 🔒 (estructura ya generada) | 🔒 |

🔒 torneo finalizado/cancelado: no se edita nada (`editar()` lo rechaza).

### Cambio de organizador (transferencia de propiedad)
**Hallazgo:** `setOrganizador()` reasignaba correctamente en `torneo_organizadores`, pero la
gestión del organizador **no verificaba propiedad**: cualquier organizador podía abrir/operar
cualquier torneo por URL, y el organizador anterior conservaba acceso tras la transferencia.

**Corrección (RBAC a nivel de recurso):**
- `OrganizadorController::requireOwnership()` — admin accede a todo; un organizador solo a sus
  torneos. Aplicado a `gestion`, `generarCompetencia`, `siguienteRondaSuizo`, `inscribir`,
  `desinscribir`.
- `ResultadoController::assertPuedeGestionar()` — mismo criterio en `cargar`, `corregir`,
  `programar` (el admin pasa siempre; el organizador solo sobre sus torneos).
- Resultado: tras transferir A→B, **B obtiene acceso total y A lo pierde**; el torneo aparece
  en los listados de B (`findByOrganizador`) y desaparece de los de A.

### Configuraciones guardadas pero no usadas (informe)
- `torneos.usa_puntos_favor` y `torneos.requiere_desempate_final`: se persisten pero **no**
  se consumen en la lógica actual. Recomendación (B): implementarlos o retirarlos del modelo
  para evitar confusión. (No se tocaron para no alterar datos.)
- Tabla `configuraciones_torneo`: existe en el esquema pero no se usa. Recomendación (B):
  usarla para settings extensibles o documentarla como reservada.

### Recomendaciones / oportunidades de mejora
- **Concurrencia (M):** lock (`SELECT ... FOR UPDATE`) al finalizar/avanzar para evitar dobles
  desempates ante cargas simultáneas.
- **Transacciones (M):** envolver también la generación de fixtures/brackets/rondas.
- **Índices únicos (B):** `equipos(nombre)` y `participantes(documento)` a nivel motor
  (hoy validados en servicio).
- **RBAC (M):** centralizar `requireOwnership` en un trait/middleware para no repetir el
  patrón por controlador.
- **Tests (M):** PHPUnit + BD de prueba para edición de torneos (empates, transferencia de
  organizador) y un test de integración de la serie de desempate punta a punta.
