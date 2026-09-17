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
| 1 | Email y contraseña son obligatorios para iniciar sesión. | `AuthService::login()` |
| 2 | No se permite el acceso con email o contraseña incorrectos. | `AuthService::login()` |
| 3 | Una cuenta inactiva no puede iniciar sesión. | `AuthService::login()` |
| 4 | Al registrarse, el nombre es obligatorio. | `RegistroService::registrar()` |
| 5 | El email de registro debe tener formato válido. | `RegistroService::registrar()` |
| 6 | No se puede registrar un email ya existente. | `RegistroService::registrar()` |
| 7 | Una solicitud de registro solo puede aprobarse/rechazarse si está `pendiente`. | `RegistroService::aprobar()` y `::rechazar()` |

## 2. Usuarios (gestión admin)

| # | Restricción | Origen |
|---|-------------|--------|
| 8 | Nombre, email y rol son obligatorios al crear/editar usuario. | `UsuarioService::validar()` |
| 9 | El email debe ser válido. | `UsuarioService::validar()` |
| 10 | La contraseña es obligatoria al crear (no al editar). | `UsuarioService::validar()` |
| 11 | No se puede repetir email entre usuarios. | `UsuarioService::crear()` y `::editar()` |
| 12 | Un usuario no puede desactivar su propia cuenta. El botón «Eliminar» del panel no borra nada: `AdminController::usuarioEliminar()` llama a `toggleActivo()`, porque una cuenta que creó torneos no se puede borrar sin perder su historial. | `UsuarioService::toggleActivo()` |
| 13 | Un usuario no puede cambiar el estado de su propia cuenta. | `UsuarioService::toggleActivo()` |

## 3. Participantes y equipos

| # | Restricción | Origen |
|---|-------------|--------|
| 14 | El nombre del participante es obligatorio. | `ParticipanteService::validar()` |
| 15 | No se puede repetir el documento entre participantes. | `ParticipanteService::editar()` |
| 16 | Un email de participante no puede estar en uso por otra cuenta. | `ParticipanteService::sincronizarCuenta()` |
| 17 | El nombre del equipo es obligatorio. | `EquipoService::validar()` |
| 18 | No se puede repetir el nombre de equipo. | `EquipoService::crear()` y `::editar()` |
| 19 | Un participante no puede agregarse dos veces al mismo equipo. | `EquipoService::agregarParticipante()` |

## 4. Inscripciones

| # | Restricción | Origen |
|---|-------------|--------|
| 20 | Solo se puede inscribir si el torneo está abierto a inscripciones. | `InscripcionService::inscribirParticipante()` y `::inscribirEquipo()` |
| 21 | En torneo por equipos no se inscriben participantes individuales (y viceversa). | `InscripcionService::inscribirParticipante()` y `::inscribirEquipo()` |
| 22 | No se puede inscribir un participante/equipo ya inscrito. | `InscripcionService::inscribirParticipante()` y `::inscribirEquipo()` |
| 23 | El participante/equipo debe existir. | `InscripcionService::inscribirParticipante()` y `::inscribirEquipo()` |
| 24 | No se puede inscribir un participante/equipo inactivo. | `InscripcionService::inscribirParticipante()` y `::inscribirEquipo()` |

## 5. Torneos

| # | Restricción | Origen |
|---|-------------|--------|
| 25 | No se puede modificar un torneo finalizado ni cancelado. | `TorneoService::editar()` |
| 26 | Todo torneo debe tener un organizador asignado. | `TorneoService::editar()` y `::validar()` |
| 27 | El nombre y el formato (tipo) del torneo son obligatorios. | `TorneoService::validar()` |
| 28 | Las fechas de inicio y fin son obligatorias y válidas. | `TorneoService::validar()` |
| 29 | La fecha de inicio no puede ser posterior a la de fin. | `TorneoService::validar()` |
| 30 | En modalidad equipos debe definirse mínimo de integrantes (≥1). | `TorneoService::validar()` |
| 31 | La modalidad debe ser una válida (individual/equipos). | `TorneoService::validar()` |
| 32 | Las rondas de Suizo deben estar entre 2 y 20. | `TorneoService::validar()` |
| 33 | No se puede eliminar un torneo en curso. | `TorneoService::eliminar()` |

## 6. Generación de fixtures (límites por formato)

| # | Restricción | Origen |
|---|-------------|--------|
| 34 | Liga: entre 2 y 64 inscritos; torneo en `inscripcion`/`borrador`. | `LigaService::generarFixture()` |
| 35 | Eliminación Directa: entre 2 y 128 inscritos. | `EliminacionDirectaService::generarBracket()` |
| 36 | Sistema Suizo: entre 2 y 256 inscritos. | `SistemaSuizoService::generarPrimeraRonda()` |
| 37 | No se puede regenerar el fixture si ya existe una ronda. | `SistemaSuizoService::generarPrimeraRonda()` |
| 38 | No se genera la siguiente ronda suiza si hay partidos pendientes en la anterior. | `SistemaSuizoService::generarSiguienteRonda()` |
| 39 | No se generan más rondas que el total configurado del torneo. | `SistemaSuizoService::generarSiguienteRonda()` |

## 7. Resultados

| # | Restricción | Origen |
|---|-------------|--------|
| 40 | Solo se cargan resultados en torneos en curso. | `ResultadoService::cargar()` |
| 41 | Un partido ya con resultado no se recarga (hay que corregir). | `ResultadoService::cargar()` |
| 42 | No se carga resultado para partidos en estado no válido. | `ResultadoService::cargar()` |
| 43 | Los puntos no pueden ser negativos. | `ResultadoService::cargar()` y `::corregir()` |
| 44 | Empates prohibidos en Eliminación Directa. | `ResultadoService::cargar()` |
| 45 | Si el torneo no permite empates, debe haber ganador. | `ResultadoService::cargar()` |
| 46 | Solo se corrigen partidos finalizados. | `ResultadoService::corregir()` |
| 47 | No se corrige un resultado que ya generó una ronda posterior, ni en Eliminación Directa ni en Suizo. Ver la sección 20. | `ResultadoService::motivoBloqueoCorreccion()` |
| 48 | El motivo de corrección es obligatorio. | `ResultadoService::corregir()` |

## 8. Programación de partidos

| # | Restricción | Origen |
|---|-------------|--------|
| 49 | No se puede programar un bye. | `ResultadoService::programar()` |
| 50 | No se programa un partido finalizado o cancelado. | `ResultadoService::programar()` |
| 51 | La fecha programada debe ser válida y caer dentro del rango de fechas del torneo. | `ResultadoService::programar()` |

## 9. Flujo de corrección (solicitudes)

| # | Restricción | Origen |
|---|-------------|--------|
| 52 | Solo se solicitan correcciones de partidos finalizados. | `CorreccionService::solicitar()` |
| 53 | El partido debe tener resultado cargado. | `CorreccionService::solicitar()` |
| 54 | El motivo es obligatorio (mínimo 10 caracteres). | `CorreccionService::solicitar()` |
| 55 | Los puntos no pueden ser negativos. | `CorreccionService::solicitar()` |
| 56 | No puede haber dos solicitudes pendientes para el mismo partido. | `CorreccionService::solicitar()` |
| 57 | Una solicitud ya resuelta no puede volver a resolverse. | `CorreccionService::aprobar()` y `::rechazar()` |
| 58 | Al rechazar, el motivo de rechazo es obligatorio. | `CorreccionService::rechazar()` |

## 10. Autorización por rol y módulo

| # | Restricción | Origen |
|---|-------------|--------|
| 59 | El administrador tiene acceso total a todos los módulos y acciones (bypass), y no se le guardan filas en `permisos`. | `PermisoService::puede()` |
| 60 | Un visitante sin sesión no tiene ningún permiso. | `PermisoService::puede()` |
| 61 | Otros roles solo operan un módulo si una fila en `permisos` lo habilita; por defecto se niega. | `PermisoService::puede()` |
| 62 | Los permisos se evalúan por acción: ver, crear, editar, eliminar. Una acción fuera de esas cuatro es un error de programación y lanza excepción en vez de negar en silencio. | `PermisoService::puede()` |
| 63 | La matriz sembrada reproduce la sección 5 de la letra: el organizador configura torneos asignados y carga resultados, pero no gestiona el padrón de participantes ni de equipos. | `database/seed.sql` → bloque PERMISOS; verificado en `tests/permisos_test.php` |
| 64 | Cambiar la matriz de permisos queda registrado en auditoría con el antes y el después. | `PermisoService::guardarMatriz()` |

> Hasta septiembre de 2026 estas restricciones citaban un `PermisoService` que
> **no existía**: la tabla `permisos` estaba en el esquema, tenía clave foránea y
> índice único, y ninguna consulta la leía. El control era solo por rol. El
> servicio existe desde entonces y las guardas lo llaman; ver
> `docs/documentacion_tecnica.md` → «Las tres compuertas de autorización».

## 11. Propiedad del torneo (ownership)

| # | Restricción | Origen |
|---|-------------|--------|
| 63 | Un organizador solo puede gestionar sus propios torneos; el admin, todos. | `BaseController::requireTorneoOwnership()` |
| 64 | Tras transferir un torneo a otro organizador, el anterior pierde el acceso. | `PermisoService::guardarMatriz()` |
| 65 | Carga/corrección/programación de resultados exige ser dueño del torneo (o admin). | `ResultadoController::assertPuedeGestionar()` |
| 66 | El listado/dashboard de un organizador solo muestran sus torneos (el admin ve todos). | `OrganizadorController::dashboard()` y `::misTorneos()` |

## 12. Restricciones de acceso por rol (controladores)

| # | Restricción | Origen |
|---|-------------|--------|
| 67 | El perfil de participante solo es accesible para roles `participante` o `administrador`. | `ParticipanteController`, vía `Auth::requireRole()` en cada acción |
| 68 | Las acciones de sesión (logout/perfil) requieren estar logueado. | `AuthController::logout()` |
| 69 | Un usuario ya logueado no puede volver a ver login/registro (se redirige). | `AuthController::loginForm()` y `::registroForm()` |

## 13. Trazabilidad / auditoría (transversal)

| # | Restricción | Origen |
|---|-------------|--------|
| 70 | Toda acción sensible se registra asociada al usuario que la ejecuta (`Auth::id()`). | `AuditoriaService::log()`, llamado desde cada servicio que escribe |
| 71 | El creador del torneo queda registrado en `creado_por` al crearlo. | `TorneoController::guardar()` → `TorneoService::crear()` |

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

## 19. Inscribir desde el panel de gestión

El admin y el organizador inscriben con el mismo panel
(`app/views/partials/inscripciones_panel.php`), que dibuja el selector como un
combobox armado por JavaScript.

| # | Restricción | Origen |
|---|-------------|--------|
| 110 | La inscripción no tiene validaciones distintas por rol: las dos rutas pasan por las mismas tres compuertas y por el mismo servicio. Lo único que cambia es el prefijo de las rutas y a dónde vuelve el redirect. | `TorneoController::inscribir()` / `OrganizadorController::inscribir()` |
| 111 | Toda página que dibuje un combobox tiene que cargar `combobox.js`. Sin ese script el `<input>` visible no tiene `name` y el hidden con el id viaja vacío: el formulario se manda en blanco y el usuario no puede hacer nada al respecto. | `TorneoController::gestion()` / `OrganizadorController::gestion()` |
| 112 | El éxito se anuncia dentro de la rama que efectivamente inscribió. Si no llegó ningún id no se inscribió a nadie, y se avisa con un error. | `OrganizadorController::inscribir()` |

## 20. La regla de la ronda posterior

Un resultado deja de ser corregible cuando el torneo ya construyó algo encima:
en Eliminación Directa el ganador se ubicó en la ronda siguiente, y en Suizo el
emparejamiento de la ronda siguiente se armó con esos puntajes. La Liga no tiene
el problema: el fixture está completo desde el arranque y la tabla se recalcula
entera.

| # | Restricción | Origen |
|---|-------------|--------|
| 113 | La regla vive en un solo lugar y la consultan los dos caminos: el que aplica la corrección y el que la pide. Si solo la mirara el primero, se registrarían solicitudes imposibles de aprobar. | `ResultadoService::motivoBloqueoCorreccion()` |
| 114 | El organizador se entera al pedir la corrección, no cuando un admin intenta aprobarla. Una solicitud bloqueada no llega a registrarse. | `CorreccionService::solicitar()` |
| 115 | Si el torneo avanza mientras la solicitud espera, `aprobar()` falla y la solicitud queda en «pendiente»: el sistema no la resuelve por el admin, que la rechaza con su motivo. | `CorreccionService::aprobar()` |

---

**Total: 115 RNE.** Documento derivado del análisis de `app/services` y `app/controllers`.

Todas las restricciones se citan por **nombre de método**, no por número de línea.
Hasta septiembre de 2026 se citaban por línea y las referencias se habían podrido:
apuntaban a constructores, a métodos que no eran, o a líneas en blanco. Un número
de línea envejece con cada edición del archivo; el nombre de un método, no.
