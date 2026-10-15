# Documentación de Seguridad — FlexArena

## Riesgos identificados y medidas aplicadas

| Riesgo | Medida |
|--------|--------|
| SQL Injection | PDO con consultas preparadas (`prepare` + `execute`) en todos los modelos |
| XSS | `View::e()` aplica `htmlspecialchars` en todas las salidas al usuario |
| CSRF | Token único por sesión, validado en todos los formularios POST (`Csrf::checkOrFail()`) |
| Autenticación débil | `password_hash(PASSWORD_BCRYPT, cost:12)` + `password_verify` |
| Sesión fija (fixation) y robo de la cookie | `session_regenerate_id(true)` en el login y cada 15 minutos mientras haya una sesión iniciada; la sesión vence a los 60 minutos sin actividad (`Session::evaluarVida()`) |
| Cuenta bloqueada, desactivada o con otro rol que sigue operando | `Auth` compara la sesión con la cuenta de la base en cada pedido (una consulta por clave primaria): el rol y los datos los decide la base, y una cuenta que no está activa pierde la sesión con un aviso |
| Fuerza bruta contra una cuenta | Bloqueo temporal tras `login_max_intentos` fallos (5), que vence solo a los `login_minutos_bloqueo` (15) |
| Fuerza bruta repartida entre muchas cuentas | Límite por IP: `login_max_intentos_ip` (20) fallos en `login_ventana_minutos` (15) y se rechaza esa IP antes de mirar ninguna cuenta (`intentos_login`) |
| Enumerar cuentas midiendo el tiempo de respuesta | Con un email inexistente la clave se verifica contra un hash falso: tarda lo mismo que una clave incorrecta |
| Dejar al sistema sin administrador | No se puede bloquear, desactivar ni quitarle el rol al único administrador activo, y su bloqueo automático nunca es permanente |
| Acceso no autorizado | `Auth::requireRole()` en cada acción sensible del controlador |
| Exposición de errores | `display_errors = Off` en producción; errores van a log interno |
| Credenciales expuestas | Variables de entorno vía `.env` (excluido del repositorio) |
| Contraseñas publicadas en el repositorio | `seed.sql` no trae ninguna contraseña ni hash: cada cuenta nace sin clave y el arranque asigna `ADMIN_PASS` / `DEMO_PASS` (`database/inicializar_cuentas.php`) |
| Usuario de la base con demasiado poder | La aplicación solo lee y escribe filas, tabla por tabla; la auditoría es de solo `INSERT`; sin `DROP`/`ALTER`/`CREATE` (`database/aplicar_privilegios.php`) |
| Credencial de administración al alcance del código web | La usa solo el arranque del contenedor y se borra del entorno antes de lanzar Apache; `public/index.php` importa del `.env` únicamente las claves que la aplicación usa |
| Datos imposibles escritos por fuera de la aplicación | Índices únicos y `CHECK` en el esquema: equipo o documento repetido, puntos negativos, torneo que termina antes de empezar, inscripción con dos competidores |
| Clickjacking | Header `X-Frame-Options: SAMEORIGIN` vía `.htaccess` |
| Sniffing de tipo | Header `X-Content-Type-Options: nosniff` |
| Redirección abierta | `Url::interna()` acepta solo rutas del propio sitio; `redirect()` lo aplica siempre |
| URL con parámetros basura | Los `{id}` de las rutas se validan como enteros positivos: si no, es 404 (`Router::PATRONES`) |
| Índices en tablas | Email, estado, torneo_id indexados para evitar full scans |

## Control de acceso por rol

```php
// En cada método de controlador:
$this->requireAdmin();          // solo administrador
$this->requireOrganizador();    // admin + organizador
$this->requireRole(['participante']); // rol específico
```

El middleware verifica la sesión activa y el rol antes de ejecutar cualquier lógica.

## Protección CSRF

Todos los formularios `POST` incluyen:
```html
<input type="hidden" name="csrf_token" value="<?= Csrf::generate() ?>">
```

El servidor valida con `hash_equals()` para prevenir timing attacks.

## Sesiones

- `session.cookie_httponly = 1` (inaccesible desde JavaScript)
- `session.cookie_samesite = Lax`: la cookie no viaja en los POST ni en los recursos de otros sitios,
  que es lo que frena el CSRF, pero sí cuando alguien llega siguiendo un enlace (desde un correo, por
  ejemplo) y no tiene que volver a iniciar sesión. Con `Strict` ese primer pedido llegaba sin sesión.
  Esta documentación decía Strict mientras el código usaba Lax.
- `session.use_strict_mode = 1` (previene session fixation)
- En producción: `session.cookie_secure = 1` (solo HTTPS)
- El identificador se cambia en el login y **cada 15 minutos** mientras haya una sesión iniciada
  (`Session::REGENERAR_CADA_SEGUNDOS`). Antes la documentación decía «cada 30 minutos» pero nada lo hacía.
- La sesión **vence tras 60 minutos de inactividad** (`Session::INACTIVIDAD_SEGUNDOS`): se cierra y la
  persona ve un aviso. Una hora para que un organizador que carga resultados entre partido y partido no
  tenga que entrar a cada rato.
- En cada pedido `Auth` compara la sesión con la cuenta que hay en la base. Si la cuenta ya no está
  `activa` (bloqueada, desactivada, pendiente, rechazada o borrada), la sesión se cierra con un aviso y
  deja de atribuirse cambios a esa cuenta; si lo está, el rol, el nombre y el email de la sesión se
  ponen al día con los de la base.

## Inicio de sesión y bloqueos

- **Por cuenta.** A los `login_max_intentos` fallos seguidos (5) una cuenta **activa** se bloquea durante
  `login_minutos_bloqueo` (15). Cuando vence, la cuenta se desbloquea sola en el siguiente intento y el
  conteo empieza de cero. Con 0, el bloqueo dura hasta que un administrador lo levante (el bloqueo manual
  siempre es así). Antes el bloqueo no vencía nunca.
- **Por IP.** Cada fallo se anota en `intentos_login`. Una IP con `login_max_intentos_ip` fallos (20)
  dentro de `login_ventana_minutos` (15) es rechazada antes de mirar ninguna cuenta, aunque traiga la
  clave correcta; mientras está rechazada no se anotan más intentos, así que el bloqueo no se prolonga
  solo. La IP es la que ve Apache (`REMOTE_ADDR`): no se confía en `X-Forwarded-For`, que escribe el
  cliente y permitiría cambiar de IP en cada intento. Detrás de un proxy hay que hacer que Apache
  reconozca la IP real (`mod_remoteip`), si no todos comparten la del proxy.
- Los tres números salen de `configuracion_sistema`. Un valor ausente o que no sirve (texto, 0 donde el
  mínimo es 1) se reemplaza por el de fábrica: una fila mal escrita no puede dejar el login sin límites.
- Solo se bloquea una cuenta **activa** y solo se desbloquea una **bloqueada**. Antes, fallar la clave de una
  cuenta pendiente de aprobación o rechazada la pasaba a «bloqueada» y desbloquearla la dejaba activa.
- **Administradores.** No se puede bloquear, desactivar ni quitar el rol al único administrador activo,
  y nadie puede bloquearse, desactivarse ni quitarse el rol a sí mismo. Si el ajuste dice «hasta que lo
  desbloqueen», el bloqueo automático del único administrador dura igual 15 minutos: cualquiera que
  conociera su email podría, si no, dejar al sistema sin quien lo administre.
- Límite conocido: cinco intentos bastan para bloquear a alguien por un rato. Es el costo de que un
  atacante no pueda adivinar claves; el límite por IP lo acota para quien ataca desde una sola conexión.

## Roles y permisos

El acceso se decide con **tres comprobaciones independientes**, y las tres tienen
que dar verde. Ninguna reemplaza a las otras:

| Compuerta | Pregunta que responde | Dónde vive |
|---|---|---|
| Rol | ¿Es administrador / organizador / participante? | `Auth::requireRole()` |
| Permiso de módulo | ¿Ese rol puede hacer esa acción en ese módulo? | tabla `permisos` → `PermisoService` |
| Propiedad | ¿Es **su** torneo (o el torneo de **ese** partido)? | `BaseController::requireTorneoOwnership()` y `::requirePartidoPropio()` |

Cuando una de las tres falla, el código **lanza una excepción** (`AccesoDenegado`, o
`SesionRequerida` si falta la sesión) en lugar de cortar el pedido con `exit`. Antes un 403 o
un «iniciá sesión» terminaban el script en medio del controlador: no se podían ejecutar en un
test, y no quedaba anotado quién había intentado qué. Ahora `ManejadorErrores` las convierte
en la página 403 con el motivo (o en JSON 403/401 para la API), deja una línea
`ACCESO_DENEGADO` en el registro y redirige a `/login` si falta la sesión.
`tests/autorizacion_test.php` recorre **toda** la tabla de rutas con cada rol.

A eso se suma `modulos.estado`, que apaga un módulo para **todo** el sistema sin
importar qué diga la matriz de permisos.

Detalles de la tabla `permisos`:

- 4 roles definidos en BD: administrador, organizador, participante, público
- Una fila por combinación rol × módulo, con cuatro acciones: ver, crear,
  editar, eliminar
- **Se niega por omisión**: sin fila, el rol no puede nada. Agregar un módulo
  nuevo no le abre la puerta a nadie por accidente
- El administrador tiene acceso total sin pasar por la tabla, y **no se le
  guardan filas**: es lo que impide dejarlo sin acceso editando la matriz
- La matriz se edita desde el panel (Sistema → Permisos) y cada cambio queda
  auditado con el estado anterior y el nuevo
- El contenido sembrado reproduce la sección 5 de la letra del proyecto.
- «Corregir resultados **si cuenta con autorización**» (§5.2) se resuelve con el
  flujo de solicitudes, no con una casilla: el organizador **solicita** la
  corrección de un partido **de su torneo** (`resultados / editar` es lo que le
  permite hacerlo) y un **administrador** la aprueba o la rechaza. Aplicar una
  corrección directamente es solo del administrador y no depende de la matriz.
  Antes alcanzaba con tener la casilla: un organizador podía mandar un POST a
  `/admin/resultados/corregir` y cambiar un resultado sin aprobación, y podía
  solicitar correcciones sobre los partidos de **cualquier** torneo. Las dos
  cosas están cerradas en el controlador y también en el servicio, que no
  depende de la sesión (`ResultadoService::corregir()`,
  `CorreccionService::solicitar()`)

## Contraseñas iniciales y privilegios de la base

**Contraseñas.** `database/seed.sql` no contiene contraseñas ni hashes: cada cuenta nace con
`password_hash = '!sin-clave-definida'`, que no es un hash válido y por lo tanto ninguna clave lo
verifica. El primer arranque del contenedor `app` asigna `ADMIN_PASS` al administrador (o una
aleatoria que imprime una sola vez) y `DEMO_PASS` a las cuentas de demostración, siempre que
cumplan la política de contraseñas; solo toca cuentas que siguen sin clave. Cada asignación queda
en la auditoría (sin guardar la contraseña). Volver a correr `seed.sql` no pisa las contraseñas
ya asignadas.

**Privilegios.** La imagen oficial de MySQL le da `ALL PRIVILEGES` al usuario de la aplicación.
`database/aplicar_privilegios.php` lo deja con lo que la aplicación usa de verdad, en cada
arranque:

| Tablas | Privilegios | Por qué |
|---|---|---|
| Las de negocio (usuarios, torneos, resultados…) | `SELECT`, `INSERT`, `UPDATE`, `DELETE` | las administra la aplicación |
| `roles`, `tipos_torneo` | `SELECT` | catálogos: solo se cargan con el seed |
| `modulos` | `SELECT`, `UPDATE` | se encienden y apagan, no se crean ni se borran |
| `configuracion_sistema` | `SELECT`, `INSERT`, `UPDATE` | se edita, no se borra |
| `auditoria` | `SELECT`, `INSERT` | evidencia: no se edita ni se borra |
| `intentos_login` | `SELECT`, `INSERT`, `DELETE` | registro operativo que se purga solo |
| `historial_bd` | `SELECT` | el historial de la base: lo escriben los triggers con los privilegios de quien los creó; la aplicación no puede falsificarlo |
| Las vistas `v_*` | `SELECT` | son de lectura: no se escribe a través de ellas |

Nunca se concede `CREATE`, `DROP`, `ALTER`, `INDEX`, `REFERENCES`, `TRIGGER`, `LOCK TABLES`,
`GRANT OPTION` ni nada a nivel global. Una inyección SQL o una credencial filtrada no puede borrar
tablas, cambiar la estructura ni reescribir el registro de auditoría. `tests/privilegios_test.php`
lo comprueba conectándose como ese usuario.

La credencial de administración (`DB_ROOT_PASS`) la usa únicamente el arranque del contenedor, que
la borra del entorno antes de lanzar Apache: ni `getenv()` ni `/proc/<pid>/environ` del proceso web
la tienen.

**Respaldos.** `scripts/backup.sh` vuelca como root *dentro* del contenedor `db`, con la clave que el
contenedor ya tiene (`MYSQL_ROOT_PASSWORD`): no se copia al host ni viaja por la línea de comandos. No
usa la cuenta de la aplicación porque, por mínimo privilegio, esta no puede ver las vistas
(`SHOW VIEW`) ni los triggers (`TRIGGER`): con ella el volcado falla, y un respaldo que se saltara esas
partes restauraría una base sin vistas ni historial. `scripts/restore.sh` también restaura como root
dentro del contenedor. `tests/backup_restore_test.sh` exige que el volcado vaya como root y con los
triggers, y que ninguno de los dos scripts necesite claves en el host.

**Exposición de puertos.** La base de datos no publica ningún puerto. phpMyAdmin —una consola de
administración de esa base— se publica solo en `127.0.0.1`: funciona desde la propia máquina y no
responde desde la red; para usarlo contra un servidor, un túnel SSH. Antes se publicaba en todas las
interfaces. `tests/exposicion_test.php` lo vigila.

## Errores y registro

- Los errores **siempre** se registran (`log_errors`), también en producción; lo único que cambia con el
  entorno es si se muestran en pantalla (nunca, fuera de desarrollo).
- La persona ve un mensaje solo si es un `ErrorNegocio` (una regla de negocio, escrita para mostrarse). Un
  error técnico —por ejemplo un fallo de la base— se registra con un código de referencia y se le muestra un
  aviso genérico con ese código, para que lo pueda informar sin ver nombres de tablas ni consultas.
- El registro lleva el pedido sin query string y la traza sin argumentos: ni los datos del pedido ni una
  contraseña que pasó por una función terminan en el archivo.
- `php_errors.log` es escribible por `www-data`: los scripts de arranque lo creaban como root y el proceso
  web no podía registrar nada.
- `_method` solo admite `PUT`, `PATCH` y `DELETE`: un POST no puede reclasificarse como GET.

## Auditoría

Se registran todas las acciones críticas:
- Logins exitosos y fallidos
- Creación/edición/eliminación de usuarios, participantes, equipos
- Creación/configuración de torneos
- Generación de fixture/bracket/rondas
- Carga y corrección de resultados
- Asignación de campeón
- Cambios de estado de torneos

Cada registro incluye: usuario, acción, tabla afectada, ID del registro, descripción, IP, user agent, timestamp.

**Historial de la base.** Además de la auditoría, la tabla `historial_bd` registra cada `INSERT`,
`UPDATE` y `DELETE` de las tablas de datos, con el registro antes y después y quién lo hizo, incluso si
el cambio no pasó por la aplicación (un script, phpMyAdmin). La escriben triggers; la aplicación solo
puede leerla. No guarda contraseñas ni datos personales: el historial no se puede modificar y, si los
guardara, la anonimización por Habeas Data (ley 18331) no serviría. Ver `docs/documentacion_tecnica.md`.

## Búsqueda, reportes y exportación

- **Búsqueda sin inyección.** El texto buscado viaja siempre como parámetro enlazado; además se acota
  a 80 caracteres y sin caracteres de control, y los comodines del `LIKE` (`%`, `_`) y el carácter de escape se
  escapan, de modo que ni se puede armar un patrón costoso ni «ver todo» escribiendo `%`. Un valor de filtro
  que no está en la lista permitida (formato, estado, acción) o una fecha imposible se descartan sin error
  (`Filtros`). `tests/listados_test.php` prueba textos con `' OR '1'='1`, `DROP TABLE` y `UNION SELECT`.
- **Listas públicas.** Filtrar por `borrador` o `cancelado` no los revela; un organizador no ve torneos de
  otro aunque busque su nombre, porque la regla va dentro de la consulta.
- **Reportes: control de acceso.** El general es solo del administrador. El de un torneo pasa por las tres
  compuertas (rol, permiso `torneos / ver`, propiedad del torneo) igual que la gestión: un organizador que
  cambie el número de la dirección es redirigido con «No tenés acceso a este torneo.». Son rutas GET que no
  modifican datos del dominio.
- **CSV injection (OWASP A03).** Los CSV contienen nombres que escribieron usuarios. Una celda que empieza con
  `=`, `+`, `-` o `@` la planilla la ejecuta como fórmula (`=HYPERLINK(...)`, `@SUM(...)`): se neutraliza
  anteponiendo un apóstrofo (`Csv::celda()`). Un número con signo (`-3`) no se toca.
- **Sin datos personales en la exportación.** Los CSV de inscritos y partidos llevan nombres y estados, no
  documento, correo ni teléfono.
- **Nombre del archivo.** Se arma solo con letras ASCII, números, guiones y la fecha (`Csv::nombreArchivo()`):
  nunca texto de usuarios, que podría romper la cabecera `Content-Disposition`.
- **Sin caché.** Las descargas salen con `Cache-Control: no-store, private`.
- **Auditoría de exportaciones.** Cada descarga registra `exportar_reporte` con el usuario y la cantidad de
  filas; las denegadas no se anotan.

## Política de seguridad de contenido (CSP) y código en línea

Hasta octubre de 2026 la política la fijaba Apache con `'unsafe-inline'` en scripts y en estilos (hallazgo S7): el
navegador ejecutaba cualquier `<script>` o `onclick="…"` que apareciera en la página, y las vistas llevaban 287 atributos
`style="…"`, 7 bloques `<script>` y 25 manejadores en línea.

**Un hallazgo real.** Tres formularios llevaban el texto de una persona DENTRO de un literal de JavaScript:
`onsubmit="return confirm('¿Aprobar a <?= View::e($u['nombre']) ?>?')"`. `View::e()` escapa para HTML, no para
JavaScript: el navegador decodifica `&apos;` antes de ejecutar el manejador. Alguien que se registrara con el nombre
`x');alert(document.cookie);//` ejecutaba código en el navegador del administrador al aprobar su solicitud (XSS
almacenado). `tests/frontend_estatico_test.php` reproduce ese nombre y comprueba que ya no llega a ningún manejador.

**Ahora** (`core/Csp.php`, enviada por `public/index.php` en cada respuesta):

| Directiva | Valor | Qué impide |
|---|---|---|
| `script-src` | `'self'` | Ejecutar scripts o manejadores escritos en la página: solo corren los archivos de este sitio. |
| `style-src` | `'self' 'nonce-…'` | Atributos `style="…"` y bloques `<style>` ajenos. Solo vale UN bloque, con el nonce del pedido. |
| `default-src`, `font-src` | `'self'` | Cargar nada de otros orígenes. |
| `img-src` | `'self' data:` | Imágenes de otros sitios (`data:` para las incrustadas). |
| `object-src 'none'`, `base-uri 'self'`, `form-action 'self'`, `frame-ancestors 'self'` | | Plugins, cambiar la base de las direcciones, enviar formularios a otro sitio y que otro sitio meta esta página en un marco. |

- **Por qué la manda PHP.** El nonce es distinto en cada respuesta (16 bytes al azar) y Apache no puede ponerlo. Por eso
  se sacó la cabecera de `public/.htaccess`: dos políticas se intersectan, y con la de Apache el nonce nunca valdría.
- **Cómo se hace sin `style="…"`.** Lo estático son clases (`utilidades.css`). Lo que se calcula al dibujar
  (la posición de cada tarjeta del bracket, el ancho de una barra, el tamaño de un avatar) pasa por
  `View::claseDinamica()`, que acepta solo propiedades con valores numéricos, y se imprime en un `<style nonce>`.
  Un texto de una persona no puede llegar ahí: la validación lo rechaza.
- **Los scripts.** Los 7 bloques en línea son archivos (`modales.js`, `gestion-torneo.js`, `bracket.js`, `registro.js`,
  `correcciones.js`, `proximos-partidos.js`); los datos viajan en bloques `type="application/json"` (con
  `JSON_HEX_TAG`: un nombre con `</script>` no cierra el bloque); las confirmaciones, en `data-confirmar`.
- **Verificado en un navegador real**: 52 pantallas de los cuatro roles, en escritorio y en celular (104 capturas), y 35 interacciones (70 capturas)
  contra la política vigente: 0 violaciones, 0 scripts en línea, 0 manejadores. Las páginas quedaron idénticas, píxel por
  píxel, a las de antes del cambio.
- Lo que sigue siendo válido bajo esta política: los estilos que un script asigna con `elemento.style.propiedad = …`
  (por ejemplo la posición del menú del bracket, que depende del tamaño de la ventana).

## API y fetch

- **La API pública es de solo lectura y sin sesión**: ninguna ruta de escritura (solo `GET`; otro método es 405)
  y ninguna cookie. Solo expone lo que el sitio ya muestra (torneos públicos, tablas, calendarios): nunca
  documento, correo, teléfono ni cuentas. `tests/api_test.php` recorre las respuestas buscando datos
  personales y comprueba que ningún pedido modifica la base.
- **Sin CORS**: no se envía `Access-Control-Allow-Origin`, así que el navegador de un visitante no deja que
  otro sitio lea la API. No hay límite de frecuencia: conviene ponerlo en el servidor web o en un proxy.
- **CSRF también en los fetch**: los formularios de resultados mandan el token en la cabecera `X-CSRF-Token`
  (`Csrf::tokenDelPedido()`). Otro sitio no puede agregar una cabecera personalizada sin un permiso CORS que
  el sitio no concede. Si la cabecera viene, es la que vale; el campo oculto sigue valiendo para el formulario
  común. Un token faltante o vencido es un 403 en JSON.
- **Las compuertas no se debilitan por ser fetch**: un 401/403 en JSON reemplaza a la redirección, pero el rol,
  el permiso del módulo y la propiedad del torneo se comprueban igual que antes. Corregir sigue siendo solo
  del administrador, también por fetch.
- **El CSP lo permite**: los scripts son del propio sitio (`script-src 'self'`) y los fetch van al mismo origen
  (`default-src 'self'`); no hubo que relajar la política.

## Imágenes subidas

- Se valida el contenido real (`getimagesize`), no la extensión ni el tipo que dice el navegador; solo JPG, PNG, WEBP y
  GIF; máximo 2 MB; nombre aleatorio.
- **La imagen se vuelve a codificar** (`core/Imagen.php`): lo guardado es solo la imagen, sin metadatos (ubicación de la
  cámara) ni código pegado al archivo. `tests/imagen_test.php` sube un JPEG con `<?php system(...) ?>` al final y
  comprueba que no queda.
- **Tope de 16 megapíxeles antes de decodificar**: un archivo chico en disco puede declarar decenas de miles de
  píxeles por lado y agotar la memoria al abrirlo (denegación de servicio). Sin el tope, un PNG de 3 KB y 24
  megapíxeles mataba el proceso de PHP.
- Un SVG se rechaza (no es un formato permitido): puede llevar scripts.

## Configuración del sistema

- Solo el administrador la ve y la cambia (rol en el controlador y en el servicio, por id de quien actúa), con token
  CSRF. Cada cambio queda en la auditoría con los valores de antes y de después.
- Cada ajuste se valida por tipo y rango; un correo con saltos de línea no pasa (evita inyectar cabeceras o
  atributos en el enlace `mailto:`), y el nombre del sitio y el correo siempre se escapan al mostrarse.
- El interruptor de **perfiles públicos** cierra `/jugador/{id}` y `/equipo/{id}` a quien no inició sesión. Se
  comprueba antes de buscar el perfil, así un visitante no puede averiguar qué identificadores existen. Los
  nombres siguen apareciendo en las tablas y listas de los torneos públicos, como siempre; lo que se cierra es la
  página de perfil con sus estadísticas.
- Un valor inválido en la base (alguien lo escribió a mano) no deja el sitio sin nombre ni a todos sin poder
  iniciar sesión: se usa el de fábrica.

## Validaciones

- Frontend: JavaScript para UX (no confiable como única validación)
- Backend: validaciones en servicios (`validar()`) antes de toda operación
- Base de datos: constraints (`UNIQUE`, `CHECK`, `NOT NULL`, `ENUM`) como última línea de defensa
