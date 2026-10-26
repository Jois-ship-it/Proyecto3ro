# Documentación Técnica — FlexArena

## Arquitectura general

El sistema sigue el patrón **MVC con capa de servicios**:

```
Request → Router → Controller → Service → Model → PDO → MySQL
                       ↓
                      View
```

### Capas

| Capa | Responsabilidad |
|------|-----------------|
| **Router** (`core/Router.php`) | Parsea URL, despacha a controlador |
| **Controllers** | Reciben request, validan permisos, llaman servicios, cargan vistas |
| **Services** | Lógica de negocio: algoritmos de torneo, validaciones complejas |
| **Models** | Solo acceso a datos: queries PDO, mapeos, persistencia |
| **Views** | Solo presentación: PHP templates con layouts |

## Tecnologías

- **Backend**: PHP 8.2 OOP
- **Base de datos**: MySQL 8.0
- **Frontend**: HTML5, CSS3, JavaScript Vanilla
- **Servidor**: Apache 2.4 con mod_rewrite
- **Despliegue**: Docker + docker-compose
- **Pruebas** (solo desarrollo): PHPUnit 11 como `.phar` (sin Composer), Node 22+ para los scripts del navegador y Cypress 16 para el
  recorrido de punta a punta. Ver «Pruebas automáticas».

## Estructura de carpetas

```
sgdm/
├── app/controllers/     — 11 controladores
├── app/models/          — 20 modelos
├── app/services/        — 23 servicios + 2 traits
├── app/views/           — 53 vistas PHP
├── config/              — app.php, database.php, routes.php
├── core/                — 20 clases base (Router, DB, Auth, CSRF, Upload, Imagen, etc.)
├── database/            — schema.sql, seed.sql
├── docs/                — Documentación
├── public/              — Front controller + assets
├── scripts/             — Bash de administración, e2e.sh e instalar_herramientas_de_prueba.sh
├── tests/               — Batería de PHP, tests/js (navegador) y tests/e2e (Cypress)
└── tools/phpunit/       — PHPUnit (.phar), se instala con scripts/instalar_herramientas_de_prueba.sh
```

## Routing

El `.htaccess` redirige todo a `public/index.php?url=path`.
El Router usa regex para matchear rutas estáticas y dinámicas (`{id}`).

Ejemplo:
```
GET /admin/torneos/5   →  TorneoController::gestion('5')
POST /admin/resultados/cargar  →  ResultadoController::cargar()
```

### Qué acepta un parámetro de ruta

`Router::PATRONES` define, por nombre de parámetro, qué valores son válidos.
Hoy el único nombre en uso es `id`, y acepta **enteros positivos sin ceros a la
izquierda y de hasta 10 dígitos** (las claves primarias son `INT UNSIGNED`).
Un valor que no encaja no matchea ninguna ruta, así que la petición termina en
el 404 del router.

Antes el patrón era `([^/]+)` —cualquier cosa menos una barra— y el valor
llegaba crudo al controlador, que hace `(int)$id`. Como `(int)'5 OR 1=1'` vale
5, `/torneo/5 OR 1=1` devolvía la página del torneo 5 con código 200. Nunca
hubo inyección, porque los modelos usan consultas preparadas, pero una URL
inválida no puede devolver una página válida.

Dos consecuencias a tener presentes:

- `/torneo/007` ya no es un alias de `/torneo/7`: cada recurso tiene una sola
  URL válida.
- Agregar una ruta con un parámetro que no se llame `id` lanza una excepción al
  registrarla, con el nombre del parámetro en el mensaje. Es a propósito: el
  patrón nuevo se elige explícitamente en `Router::PATRONES`, en vez de heredar
  por omisión uno que acepte cualquier cosa.

La regex de cada ruta se arma escapando los tramos literales, de modo que el
texto de la ruta nunca se interprete como expresión regular, y se cierra con el
modificador `D`: sin él, `$` también matchea justo antes de un salto de línea
final y `/torneo/5%0A` pasaría como si fuera `/torneo/5`.

Cubierto por `tests/ruta_parametros_test.php`.

### A dónde se puede redirigir

`BaseController::redirect()` no manda a cualquier lado: el destino pasa por
`Url::interna()`, que acepta únicamente rutas del propio sitio. Existe porque
varios controladores arman el destino con datos del pedido —la cabecera
`Referer`, el campo `return` de un formulario— para devolver al usuario a la
pantalla de la que vino, y esos valores los controla quien manda el pedido.

El filtro está puesto dos veces a propósito: en cada lugar que arma el destino,
con el destino de reserva que corresponde a esa pantalla, y adentro de
`redirect()` con la portada como reserva. La segunda es la que hace que un
controlador nuevo no pueda abrir un agujero por descuido.

Detalle en `docs/owasp.md` → A01. Cubierto por
`tests/redireccion_segura_test.php`.

## Base de datos

Ver `database/schema.sql`. Las tablas principales son:

- `torneos` — configuración completa de cada torneo
- `enfrentamientos` — partidos (soporta individual y equipos)
- `resultados` — resultado con soporte de corrección y auditoría
- `tabla_posiciones` — materializada, recalculada en cada resultado
- `configuracion_sistema` — ajustes generales de la plataforma (clave/valor); no confundir con
  `configuraciones_torneo`, que es por torneo
- `intentos_login` — inicios de sesión fallidos, para limitar los intentos por dirección IP
- `historial_bd` — cada cambio en las tablas de datos, escrito por triggers (ver «Historial de la base»)
- cinco vistas `v_*` que arman lo que la aplicación consulta junto (ver «Vistas»)

### Integridad que garantiza el motor

Además de las claves foráneas, el esquema impide por sí mismo lo que la capa de servicios ya
validaba. Una escritura que se salte los servicios (un script, phpMyAdmin) no puede dejar datos
que la aplicación considera imposibles:

| Regla | Mecanismo |
|---|---|
| Dos equipos no se llaman igual | índice único `uq_equipos_nombre` |
| Dos participantes no comparten documento (sin documento es `NULL`, nunca `''`) | índice único `uq_participantes_documento` |
| Los puntos de un resultado o de una solicitud de corrección no son negativos | `chk_resultados_puntos`, `chk_solicitudes_puntos` |
| Un torneo no termina antes de empezar | `chk_torneos_fechas` |
| Una inscripción y una fila de posiciones son de **un** competidor (participante o equipo) | `chk_inscripcion_un_competidor`, `chk_tabla_un_competidor` |
| Un formato de torneo pertenece a un módulo que existe, y no se puede borrar un módulo que tiene formatos | clave foránea `fk_tipos_torneo_modulo` (`tipos_torneo.modulo_slug → modulos.slug`) |

El servicio sigue validando primero para dar un mensaje que entienda una persona; el motor es la
última línea. Cubierto por `tests/esquema_integridad_test.php`.

### Vistas

Las consultas que varias pantallas repetían pasan a ser vistas SQL, de modo que «qué es público»
o «cómo se arma un partido con los nombres de sus dos lados» tiene una sola definición. Están en
`database/migrations/2026_10_vistas.sql` y, idénticas, en `schema.sql`.

| Vista | Qué entrega | La usa |
|---|---|---|
| `v_torneos_publicos` | los torneos que ve un visitante (públicos y fuera de borrador), con su cantidad de inscritos activos | `TorneoModel::findPublicos()`, `TorneoModel::esPublico()` |
| `v_calendario` | cada partido con su torneo, su ronda, los nombres de sus dos lados y el resultado | `EnfrentamientoModel::getByRonda()` |
| `v_posiciones` | la tabla de posiciones con el nombre de cada competidor | `TablaPosicionesModel::getByTorneo()` |
| `v_estadisticas_sistema` | los contadores del panel de administración, en una sola fila | `EstadisticasModel::get()` |
| `v_participantes_publicos` | el perfil público: nombre, nick, foto y estado, **sin** documento, email ni teléfono | `ParticipanteModel::findPublicoConEquipos()`, `EquipoModel::findPublicoConParticipantes()` |

Qué cambió en la práctica: el listado público ya no consulta los inscritos torneo por torneo (una
consulta por tarjeta) y los cuenta una sola vez, solo los activos; la página de detalle aplica la
misma regla de visibilidad que el listado, así que un borrador no se ve conociendo su dirección;
y que un dato personal no salga hacia una página pública lo decide la definición de la vista, no el
cuidado de cada consulta.

Una vista con `SELECT t.*` toma las columnas que la tabla tenía cuando se creó: si la tabla gana
una columna, hay que volver a correr la migración de vistas (es `CREATE OR REPLACE`).
`tests/vistas_test.php` lo detecta y compara cada vista con la consulta que reemplazó.

### Historial de la base (`historial_bd`)

`auditoria` guarda lo que una persona hace desde la aplicación; `historial_bd` guarda **cualquier
cambio** en las tablas de datos, venga de donde venga (la aplicación, un script, phpMyAdmin, el
cliente `mysql`). Cada `INSERT`, `UPDATE` y `DELETE` deja una fila con la tabla, la operación, el
id del registro, el registro **antes** y **después** (JSON), la hora, el usuario de la aplicación
y la cuenta de MySQL que lo hizo.

- **Cómo se escribe.** Con 51 triggers (`trg_hist_<tabla>_ai`, `_au` y `_ad`: tres por cada una de
  las 17 tablas de datos). Son iguales salvo por las columnas, así que los genera
  `database/generar_historial.php` y no se editan a mano; `php database/generar_historial.php --verificar`
  y `tests/historial_bd_test.php` avisan si una tabla ganó una columna y falta regenerarlos. Un
  `UPDATE` que no cambió nada no deja fila.
- **Quién lo hizo.** `Database` fija `@id_usuario_app` al abrir la conexión con el usuario de la
  sesión, y `Auth` lo actualiza al iniciar y al cerrar sesión; los triggers lo copian. Es informativo:
  lo fija la aplicación. La cuenta de MySQL sale de `USER()`.
- **Nadie la modifica desde la aplicación.** La cuenta de la aplicación solo puede leer la tabla; la
  escriben los triggers con los privilegios de quien los creó, así que ni una inyección SQL puede
  falsificarla (`tests/privilegios_test.php`).
- **Qué no guarda.** Contraseñas ni datos personales (nombre, documento, nick, email, teléfono,
  foto): de esas columnas solo anota que cambiaron (`datos_reservados_modificados`). El historial no se
  puede modificar, así que si los guardara la anonimización que pide la ley de datos personales no
  serviría de nada. Tampoco registra `auditoria`, `intentos_login` ni `historial_bd` (ya son un
  registro) ni `tabla_posiciones` (se recalcula entera en cada resultado: sería puro ruido).
- **Qué no ve.** Lo que la base hace por su cuenta no dispara triggers: los borrados en cascada,
  `TRUNCATE` y `DROP`. Y las filas de `seed.sql` quedan registradas al instalar: son la primera
  historia.

### Transacciones

Una operación que escribe en varias tablas es **todo o nada**: si falla a la mitad no queda nada a
medias (rondas sin partidos, una cuenta sin perfil, un torneo sin organizador, una matriz de
permisos cambiada hasta la mitad). `Database::transaccion()` la envuelve:

| Operación | Qué queda junto |
|---|---|
| `LigaService::generarFixture()`, `EliminacionDirectaService::generarBracket()`, `SistemaSuizoService::generarPrimeraRonda()` y `::generarSiguienteRonda()` | rondas, partidos, byes, tabla, estado del torneo y auditoría |
| `ResultadoService::cargar()` y `::corregir()` | el resultado, la tabla, el avance del ganador o la ronda siguiente, el cierre del torneo y la auditoría |
| `CorreccionService::aprobar()` | la corrección aplicada y la solicitud marcada como aprobada |
| `RegistroService::registrar()`, `::aprobar()` y `::rechazar()` | la cuenta y su perfil |
| `TorneoService::crear()` y `::editar()` | los datos, el organizador y la auditoría |
| `TorneoModel::setOrganizador()` | borrar al organizador anterior y poner al nuevo: el torneo no queda sin organizador |
| `ParticipanteService::editar()` y `::actualizarPerfil()` | la cuenta de acceso (nombre, email) y el perfil, que son la misma identidad |
| `UsuarioModel::lockAccount()` y `::unlockAccount()` | la cuenta y su perfil (bloqueada/suspendido, activo/activo) |
| `PermisoService::guardarMatriz()`, `ConfiguracionTorneoService::guardar()` | la matriz entera, los datos del evento enteros |
| `TablaPosicionesService::recalcular()` | borrar y volver a escribir la tabla |
| `ParticipanteService::anonimizarDatos()` | perfil y cuenta; la foto se borra **después** |

Reglas de uso:

- **Se anida.** Si ya hay una transacción abierta, la función se une a ella y no confirma ni deshace
  por su cuenta: eso lo decide la más externa. Por eso `cargar()` puede llamar a `recalcular()`
  sin que cada una confirme lo suyo.
- **La excepción tiene que llegar a la más externa.** La anidación es plana (sin puntos de guardado):
  atrapar el error de una operación en el medio y seguir confirmaría lo que esa operación alcanzó a
  escribir.
- **La auditoría va adentro.** Se evita tanto un resultado cargado sin su registro como un registro de
  algo que no pasó.
- **Lo que no se puede deshacer va después del `COMMIT`**, como borrar un archivo.
- **Sin DDL adentro.** `CREATE`, `ALTER` y `TRUNCATE` confirman la transacción en MySQL; los
  servicios no los usan.
- **No todo se envuelve: lo que tiene que dejar rastro aunque falle va afuera.** Un login fallido
  cuenta el intento y *después* lanza el error; dentro de una transacción esa excepción deshacería el
  contador y el bloqueo de cuentas a los 5 intentos dejaría de funcionar. Por eso `AuthService::login()`
  queda fuera a propósito (`tests/lockout_estado_test.php` lo vigila). Las operaciones de una sola fila
  más su registro de auditoría (activar un equipo, cerrar una ronda…) tampoco se envolvieron: no hay
  nada que dejar a medias.

`tests/transacciones_test.php` provoca el corte en cada una —un trigger de prueba lanza un error a
mitad de camino— y comprueba el estado completo, además de que reparada la falla se pueda reintentar.

## Algoritmos de torneo

### Liga
Algoritmo de rotación de Berger (round-robin). Si N es impar, se agrega un "bye virtual" y se saltan los partidos donde uno es null.

### Eliminación Directa
Potencia de 2 más cercana ≥ N. Los byes se distribuyen automáticamente al inicio. El avance de ganadores crea o completa slots en la siguiente ronda.

### Sistema Suizo
Primera ronda: split-pairing (top mitad vs bottom mitad).
Rondas siguientes: ordenar por score, emparejar sin repetición de rivales (Dutch pairing simplificado). Bye al participante de menor score sin bye previo.

## Registro de participantes y aprobación

Los participantes **no se crean manualmente**: se auto-registran desde `/registro`.

- `RegistroService::registrar()` crea un `usuarios` (rol participante, estado `pendiente`) + su `participantes` vinculado (estado `pendiente`).
- El login filtra `usuarios.estado = 'activo'`, por lo que un pendiente **no puede ingresar**.
- Un administrador aprueba/rechaza en `/admin/registros` (`RegistroService::aprobar/rechazar`), que pasa ambos registros a `activo` o `rechazado`.
- Política de contraseñas en `core/Validator.php` (longitud, mayús, minús, número, símbolo, confirmación), validada en frontend (JS) y backend (autoritativo).
- **Política de Privacidad y Términos y Condiciones.** El formulario de registro (no el de login) resume qué datos se guardan, quién los ve y para qué, y trae una casilla obligatoria (`acepto_politicas`, bloque `partials/consentimiento.php`) con enlaces a `/privacidad` y `/terminos` (`PublicoController::privacidad()` y `terminos()`; públicas y sin sesión, enlazadas también desde el pie del sitio). `RegistroService::registrar()` no crea nada si la casilla no llegó marcada (`Politicas::aceptada()`): es la validación que manda, el navegador y `registro.js` solo evitan el viaje. La versión vigente de los textos vive en `core/Politicas.php` (`VERSION`, `VIGENTE_DESDE`) y la auditoría guarda cuál aceptó cada persona («Aceptó la Política de Privacidad y los Términos y Condiciones, versión 1.0» en la acción `registro_participante`). Si cambian los textos, se sube la versión. Lo que dicen las páginas sobre quién ve qué es lo que hace el sistema: nombre, nick, foto, equipos, resultados y estadísticas son públicos (los perfiles se pueden cerrar en Configuración); email, teléfono y documento no salen en ninguna página pública ni en las vistas de organizador. Cubierto por `tests/politicas_test.php`.

## Perfiles, estadísticas y subida de imágenes

- `core/Upload.php` — subida segura de imágenes: valida el contenido real con `getimagesize` (no por extensión), whitelist JPG/PNG/WEBP/GIF, máx 2 MB y 16 megapíxeles, nombre aleatorio. La imagen se reduce y se vuelve a codificar (ver «Imágenes subidas»). Carpeta `public/assets/uploads/{avatars,logos}` con `.htaccess` que **desactiva la ejecución de scripts**.
- `app/services/StatsService.php` — agregación de estadísticas e historial para participantes (`participante()`) y equipos (`equipo()`): PJ/PG/PE/PP/PF/PC, % victorias, torneos (activos/finalizados), campeonatos, posiciones, historial cronológico y **evolución** (win-rate acumulado). Cruza los tres formatos. Sin tablas nuevas.
- `app/views/partials/perfil_stats.php` — panel reutilizable (KPIs + barra de rendimiento + sparkline SVG de evolución + form guide + torneos + historial), usado por el perfil de participante y de equipo.
- Perfiles: privado autogestionado (`/participante/perfil`, con subida de foto) y públicos read-only (`/jugador/{id}`, `/equipo/{id}`). `View::avatar()` muestra imagen o iniciales.

## Imágenes subidas

Antes una foto de perfil o un logo se guardaba tal cual: hasta 2 MB para mostrarse en un círculo de 56 px, con
todo lo que trajera el archivo adentro. Ahora `Upload::guardar()` valida y `core/Imagen.php` reduce:

- **Reducir.** `Imagen::reducir()` decodifica con GD, achica para que el lado mayor no pase de 512 px
  (`Imagen::dimensionesDestino()`: conserva la proporción y no agranda una imagen chica) y vuelve a codificar.
  Una foto de 3000×2000 de ~140 KB queda de ~9 KB.
- **Volver a codificar es una defensa.** Lo que sobrevive es solo la imagen: un JPEG con código PHP pegado al
  final, o con metadatos de ubicación, sale limpio. La carpeta de subidas además no ejecuta scripts.
- **Memoria.** GD descomprime a 4 bytes por píxel. Una imagen de más de 16 megapíxeles
  (`Imagen::cabeEnMemoria()`) se rechaza *antes* de decodificarla: sin ese tope, un PNG de 3 KB que declara
  6000×4000 px agotaba los 128 MB de PHP y mataba el proceso.
- **Qué se conserva.** La transparencia (PNG, GIF), y la orientación de las fotos de celular: el JPEG la trae
  en un dato EXIF que se pierde al volver a codificar, así que se aplica antes (`Imagen::reducir()`). Un GIF
  se guarda como PNG (GD solo lee su primer cuadro y una foto de perfil no necesita animarse).
- **Si GD no sabe.** `Imagen::soporta()` dice si esta instalación lee y escribe un formato. Si no, se guarda el
  archivo original, que ya pasó las validaciones. El `Dockerfile` instala GD con WebP y la extensión `exif`.
- **Errores.** Un archivo que PHP rechaza por pesado (`upload_max_filesize`) dice «supera el máximo de 2 MB» en lugar
  de un código.

`tests/imagen_test.php` prueba todo esto con imágenes generadas al vuelo, y se verificó también con subidas HTTP
reales (multipart) a un servidor de prueba.

## Vistas sin acceso a la base

Ninguna vista crea modelos ni servicios (M1 del análisis de la letra): los datos llegan desde el controlador.
`tests/vistas_sin_modelos_test.php` es la regla (recorre `app/views/` y falla si una vista crea un
modelo, un servicio o un controlador, o llama a `Database`/`PDO`).

- **Resultados de los partidos.** `EnfrentamientoModel::getByRonda()` trae con cada partido su resultado y si fue
  corregido; las vistas lo leen con `View::resultadoDe()`. Antes cada partido dibujado hacía su propia consulta.
- **Menú lateral.** Los contadores de registros y correcciones pendientes los calcula
  `BaseController::pendientesDelMenu()` y llegan a la vista como `$_pendientes`: solo para el administrador y solo
  en el layout del panel (para otro rol no se hace ninguna consulta).
- **El sitio.** `BaseController::render()` agrega `$_sitio` (nombre, contacto, perfiles). `View::render()` pone un
  valor por omisión para las páginas que no pasan por un controlador (el 404 del router y las de error), que no
  deben depender de la base.

## Panel del participante

`PanelParticipanteService::panel()` arma lo que muestra «Mi panel» (letra §5.3): próximos partidos, últimos
resultados y cómo va en cada torneo. Cada lista trae como mucho 5.

- **Partidos.** Salen de `v_calendario` (`EnfrentamientoModel::proximosDe()` y `recientesDe()`). Un participante compite
  solo o a través de sus equipos (`EquipoModel::idsDeParticipante()`): el filtro mira el participante y sus
  equipos, y el panel dice con cuál juega («con Liga Cerro Largo»).
- **Resultado.** Victoria, empate o derrota se deciden por los puntos de cada lado visto desde el participante.
- **Posición.** En liga y suizo es la fila de la tabla pública (`TablaPosicionesModel::filaDe()`), y solo con
  un partido jugado: con cero, la tabla ordena por desempate técnico y ese «lugar» no dice nada. En una llave no hay
  tabla: se dice campeón, sigue en carrera, o en qué ronda quedó afuera (`PanelParticipanteService::estadoEnLlave()`).
- **Qué torneos.** `InscripcionModel::competenciasDe()`: inscripción activa, torneo en curso o finalizado, primero los
  que están en curso.

## Configuración del sistema

`ConfiguracionSistemaService` lee y guarda los ajustes de `configuracion_sistema`. `ConfiguracionController`
(`/admin/configuracion`, solo el administrador) es la pantalla.

- **Catálogo.** `ConfiguracionSistemaService::CAMPOS` dice qué es cada ajuste (tipo, rango, etiqueta, ayuda,
  descripción). La pantalla se dibuja con él y `validar()` lo usa para validar: no hay dos listas que mantener.
  Los mínimos de los enteros son los que usa `AuthService` al leerlos.
- **Guardar.** `guardar($datos, $actorId)`: comprueba que quien actúa es administrador, valida todo (si algo no
  sirve no se guarda nada), escribe solo lo que cambió con `updated_by`, y deja una entrada de auditoría
  `actualizar_configuracion` con los valores de antes y de después, todo en una transacción. La pantalla vuelve a
  mostrar el formulario con lo escrito y el motivo (estado 422).
- **Qué cambia en el sitio.** El nombre sale en el menú, el título y el pie (`$_sitio`, siempre escapado); el
  correo de contacto sale en el pie como enlace si es válido; el interruptor de perfiles públicos cierra
  `/jugador/{id}` y `/equipo/{id}` a quien no inició sesión (`PublicoController::exigirPerfilVisible()`, antes de
  buscar el perfil) y las páginas de torneo dejan de enlazarlos a un visitante. Los números del inicio de sesión
  los leen `AuthService` y `IntentoLoginModel`.

## Listas con búsqueda y paginación

Antes solo la auditoría paginaba. La lista pública traía todos los torneos y los filtraba en PHP, y la
portada traía todos para quedarse con uno; Usuarios, Participantes, Equipos y Torneos del panel se listaban
enteros. Ahora toda lista larga sigue el mismo camino, en cuatro pasos dentro del controlador:

```php
$filtros = Filtros::desdeQuery($_GET, ['q' => 'texto', 'estado' => ['activo', 'inactivo']]);  // 1. sanear
$pag     = Paginador::de($servicio->contar($filtros), $this->get('page', 1), 15);              // 2. COUNT y página
$filas   = $servicio->buscar($filtros, $pag->porPagina, $pag->desplazamiento);                 // 3. solo esa página
$this->render('admin/lista', ['filas' => $filas, 'filtros' => $filtros, 'pag' => $pag]);       // 4. la vista dibuja
```

- **`Filtros::desdeQuery()`** (`core/Filtros.php`) reduce lo que llega por GET a algo seguro: el texto queda
  acotado (80 caracteres) y sin caracteres de control; una opción tiene que estar en la lista permitida; una
  fecha tiene que existir. Lo que no corresponde **se descarta** (`''`) y la lista se ve como si ese filtro
  no estuviera: una dirección vieja o escrita a mano no da un error. Devuelve siempre todas las claves, así
  que las vistas no preguntan si existen.
- **`Paginador::de()`** (`core/Paginador.php`) no toca la base: recibe el total del `COUNT(*)` y calcula la
  página (se corrige si está fuera de rango), el `OFFSET`, «Mostrando X–Y de N» y `ventana()`, los números a
  dibujar (`1 … 9 10 11 … 20`). `Paginador::enlace()` arma la dirección de cada página conservando los filtros.
  Tope de 100 filas por página.
- **Los modelos** (`buscar…()` y `contar…()`) comparten el mismo `WHERE`, armado en un único método privado
  por modelo: el total y las filas no pueden diferir. `BaseModel::dondeDe()`, `BaseModel::agregarBusqueda()`
  y `BaseModel::limiteDe()` son los ladrillos comunes.
- **La búsqueda** es un `LIKE '%texto%' ESCAPE '|'`: `Filtros::patronLike()` escapa `|`, `%` y `_`, de modo que
  buscar «50%» busca esos caracteres y no un comodín. La intercalación de la base (`utf8mb4_unicode_ci`)
  hace que no importen mayúsculas ni tildes. Cada columna lleva su propio parámetro (`:q1`, `:q2`…) porque con
  consultas preparadas nativas (`ATTR_EMULATE_PREPARES => false`) un mismo nombre no puede repetirse.
- **`LIMIT` y `OFFSET`** se escriben en la consulta como enteros ya convertidos con `max(0, …)`, no como
  parámetros: no hay texto de usuario en ese tramo.
- **El orden es estable**: cada lista desempata por identificador (`ORDER BY nombre, id`), así las páginas,
  juntas, cubren todas las filas sin repetir ni saltear.
- **La regla de quién ve qué viaja a la consulta**: `TorneoModel::buscarParaPanel()` recibe el organizador y
  filtra con un `EXISTS`; no se trae todo para recortar después.
- **Las vistas** reutilizan dos fragmentos: `partials/filtros.php` (formulario GET: buscar, selectores,
  fechas) y `partials/paginacion.php` (resumen y botones). Un fragmento recibe un arreglo que describe los
  campos; no instancia modelos. La lista pública además se actualiza en vivo (ver «Búsqueda en vivo» en
  «Frontend sin código en línea»).

| Lista | Controlador → servicio → modelo | Por página |
|---|---|---|
| `/torneos` (pública) | `PublicoController::torneos()` → `TorneoModel::buscarPublicos()` | 12 |
| `/admin/torneos` | `TorneoController::listadoAdmin()` → `TorneoModel::buscarParaPanel()` | 15 |
| Participantes | `AdminController::participantes()` → `ParticipanteModel::buscarConEquipo()` | 15 |
| Equipos | `AdminController::equipos()` → `EquipoModel::buscarConConteo()` | 15 |
| Organizadores / Administradores | `AdminController::usuarios()` / `::administradores()` → `UsuarioModel::buscarPorRol()` | 15 |
| Auditoría | `AdminController::auditoria()` → `AuditoriaModel::buscar()` | 50 |

La portada pide ahora una sola fila, `TorneoModel::destacadoPublico()`.

### Empates y campeón al corregir

- **Un empate no tiene ganador.** `ResultadoService::determinarGanador()` devuelve `null` en los dos lados
  y `EnfrentamientoModel::updateGanadorParticipante()` / `::updateGanadorEquipo()` aceptan `null`: las columnas
  `ganador_*` y `perdedor_*` quedan en `NULL`. Antes se guardaba al lado A como «ganador»; la tabla se calcula
  por los puntos y no lo leía, pero cualquier lista que mostrara la columna lo presentaba como una victoria.
  `database/migrations/2026_10_empates_sin_ganador.sql` limpia los empates ya guardados (no toca los bye).
- **Una sola regla de empates** para cargar, corregir y pedir una corrección
  (`ResultadoService::motivoMarcadorNoPermitido()`): antes `corregir()` no la aplicaba y permitía «corregir» a
  un empate en Eliminación Directa o en un torneo que no los admite.
- **El campeón se vuelve a decidir al corregir un torneo finalizado.** `ResultadoService::corregir()` llama,
  dentro de su transacción, a `DesempateTrait::reevaluarCampeon()` (Liga y Suizo) o a
  `EliminacionDirectaService::reevaluarCampeon()` (la final). Devuelve un aviso (otro campeón, o torneo reabierto
  por empate arriba) que los controladores muestran como un mensaje aparte (`warning`); el cambio queda en la
  auditoría (`cambiar_campeon`, `reabrir_torneo`). Un torneo que no estaba finalizado no se toca.

## API JSON y formularios por fetch

Ver `docs/api.md` para el detalle de cada ruta y de las respuestas. En breve:

- **API pública de solo lectura**: `ApiController` → `ApiService` (`GET /api/torneos`,
  `/api/torneos/{id}/posiciones`, `/api/torneos/{id}/calendario`). `ApiService` arma datos con forma de JSON
  (números como números, fechas ISO 8601, competidores `{id, nombre, tipo}`) a partir de las mismas consultas y
  vistas que las páginas públicas (`TorneoModel::buscarPublicos()`, `v_posiciones`, `v_calendario`). No escribe.
- **Sin sesión**: `public/index.php` no inicia la sesión para las rutas `/api`, así un cliente de la API no crea un
  archivo de sesión por pedido ni recibe una cookie.
- **405 con `Allow`**: `Router::metodosPermitidos()` dice con qué métodos existe una dirección; una dirección de
  la API pedida con otro método responde 405 en lugar de 404.
- **Un solo formato** de respuesta (`core/Respuesta.php`). `BaseController::responderJson()` envía el JSON y es
  un método aparte para que los tests lo sustituyan.
- **Una acción, dos maneras de pedirla.** Cargar, corregir, solicitar una corrección y programar un partido
  atienden el formulario común y el fetch en la misma ruta: `BaseController::terminarAccion()` cierra la acción
  con JSON si el pedido lo espera (`ManejadorErrores::esPeticionApi()`) o con flash y redirección si no.
- **Las compuertas no redirigen a un fetch**: `BaseController::requirePermiso()` y
  `BaseController::requireTorneoOwnership()` lanzan `AccesoDenegado` (403 en JSON) si el pedido espera JSON, en
  lugar de hacer flash y redirigir a una página que el cliente no puede interpretar.
- **CSRF por cabecera**: `Csrf::tokenDelPedido()` toma el token de `X-CSRF-Token` o, si no hay cabecera, del campo
  `csrf_token`; el layout del panel lo publica en `<meta name="csrf-token">`.
- **Cliente**: `public/assets/js/api.js` (`FlexApi`, `ApiError`) y `public/assets/js/resultados.js` (engancha
  los `<form data-api>`). Se cargan solo en las pantallas de gestión de torneos, `api.js` primero.
  `tests/js/resultados_cliente_test.js` los ejecuta con Node y un fetch simulado.

## Reportes y exportación a CSV

`ReporteController` → `ReporteService` → `ReporteModel` (y `TorneoModel::buscarConAvance()` para la tabla de
torneos del general). Todo es de **solo lectura** (GET) y se apoya en las vistas de la base
(`v_estadisticas_sistema`, `v_posiciones`, `v_calendario`).

| Ruta | Quién | Qué |
|---|---|---|
| `GET /admin/reportes` | administrador | Cifras generales y tabla de torneos (con filtros y páginas) |
| `GET /admin/reportes/torneos.csv` | administrador | La tabla de torneos con el filtro aplicado, **completa** |
| `GET /admin/torneos/{id}/reporte` | administrador; organizador dueño | Resumen, avance, posiciones y partidos |
| `GET /admin/torneos/{id}/reporte/{posiciones,partidos,inscritos}.csv` | ídem | Los tres CSV de un torneo |

- **Permisos**: el reporte de un torneo pasa por las tres compuertas (rol organizador, permiso `torneos / ver` y
  propiedad del torneo). No hay un módulo «reportes» propio: el reporte de un torneo es información del torneo.
- **Qué es un partido**: `ReporteModel::resumenTorneo()` cuenta los enfrentamientos que se juegan: no los
  *bye* ni los cancelados. Así el general es la suma de los de cada torneo y los puntos anotados coinciden con
  los puntos a favor de la tabla. Un **empate** se reconoce por los puntos, no por la columna «ganador» (hoy queda en
  `NULL`, pero una base anterior a la migración `2026_10_empates_sin_ganador` guardaba ahí al lado A): `ReporteService::torneo()` lo resuelve una vez y lo usan la pantalla y el CSV.
- **CSV** (`core/Csv.php`): separador `;`, BOM de UTF-8, fin de línea `\r\n` y decimales con coma, que es lo que
  abre bien Excel en español. `Csv::celda()` neutraliza con un apóstrofo toda celda de texto que empiece con
  `=`, `+`, `-` o `@` (CSV injection); `Csv::nombreArchivo()` arma un nombre solo con ASCII y la fecha.
  `ReporteController::descargarCsv()` manda las cabeceras (`Content-Disposition: attachment`,
  `Cache-Control: no-store`) y es un método aparte para que los tests miren el contenido sin cabeceras.
- **Auditoría**: cada CSV descargado registra `exportar_reporte` con quién lo bajó y cuántas filas salieron;
  una descarga denegada no se anota.
- **Imprimir / PDF** no usa librerías: el botón llama a `window.print()` y `@media print` (en
  `public/assets/css/impresion.css`) oculta menús y botones y pasa a fondo blanco. «Guardar como PDF» es una
  impresora del navegador. Era una funcionalidad opcional de la letra (§14).

## Las tres compuertas de autorización

Una acción del panel pasa por tres comprobaciones distintas, y las tres tienen
que dar verde. Cada una responde una pregunta que las otras no pueden responder:

| Compuerta | Pregunta | Granularidad | Dónde |
|---|---|---|---|
| **Rol** | ¿Qué tipo de usuario es? | por rol | `Auth::requireRole()` |
| **Permiso de módulo** | ¿Su rol puede hacer esa acción en ese módulo? | rol × módulo × acción | `PermisoService` sobre la tabla `permisos` |
| **Propiedad** | ¿Es **su** torneo (o el de **ese** partido)? | por fila | `BaseController::requireTorneoOwnership()` y `::requirePartidoPropio()` |

Un cuarto interruptor, `modulos.estado`, es de otra naturaleza: apaga un módulo
para **todo el sistema**. No es autorización de un usuario, es disponibilidad de
una funcionalidad, y por eso vive aparte (`ModuloActivoTrait`).

Orden en el que se llaman dentro de una acción:

```php
$this->requireOrganizador();                                   // 1. rol
$this->requirePermiso('torneos', 'editar', '/admin/torneos');  // 2. módulo
$this->requireTorneoOwnership((int)$id, '/admin/torneos');     // 3. propiedad
$this->checkCsrf();
```

### Por qué tres y no una

La tabla `permisos` **no puede** expresar la propiedad del torneo: es por rol y
módulo, no por fila. Y el rol solo no puede expresar «este organizador sí puede
corregir resultados y aquel no», que es literalmente lo que pide la letra del
proyecto en §5.2: «corregir resultados **si cuenta con autorización**». Cada
compuerta cubre lo que las otras no alcanzan.

La «autorización» de §5.2 es la del administrador: el organizador **solicita**
(`CorreccionController::solicitar()`, con las tres compuertas, la tercera sobre el
torneo del partido) y un administrador aprueba. Aplicar la corrección directamente
(`ResultadoController::corregir()`) exige rol de administrador, y el servicio
(`ResultadoService::corregir()`) lo vuelve a comprobar con el id de quien la pide,
para que no dependa de que el controlador esté bien armado.

### Cómo falla una compuerta

Una compuerta que no da verde **lanza**, no hace `exit`:

| Excepción | Cuándo | Qué ve la persona |
|---|---|---|
| `AccesoDenegado` | rol equivocado, sin permiso de módulo, token CSRF inválido | página 403 con el motivo (JSON 403 en la API); queda una línea `ACCESO_DENEGADO` en el registro, sin traza |
| `SesionRequerida` | no hay sesión, o la cuenta dejó de estar activa | redirección a `/login` con el aviso (JSON 401 en la API); no se anota |

Ambas heredan de `Exception` y **no** de `RuntimeException`, a propósito: los
controladores atrapan `RuntimeException` alrededor de los servicios para mostrar un
aviso, y una compuerta no puede quedar convertida en un aviso por un `catch` que no
estaba pensado para ella. `ManejadorErrores` las traduce en un solo lugar.

### Cuentas por rol (Organizadores y Administradores)

Los apartados `/admin/organizadores` y `/admin/administradores` son la misma pantalla
parametrizada por rol (`AdminController::contextoUsuarios()`): lista, alta, edición,
activar/desactivar, bloquear y desbloquear. **El rol lo fija el apartado, no el
formulario**, y cada apartado rechaza las cuentas de otro rol
(`guardarUsuario()` y `activarODesactivarUsuario()`): antes el de organizadores ponía
el rol «organizador» sin mirar qué cuenta se editaba, y por esa ruta se podía
degradar a un administrador. La lista no ofrece desactivar ni bloquear la cuenta
propia ni la del único administrador activo
(`UsuarioService::unicoAdministradorActivoId()`); el servicio igual lo impide.

### Negar por omisión

Sin fila en `permisos`, el rol no puede nada. Es deliberado: agregar un módulo
nuevo al catálogo no le abre la puerta a nadie hasta que un administrador lo
habilite desde Sistema → Permisos.

El administrador es la única excepción: pasa sin consultar la tabla (§5.1, «control
completo sobre el sistema») y **no se le guardan filas**. Sin esa excepción, un
descuido en la pantalla de permisos dejaría el sistema sin nadie que pueda
entrar a arreglarlo.

### Historia

Hasta septiembre de 2026 la tabla `permisos` existía en el esquema, tenía clave
foránea a `modulos`, índice único y hasta una migración propia
(`2026_06_fk_permisos_modulos.sql`)… y ninguna consulta la leía. El control era
solo por rol, y cuatro restricciones del documento de RNE citaban un
`PermisoService` que no existía. Cubierto por `tests/permisos_test.php`, que
además verifica que las guardas estén puestas en los controladores: que el
servicio decida bien no sirve de nada si nadie lo llama.

## Dónde va cada dato de un torneo

Un torneo guarda sus datos en dos lugares, y la regla para elegir es simple:

| | `torneos` (columnas) | `configuraciones_torneo` (clave/valor) |
|---|---|---|
| Qué guarda | Lo que el sistema **usa para calcular** | Lo que el sistema **muestra o consulta puntualmente** |
| Ejemplos | `puntos_victoria`, `modalidad`, `rondas_suizo`, `fecha_inicio` | `sede`, `contacto`, `cierre_inscripcion`, `observaciones` |
| Tipo | Tipado por el motor (`TINYINT`, `ENUM`, `DATE`) | `TEXT`, validado en la capa de servicio |
| Obligatoriedad | Tienen default y siempre hay valor | Opcionales; ausente y vacío son lo mismo |
| Agregar uno | Migración + `ALTER TABLE` | Una entrada en `ConfiguracionTorneoService::CLAVES` |

**La puntuación no va en la tabla clave/valor.** `puntos_victoria`, `puntos_empate`
y `puntos_derrota` ya son columnas de `torneos`, configurables por torneo desde
el formulario y consumidas por `TablaPosicionesService`. Moverlas a
`configuraciones_torneo` sería cambiar tres enteros tipados por tres strings sin
default: peor diseño, no mejor.

### El catálogo de claves

El riesgo de una tabla clave/valor es que se vuelva un cajón de sastre donde cada
quien escribe la clave que se le ocurre, y después nadie sabe qué hay adentro.
Por eso las claves válidas se declaran en `ConfiguracionTorneoService::CLAVES`,
con etiqueta, tipo, máximo y texto de ayuda. Una clave que no esté en el catálogo
se ignora al guardar.

Ese catálogo es además la única fuente del formulario y de la ficha pública:
agregar una clave nueva es editar la constante, sin tocar ninguna vista.

### `cierre_inscripcion` no es decorativo

`InscripcionService` lo consulta antes de anotar a alguien y rechaza la
inscripción si el plazo venció, en las dos modalidades. Tres detalles de la regla:

- **El último día cuenta.** Un torneo que cierra el 20 acepta inscripciones
  durante todo el 20; recién el 21 está vencido.
- **Sin fecha no hay plazo.** La clave es opcional y su ausencia no puede cerrar
  un torneo.
- **En `borrador` no se aplica.** El torneo todavía se está armando y no es
  público, así que una fecha de cierre ahí no significa nada.
- **Cerrar el plazo impide anotarse, no bajarse.** `desinscribir()` sigue
  funcionando: alguien que ya no va a competir tiene que poder salir de la lista.

Y el cierre no puede ser posterior a `torneos.fecha_inicio`: una fecha así no
querría decir nada, porque para entonces el torneo ya arrancó.

Cubierto por `tests/configuracion_torneo_test.php`.

## Orden de la tabla de posiciones

Un solo comparador, `TablaPosicionesService::comparar()`, y lo usan los dos
lugares que necesitan el criterio: el orden de la tabla y la decisión de si hay
que jugar un desempate.

```
puntos → [diferencia → puntos a favor] → partidos ganados → buchholz → id
```

Los dos criterios entre corchetes solo cuentan si el torneo tiene
`usa_puntos_favor`. Se apagan cuando el marcador no mide rendimiento: en ajedrez
se anota 1, medio punto o 0, así que «puntos a favor» es una copia de «puntos» y
ordenar por eso no agrega nada. En fútbol, en cambio, la diferencia de goles es
el desempate de toda la vida. Apagado el criterio, la diferencia se sigue
calculando y mostrando; lo único que cambia es que no ordena.

El id al final no es un criterio deportivo: es lo que hace que el orden sea
estable y reproducible cuando ya no queda nada que comparar. Por eso
`hayEmpateEnCima()` lo excluye —un campeonato no puede decidirse por el id más
chico— y cuando los dos primeros empatan en todo lo demás, el sistema crea una
ronda «Desempate N» en vez de coronar a alguien.

### Por qué el mismo comparador en los dos lados

Si el orden y la decisión del campeón usaran criterios distintos, la tabla
mostraría un líder que el sistema no reconoce como tal, o al revés. Con
`usa_puntos_favor = 0`, dos participantes con distinta diferencia de goles están
igual de empatados que si la tuvieran igual, porque para ese torneo la
diferencia no es un criterio.

### La trampa de `isset()` con las casillas

`usa_puntos_favor` llegaba al servicio como `isset($d[...]) ? 1 : 0`. Ese idiom
sirve cuando el formulario **siempre** manda el campo, y acá no lo mandaba: el
valor llegaba `null`, `isset(null)` es `false`, y todo torneo guardado desde la
app terminaba en 0 pisando el `DEFAULT 1` del esquema. Peor aún, si el campo
hubiera llegado como `"0"`, `isset("0")` es `true` y guardaba **1**: el idiom
estaba mal en tres de los cuatro casos posibles.

El formulario manda ahora un `<input type="hidden" value="0">` delante de la
casilla, con el mismo `name`. Así el campo llega siempre: si la casilla está
tildada, su valor pisa al del hidden. Y el servicio distingue «vino en 0» de
«no vino», que es lo que le deja respetar el default del esquema cuando el
torneo lo crea un script.

Cubierto por `tests/puntos_favor_test.php`.

## Estados reversibles

Participantes, equipos y organizadores usan un **toggle activo↔inactivo** (`toggleActivo()` en cada servicio). Es un soft-state: no borra historial (inscripciones/resultados se conservan); reactivar restaura la disponibilidad. El estado fino (`suspendido`) sigue disponible en el formulario de edición.

## Frontend sin código en línea

Hasta octubre de 2026 las vistas llevaban 287 atributos `style="…"`, 7 bloques `<script>` y 25 manejadores
`onclick/onsubmit="…"`, y había una sola hoja de 591 líneas. Hoy no queda nada de eso en el HTML, y las páginas se ven
idénticas (se comprobó píxel por píxel).

**Hojas de estilo** (`public/assets/css/`, en el orden de `View::HOJAS`; cada una es un tramo del archivo original, en su
mismo orden, así la cascada no cambió):

| Archivo | Qué tiene |
|---|---|
| `tokens.css` | Las variables del diseño: paleta, radio, sombra, ancho de la barra lateral. |
| `base.css` | Reinicio, tipografía, `[hidden]`, enlace «Saltar al contenido», navegación pública, hero, armazón del panel, pie y avisos. |
| `componentes.css` | Botones, chips, tarjetas, tablas, formularios, diálogos, avatares, listas con filtros y paginación, desplegable de búsqueda. |
| `paginas.css` | Lo de una pantalla: acceso, bracket (con su menú de acciones), calendario, próximos partidos. |
| `responsive.css` | Los dos cortes Mobile First (721 y 981 px), después de todo lo demás. |
| `utilidades.css` | Lo que antes eran estilos en línea: utilidades de una declaración (`.mb-1`, `.flex`, `.fs-80`…, con `!important`, como pesaba el estilo en línea), ajustes con nombre (`.banner-campeon`) y los porcentajes `.pct-0` … `.pct-100`. |
| `impresion.css` | Se carga con `media="print"`: es lo último que se aplica al imprimir. |

`partials/estilos.php` las carga con `View::asset()` (versión por fecha de modificación) y, si la página calculó estilos
(`View::claseDinamica()`), imprime el bloque `<style nonce>`.

**Scripts** (`public/assets/js/`; cada pantalla pide los suyos por `extraJs` en su controlador):

| Archivo | Para qué |
|---|---|
| `app.js` | Menús, avisos que se esfuman, `data-confirmar` (confirmar antes de enviar) y `data-imprimir`. En todas las páginas. |
| `modales.js` | Abrir/cerrar diálogos (`data-abrir-modal`, `data-cerrar-modal`, `data-cierra-fuera`), con foco, Tab atrapado, Escape y retorno del foco. |
| `gestion-torneo.js` | Completa y abre los diálogos de cargar, corregir y programar (los dos paneles usan el mismo). |
| `bracket.js` | El menú de acciones de las tarjetas del bracket. |
| `formato.js` | `FlexFormato`: cómo se escriben fechas, rangos y estados en el navegador, en un solo lugar. Los estados repiten `View::estadoChip()` (PHP) y un test los compara uno por uno. Va **antes** de los scripts que lo usan en `extraJs` (un test lo vigila). |
| `correcciones.js`, `registro.js`, `proximos-partidos.js` | Cada una, su pantalla. |
| `torneos-en-vivo.js` | La búsqueda en vivo de la lista pública (`/torneos`). Va **después** de `formato.js` y `api.js`. Ver «Búsqueda en vivo» más abajo. |
| `calendar.js` | El calendario público (`/torneos/calendario`): lee los torneos del bloque `#torneos-cal` y dibuja un mes por vez, con cada torneo en el día en que empieza. Todo por clases (`cal-*`), sin estilos en línea; los nombres se escriben con `textContent`. |
| `api.js`, `resultados.js`, `combobox.js`, `torneo-form.js` | Como antes (con `hidden` en lugar de `style.display`). |

**Datos para los scripts**: un bloque `<script type="application/json" id="…">` (el navegador no lo ejecuta, la CSP no
lo toca). Se escribe con `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT`.

**Accesibilidad.** Etiquetas asociadas (`for`/`id`), `aria-label` y `scope="col"` en las tablas, `role="dialog"` en los
diálogos, `<main>`/`<nav>`, enlace «Saltar al contenido», un solo `h1` por página, títulos sin saltear niveles
(`.como-h2`/`.como-h3` dan el aspecto del otro nivel), `role="alert"|"status"` en los avisos, `autocomplete` donde
corresponde. `tests/frontend_estatico_test.php` lo vigila, y `tests/js/interfaz_test.js` prueba los scripts con un DOM
simulado (`node tests/js/interfaz_test.js`).

**Contraste.** El texto blanco de los botones se mide contra todo el fondo, no contra un punto: el botón primario es un
degradado y su extremo claro (`#3498db`) daba 3,15:1, por debajo del mínimo AA (4,5:1). Ahora va de `#1f7ab8` (4,63:1) a
`--primary-dark` (4,89:1), con la misma apariencia de botón azul; `frontend_estatico_test.php` calcula la razón de
contraste de cada extremo y falla si alguno baja de 4,5.

**Calendario público.** La grilla es `repeat(7, minmax(0, 1fr))`: con `1fr` una columna no baja del ancho de su contenido
y un título largo sin cortes ensanchaba el calendario (a 1280 px llegaba a 1612 px y en el celular rompía la página). Cada
día es una celda que puede achicarse (`min-width: 0`) y el título de un torneo se corta con puntos suspensivos (el nombre
completo queda como ayuda al pasar el mouse). Mobile First: hasta 720 px un punto por torneo (hasta tres, decorativos
para los lectores de pantalla) y debajo la lista «Torneos que empiezan este mes» con enlace y fechas, porque en un
cuadrito de 45 px no entra un título; desde 721 px el día muestra sus torneos como tarjetas con enlace y la lista se oculta.

**Búsqueda en vivo (`/torneos`).** Antes, cada letra escrita en «Buscar» (o cada cambio de «Formato» y «Estado») pedía
apretar el botón y recargaba la página entera. Ahora `torneos-en-vivo.js` actualiza solo la lista, con los datos de
`GET /api/torneos`, que usa la misma consulta que el servidor (`TorneoModel::buscarPublicos()`). Es una **mejora y no un
reemplazo**: el formulario sigue siendo un GET común, con su botón y su dirección copiable, y sin JavaScript funciona igual.

- Al tipear espera 300 ms de calma (no manda una consulta por tecla) y cancela con `AbortController` la que siga en camino.
- Cada pedido lleva un número y solo el último dibuja: una respuesta vieja nunca pisa a una nueva.
- Mientras espera, la lista se atenúa (`.cargando`) y queda `aria-busy`. Los lectores de pantalla oyen cuántos torneos hay
  (`#estado-lista`, un `role="status"`). La dirección del navegador se actualiza con `history.replaceState`.
- Si el pedido falla, la lista queda como estaba y arriba aparece el motivo (`.alerta-lista`, `role="alert"`).
- Dibuja el **mismo marcado** que el servidor (`app/views/public/torneos.php` y `app/views/partials/paginacion.php`): mismas clases, mismo texto,
  incluido el espacio entre el formato y la modalidad. Todo dato entra con `textContent`, nunca como HTML, y no hay
  estilos en línea (la CSP no los admite). Lo vigilan `tests/frontend_estatico_test.php` (las clases que dibuja existen
  en las hojas y coinciden con las de la vista), `tests/js/torneos_en_vivo_test.js` (cuándo pide, qué dibuja, respuestas
  tardías, cancelación, fallas) y `09_busqueda_en_vivo` de Cypress, que compara el texto de la lista en vivo con el de la
  misma dirección abierta de cero. Se comparó además píxel por píxel contra la lista del servidor: idéntica.

**Caché y compresión.** Las hojas y los scripts se piden con `?v=<fecha de modificación>` (`View::asset()`): cuando el
archivo cambia, su dirección cambia. `public/.htaccess` los guarda **un año** (`Cache-Control: public, max-age=31536000,
immutable`) solo cuando la consulta es exactamente `?v=<número>`; sin versión (o con otra consulta) valen los 7 días de
siempre. Apache comprime (`mod_deflate`) el HTML, el CSS, el JavaScript y el JSON.

## Maquetado (Mobile First)

Las hojas de `public/assets/css/` están escritas con la filosofía **Mobile First**
que pide la letra: las reglas base son las de pantalla chica y los media queries
solo **agregan** lo que hace falta a medida que hay más ancho disponible. No hay
ningún `@media (max-width: …)`.

Los cortes son dos:

| Corte | Qué entra |
|-------|-----------|
| `min-width: 721px` | Navegación pública horizontal (se esconde el botón ☰), formularios a dos columnas, topbar en fila. |
| `min-width: 981px` | Grillas de 2/3/4 columnas, hero a dos columnas, fila de KPIs, y la barra lateral deja de ser un cajón deslizante para ocupar su propia columna. |

Hay además un `min-width: 920px` para el menú emergente del bracket, que ya
estaba escrito en este estilo.

Antes había tres cortes `max-width` (980, 720 y 719) y uno solo `min-width`.
Los de 720 y 719 se diferenciaban en un único píxel y se unificaron en 721.

Dos detalles que conviene no perder de vista al tocar este archivo:

- El orden importa. `@media (min-width: …)` no aumenta la especificidad: entre
  dos reglas que aplican con la misma especificidad gana la última del archivo.
  Por eso el override de `.bk-action-menu` está **después** de su regla base y no
  junto a los demás cortes de 721px.
- La barra lateral tiene `transition: transform`. Si se mide con
  `getComputedStyle` justo después de cambiar el ancho, se obtiene el valor
  intermedio de la animación y no el final.

Flexbox y Grid se usan en todo el maquetado (13 y 10 usos respectivamente).

## Manejo de errores y respuestas JSON

Todo lo que falla termina en un solo lugar y deja rastro. Antes, en producción, el error se ocultaba
con `error_reporting(0)` (que además apagaba el registro), una excepción sin atrapar daba una página
en blanco, y los controladores mostraban el mensaje de **cualquier** `RuntimeException`, incluida la de
la base: un nick demasiado largo terminaba en pantalla como «SQLSTATE[22001]: Data too long…».

**Dos clases de error.** `ErrorNegocio` es una regla que no se cumplió y su mensaje está escrito para la
persona («Ya existe un equipo con ese nombre»); los servicios lanzan solo esa (`RuntimeException` a secas
ya no se usa en `app/services`). Todo lo demás —un `PDOException`, un tipo equivocado, un archivo que no se
puede escribir— es un error técnico. `ErrorNegocio` hereda de `RuntimeException`, así que los `catch`
existentes siguen atrapándolo.

| Quién lo atrapa | Qué ve la persona | Qué queda en el registro |
|---|---|---|
| Un controlador, con `BaseController::mensajeDe()` | el mensaje, si es `ErrorNegocio`; si no, «Ocurrió un error inesperado y ya quedó registrado. (código a1b2c3d4)» | los errores técnicos, con ese código |
| Nadie: lo atiende `ManejadorErrores`, enganchado en `public/index.php` | una página con el diseño del sitio y el código de referencia (`app/views/shared/error.php`); si el layout no se puede dibujar, una página mínima; si el pedido es de la API o de un fetch, JSON | siempre |
| Un error fatal de PHP (memoria agotada…) | la página mínima con un código que se enlaza con la línea que PHP escribió | siempre |

En desarrollo (`APP_ENV=development`) la página incluye además el detalle técnico; nunca en producción.

**El registro** (`/var/log/apache2/php_errors.log`) lleva por cada error: el código, la clase, el mensaje,
el archivo (relativo al proyecto), el pedido (método, ruta **sin** query string, IP y usuario) y la traza
**sin los argumentos** de cada llamada —con ellos, el comienzo de una contraseña que pasó por
`password_verify()` quedaba escrito—, más las causas encadenadas. Dos detalles de despliegue que importan:
`error_reporting` ya no se apaga nunca (`config/app.php`: solo cambia si se muestra en pantalla), y el
entrypoint deja el archivo en manos de `www-data`; los scripts de arranque lo creaban como root y Apache,
que corre PHP como `www-data`, no podía escribirlo.

**Respuestas JSON** (`Respuesta`): un único formato para la API y los fetch.

```json
{"ok": true,  "datos": [...], "meta": {"pagina": 1, "total": 40}}
{"ok": false, "error": {"codigo": "no_encontrado", "mensaje": "El recurso que buscás no existe.", "id": "a1b2c3d4"}}
```

`codigo` es estable y legible por un programa (`peticion_invalida`, `sin_permiso`, `no_encontrado`,
`error_interno`…), `mensaje` es para una persona e `id` aparece en los errores técnicos. Un pedido es «de
API» si su ruta empieza con `/api`, si lo hace un fetch (`X-Requested-With`) o si pide JSON y no HTML
(`ManejadorErrores::esPeticionApi()`).

**Router.** `_method` solo admite `PUT`, `PATCH` y `DELETE` (`Router::metodoEfectivo()`); antes un POST con
`_method=GET` se despachaba como GET, por rutas que no piden token CSRF. Un error de despacho (una ruta que
apunta a un controlador que no existe) se lanza y lo atiende `ManejadorErrores` en vez de mostrar la
página de «no encontrado».

**Largos.** `Validator::assertLargos()` avisa qué campo es demasiado largo (con el largo real de la
columna) antes de tocar la base; lo usan el registro y los formularios de participante, usuario, equipo y torneo.

Cubierto por `tests/manejador_errores_test.php` y `tests/validator_test.php`.

## Sesión y acceso

**La cuenta manda.** `Auth::isLoggedIn()` ya no se conforma con que la sesión tenga un `user_id`: la
primera vez que se la usa en un pedido compara con la fila de `usuarios` (una consulta por clave primaria,
una sola por pedido). Si la cuenta no está `activa` o no existe, se cierra la sesión, se deja un aviso en
pantalla y los cambios que siguen dejan de atribuirse a esa cuenta (`Auth::motivoDeExpulsion()` decide el
texto). Si está en regla, el rol, el nombre y el email de la sesión se ponen al día con los de la base, así
que un cambio de rol se nota en el pedido siguiente.

**Vida de la sesión.** `Session::evaluarVida()` decide, sobre el contenido de la sesión y la hora, si está
`vigente`, si toca `regenerar` el identificador (cada 15 minutos) o si `vencio` por inactividad (60
minutos). `Session::start()` lo aplica en cada pedido.

**Inicio de sesión** (`AuthService::login()`), en orden:

1. Si la IP ya gastó sus intentos (`IntentoLoginModel::contarPorIp()`), se rechaza sin mirar ninguna cuenta.
2. Si el email no existe: se anota el intento y se verifica la clave contra un hash falso (mismo tiempo).
3. Si la cuenta está bloqueada: si el bloqueo tenía fecha de fin y ya pasó, se desbloquea sola y sigue; si no,
   se la rechaza diciendo hasta cuándo.
4. Si la clave es incorrecta: se anota el intento (por IP y por cuenta) y, al llegar al máximo, se bloquea
   una cuenta **activa** durante `login_minutos_bloqueo`.
5. Si todo está bien pero la cuenta no está activa (pendiente, rechazada…), se avisa sin iniciar sesión.

`login()` no va en una transacción a propósito (ver «Transacciones»).

**Ajustes.** Los límites salen de `configuracion_sistema` por `ConfiguracionSistemaService`, que los lee una
vez por pedido y reemplaza por el valor de fábrica lo que falte o no sirva:

| Clave | Fábrica | Qué es |
|---|---|---|
| `login_max_intentos` | 5 | Fallos seguidos antes de bloquear la cuenta |
| `login_minutos_bloqueo` | 15 | Duración del bloqueo; 0 = hasta que un administrador la desbloquee |
| `login_max_intentos_ip` | 20 | Fallos de una IP dentro de la ventana antes de rechazarla |
| `login_ventana_minutos` | 15 | Ventana sobre la que se cuentan los fallos por IP |

Mientras no exista la pantalla de configuración se cambian con un `UPDATE` sobre esa tabla.

**Bloquear y desbloquear.** `UsuarioModel::lockAccount()` solo actúa sobre una cuenta `activa` y
`UsuarioModel::unlockAccount()` solo sobre una `bloqueada`; devuelven si cambiaron algo. El bloqueo con fecha
de fin guarda `usuarios.bloqueada_hasta`; sin fecha (0 minutos, o bloqueo manual) queda en `NULL`. La cuenta y
su perfil de participante cambian juntos. `UsuarioService` agrega las reglas de los administradores: el
único administrador activo no se bloquea, desactiva ni degrada, y nadie hace eso con su propia cuenta.

Cubierto por `tests/sesion_test.php` y `tests/login_limites_test.php`.

## Seguridad

Ver `docs/documentacion_seguridad.md` y `docs/owasp.md`.

Nota PDO: con `ATTR_EMULATE_PREPARES = false` (prepares nativos) **no se puede reusar un placeholder con nombre** en una misma query (causa `SQLSTATE[HY093]`). Usar placeholders distintos (`:ga`/`:gb`) cuando el mismo valor aparece dos veces.

## Docker

```yaml
# Servicios en docker-compose.yml
app:  PHP 8.2 + Apache (puertos 8080 HTTP / 443 HTTPS)
db:   MySQL 8.0        (sin publicar al host — solo accesible dentro de flexarena_net)
pma:  phpMyAdmin       (127.0.0.1:8081 — solo desde la propia máquina, no desde la red)
```

El schema y seed se cargan automáticamente vía `docker-entrypoint-initdb.d/`.

### Arranque del contenedor `app`

`docker/docker-entrypoint.sh` hace, en orden:

1. Prepara el certificado SSL (real o autofirmado).
2. Espera a que la base acepte conexiones reales.
3. `database/aplicar_privilegios.php` (como administrador de la base): deja al usuario de la
   aplicación con el mínimo privilegio. Se repite en cada arranque; es idempotente.
4. `database/seed_demo.php` (como administrador, porque hace `TRUNCATE`): solo la primera vez.
5. `database/inicializar_cuentas.php`: asigna `ADMIN_PASS` y `DEMO_PASS` a las cuentas que siguen sin clave.
6. Borra del entorno `DB_ROOT_PASS`, `ADMIN_PASS` y `DEMO_PASS` y recién entonces lanza Apache.

### Respaldos

`scripts/backup.sh` corre `mysqldump` dentro del contenedor `db`, como root y con la clave que el
propio contenedor ya tiene (`MYSQL_ROOT_PASSWORD`): no hace falta copiarla al host. La cuenta de la
aplicación no sirve para esto —por mínimo privilegio no puede ver las vistas ni los triggers, y el
volcado falla— y un respaldo que se saltara esas partes restauraría una base sin vistas y sin
historial. El volcado incluye los triggers; al restaurar, mysqldump los crea **después** de los datos
de cada tabla, así que restaurar no genera filas de historial. El respaldo se verifica antes de darlo
por bueno (`scripts/lib_dump.sh`) y `scripts/restore.sh` aplica el mismo criterio antes de tocar la base;
también restaura como root dentro del contenedor, con la clave del propio contenedor.

HTTPS lo maneja `mod_ssl` directo en el contenedor `app` (sin proxy externo): usa el
certificado real de `ssl/certificate.crt` + `ssl/private.key` si está presente, o genera uno
autofirmado en su defecto (`docker/docker-entrypoint.sh`). Despliegue en AlmaLinux vía
`scripts/install_almalinux.sh` (instala Docker, SELinux, firewalld — ver README).

## Pruebas automáticas

Hay cuatro capas, de lo más chico a lo más grande. Cómo correrlas está en el README → Tests; acá, cómo están armadas y qué
encontraron.

| Capa | Dónde | Qué prueba |
|---|---|---|
| Integración (PHP) | `tests/*_test.php`, `tests/run.php` | Los servicios, modelos y controladores reales contra una base descartable (`flexarena_test`), un proceso por archivo. Con `--phpunit` los mismos archivos corren sobre PHPUnit 11. |
| Scripts del navegador | `tests/js/*.js` | Cada script de `public/assets/js/` **ejecutado** con un DOM, un `fetch` y un reloj simulados (`tests/js/dom_falso.js`): sin instalar nada. |
| De punta a punta | `tests/e2e/` | La aplicación en un navegador real (Cypress 16), contra un entorno **aislado** (`docker-compose.e2e.yml`) para que lo que escriben —usuarios, torneos, resultados— no toque los datos de la instalación normal. |
| Mutaciones | (no se versionan) | Cada test se verificó rompiendo a propósito lo que cuida —un `sed` sobre el código, restaurado siempre— y comprobando que falla. Un test que sigue verde con el código roto no vigila nada. |

### PHPUnit sin Composer

`tests/lib/TestCase.php` elige la base según quién corra (`TestCaseSolo.php` o `TestCasePhpunit.php`), así que los tests no cambian.
PHPUnit exige que el archivo se llame como su clase y estos no (`paginador_test.php`): `tests/run.php --phpunit` arma, por cada clase, un
archivo con el nombre esperado en un directorio temporal que carga el test y lo subclasifica (por eso las clases no son `final`). El `.phar`
se instala con `scripts/instalar_herramientas_de_prueba.sh`, que **verifica la firma GPG** de su autor antes de dejarlo usar, y se copia al disco
local del contenedor (leerlo del montaje de Windows tarda ~15 s por arranque). `phpunit.xml` es estricto: un caso sin aserciones, una
advertencia, un aviso o una obsolescencia de PHP hacen fallar la corrida.

PHPUnit **no instala su manejador de errores si ya hay uno registrado**. `tests/bootstrap.php` instalaba el suyo, así que durante un tiempo
ningún aviso de PHP dentro de un test llegaba a ningún lado; ahora solo lo instala el runner propio.

### De punta a punta

- **Entorno aislado.** `docker-compose.e2e.yml` levanta otra copia de la aplicación (mismo código y misma imagen) sobre su propia base, con
  volúmenes, red y puertos propios (`https://localhost:8643`) y sin datos de demostración. La base arranca con el fsync relajado: la primera
  carga baja de ~6 minutos a ~4. `bash scripts/e2e.sh bajar` la borra.
- **Contraseñas.** Salen del `.env` por una tarea de Node (`cy.task('credencial')`), no por la configuración de Cypress (que se muestra), y se
  escriben con `{log: false}`.
- **La política de seguridad.** Por defecto Cypress **elimina** la cabecera `Content-Security-Policy` de cada respuesta; con eso un `<script>`
  en línea correría y la vigilancia de violaciones (`securitypolicyviolation`) jamás vería nada. `experimentalCspAllowList` le pide que conserve
  las directivas de scripts: la mutación «un script en línea en el login» pasó sin ser detectada hasta que se activó.
- **Sin reintentos.** Los recorridos tienen estado (crean torneos, aprueban solicitudes): repetirlos a mitad de camino solo confunde.
- **Contador de ingresos fallidos.** La aplicación rechaza lo que pasa de 20 intentos por IP; `scripts/e2e.sh probar` lo vacía antes de cada corrida
  (es una base descartable).
- **Electron.** El de Cypress se cae al arrancar en algunos Windows (código `0x80000003`): se lo lanza con `--disable-gpu --no-sandbox`.
- **En una máquina cargada.** Una corrida completa pasa de unos 10 minutos a casi una hora, y los tiempos de Cypress se quedan cortos sin que
  la aplicación falle (el servidor responde en 0,3 s). Tres cosas lo resuelven, y ninguna toca los casos: `E2E_HOST=127.0.0.1` (con
  `localhost` el sistema prueba primero IPv6, donde Docker no escucha, y cada conexión nueva espera 250 ms o más), `E2E_TIEMPO_COMANDO` y
  `E2E_TIEMPO_PAGINA` (lo que Cypress espera a un comando y a la carga de una pantalla; por defecto 8000 y 30000 ms).
- **La sesión guardada se comprueba.** El servidor cambia el identificador de una sesión iniciada cada 15 minutos; la que guardó `cy.session`
  es la de cuando se inició. En una corrida larga dejaba de valer y el caso siguiente caía en el login (cinco casos de una misma
  especificación fallaron así). `iniciarSesion` pasa un `validate` que pide el panel del rol: si ya no abre, Cypress inicia sesión de nuevo.
- **Conexiones persistentes.** Apache cierra a los 5 segundos una conexión sin uso y el proxy de Cypress la reutiliza igual: el primer POST de
  resultados de una especificación quedaba colgado hasta que Cypress se rendía. Solo en el entorno aislado, `docker/e2e-keepalive.conf`
  (montado por `docker-compose.e2e.yml`) sube `KeepAliveTimeout` a 300 s; en producción el valor de siempre es el correcto.

### Qué encontraron las capas nuevas

| Hallazgo | Quién lo encontró | Arreglo |
|---|---|---|
| El aviso «Sesión cerrada correctamente» **nunca se veía**: `AuthController::logout()` destruía la sesión y *después* escribía el aviso en ella. Y destruir y empezar otra mandaba dos cookies en una respuesta (una vencida, una nueva), que el navegador de Cypress aplicaba en cualquier orden. | e2e (spec 02) | `Session::reiniciar()`: vaciar y cambiar el identificador (una sola cookie). |
| La corrección **directa** de un resultado aceptaba cualquier motivo no vacío: el formulario pedía 10 caracteres y el servidor no los exigía (la *solicitud* del organizador sí). | e2e (spec 05) | `ResultadoService::MOTIVO_CORRECCION_MINIMO`, usado por las dos vías. |
| Un envío manipulado en Configuración (`campo[]=x`) volvía al formulario como «Array», con un aviso de PHP que, en desarrollo, se escribía dentro de la página. | PHPUnit estricto | `ConfiguracionController::mostrar()` devuelve solo texto. |
| Cuatro tests dibujaban vistas sin las variables que inyecta el controlador, y otro tenía un `$accion»` (el `»` cuenta como parte del nombre de la variable) en un mensaje. Pasaban por el manejador de errores que escondía los avisos. | PHPUnit estricto | Los tests completan lo que falta. |
| En la lista en vivo, la modalidad quedaba 4 px corrida: el HTML del servidor tiene un espacio entre dos etiquetas y el DOM armado por el script no. | Comparación píxel a píxel contra el servidor | Un nodo de texto con el espacio. |
| Un nombre de torneo de 80 letras **sin espacios** ensanchaba la tarjeta y la página entera (en el celular sacaba todo de pantalla), en la lista pública, las fichas, el panel del organizador y el tablero. | e2e (spec 07 crea a propósito un torneo así) | `.rompe-texto` en los títulos y tarjetas que muestran ese texto. El primer intento, `overflow-wrap: anywhere` en todas las tarjetas, partía palabras normales («Semifinale/s», «Garcí/a»): lo mostró la comparación píxel a píxel, y por eso la clase va solo donde hace falta. |

### Trampas conocidas al mutar

- Restaurar un archivo con `mv` le devuelve su fecha **vieja**: el OPcache de Apache (que revisa por fecha) puede quedarse con el código mutado.
  Los scripts de mutación hacen `touch` después de restaurar.
- `sed` no ve saltos de línea (`:a;N;$!ba;s/…\n…/…/` sí), y un heredoc de Bash se come las barras invertidas: los scripts con barras van por un archivo.
