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
| 59 | El administrador tiene acceso total a todos los módulos y acciones (bypass), y no se le guardan filas en `permisos`. | `PermisoService:81` |
| 60 | Un visitante sin sesión no tiene ningún permiso. | `PermisoService:80` |
| 61 | Otros roles solo operan un módulo si una fila en `permisos` lo habilita; por defecto se niega. | `PermisoService:88` |
| 62 | Los permisos se evalúan por acción: ver, crear, editar, eliminar. Una acción fuera de esas cuatro es un error de programación y lanza excepción en vez de negar en silencio. | `PermisoService:74`, `PermisoService:90` |
| 63 | La matriz sembrada reproduce la sección 5 de la letra: el organizador configura torneos asignados y carga resultados, pero no gestiona el padrón de participantes ni de equipos. | `database/seed.sql` → bloque PERMISOS; verificado en `tests/permisos_test.php` |
| 64 | Cambiar la matriz de permisos queda registrado en auditoría con el antes y el después. | `PermisoService:161` |

> Hasta septiembre de 2026 estas restricciones citaban un `PermisoService` que
> **no existía**: la tabla `permisos` estaba en el esquema, tenía clave foránea y
> índice único, y ninguna consulta la leía. El control era solo por rol. El
> servicio existe desde entonces y las guardas lo llaman; ver
> `docs/documentacion_tecnica.md` → «Las tres compuertas de autorización».

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

## 14. Módulos habilitados

Reglas que dan efecto funcional al toggle de `modulos`. Se citan por método porque
son puntos de entrada estables, no por número de línea.

| # | Restricción | Origen |
|---|-------------|--------|
| 72 | Generar el fixture de una Liga exige el módulo `liga` activo. | `LigaService::generarFixture()` |
| 73 | Generar el bracket exige el módulo `eliminacion_directa` activo. | `EliminacionDirectaService::generarBracket()` |
| 74 | Generar la primera ronda suiza exige el módulo `suizo` activo. | `SistemaSuizoService::generarPrimeraRonda()` |
| 75 | Crear un torneo —o cambiarle el formato— exige que el módulo de ese formato esté activo. | `TorneoService::crear()`, `TorneoService::editar()` |
| 76 | El formulario de torneo solo ofrece formatos con módulo activo; al editar conserva el formato actual del torneo. | `TipoTorneoModel::findDisponibles()` |
| 77 | Un torneo ya en curso NO se interrumpe al deshabilitar su módulo: rondas siguientes, carga de resultados y definición de campeón siguen funcionando. | `SistemaSuizoService::generarSiguienteRonda()` (sin guarda, deliberado) |
| 78 | Un slug de módulo inexistente se trata como deshabilitado (deniega por defecto). | `ModuloActivoTrait::assertModuloActivo()` |
| 79 | Todo cambio de estado de un módulo queda auditado con estado anterior y nuevo. | `ModuloService::toggle()` |

## 15. Estado de las rondas

`rondas.estado` dejó de ser metadata: lo mantiene `RondaService` y lo consulta
`ResultadoService`. El ENUM quedó en `pendiente | en_curso | cerrada`
(`bloqueada` se eliminó: significaba lo mismo que `cerrada`).

| # | Restricción | Origen |
|---|-------------|--------|
| 80 | Una ronda a la que le faltan cruces por definir queda en `pendiente` (caso típico: la ronda siguiente de un bracket mientras avanzan los ganadores). | `RondaService::estadoSegunPartidos()` |
| 81 | Una ronda con todos sus cruces definidos y partidos por resolver queda en `en_curso`. | `RondaService::estadoSegunPartidos()` |
| 82 | Una ronda cuyos partidos terminaron todos (finalizado/bye/cancelado) pasa a `cerrada` sola. | `RondaService::sincronizarTorneo()` |
| 83 | La sincronización automática solo avanza el estado, nunca lo retrocede: un cierre manual no se deshace al cargar otro resultado. | `RondaService::sincronizarTorneo()` |
| 84 | Una ronda `cerrada` no admite carga de resultados; reabrirla la habilita de nuevo. | `RondaService::assertAbiertaParaCarga()`, `ResultadoService::cargar()` |
| 85 | La corrección de resultados NO se bloquea por ronda cerrada: si no, un error en una ronda terminada quedaría sin arreglo. | `ResultadoService::corregir()` |
| 86 | No se reabre una ronda cuyos partidos terminaron todos: no habría nada que cargar. | `RondaService::reabrir()` |
| 87 | Cerrar o reabrir exige ser dueño del torneo (o admin), token CSRF válido, y queda auditado con estado anterior y nuevo. | `TorneoController::cerrarRonda()/reabrirRonda()`, `RondaService` |

## 16. Datos del evento y cierre de inscripción

`configuraciones_torneo` dejó de ser una tabla sin lector: la maneja
`ConfiguracionTorneoService` y una de sus claves cambia el comportamiento del
sistema.

| # | Restricción | Origen |
|---|-------------|--------|
| 88 | Solo se guardan las claves declaradas en el catálogo; cualquier otra que llegue del formulario se ignora. | `ConfiguracionTorneoService::CLAVES`, `::guardar()` |
| 89 | Una clave con valor vacío se borra en vez de guardarse en blanco: ausente y vacía son lo mismo. | `ConfiguracionTorneoService::guardar()` |
| 90 | `contacto` tiene que ser un correo electrónico válido. | `ConfiguracionTorneoService::validarValor()` |
| 91 | `cierre_inscripcion` tiene que ser una fecha real en formato AAAA-MM-DD (se descarta, por ejemplo, un 31 de febrero). | `ConfiguracionTorneoService::validarValor()` |
| 92 | `cierre_inscripcion` no puede ser posterior a `torneos.fecha_inicio`. | `ConfiguracionTorneoService::guardar()` |
| 93 | Pasado el cierre de inscripción no se admiten nuevas inscripciones, ni de participantes ni de equipos. | `InscripcionService::assertPlazoAbierto()` |
| 94 | El plazo incluye su propio día: un torneo que cierra el 20 acepta inscripciones durante todo el 20. | `ConfiguracionTorneoService::inscripcionVencida()` |
| 95 | Sin fecha de cierre no hay plazo: la clave es opcional y su ausencia no cierra un torneo. | `ConfiguracionTorneoService::inscripcionVencida()` |
| 96 | El plazo solo rige en estado `inscripcion`; en `borrador` el torneo se está armando y no es público. | `InscripcionService::assertPlazoAbierto()` |
| 97 | Cerrar el plazo impide anotarse, no retirarse: `desinscribir()` sigue funcionando. | `InscripcionService::desinscribir()` |
| 98 | Cambiar los datos del evento queda auditado con el antes y el después; guardar sin cambios no deja registro. | `ConfiguracionTorneoService::guardar()` |

## 17. Criterios de desempate de la tabla

`torneos.usa_puntos_favor` dejó de ser una columna que se escribía y nadie leía.
`torneos.requiere_desempate_final` se eliminó: ver la migración
`2026_09_banderas_puntuacion.sql`.

| # | Restricción | Origen |
|---|-------------|--------|
| 99 | La tabla ordena por puntos y, si el torneo usa puntos a favor, después por diferencia y por puntos a favor; luego por partidos ganados, buchholz e id. | `TablaPosicionesService::comparar()` |
| 100 | Con `usa_puntos_favor = 0` la diferencia y los puntos a favor se siguen calculando y mostrando, pero no ordenan. | `TablaPosicionesService::comparar()` |
| 101 | La detección de empate en la cima usa exactamente los mismos criterios que el orden, sin el id: el campeón no puede decidirse por una métrica que la tabla no mira. | `DesempateTrait::hayEmpateEnCima()` |
| 102 | Un torneo creado sin el dato queda con el criterio activo, que es el `DEFAULT 1` de la columna. | `TorneoService::prepararDatos()` |
| 103 | El criterio se puede cambiar con el torneo en curso, y la tabla se recalcula. | `TorneoService::editar()` |

## 18. Destinos de redirección

Varios controladores devuelven al usuario a la pantalla de la que vino usando un
valor del pedido (`Referer`, o el campo `return` del formulario). Ver
`docs/owasp.md` → A01.

| # | Restricción | Origen |
|---|-------------|--------|
| 104 | Solo se redirige a rutas del propio sitio; cualquier otro destino cae en el de reserva. | `Url::interna()` |
| 105 | Se rechazan los destinos de protocolo relativo (`//host`, `/\host`), que el navegador resuelve contra otro dominio aunque empiecen con barra. | `Url::rutaInterna()` |
| 106 | Se rechazan los esquemas que no sean `http`/`https`, y los destinos con caracteres de control. | `Url::rutaInterna()` |
| 107 | Un host que solo empieza igual que el propio (`flexarena.local.sitio-falso.com`) no cuenta como propio. | `Url::esHostPropio()` |
| 108 | Una URL absoluta del propio sitio se reduce a su ruta: no se arrastran esquema ni puerto, que pueden no coincidir con los del visitante. | `Url::rutaInterna()` |
| 109 | `redirect()` aplica el filtro por su cuenta: un controlador que olvide filtrar manda a la portada, no afuera. | `BaseController::redirect()` |

---

**Total: 109 RNE.** Documento derivado del análisis de `app/services` y `app/controllers`.
Las referencias de línea corresponden al estado del código al 2026-06-17;
las de las secciones 14 y 15 se citan por método.
