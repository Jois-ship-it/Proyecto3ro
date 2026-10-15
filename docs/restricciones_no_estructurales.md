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
| 15 | No se puede repetir el documento entre participantes. (Además la garantiza el índice único `uq_participantes_documento`; el servicio avisa antes, con un mensaje claro.) | `ParticipanteService::editar()` y `RegistroService::registrar()` |
| 16 | Un email de participante no puede estar en uso por otra cuenta. | `ParticipanteService::sincronizarCuenta()` |
| 17 | El nombre del equipo es obligatorio. | `EquipoService::validar()` |
| 18 | No se puede repetir el nombre de equipo. (Además la garantiza el índice único `uq_equipos_nombre`; el servicio avisa antes, con un mensaje claro.) | `EquipoService::crear()` y `::editar()` |
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
| 48 | El motivo de una corrección es obligatorio y de al menos 10 caracteres (sin contar espacios de los extremos), venga la corrección de donde venga: la directa del administrador y la solicitud del organizador usan la misma regla. Antes la directa solo miraba que no estuviera vacío, aunque el formulario pedía 10. | `ResultadoService::corregir()`, `CorreccionService::solicitar()` |

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

## 21. Operaciones de varias filas: todo o nada

Cuando una operación escribe en más de una tabla, `Database::transaccion()` la envuelve: o queda
completa o no queda nada. Cubierto por `tests/transacciones_test.php`.

| # | Restricción | Origen |
|---|-------------|--------|
| 116 | Generar el fixture de Liga, el bracket de Eliminación Directa o una ronda de Suizo es todo o nada: si falla a mitad no quedan rondas sin partidos ni un torneo que arrancó sin estructura. | `LigaService::generarFixture()`, `EliminacionDirectaService::generarBracket()`, `SistemaSuizoService::generarPrimeraRonda()` y `::generarSiguienteRonda()` |
| 117 | Cargar o corregir un resultado incluye todo lo que provoca —recalcular la tabla, avanzar al ganador o crear la ronda siguiente, cerrar el torneo— y su registro de auditoría. | `ResultadoService::cargar()` y `::corregir()` |
| 118 | Aprobar una corrección aplica el cambio y marca la solicitud como aprobada en una sola operación: no puede quedar el resultado corregido con la solicitud todavía «pendiente». | `CorreccionService::aprobar()` |
| 119 | Registrar una cuenta crea la cuenta y su perfil juntos; aprobar o rechazar cambia el estado de las dos a la vez. | `RegistroService::registrar()`, `::aprobar()` y `::rechazar()` |
| 120 | Crear o editar un torneo guarda los datos, el organizador y la auditoría juntos: un cambio de organizador que falla a la mitad no deja al torneo sin organizador. | `TorneoService::crear()` y `::editar()` |
| 121 | La matriz de permisos y los datos del evento de un torneo se guardan enteros o no se guardan. | `PermisoService::guardarMatriz()`, `ConfiguracionTorneoService::guardar()` |
| 122 | Recalcular la tabla de posiciones (se borra y se vuelve a escribir) no deja una tabla a medias. | `TablaPosicionesService::recalcular()` |
| 123 | Anonimizar a una persona (Habeas Data) deja perfil y cuenta anonimizados juntos, y la foto se borra recién cuando la base quedó bien: un archivo borrado no se recupera. | `ParticipanteService::anonimizarDatos()` |
| 124 | Una operación transaccional se puede anidar en otra, pero su excepción tiene que llegar hasta la más externa: atraparla en el medio y seguir confirmaría lo que alcanzó a escribir. | `Database::transaccion()` |
| 125 | Editar el perfil de un participante cambia su cuenta de acceso (nombre, email) y su perfil juntos: no pueden quedar con datos distintos. | `ParticipanteService::editar()` y `::actualizarPerfil()` |
| 126 | Bloquear o desbloquear una cuenta marca la cuenta y su perfil a la vez: no puede quedar una bloqueada y el otro activo. | `UsuarioModel::lockAccount()` y `::unlockAccount()` |
| 127 | Cambiar el organizador de un torneo (borrar el anterior y poner el nuevo) es una sola operación: el torneo nunca queda sin organizador. | `TorneoModel::setOrganizador()` |

## 22. Errores: qué ve la persona y qué queda registrado

Cubierto por `tests/manejador_errores_test.php` y `tests/validator_test.php`.

| # | Restricción | Origen |
|---|-------------|--------|
| 128 | Un error de negocio (`ErrorNegocio`) muestra su mensaje a la persona; cualquier otro error muestra un aviso genérico con un código de referencia y el detalle queda en el registro. | `BaseController::mensajeDe()`, `ManejadorErrores::describir()` |
| 129 | Todo error queda registrado con su código, clase, mensaje, archivo, pedido (sin query string) y traza sin argumentos; los errores nunca se dejan de registrar, solo se ocultan de la pantalla. | `ManejadorErrores::registrarEnLog()` |
| 130 | Una excepción sin atrapar o un error fatal nunca deja una página en blanco: página del sitio, página mínima si el layout no se puede dibujar, o JSON si el pedido es de la API. | `ManejadorErrores::responder()` y `::alApagar()` |
| 131 | Un POST solo puede cambiar su método por PUT, PATCH o DELETE (`_method`); no puede hacerse pasar por GET. | `Router::metodoEfectivo()` |
| 132 | Ningún texto puede superar el largo de su columna: se avisa qué campo corregir antes de tocar la base. | `Validator::assertLargos()` |

## 23. Sesión, bloqueos y administradores

Cubierto por `tests/sesion_test.php` y `tests/login_limites_test.php`.

| # | Restricción | Origen |
|---|-------------|--------|
| 133 | La sesión se compara con la cuenta de la base en cada pedido: el rol y los datos los decide la base, y una cuenta que no está activa pierde la sesión con un aviso. | `Auth::isLoggedIn()`, `Auth::motivoDeExpulsion()` |
| 134 | Una sesión iniciada vence a los 60 minutos sin actividad y cambia de identificador cada 15 minutos. | `Session::evaluarVida()` |
| 135 | Tras `login_max_intentos` fallos seguidos una cuenta activa se bloquea durante `login_minutos_bloqueo`; vencido el bloqueo, se desbloquea sola en el siguiente intento y el conteo empieza de cero. Con 0 minutos dura hasta que un administrador la desbloquee. | `AuthService::login()` |
| 136 | Una IP con `login_max_intentos_ip` fallos dentro de `login_ventana_minutos` es rechazada antes de mirar ninguna cuenta, aunque use la clave correcta; mientras está rechazada no se anotan más intentos. | `AuthService::login()`, `IntentoLoginModel::contarPorIp()` |
| 137 | Los límites del inicio de sesión salen de `configuracion_sistema`; un valor ausente o inválido se reemplaza por el de fábrica. | `ConfiguracionSistemaService::getInt()` |
| 138 | Solo se bloquea una cuenta activa y solo se desbloquea una bloqueada: fallar la clave no bloquea una cuenta pendiente o rechazada, y desbloquear no activa una cuenta que no estaba bloqueada. | `UsuarioModel::lockAccount()` y `::unlockAccount()`, `UsuarioService::desbloquear()` |
| 139 | No se puede bloquear, desactivar ni quitar el rol de administrador al único administrador activo; y nadie puede bloquearse, desactivarse ni quitarse el rol de administrador a sí mismo. | `UsuarioService::bloquear()`, `::toggleActivo()` y `::editar()` |
| 140 | El bloqueo automático del único administrador activo nunca es permanente, aunque el ajuste diga 0 minutos. | `AuthService::login()` |
| 141 | Un usuario no se crea ni se edita con un rol que no existe. | `UsuarioService::crear()` |
| 211 | Cerrar sesión deja una sesión nueva y vacía (otro identificador) y el aviso «Sesión cerrada correctamente» viaja en ella. Antes se destruía la sesión y el aviso se escribía en la ya destruida: nadie lo veía, y además se mandaban dos cookies en una misma respuesta. La cookie de antes no sirve para volver a entrar. | `Auth::logout()`, `Session::reiniciar()`, `AuthController::logout()` |

## 24. Autorización: quién puede qué

Cubierto por `tests/autorizacion_test.php` y `tests/administradores_test.php`.

| # | Restricción | Origen |
|---|-------------|--------|
| 142 | Aplicar una corrección de resultado directamente es solo del administrador, aunque el organizador tenga el permiso `resultados / editar`: el organizador la solicita y un administrador la aprueba. | `ResultadoController::corregir()` y `ResultadoService::corregir()` |
| 143 | Un organizador solo solicita correcciones de partidos de sus torneos; un administrador, de cualquiera. | `CorreccionController::solicitar()` y `CorreccionService::solicitar()` |
| 144 | Una compuerta de acceso que falla (rol, permiso de módulo, token CSRF, sesión) lanza una excepción y no corta el pedido con `exit`; ningún `catch` de `RuntimeException` la convierte en un aviso. | `Auth::requireRole()`, `Auth::requireLogin()`, `Csrf::checkOrFail()`, `BaseController::requirePermiso()` |
| 145 | Todo acceso denegado queda anotado en el registro (qué pedido y por qué), sin traza; que falte la sesión no se anota. | `ManejadorErrores::registrarAcceso()` |
| 146 | Sin sesión, una página redirige a `/login` con un aviso y la API responde 401; sin permiso, la página 403 muestra el motivo y la API responde 403. | `ManejadorErrores::responder()` |
| 147 | Todo POST exige el token CSRF; sin él no se ejecuta la acción. | `Csrf::checkOrFail()`, `BaseController::checkCsrf()` |
| 148 | Los apartados Organizadores y Administradores solo gestionan cuentas de su rol: el rol lo fija el apartado y no el formulario, y una cuenta de otro rol no se edita ni se activa o desactiva desde ahí. | `AdminController::guardarUsuario()` y `::activarODesactivarUsuario()` |
| 149 | La lista de cuentas no ofrece desactivar ni bloquear la cuenta propia ni la del único administrador activo; una cuenta bloqueada solo se puede desbloquear. | `UsuarioService::unicoAdministradorActivoId()`, `UsuarioService::toggleActivo()` |

## 25. Listados, búsqueda y reportes

Cubierto por `tests/paginador_test.php`, `tests/filtros_test.php`, `tests/csv_test.php`,
`tests/listados_test.php`, `tests/reportes_test.php` y `tests/campeon_empates_test.php`.

| # | Restricción | Origen |
|---|-------------|--------|
| 150 | Toda lista con búsqueda filtra, ordena y recorta la página en la propia consulta (WHERE, LIMIT/OFFSET y un COUNT aparte); no se trae la tabla entera para filtrar en PHP. | `TorneoModel::buscarPublicos()`, `TorneoModel::buscarParaPanel()`, `ParticipanteModel::buscarConEquipo()`, `EquipoModel::buscarConConteo()`, `UsuarioModel::buscarPorRol()`, `AuditoriaModel::buscar()` |
| 151 | Un valor de filtro que no corresponde (una opción inventada, una fecha imposible, un arreglo en lugar de un texto) se descarta: la lista se ve como si ese filtro no estuviera, nunca da error. | `Filtros::desdeQuery()` |
| 152 | El texto de búsqueda se acota a 80 caracteres, sin caracteres de control, y los comodines del LIKE (`%`, `_`) se buscan como caracteres. La búsqueda ignora mayúsculas y tildes. | `Filtros::texto()`, `Filtros::patronLike()`, `BaseModel::agregarBusqueda()` |
| 153 | Una página pedida fuera de rango se corrige (la última, o la primera si no es un número válido) y las filas por página tienen un tope de 100. | `Paginador::de()` |
| 154 | El orden de cada lista es estable (desempata por identificador): las páginas, juntas, cubren todas las filas sin repetir ni saltear ninguna. | `TorneoModel::buscarPublicos()`, `AuditoriaModel::buscar()`, `ParticipanteModel::buscarConEquipo()` |
| 155 | La lista pública solo trae torneos públicos que no sean borrador ni cancelados; filtrar por esos estados no los revela. | `TorneoModel::buscarPublicos()` |
| 156 | La lista de torneos del panel aplica en la consulta la regla de que un organizador solo ve los suyos: buscar no abre los de otro. | `TorneoModel::buscarParaPanel()`, `TorneoController::listadoAdmin()` |
| 157 | La portada pide una sola fila, el torneo destacado, y no todos los torneos públicos. | `TorneoModel::destacadoPublico()` |
| 158 | El reporte general y su CSV son solo del administrador. El reporte de un torneo, sus CSV y su impresión, del administrador y del organizador dueño de ese torneo, siempre que su rol tenga permiso de ver el módulo «torneos». | `ReporteController::general()`, `ReporteController::generalCsv()`, `ReporteController::torneo()` |
| 159 | Los reportes son de lectura (solo GET) y no modifican datos del dominio; cada CSV que se descarga queda en la auditoría con quién lo bajó y cuántas filas tenía. | `ReporteController::exportar()`, `ReporteController::generalCsv()` |
| 160 | Un «partido» en las cifras es un enfrentamiento que se juega: no cuenta los bye ni los cancelados. Así el reporte general es la suma de los de cada torneo y los puntos anotados coinciden con los puntos a favor de la tabla. | `ReporteModel::resumenTorneo()`, `TorneoModel::buscarConAvance()` |
| 161 | Un empate se reconoce por los puntos y no por la columna «ganador» (hoy queda en `NULL`; las bases anteriores a la migración `2026_10_empates_sin_ganador` guardaban ahí al lado A); un bye no muestra resultado. | `ReporteService::torneo()` |
| 162 | En un CSV el separador es «;», el archivo lleva BOM de UTF-8 y los decimales usan coma; una celda de texto que empieza con `=`, `+`, `-` o `@` se neutraliza con un apóstrofo para que la planilla no la ejecute como fórmula. | `Csv::celda()`, `Csv::generar()` |
| 163 | El CSV general exporta todos los torneos que cumplen el filtro (no solo la página que se veía) y el de inscritos no incluye datos de contacto. | `ReporteService::csvTorneos()`, `ReporteService::csvInscritos()` |
| 164 | El nombre del archivo que se descarga solo lleva letras ASCII, números, guiones y la fecha; nunca texto de usuarios. | `Csv::nombreArchivo()` |
| 165 | También paginan, con la misma barra de búsqueda: los registros pendientes, la bandeja de correcciones (las pendientes primero), «Mis torneos» del organizador y «Mis torneos» del participante. El organizador no ve solicitudes ni torneos ajenos aunque los busque. | `UsuarioModel::buscarParticipantesPendientes()`, `SolicitudCorreccionModel::buscarConDetalle()`, `InscripcionModel::buscarPorParticipante()`, `OrganizadorController::misTorneos()` |
| 166 | Un empate no tiene ganador ni perdedor: las columnas `ganador_*` y `perdedor_*` de `enfrentamientos` y `ganador_*` de `resultados` quedan en `NULL`. | `ResultadoService::cargar()`, `ResultadoService::corregir()`, `EnfrentamientoModel::updateGanadorParticipante()` |
| 167 | Corregir un resultado, y pedir su corrección, respetan la misma regla de empates que cargar: Eliminación Directa no los admite y Liga y Suizo solo si el torneo los permite (un desempate siempre los admite). | `ResultadoService::motivoMarcadorNoPermitido()`, `CorreccionService::solicitar()` |
| 168 | Corregir un resultado de un torneo finalizado vuelve a decidir al campeón con la regla de siempre: un líder distinto pasa a ser campeón y queda en la auditoría; si los dos primeros quedan empatados en todo, el torneo vuelve a «en curso», sin campeón, con un partido de desempate. En Eliminación Directa solo la final puede cambiarlo. | `DesempateTrait::reevaluarCampeon()`, `EliminacionDirectaService::reevaluarCampeon()`, `ResultadoService::corregir()` |

## 26. API JSON y formularios por fetch

Cubierto por `tests/api_test.php` y `tests/fetch_resultados_test.php`. Ver `docs/api.md`.

| # | Restricción | Origen |
|---|-------------|--------|
| 169 | La API `/api` es de solo lectura: ninguna ruta de escritura, solo `GET`; con otro método responde 405 con la cabecera `Allow`. No pide sesión y no abre una (ni cookie ni archivo de sesión por cliente). | `ApiController::torneos()`, `Router::metodosPermitidos()` |
| 170 | La API solo muestra lo que un visitante ve en el sitio: torneos públicos y no borradores (la lista omite además los cancelados; uno cancelado se abre por su identificador). Un torneo que no existe o no es público es un 404 en JSON. | `ApiService::listar()`, `ApiService::posiciones()`, `ApiService::calendario()` |
| 171 | La API no expone datos personales: nombres, estados y resultados, nunca documento, correo, teléfono ni cuentas. | `ApiService::posiciones()`, `ApiService::calendario()` |
| 172 | La lista de la API tiene un tope de 50 torneos por página (12 por defecto); un filtro inválido se ignora y `meta.filtros` dice cuáles se aplicaron. | `ApiService::listar()`, `ApiController::torneos()` |
| 173 | En la API un empate se reconoce por los puntos y no tiene ganador; un bye no tiene visitante ni resultado y «gana» quien pasa de ronda. | `ApiService::calendario()` |
| 174 | Un pedido de fetch (`X-Requested-With` o `Accept: application/json`) recibe JSON: 200 si salió bien, 422 ante un rechazo de negocio, 500 ante un fallo técnico. Una compuerta que falla (rol, permiso, torneo ajeno, token) responde 401/403 en JSON y no redirige. El formulario común sigue con aviso y redirección. | `BaseController::terminarAccion()`, `BaseController::requirePermiso()`, `BaseController::requireTorneoOwnership()` |
| 175 | El token CSRF puede viajar en la cabecera `X-CSRF-Token` o en el campo `csrf_token`; si la cabecera viene, es la que vale. | `Csrf::tokenDelPedido()`, `Csrf::checkOrFail()` |

## 27. Imágenes subidas

Cubierto por `tests/imagen_test.php`. Ver «Imágenes subidas» en `docs/documentacion_tecnica.md`.

| # | Restricción | Origen |
|---|-------------|--------|
| 176 | Una imagen subida se decodifica y se vuelve a codificar: el lado mayor no pasa de 512 px, no se agranda una imagen chica y se conserva la proporción. Lo guardado no lleva metadatos ni nada pegado al final del archivo (un JPEG con código PHP sale limpio). | `Imagen::reducir()`, `Upload::guardar()` |
| 177 | Se rechaza con un motivo lo que no es una imagen, un formato que no sea JPG, PNG, WEBP o GIF y una imagen de más de 16 megapíxeles (antes de decodificarla, para no agotar la memoria). Un archivo que el servidor rechaza por pesado también dice «supera el máximo de 2 MB». | `Upload::guardar()`, `Upload::image()`, `Imagen::cabeEnMemoria()` |
| 178 | Un GIF se guarda como PNG. Se conserva la transparencia y se aplica la orientación EXIF de un JPEG antes de volver a codificar (los celulares guardan la foto «de costado» y lo anotan ahí). | `Imagen::reducir()` |
| 179 | Si esta instalación de GD no sabe tratar un formato, se guarda el archivo original (ya validado) en lugar de rechazarlo. | `Imagen::soporta()`, `Upload::guardar()` |

## 28. Vistas y menú lateral

Cubierto por `tests/vistas_sin_modelos_test.php`.

| # | Restricción | Origen |
|---|-------------|--------|
| 180 | Las vistas no crean modelos, servicios ni controladores ni llaman a la base: los datos llegan desde el controlador. El test falla si una vista nueva lo hace. | `tests/vistas_sin_modelos_test.php` |
| 181 | Los contadores del menú del administrador (registros por aprobar y correcciones por resolver) los calcula el controlador, solo para el administrador y solo en el layout del panel. | `BaseController::pendientesDelMenu()`, `BaseController::render()` |
| 182 | Cada partido de una ronda trae su resultado y si fue corregido en la misma consulta: no hay una consulta por partido dibujado. | `EnfrentamientoModel::getByRonda()`, `View::resultadoDe()` |

## 29. Panel del participante

Cubierto por `tests/panel_participante_test.php` y `tests/configuracion_pantalla_test.php`.

| # | Restricción | Origen |
|---|-------------|--------|
| 183 | «Mi panel» muestra hasta 5 próximos partidos, hasta 5 últimos resultados y la posición en hasta 5 torneos. | `PanelParticipanteService::panel()` |
| 184 | Próximos partidos: solo de torneos en curso, con los dos lados ya definidos y sin bye. Primero los que tienen fecha, del más cercano al más lejano; después los demás, por torneo y ronda. | `EnfrentamientoModel::proximosDe()` |
| 185 | Un participante compite solo o con sus equipos: en un torneo por equipos el partido es del equipo y el panel dice con cuál. Si deja el equipo, deja de verlo. | `EnfrentamientoModel::proximosDe()`, `EnfrentamientoModel::recientesDe()`, `EquipoModel::idsDeParticipante()` |
| 186 | Victoria, empate o derrota se deciden por los puntos de cada lado, vistos desde el participante (sea el lado A o el B); un empate no tiene ganador. | `PanelParticipanteService::reciente()` |
| 187 | En una liga o un suizo la posición es la de la tabla pública, y solo se muestra con al menos un partido jugado. En una llave se dice si es campeón, si sigue en carrera o en qué ronda quedó afuera. | `PanelParticipanteService::competencia()`, `PanelParticipanteService::estadoEnLlave()` |
| 188 | Una inscripción retirada, y un torneo que no está en curso ni finalizado, no cuentan en el panel. | `InscripcionModel::competenciasDe()` |

## 30. Configuración del sistema

Cubierto por `tests/configuracion_sistema_test.php` y `tests/configuracion_pantalla_test.php`.

| # | Restricción | Origen |
|---|-------------|--------|
| 189 | Solo un administrador cambia la configuración: se comprueba en el servicio, con el id de quien actúa, y en el controlador. | `ConfiguracionSistemaService::guardar()`, `ConfiguracionController::index()`, `ConfiguracionController::guardar()` |
| 190 | Cada ajuste acepta un tipo y un rango: nombre del sitio de 1 a 60 caracteres sin caracteres de control; correo de contacto válido de hasta 150 caracteres, o vacío; interruptor de perfiles 0 o 1; intentos de login de 1 a 20; minutos de bloqueo de 0 a 1440; intentos por IP de 1 a 1000; ventana de 1 a 1440 minutos. | `ConfiguracionSistemaService::validar()` |
| 191 | Se guarda todo o nada: si un valor no sirve no se guarda ninguno, y el formulario vuelve a mostrarse con lo que la persona escribió y el motivo (estado 422). | `ConfiguracionSistemaService::guardar()`, `ConfiguracionController::guardar()` |
| 192 | Solo se escribe lo que cambió, con quién lo cambió, y queda UNA entrada de auditoría (`actualizar_configuracion`) con los valores de antes y de después. Sin cambios no se escribe ni se audita. | `ConfiguracionSistemaService::guardar()`, `ConfiguracionSistemaModel::guardar()` |
| 193 | Una clave que falta en el envío no se toca (no equivale a apagarla) y lo que no es un ajuste se ignora. | `ConfiguracionSistemaService::validar()` |
| 194 | Un ajuste ausente o inservible en la base se lee como su valor de fábrica: un nombre en blanco, un correo mal escrito o un valor raro del interruptor no rompen el sitio. Solo un «0» cierra los perfiles. | `ConfiguracionSistemaService::sitio()`, `ConfiguracionSistemaService::perfilesPublicos()` |
| 195 | El nombre del sitio sale en el menú, el título y el pie, siempre escapado. El correo de contacto se muestra en el pie, como enlace, solo si es válido. Las páginas que no pasan por un controlador (404, errores) usan el nombre del entorno y no leen la base. | `BaseController::render()`, `View::render()` |
| 196 | Con los perfiles cerrados, `/jugador/{id}` y `/equipo/{id}` piden iniciar sesión a un visitante. Se decide antes de buscar el perfil, así no se revela qué identificadores existen, y las páginas de torneo dejan de enlazar los perfiles. | `PublicoController::exigirPerfilVisible()`, `PublicoController::perfilesVisibles()` |
| 197 | La API pública no depende del interruptor de perfiles: no expone perfiles, solo nombres y posiciones, como las páginas de torneos. | `ApiService::posiciones()` |

## 31. Frontend: sin código en línea, política de seguridad y accesibilidad

Cubierto por `tests/frontend_estatico_test.php` y `tests/js/interfaz_test.js`. Ver «Frontend sin código en línea» en
`docs/documentacion_tecnica.md`.

| # | Restricción | Origen |
|---|-------------|--------|
| 198 | Las vistas no llevan atributos `style="…"`, ni `<script>` ejecutable, ni manejadores `on…="…"`. Los datos para los scripts viajan en bloques `<script type="application/json">`, que el navegador no ejecuta. | `tests/frontend_estatico_test.php` |
| 199 | La política de seguridad de contenido la manda PHP en cada respuesta (Apache no fija otra: dos políticas se intersectan): `script-src 'self'` y `style-src 'self' 'nonce-…'`, sin `'unsafe-inline'` ni `'unsafe-eval'`. El nonce cambia en cada pedido. | `Csp::politica()`, `Csp::enviar()`, `Csp::nonce()` |
| 200 | Un estilo que se calcula al dibujar la página (la posición del bracket, el ancho de una barra, el tamaño de un avatar) va en una clase dinámica dentro de un `<style nonce>`, y solo admite propiedades con valores numéricos (rem, px, %): nunca texto de una persona. | `View::claseDinamica()`, `View::estilosDinamicos()`, `View::avatar()` |
| 201 | Un formulario que pide confirmación lleva el mensaje en `data-confirmar`: un texto con comillas, como un nombre, no puede salirse de un literal de JavaScript. | `public/assets/js/app.js` |
| 202 | Las hojas de estilo son seis archivos que se cargan en un orden fijo (más la de impresión, solo al imprimir) y siguen siendo Mobile First: solo `@media (min-width…)`. Todo atributo `hidden` oculta, aunque su clase dé un `display`. | `View::HOJAS`, `public/assets/css/` |
| 203 | Las hojas y los scripts se piden con `?v=<fecha de modificación>`: si el archivo cambia, el navegador no sigue usando la copia vieja. | `View::asset()` |
| 204 | Un diálogo es un diálogo para un lector de pantalla (`role="dialog"`, `aria-modal`, nombre) y para el teclado: al abrir el foco entra al diálogo, Tab no sale de él, Escape lo cierra y el foco vuelve a quien lo abrió. | `public/assets/js/modales.js` |
| 205 | Cada campo de un formulario tiene su etiqueta asociada (`for` e `id`, o el campo adentro del `<label>`); cada tabla, un título accesible (`aria-label`) y `scope="col"` en sus cabeceras. | `tests/frontend_estatico_test.php` |
| 206 | Cada página tiene un `<main>` (o el formulario como contenido principal), un `<nav>` donde hay un menú, un enlace «Saltar al contenido» que solo se ve al recibir el foco y títulos sin saltear niveles: el nivel lo da la estructura y el tamaño, la clase. | `app/views/layouts/`, `.saltar`, `.como-h2`, `.como-h3` |
| 207 | Los avisos se anuncian a los lectores de pantalla (`role="alert"` los errores, `role="status"` el resto), también el motivo de un rechazo dentro de un diálogo. Ninguna hoja apaga el marco de foco del teclado sin reemplazarlo. | `app/views/partials/flash_messages.php`, `public/assets/css/componentes.css` |
| 208 | Cada pantalla tiene un título propio, también la de «Página no encontrada». | `Router::dispatch()`, `PublicoController` |
| 209 | El calendario público de torneos cabe en cualquier ancho de pantalla: su grilla de siete columnas usa `minmax(0, 1fr)` (con `1fr` un título largo la ensanchaba más allá de la pantalla), el torneo aparece en el día en que **empieza** y el nombre se escribe como texto. Mobile First decide qué se ve: hasta 720 px un punto por torneo (hasta tres) y debajo la lista del mes; desde 721 px, una tarjeta con enlace por torneo y sin la lista. | `public/assets/js/calendar.js`, `public/assets/css/paginas.css`, `public/assets/css/responsive.css` |
| 210 | El texto blanco del botón primario tiene al menos 4,5:1 de contraste (WCAG AA) sobre todo su degradado: 4,63:1 en el extremo claro (`#1f7ab8`) y 4,89:1 en el oscuro. | `.btn.primary` en `public/assets/css/componentes.css` |
| 212 | El texto que escribe una persona (el nombre de un torneo) y no tiene espacios —una dirección pegada, una palabra enorme— no ensancha la tarjeta ni la página: donde se muestra en una tarjeta o en un título, se parte donde haga falta (`.rompe-texto`). Sin esto, 80 letras seguidas sacaban todo de pantalla en el celular. La clase va **solo** ahí: puesta en general parte palabras normales de elementos angostos. | `.rompe-texto` en `public/assets/css/componentes.css` |
| 213 | La lista pública de torneos se actualiza al escribir, sin recargar, pero **es una mejora, no un reemplazo**: el formulario sigue siendo un GET común y sin JavaScript funciona igual. Pide a la API pública (`/api/torneos`, la misma consulta del servidor) tras 300 ms de calma; una respuesta vieja nunca pisa a una nueva; mientras espera la lista se atenúa y queda `aria-busy`; los lectores de pantalla oyen cuántos torneos hay (`role="status"`); la dirección se actualiza para poder copiarla; si el pedido falla, la lista queda como estaba y arriba aparece el motivo. Dibuja el mismo marcado que el servidor y todo dato entra con `textContent`, nunca como HTML. | `PublicoController::torneos()`, `public/assets/js/torneos-en-vivo.js`, `public/assets/js/formato.js` |

---

**Total: 213 RNE.** Documento derivado del análisis de `app/services` y `app/controllers`.

Todas las restricciones se citan por **nombre de método**, no por número de línea.
Hasta septiembre de 2026 se citaban por línea y las referencias se habían podrido:
apuntaban a constructores, a métodos que no eran, o a líneas en blanco. Un número
de línea envejece con cada edición del archivo; el nombre de un método, no.
