# Restricciones No Estructurales (RNE) — FlexArena

Reglas de negocio que **no** son garantizadas por el esquema de la base de datos
(no son CHECK/UNIQUE/FK/ENUM), sino aplicadas por la capa de aplicación
(`app/services`, `app/controllers`). Cada regla indica su origen en el código.

> Para las reglas que sí garantiza el motor (UNIQUE, FK, ENUM, CHECK, DEFAULT)
> ver el esquema en `database/schema.sql`.

---

## 1. Autenticación y registro

| # | Restricción | Origen |
|---|-------------|--------|
| 1 | Email y contraseña son obligatorios para iniciar sesión. | `AuthService:21` |
| 2 | No se permite el acceso con email o contraseña incorrectos. | `AuthService:34` |
| 3 | Una cuenta inactiva no puede iniciar sesión. | `AuthService:38` |
| 4 | Al registrarse, el nombre es obligatorio. | `RegistroService:39` |
| 5 | El email de registro debe tener formato válido. | `RegistroService:40` |
| 6 | No se puede registrar un email ya existente. | `RegistroService:42` |
| 7 | Una solicitud de registro solo puede aprobarse/rechazarse si está `pendiente`. | `RegistroService:91,105` |

## 2. Usuarios (gestión admin)

| # | Restricción | Origen |
|---|-------------|--------|
| 8 | Nombre, email y rol son obligatorios al crear/editar usuario. | `UsuarioService:120-124` |
| 9 | El email debe ser válido. | `UsuarioService:122` |
| 10 | La contraseña es obligatoria al crear (no al editar). | `UsuarioService:126` |
| 11 | No se puede repetir email entre usuarios. | `UsuarioService:51,71` |
| 12 | Un usuario no puede eliminar su propia cuenta. | `UsuarioService:94` |
| 13 | Un usuario no puede cambiar el estado de su propia cuenta. | `UsuarioService:106` |

## 3. Participantes y equipos

| # | Restricción | Origen |
|---|-------------|--------|
| 14 | El nombre del participante es obligatorio. | `ParticipanteService:68,131` |
| 15 | No se puede repetir el documento entre participantes. | `ParticipanteService:22,40` |
| 16 | Un email de participante no puede estar en uso por otra cuenta. | `ParticipanteService:97` |
| 17 | El nombre del equipo es obligatorio. | `EquipoService:96` |
| 18 | No se puede repetir el nombre de equipo. | `EquipoService:23,42` |
| 19 | Un participante no puede agregarse dos veces al mismo equipo. | `EquipoService:82` |

## 4. Inscripciones

| # | Restricción | Origen |
|---|-------------|--------|
| 20 | Solo se puede inscribir si el torneo está abierto a inscripciones. | `InscripcionService:24,50` |
| 21 | En torneo por equipos no se inscriben participantes individuales (y viceversa). | `InscripcionService:27,53` |
| 22 | No se puede inscribir un participante/equipo ya inscrito. | `InscripcionService:30,56` |
| 23 | El participante/equipo debe existir. | `InscripcionService:33,60` |
| 24 | No se puede inscribir un participante/equipo inactivo. | `InscripcionService:35,62` |

## 5. Torneos

| # | Restricción | Origen |
|---|-------------|--------|
| 25 | No se puede modificar un torneo finalizado ni cancelado. | `TorneoService:42,45` |
| 26 | Todo torneo debe tener un organizador asignado. | `TorneoService:49,157` |
| 27 | El nombre y el formato (tipo) del torneo son obligatorios. | `TorneoService:58,155-156` |
| 28 | Las fechas de inicio y fin son obligatorias y válidas. | `TorneoService:162-167` |
| 29 | La fecha de inicio no puede ser posterior a la de fin. | `TorneoService:67,170` |
| 30 | En modalidad equipos debe definirse mínimo de integrantes (≥1). | `TorneoService:179` |
| 31 | La modalidad debe ser una válida (individual/equipos). | `TorneoService:176` |
| 32 | Las rondas de Suizo deben estar entre 2 y 20. | `TorneoService:185` |
| 33 | No se puede eliminar un torneo en curso. | `TorneoService:121` |

## 6. Generación de fixtures (límites por formato)

| # | Restricción | Origen |
|---|-------------|--------|
| 34 | Liga: entre 2 y 64 inscritos; torneo en `inscripcion`/`borrador`. | `LigaService:32-38` |
| 35 | Eliminación Directa: entre 2 y 128 inscritos. | `EliminacionDirectaService:34-35` |
| 36 | Sistema Suizo: entre 2 y 256 inscritos. | `SistemaSuizoService:41-42` |
| 37 | No se puede regenerar el fixture si ya existe una ronda. | `SistemaSuizoService:36` |
| 38 | No se genera la siguiente ronda suiza si hay partidos pendientes en la anterior. | `SistemaSuizoService:99` |
| 39 | No se generan más rondas que el total configurado del torneo. | `SistemaSuizoService:95` |

## 7. Resultados

| # | Restricción | Origen |
|---|-------------|--------|
| 40 | Solo se cargan resultados en torneos en curso. | `ResultadoService:49` |
| 41 | Un partido ya con resultado no se recarga (hay que corregir). | `ResultadoService:39` |
| 42 | No se carga resultado para partidos en estado no válido. | `ResultadoService:42` |
| 43 | Los puntos no pueden ser negativos. | `ResultadoService:53,156` |
| 44 | Empates prohibidos en Eliminación Directa. | `ResultadoService:62` |
| 45 | Si el torneo no permite empates, debe haber ganador. | `ResultadoService:66` |
| 46 | Solo se corrigen partidos finalizados. | `ResultadoService:133` |
| 47 | No se corrige un resultado que ya generó una ronda posterior (bracket). | `ResultadoService:143,151` |
| 48 | El motivo de corrección es obligatorio. | `ResultadoService:155` |

## 8. Programación de partidos

| # | Restricción | Origen |
|---|-------------|--------|
| 49 | No se puede programar un bye. | `ResultadoService:217` |
| 50 | No se programa un partido finalizado o cancelado. | `ResultadoService:219` |
| 51 | La fecha programada debe ser válida y caer dentro del rango de fechas del torneo. | `ResultadoService:225,253-258` |

## 9. Flujo de corrección (solicitudes)

| # | Restricción | Origen |
|---|-------------|--------|
| 52 | Solo se solicitan correcciones de partidos finalizados. | `CorreccionService:36` |
| 53 | El partido debe tener resultado cargado. | `CorreccionService:39` |
| 54 | El motivo es obligatorio (mínimo 10 caracteres). | `CorreccionService:42` |
| 55 | Los puntos no pueden ser negativos. | `CorreccionService:45` |
| 56 | No puede haber dos solicitudes pendientes para el mismo partido. | `CorreccionService:48` |
| 57 | Una solicitud ya resuelta no puede volver a resolverse. | `CorreccionService:71,94` |
| 58 | Al rechazar, el motivo de rechazo es obligatorio. | `CorreccionService:97` |

## 10. Autorización por rol y módulo

| # | Restricción | Origen |
|---|-------------|--------|
| 59 | El administrador tiene acceso total a todos los módulos y acciones (bypass). | `PermisoService:15` |
| 60 | Un usuario sin rol no tiene ningún permiso. | `PermisoService:12` |
| 61 | Otros roles solo operan un módulo si una fila en `permisos` lo habilita; por defecto se niega. | `PermisoService:32` |
| 62 | Los permisos se evalúan por acción: ver, crear, editar, eliminar. | `PermisoService:38-41` |

## 11. Propiedad del torneo (ownership)

| # | Restricción | Origen |
|---|-------------|--------|
| 63 | Un organizador solo puede gestionar sus propios torneos; el admin, todos. | `OrganizadorController:21-26` |
| 64 | Tras transferir un torneo a otro organizador, el anterior pierde el acceso. | `OrganizadorController:17` |
| 65 | Carga/corrección/programación de resultados exige ser dueño del torneo (o admin). | `ResultadoController:16-20` |
| 66 | El listado/dashboard de un organizador solo muestran sus torneos (el admin ve todos). | `OrganizadorController:32-48` |

## 12. Restricciones de acceso por rol (controladores)

| # | Restricción | Origen |
|---|-------------|--------|
| 67 | El perfil de participante solo es accesible para roles `participante` o `administrador`. | `ParticipanteController:8,31,47,82` |
| 68 | Las acciones de sesión (logout/perfil) requieren estar logueado. | `AuthController:77` |
| 69 | Un usuario ya logueado no puede volver a ver login/registro (se redirige). | `AuthController:15,25` |

## 13. Trazabilidad / auditoría (transversal)

| # | Restricción | Origen |
|---|-------------|--------|
| 70 | Toda acción sensible se registra asociada al usuario que la ejecuta (`Auth::id()`). | `AdminController:122,135`; `CorreccionController:25,49,62`; `ResultadoController:38,59,82` |
| 71 | El creador del torneo queda registrado en `creado_por` al crearlo. | `TorneoController:74` |

---

**Total: 71 RNE.** Documento derivado del análisis de `app/services` y `app/controllers`.
Las referencias de línea corresponden al estado del código al 2026-06-17.
