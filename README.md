# FlexArena

**Sistema de Gestión Deportiva Modular**

Plataforma web para organizar, administrar y consultar torneos deportivos, mentales y electrónicos.

---

## Inicio rápido

### Requisitos

- Docker Desktop (Windows/Mac/Linux) — no hace falta instalar PHP, Apache ni MySQL aparte.

> Para el paso a paso completo —incluido cómo instalarlo desde cero en un
> servidor que ya tiene el proyecto— ver **[docs/INSTALACION.md](docs/INSTALACION.md)**.

### Levantar el proyecto (desarrollo local)

```bash
# 1. Copiar variables de entorno
cp .env.example .env
# Editá .env y poné tu propio DB_PASS y DB_ROOT_PASS. Vienen como placeholder
# ("change_this_db_password" / "change_this_root_password") a propósito: es el
# mismo valor genérico que docker-compose.yml usa de default si te olvidás de
# definirlos, así que conviene no dejarlo así ni en desarrollo local.
#
# Definí también ADMIN_PASS (la contraseña del administrador) y, si querés usar las
# cuentas de demostración, DEMO_PASS. seed.sql ya NO trae ninguna contraseña: cada
# cuenta nace sin clave y el primer arranque le asigna la de tu .env. Si dejás
# ADMIN_PASS vacía se genera una al azar y se imprime UNA vez en el log
# (docker compose logs app). Ver «Contraseñas iniciales» más abajo.
#
# Para desarrollo local poné además APP_ENV=development: el ejemplo viene en
# 'production' porque es el default seguro para un servidor (oculta los errores
# de PHP), pero mientras desarrollás vas a querer verlos en vez de recibir una
# página en blanco. .env está en .gitignore, así que ese cambio no se commitea.

# 2. Construir las imágenes y levantar los contenedores
docker compose up -d --build

# Usá --build siempre que cambie el Dockerfile o docker/docker-entrypoint.sh:
# el entrypoint se COPIA dentro de la imagen, no se monta. Sin --build seguís
# corriendo el de la imagen vieja, aunque el archivo del repo diga otra cosa.

# En la primera inicialización, el contenedor 'app' carga además los datos de
# demostración (database/seed_demo.php): 52 torneos con historia real, 60
# participantes, 52 equipos, correcciones de resultados, etc. Tarda desde un par
# de minutos (disco normal) hasta unos siete (disco lento) y se hace una sola vez
# por instalación: queda una marca en el volumen
# flexarena_estado y no se repite en cada arranque.
#   SEED_DEMO=0      docker compose up -d   → sin datos de demo (solo schema + seed)
#   SEED_DEMO=force  docker compose up -d   → volver a sembrar, PISANDO lo que haya

# 3. Acceder a la aplicación
#    App (HTTP):  http://localhost:8080   (redirige automáticamente a HTTPS, 301)
#    App (HTTPS): https://localhost:443   (certificado autofirmado hasta que coloques uno real)
#    phpMyAdmin:  http://localhost:8081   (solo desde esta máquina: escucha únicamente en 127.0.0.1)
```

La base de datos se inicializa automáticamente con `database/schema.sql` + `database/seed.sql`
la primera vez que arranca el contenedor `db` (volumen vacío). Si el volumen
`flexarena_mysql_data` ya existe, no se vuelve a cargar.

Esos dos archivos son todo lo que hace falta: `schema.sql` ya contiene las dieciséis
migraciones de `database/migrations/`, que quedan como registro de cómo evolucionó
el esquema y como camino de actualización de una base que ya existe. Lo comprueba
`tests/migraciones_consolidadas_test.php`, que las aplica sobre un esquema recién
creado y verifica que no cambien nada, y que una base de septiembre migrada termina
idéntica a `schema.sql`.

> **Primer arranque:** crear el volumen de datos y cargar el esquema (21 tablas, 5 vistas y 51
> triggers) puede pasar de cinco minutos en una máquina con disco lento, y la siembra de los datos
> de demostración suma unos minutos más: en total, de cinco a quince según el disco. El
> `start_period` del healthcheck (10 minutos) contempla eso, así que `docker compose up -d`
> espera en vez de fallar — pero tené paciencia la primera vez.
>
> El healthcheck consulta una tabla del esquema por TCP, a propósito: el servidor **temporal**
> que la imagen de MySQL levanta para correr `schema.sql` y `seed.sql` escucha solo por socket.
> Un simple `mysqladmin ping` lo daba por sano y `app` arrancaba sobre una base sin tablas.

### HTTPS y el certificado SSL

El puerto HTTP (`8080` en local, `80` en producción) **nunca sirve contenido**: solo redirige
(301) a HTTPS. Todo el sitio corre sobre HTTPS.

El contenedor `app` (`docker/docker-entrypoint.sh`) decide el certificado a usar en cada
arranque, en este orden:
1. Si `ssl/certificate.crt` + `ssl/private.key` existen → los usa tal cual.
2. Si no existen → genera uno autofirmado temporal (el navegador muestra "no seguro" —
   esperable hasta que coloques uno real).

Para instalar cualquier certificado: copiá los dos archivos a `ssl/` con esos nombres exactos
y corré `docker compose restart app` — no hace falta reconstruir la imagen ni bajar los
contenedores. Confirmá que tomó el correcto con:
```bash
docker compose logs app | grep "SSL:"
# debe decir "usando certificado provisto en ssl/certificate.crt + ssl/private.key"
openssl x509 -in ssl/certificate.crt -noout -subject -issuer -dates
```

Hay tres formas de conseguir ese certificado, según la situación:

**A) El certificado real de la institución (producción)** — es el que corresponde para el
despliegue real. Preguntales si es de una CA pública reconocida (no hace falta nada más del
lado del cliente) o de una CA interna propia del instituto (en ese caso, además del
certificado del sitio, van a necesitar distribuir el certificado raíz de esa CA a los
dispositivos que accedan — normalmente vía política de dominio/MDM del instituto).

**B) `mkcert` — para probar en HTTPS sin advertencias, en desarrollo/testing local.**
`mkcert` crea una CA local y certificados firmados por ella; instalando esa CA en tu propio
navegador/sistema, el candado queda verde sin advertencia, sin depender del certificado
real todavía.
```bash
# 1. Instalar mkcert (una vez) y crear su CA local:
#    Windows: choco install mkcert   |   Linux: ver https://github.com/FiloSottile/mkcert
mkcert -install

# 2. Generar el certificado para tu dominio de prueba:
mkcert flexarena.local
# genera flexarena.local.pem y flexarena.local-key.pem

# 3. Copiarlos al proyecto con los nombres que espera el entrypoint:
cp flexarena.local.pem     ssl/certificate.crt
cp flexarena.local-key.pem ssl/private.key
docker compose restart app
```
Importante: `mkcert -install` solo hace confiar a **la máquina donde lo corriste**. Si vas a
probar desde otro dispositivo (otra PC, el celular), instalá ahí también el archivo
`rootCA.pem` que te indica `mkcert -CAROOT` — o generá el certificado directo en esa máquina
si es donde vas a navegar.

Si tu `curl`/cliente muestra un error de **revocación** (`CRYPT_E_NO_REVOCATION_CHECK` en
Windows) en vez de confianza: es normal, una CA local no publica listas de revocación —
agregá `--ssl-no-revoke` (curl en Windows) o probá directo desde el navegador, que no lo
trata como error fatal.

**C) Let's Encrypt — certificado público real y gratuito, si tienen un dominio propio.**
Requiere un dominio público de verdad (no `.local`) con DNS apuntando a una IP alcanzable
desde internet — no aplica mientras el proyecto esté solo en la red interna del instituto.
La pieza estándar es **Certbot**; el flujo general es: Certbot valida el dominio (por HTTP en
el puerto 80, o por DNS), emite el certificado, y se copia a `ssl/` igual que los otros casos.
Un detalle a tener en cuenta: el vhost de HTTP redirige *todo* a HTTPS, así que hay que
exceptuar `/.well-known/acme-challenge/` de esa regla para que la validación HTTP funcione.
Certbot se programa solo para renovar cada ~60 días (los certificados duran 90).

### Contraseñas iniciales

`database/seed.sql` **no trae ninguna contraseña**: una escrita en el repositorio la puede
leer cualquiera que lo clone. Las cuentas nacen sin clave (`password_hash` vale
`!sin-clave-definida`, que ninguna contraseña verifica) y el primer arranque del contenedor
`app` (`database/inicializar_cuentas.php`) les asigna la que corresponda:

| Cuenta | Contraseña | Si falta en `.env` |
|---|---|---|
| Administrador (`admin@flexarena.com`) | `ADMIN_PASS` | se genera una al azar y se imprime **una sola vez** en el log: `docker compose logs app` |
| Organizadores y participantes de demostración | `DEMO_PASS` | quedan sin clave: nadie puede entrar con ellas (lo correcto en producción) |

Las dos tienen que cumplir la política de contraseñas del sistema (8 o más caracteres, con
mayúscula, minúscula, número y símbolo); una que no la cumple se rechaza y la cuenta queda sin
clave. Solo se asignan a cuentas que **siguen sin clave**: cambiarlas en el `.env` después no
pisa la que alguien ya eligió.

**Olvidé la contraseña del administrador.** Se la deja sin clave y se reinicia el contenedor,
que le vuelve a aplicar `ADMIN_PASS`:

```bash
docker compose exec db sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" flexarena -e \
  "UPDATE usuarios SET password_hash=\"!sin-clave-definida\" WHERE email=\"admin@flexarena.com\""'
docker compose restart app
```

### Qué puede hacer cada credencial de la base

| Credencial | Quién la usa | Qué puede |
|---|---|---|
| `DB_USER` / `DB_PASS` | la aplicación web | Leer y escribir filas, **tabla por tabla**. Sin `DROP`, `ALTER`, `CREATE` ni `TRIGGER`; la auditoría solo admite `INSERT`; los catálogos y el historial de la base (`historial_bd`) solo se leen. |
| `root` / `DB_ROOT_PASS` | el arranque del contenedor `app` y las migraciones | Todo. El entrypoint la usa para ajustar los privilegios y sembrar los datos de demostración, y **la borra de su entorno antes de lanzar Apache**: el código web no la ve. |
| `root` del contenedor `db` | el respaldo y la restauración (`scripts/backup.sh`, `scripts/restore.sh`) | Lo mismo, pero con la clave que el propio contenedor ya tiene: no hace falta tenerla en el host. Se usa root y no la cuenta de la aplicación porque esa, por mínimo privilegio, no puede ver las vistas ni los triggers (un respaldo sin ellos restauraría una base sin historial) ni crear tablas. |

La imagen oficial de MySQL le da `ALL PRIVILEGES` a `DB_USER`; `database/aplicar_privilegios.php`
lo corrige en cada arranque (es idempotente), y lo verifica `tests/privilegios_test.php`.

### Historial de la base de datos

`auditoria` guarda lo que una persona hace desde la aplicación. `historial_bd` guarda **cualquier
cambio** en las tablas de datos, venga de donde venga —la aplicación, un script, phpMyAdmin—: cada
`INSERT`, `UPDATE` y `DELETE` deja una fila con el registro antes y después, la hora y quién lo hizo.
Lo escriben 51 triggers (tres por cada una de las 17 tablas de datos) que genera
`database/generar_historial.php`. La aplicación solo puede **leer** la tabla, así que ni una
inyección SQL puede falsificarla.

No guarda contraseñas ni datos personales (de esas columnas solo anota que cambiaron): como el
historial no se puede modificar, si los guardara la anonimización que pide la ley de datos
personales no serviría de nada. Tampoco ve lo que la base hace por su cuenta —borrados en cascada,
`TRUNCATE`— ni las tablas que ya son un registro (`auditoria`, `intentos_login`) o se recalculan
(`tabla_posiciones`). Detalle en `docs/documentacion_tecnica.md`.

---

## Despliegue en producción (AlmaLinux)

Para un servidor AlmaLinux 8/9 dedicado, `scripts/install_almalinux.sh` automatiza todo:
instala Docker Engine + Compose, genera `.env` desde `.env.example` si no existe, aplica el
contexto SELinux necesario para el bind mount del proyecto, abre en firewalld **solo** los
puertos que `docker-compose.yml` realmente publica (la base de datos nunca se expone al
host), y levanta los contenedores.

```bash
# En el servidor, como root, parado en la carpeta del proyecto:
sudo bash scripts/install_almalinux.sh
```

Opciones: `--no-deps` (Docker ya instalado), `--no-firewall`, `--no-selinux`, `--no-up`
(preparar todo sin levantar los contenedores todavía).

El script valida `.env` al final: si `DB_PASS` o `DB_ROOT_PASS` quedaron con
el valor de ejemplo — **o directamente faltan** (sin esas líneas, MySQL y la app caen en
silencio en el default genérico de `docker-compose.yml`: `change_this_db_password` /
`change_this_root_password`) — imprime una `ADVERTENCIA` explícita. No bloquea la
instalación (puede ser un ambiente de prueba a propósito), pero avisa antes de dejar esto
expuesto en producción. Revisá `.env` a mano: cambiá esos dos valores por propios, y
ajustá `APP_URL` al dominio o IP real del servidor. Podés levantar primero con el
certificado autofirmado y reemplazarlo en `ssl/` cuando llegue el de la institución.

---

## Administración

```bash
bash scripts/server_management.sh {start|stop|restart|status|logs|backup|shell-app|shell-db}
bash scripts/backup.sh                    # respaldo de la BD (comprimido, en backups/), con vistas y triggers
bash scripts/restore.sh <archivo.sql.gz>  # restaurar un respaldo
bash scripts/monitor_db.sh                # estado básico de la BD
```

Los contenedores se manejan con `server_management.sh`. Para los servicios del
**host** —Docker, firewalld, crond, sshd— está `gestion_servicios.sh`, que es un
envoltorio sobre `systemctl` y `journalctl`:

```bash
bash scripts/gestion_servicios.sh list                    # resumen de los conocidos
bash scripts/gestion_servicios.sh status <servicio>
bash scripts/gestion_servicios.sh logs <servicio> [-f]
sudo bash scripts/gestion_servicios.sh restart <servicio> # cambiar estado exige root
```

`<servicio>` puede ser cualquier unidad systemd: la lista de conocidos solo se
usa para el resumen, no restringe nada. Apache/PHP y MySQL **no** van por acá:
corren dentro de los contenedores y se gestionan con `server_management.sh`.

---

## Tests

La batería vive en `tests/` y es de **integración**: cada caso instancia los
servicios, modelos y controladores reales del proyecto y comprueba lo que quedó
en la base. No hay lógica reimplementada dentro de los tests.

### Preparar la base de pruebas (una sola vez)

Los tests truncan tablas, así que corren contra una base **descartable**, nunca
contra la de la aplicación. El arranque (`tests/bootstrap.php`) aborta si el
nombre de la base no termina en `_test`.

```bash
mysql -u root -p -e "CREATE DATABASE flexarena_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p flexarena_test < database/schema.sql
mysql -u root -p flexarena_test < database/seed.sql
```

### Correr los tests

```bash
# Toda la batería (cada archivo en su propio proceso)
DB_HOST=127.0.0.1 DB_USER=root DB_PASS=tu_password php tests/run.php

# Solo los que coincidan con un texto
DB_HOST=127.0.0.1 DB_USER=root DB_PASS=tu_password php tests/run.php suizo

# Un archivo suelto
DB_HOST=127.0.0.1 DB_USER=root DB_PASS=tu_password php tests/suizo_sin_revanchas_test.php
```

En Windows/PowerShell:

```powershell
$env:DB_HOST="127.0.0.1"; $env:DB_USER="root"; $env:DB_PASS=""
php tests/run.php
```

Las variables de entorno tienen prioridad sobre el `.env` del proyecto, así que
no hace falta tocarlo. Si no se define `DB_NAME`, se usa el de `.env` con el
sufijo `_test`.

Los scripts del navegador y el recorrido de punta a punta con Cypress tienen sus propias pruebas: ver más abajo.

### Qué cubre cada archivo

| Archivo | Qué ejercita |
|---------|--------------|
| `suizo_sin_revanchas_test.php` | `SistemaSuizoService`: no-revancha, byes, control de rondas. Incluye una corrida completa de `seed_demo.php`. |
| `bracket_integridad_test.php` | `EliminacionDirectaService`: rondas, avance de ganadores, byes, campeón, bloqueo de corrección. |
| `tabla_posiciones_test.php` | `TablaPosicionesService`: cifras exactas, orden, desempate por diferencia, puntuación configurable, recálculo. |
| `tiebreak_test.php` | `DesempateTrait` + `intentarFinalizar`: cadena de partidos de desempate hasta que haya campeón. |
| `correccion_resultados_test.php` | `CorreccionService`: solicitar / aprobar / rechazar, y el bloqueo por ronda posterior, que pedir y aplicar consultan igual. |
| `match_schedule_test.php` | `ResultadoService::programar`: rango de fechas del torneo, byes, partidos finalizados. |
| `lockout_estado_test.php` | `AuthService` / `UsuarioService` / `ParticipanteService`: bloqueo a los 5 intentos y desbloqueo. |
| `sesion_test.php` | La sesión: vencimiento por inactividad y regeneración periódica, y que `Auth` la compare con la base en cada pedido (rol al día; una cuenta bloqueada, desactivada o borrada pierde la sesión con un aviso). |
| `login_limites_test.php` | El inicio de sesión: bloqueo temporal que vence solo, límite por IP, límites leídos de `configuracion_sistema`, bloquear solo cuentas activas y desbloquear solo bloqueadas, y la protección del único administrador. |
| `torneo_ownership_test.php` | `TorneoController`: quién puede crear, editar y reasignar torneos. |
| `modulos_toggle_test.php` | `ModuloService` + guardas de módulo en los tres formatos. |
| `rondas_estado_test.php` | `RondaService`: estado de las rondas, cierre automático y cerrar/reabrir manual. |
| `redireccion_segura_test.php` | `Url::interna()`: solo se redirige adentro del sitio. Cubre las formas de disfrazar un destino externo y comprueba que los controladores filtren. |
| `puntos_favor_test.php` | `usa_puntos_favor`: ordena o no por diferencia, el mismo criterio decide el campeón, y la casilla no se apaga sola al guardar. |
| `configuracion_torneo_test.php` | `ConfiguracionTorneoService`: catálogo de claves, validaciones, y el cierre de inscripción rechazando anotarse fuera de plazo. |
| `permisos_test.php` | `PermisoService` + tabla `permisos`: bypass del administrador, negar por omisión, la matriz contra la sección 5 de la letra, y que las guardas estén puestas en los controladores. |
| `autorizacion_test.php` | Quién puede entrar a qué, recorriendo **toda** la tabla de rutas: sin sesión se pide iniciar sesión, un participante no entra a nada de administración ni de organizador, un organizador no entra a lo que es solo del administrador y todo POST exige el token. Además, que un organizador no pueda aplicar una corrección directa ni solicitarla en el torneo de otro, en el controlador y en el servicio. |
| `administradores_test.php` | Los apartados Organizadores y Administradores: cada uno lista, crea, edita, activa y bloquea solo cuentas de su rol (no se degrada a un administrador por la ruta de organizadores), el rol lo fija el apartado y no el formulario, y la lista no ofrece desactivar ni bloquear la cuenta propia ni la del único administrador activo. |
| `paginador_test.php` | `Paginador`: desde dónde se lee cada página, «Mostrando X–Y de N», una página inválida o fuera de rango se corrige, las páginas juntas cubren todo sin repetir, qué números se dibujan y los enlaces que conservan los filtros. |
| `filtros_test.php` | `Filtros`: lo que se acepta de la dirección (texto acotado y sin caracteres de control, opciones permitidas, fechas reales, comodines del `LIKE` escapados); lo que no corresponde se descarta sin error. |
| `csv_test.php` | `Csv`: separador `;`, BOM, decimales con coma, comillas y saltos de línea dentro de una celda, que ninguna celda llegue a la planilla como fórmula (CSV injection) y que el nombre del archivo no lleve texto de usuarios. |
| `listados_test.php` | Las listas con búsqueda, filtros y paginación en SQL (pública, torneos, participantes, equipos, organizadores, administradores y auditoría): cada filtro, comodines y SQL en el texto buscado, `COUNT` = filas, páginas que cubren todo, la regla del organizador dentro de la consulta, y lo escrito por el usuario dibujado escapado. |
| `reportes_test.php` | Los reportes y su CSV: las cifras contra la tabla de posiciones, empates, byes y torneos sin generar, el general como suma de los de cada torneo, fórmulas en nombres, exportaciones auditadas, quién entra (general solo administrador; torneo, administrador y organizador dueño) y que no escriban en la base. |
| `campeon_empates_test.php` | Un empate no tiene ganador ni perdedor (liga, equipos, corregir a empate y de empate a victoria); corregir y pedir una corrección respetan la regla de empates; corregir un torneo finalizado vuelve a decidir al campeón (liga, por equipos, suizo y la final de un bracket) o lo reabre con un desempate si quedan dos empatados arriba; y la migración que limpia los empates ya guardados (no toca bye ni victorias, idempotente). |
| `frontend_estatico_test.php` | Que ninguna vista lleve `style="…"`, `<script>` ni manejadores en línea; la política de seguridad (sin `'unsafe-inline'`, nonce distinto por pedido, Apache sin su propia política); las clases dinámicas que solo aceptan números; el nombre `x');alert(…);//` que ya no llega a un manejador; las hojas Mobile First; el calendario público (grilla que no se ensancha, puntos y lista en pantalla chica, tarjetas desde 721 px, nada de HTML ni estilos armados con datos); y la accesibilidad (etiquetas, tablas, diálogos, landmarks, foco, avisos, contraste AA del botón primario). |
| `imagen_test.php` | Las imágenes subidas: se achican a 512 px conservando la proporción, no se agrandan, conservan transparencia y orientación del celular, un JPEG con código PHP pegado sale limpio, y se rechazan lo que no es imagen, un formato no permitido y las de más de 16 megapíxeles. |
| `vistas_sin_modelos_test.php` | Que ninguna vista cree modelos ni servicios ni consulte la base, que el resultado de cada partido viaje con el partido y que el menú lateral reciba sus contadores del controlador. |
| `panel_participante_test.php` | Los datos de «Mi panel»: próximos partidos, resultados vistos desde el lado del participante, posición en liga y llave, torneos por equipos, bye, retiradas y tope de cinco. |
| `configuracion_sistema_test.php` | La configuración del sistema: validación de cada ajuste, solo administrador, todo o nada, auditoría con antes y después, perfiles públicos (el visitante va al login y no se averigua qué ids existen) y nombre del sitio escapado. |
| `configuracion_pantalla_test.php` | Las pantallas de Configuración y «Mi panel»: acceso y token, errores que devuelven el formulario con lo escrito, envíos manipulados, pie con contacto, estados vacíos y escape de nombres. |
| `api_test.php` | La API pública de solo lectura: el formato, que solo muestre lo público y ningún dato personal, filtros y paginación con sus topes, empates, byes y partidos sin jugar, 404 en JSON, 405 con `Allow`, que no abra sesión y que ningún pedido modifique la base. |
| `fetch_resultados_test.php` | Cargar, corregir, solicitar y programar por fetch: respuestas JSON (200/422/500), el token CSRF en la cabecera, 401/403 en JSON sin redirigir (sesión, rol, permiso, torneo ajeno), que el formulario común siga igual y que las pantallas de gestión publiquen el token y carguen los scripts en orden. |
| `ruta_parametros_test.php` | `Router`: los `{id}` de las rutas tienen que ser enteros positivos; cualquier otra cosa es 404. Recorre las 40 rutas con parámetros. |
| `inscripcion_organizador_test.php` | Inscribir desde el panel del organizador: renderiza las dos páginas de gestión y exige que la que dibuja un combobox cargue su script, y que el formulario no anuncie éxito sin inscribir a nadie. |
| `esquema_enums_test.php` | El catálogo de valores de cada columna ENUM. El esquema no puede declarar estados que el sistema no sabe producir, ni perder uno que el código escribe. |
| `migraciones_consolidadas_test.php` | Que `schema.sql` y las migraciones cuenten la misma historia, sobre bases descartables: aplicar las dieciséis sobre el esquema actual no cambia nada (columnas, índices, claves foráneas, `CHECK`, vistas y triggers), y una base de septiembre (`tests/fixtures/`) con las migraciones nuevas termina idéntica a `schema.sql`. Es lo que permite instalar con solo `schema.sql` + `seed.sql`. |
| `migracion_2026_10_test.php` | Las migraciones de octubre sobre una base **con datos**: las cuentas con clave publicada quedan sin clave, los documentos vacíos pasan a `NULL`, cada formato queda en su módulo, el historial arranca vacío y registra desde ahí; con datos repetidos falla diciendo cuál (sin borrar nada) y es idempotente. |
| `esquema_integridad_test.php` | Que el **motor** rechace lo que el servicio ya validaba (equipo o documento repetido, puntos negativos, torneo que termina antes de empezar, inscripción con dos competidores, formato sin módulo) y que cada servicio siga avisando antes con un mensaje claro. |
| `vistas_test.php` | Las cinco vistas contra la consulta que cada una reemplazó (mismas filas y columnas, con datos de los tres formatos), que `v_participantes_publicos` no exponga documento, email ni teléfono, y que una vista con `SELECT t.*` avise si quedó vieja. |
| `historial_bd_test.php` | El historial de la base: que los triggers versionados sean los que el generador produce hoy, que cada `INSERT`/`UPDATE`/`DELETE` quede con su antes y después y su autor, que un `UPDATE` sin cambios no deje ruido, y que **no** queden contraseñas ni datos personales ni después de anonimizar a una persona. |
| `transacciones_test.php` | Que las operaciones de varias filas sean **todo o nada**: se las corta a la mitad con un trigger de prueba (al crear la ronda 2, al auditar, al guardar el organizador…) y se comprueba que no quede nada a medias —ni rondas sueltas, ni cuentas sin perfil, ni un torneo sin organizador, ni una matriz de permisos cambiada hasta la mitad— y que reparada la falla se pueda reintentar. Incluye `Database::transaccion()` y su anidación. |
| `exposicion_test.php` | Qué publica `docker-compose.yml` hacia afuera: la base no publica nada, phpMyAdmin solo en `127.0.0.1`, y solo la aplicación sale a la red. |
| `manejador_errores_test.php` | El manejo central de errores: página con código de referencia (JSON para la API), que el mensaje de la base no llegue a la persona, el registro sin argumentos de la traza ni query string, `_method` con lista blanca y que Apache pueda escribir el log de PHP. |
| `validator_test.php` | `Validator`: el largo máximo de los textos contra el tamaño real de cada columna (registro, participante, usuario, equipo, torneo), la política de contraseñas y el email. |
| `privilegios_test.php` | El mínimo privilegio de `DB_USER`: crea un usuario temporal, **se conecta como él** e intenta lo permitido y lo prohibido (auditoría inmutable, historial de solo lectura que igual se escribe solo, catálogos de solo lectura, vistas que no se escriben, sin `DROP`/`ALTER`/`CREATE`). |
| `cuentas_iniciales_test.php` | `database/inicializar_cuentas.php` (qué clave recibe cada cuenta, qué pasa si falta o es débil, que nunca pise una existente) y que `seed.sql` no publique ninguna contraseña ni hash. |
| `seed_base_test.php` | `seed.sql` por su cuenta: horarios de los partidos jugados, byes sin horario, perfiles coherentes con sus cuentas. |
| `alta_participantes_test.php` | Que el alta de participantes tenga un solo camino —el registro público— y que no vuelvan las piezas del alta manual, que era código inalcanzable. |
| `docs_referencias_test.php` | Que la documentación no cite código que no existe: cada `Clase::metodo()` y cada ruta de archivo que nombra tienen que estar ahí. |
| `bracket_apilado_test.php` | El orden de apilado del menú de acciones del bracket: sobre las otras tarjetas y las barras fijas, debajo de los modales. |
| `backup_restore_test.sh` | `scripts/backup.sh` y `scripts/restore.sh`: verificación de los respaldos, y que el volcado se haga como root y con los triggers, sin claves en el host. No toca Docker ni la base (usa un `docker` simulado). |
| `datos_minimos_test.php` | El mínimo de 50 registros por componente que pide la letra, y la coherencia de los datos sembrados. |

Archivos de apoyo: `tests/bootstrap.php` (conexión y autoload), `tests/lib/TestCase.php`
(elige la clase base: `TestCaseSolo.php` o `TestCasePhpunit.php`), `tests/lib/Fixtures.php` (datos de prueba),
`phpunit.xml` (la configuración estricta de PHPUnit) y
`scripts/lib_dump.sh` (criterio de verificación de respaldos, compartido por
`backup.sh` y `restore.sh`).

Los tests de shell (`*_test.sh`) necesitan `bash`; en Windows viene con Git Bash.
Si no está instalado, `tests/run.php` los marca como omitidos en lugar de fallar.

**Duración**: la batería completa tarda del orden de diez minutos. El grueso son
los tres archivos que regeneran todo el juego de datos con `seed_demo.php`
(`suizo_sin_revanchas`, `rondas_estado` y `datos_minimos`): cada uno arma los 52
torneos desde cero pasando por los servicios reales. Para iterar rápido conviene
filtrar: `php tests/run.php tabla`, `php tests/run.php ownership`, etc.

### Con PHPUnit

Los mismos archivos corren **de dos maneras**, sin duplicar ni reescribir nada:

| Cómo | Qué hace | Para qué |
|---|---|---|
| `php tests/run.php` | La base propia (`tests/lib/TestCaseSolo.php`): no hay nada que instalar. | Lo de todos los días, en cualquier máquina. |
| `php tests/run.php --phpunit` | PHPUnit 11: sus aserciones, su informe y su manera de contar. | La corrida «de verdad» antes de entregar. |

PHPUnit se usa como un solo archivo (`.phar`), **sin Composer**. Se instala una vez, con la firma GPG de su autor
verificada antes de usarlo:

```bash
bash scripts/instalar_herramientas_de_prueba.sh phpunit      # queda en tools/phpunit/ (no se versiona)
DB_HOST=127.0.0.1 DB_USER=root DB_PASS=tu_password php tests/run.php --phpunit
DB_HOST=127.0.0.1 DB_USER=root DB_PASS=tu_password php tests/run.php --phpunit suizo
```

Cómo encaja (todo en `tests/run.php`, `tests/lib/` y `phpunit.xml`):

- `tests/lib/TestCase.php` elige la base según quién corre: si PHPUnit ya está cargado, `TestCasePhpunit.php` (hereda de
  `PHPUnit\Framework\TestCase` y agrega `assertThrows`, `assertDoesNotThrow` y `fallar`, que PHPUnit no tiene); si no,
  `TestCaseSolo.php`. Los tests no saben la diferencia.
- Cada archivo termina con `TestCase::correrSiEsScript(…)`: con la base propia corre y sale; con PHPUnit no hace nada.
- PHPUnit exige que el archivo se llame como su clase (`PaginadorTest.php`) y estos se llaman `paginador_test.php`. En vez de
  renombrar 51 archivos (y todo lo que los cita), `run.php --phpunit` escribe en un directorio temporal un archivo con el nombre
  que PHPUnit espera, que carga el test y lo subclasifica (por eso las clases de test no son `final`). Sigue siendo **un proceso
  por archivo**, como con la base propia: ninguno hereda la sesión ni los datos del anterior.
- `phpunit.xml` es **estricto**: un caso sin aserciones (riesgoso), una advertencia, un aviso o una obsolescencia de PHP hacen
  fallar la corrida. Por eso `tests/bootstrap.php` ya no instala su propio manejador de errores bajo PHPUnit: PHPUnit no instala el
  suyo si hay otro, y los avisos de PHP dentro de un test pasaban en silencio. Así se encontró un `Undefined variable` en un mensaje de
  un test y una vista del formulario de configuración que dibujaba «Array» ante un envío manipulado.
- El `.phar` se copia al disco local del contenedor antes de correr: leerlo del montaje de Windows tarda ~15 s por arranque.

### Pruebas del navegador (JavaScript)

Los scripts de `public/assets/js/` se **ejecutan** con un DOM, un `fetch` y un reloj simulados (Node 18 o más nuevo, sin instalar
nada). Cada uno prueba lo que hace, no cómo está escrito:

```bash
node tests/js/interfaz_test.js            # confirmar, imprimir, diálogos (foco, Tab, Escape), bracket, registro, correcciones, próximos partidos, calendario
node tests/js/formato_test.js             # formato.js: fechas, rangos, estados (los mismos que View::estadoChip() de PHP)
node tests/js/torneos_en_vivo_test.js     # la búsqueda en vivo: cuándo pide, qué dibuja, respuestas tardías, cancelación, fallas de red
node tests/js/resultados_cliente_test.js  # api.js y resultados.js
```

### Pruebas de punta a punta (Cypress)

`tests/e2e/` recorre la aplicación **de verdad**, en un navegador, como lo haría una persona: se registra, un administrador la
aprueba, se crea un torneo de cada formato, se inscribe, se juega, se corrige un resultado, se apaga un módulo… y cada pantalla en
tres anchos (375, 768 y 1280 px) sin scroll horizontal ni violaciones de la política de seguridad.

Esas pruebas **escriben** (usuarios, torneos, resultados), así que **no corren contra la instalación normal**: usan un entorno
aislado (`docker-compose.e2e.yml`) con su propia base, volúmenes, red y puertos (`https://localhost:8643`), que se tira y se
vuelve a crear sin consecuencias. Las contraseñas salen del mismo `.env` y no se imprimen ni quedan en las capturas.

```bash
bash scripts/instalar_herramientas_de_prueba.sh cypress   # una vez: Cypress 16.1.1 por npm (baja también su binario, ~600 MB)
bash scripts/e2e.sh subir                                 # levanta el entorno aislado (la primera vez crea la base: unos minutos)
bash scripts/e2e.sh probar                                # corre todo; o: probar --spec cypress/e2e/03_liga.cy.js
bash scripts/e2e.sh bajar                                 # lo apaga y borra su base
```

| Especificación | Qué recorre |
|---|---|
| `01_publico` | Portada, lista de torneos (búsqueda, filtros, «Limpiar»), ficha, calendario (también en celular), 404, acceso sin sesión, API pública y su política de seguridad. |
| `02_registro_y_acceso` | Reglas de contraseña, alta de un participante, aprobación y rechazo por un administrador, ingreso y cierre de sesión (con su aviso y sin que la cookie de antes sirva), los 403 por rol, bloqueo por intentos fallidos y desbloqueo, CSRF. |
| `03_liga` | Una liga completa: crear, inscribir 4, fixture de 6 partidos, jugar todo, campeón. |
| `04_eliminacion_y_suizo` | Eliminación directa con 5 (pase libre), suizo de 2 rondas (la segunda se genera a mano), un torneo por equipos, y que con menos de 2 inscritos no se pueda generar. |
| `05_correcciones_y_propiedad` | El organizador solicita, el administrador aprueba o rechaza o corrige directo (motivo mínimo, empates no permitidos), y un organizador no entra a un torneo ajeno. |
| `06_administracion` | Módulos (apagar un formato lo saca del alta, también si se fuerza el envío), configuración del sitio, auditoría de solo lectura, reportes y sus CSV, bloqueo de cuentas. |
| `07_pantallas_y_adaptabilidad` | 37 pantallas × 3 anchos (con un torneo de nombre largo sin espacios, que antes sacaba todo de pantalla): sin desbordar —y si desborda, dice qué elemento—, un `h1`, título, imágenes con `alt`, sin violaciones de la CSP; los menús del celular y «Saltar al contenido». |
| `08_casos_del_plan` | Los casos de `docs/plan_testing.md` que dependían del navegador y se recorrían a mano, con su número entre corchetes: ingreso por rol, liga de 8 y de 5, empates, llaves de 2, 4, 6 y 8, suizo de 6 y de 7, errores de generación, resultados negativos o duplicados, inyección SQL, XSS, CSRF y 403. |
| `09_busqueda_en_vivo` | La lista pública que se actualiza al escribir, contra la API real: sin recargar, con pausa al tipear, atenuada mientras espera, una respuesta vieja que no pisa a la nueva, falla de red, mensaje de la API, páginas, y nombres con HTML. |

Detalles que conviene saber: Cypress corre con `--disable-gpu --no-sandbox` (su Electron se cae al arrancar en algunos Windows);
no hay reintentos (los recorridos tienen estado); antes de cada corrida se vacía el contador de ingresos fallidos del entorno aislado
(la aplicación rechaza lo que supera 20 por IP). En una máquina cargada o donde «localhost» tarda (el sistema prueba primero IPv6,
donde Docker no escucha), se puede correr con `E2E_HOST=127.0.0.1` y ensanchar lo que Cypress espera con `E2E_TIEMPO_COMANDO` y
`E2E_TIEMPO_PAGINA` (milisegundos; por defecto 8000 y 30000): un caso que falla por lentitud pasa al repetirlo con más tiempo.

---

## Estructura del proyecto

```
sgdm/
├── app/
│   ├── controllers/   — Reciben requests, validan, llaman servicios
│   ├── models/        — Acceso a datos con PDO
│   ├── services/      — Lógica de negocio (formatos de torneo)
│   └── views/         — Plantillas PHP con layouts
├── config/            — Configuración de la app y rutas
├── core/              — Router, Database, Session, Auth, View, CSRF, Csp, Paginador, Filtros, Csv, Upload, Imagen
├── database/          — schema.sql, seed.sql, seed_demo.php y migrations/
│                        (para instalar alcanza con schema.sql + seed.sql:
│                         ver database/migrations/README.md); además los scripts
│                        del arranque (aplicar_privilegios.php, inicializar_cuentas.php)
│                        y generar_historial.php, que escribe los triggers del historial
├── docker/            — Entrypoint del contenedor app (prepara el certificado SSL)
├── docs/              — Documentación completa
├── public/            — Front controller y assets (CSS, JS, img)
├── scripts/           — Scripts de administración, backup e instalación, e2e.sh e instalar_herramientas_de_prueba.sh
├── tests/             — La batería de PHP (run.php), los tests de JavaScript (js/) y los de punta a punta (e2e/, Cypress)
├── tools/phpunit/     — PHPUnit 11 (.phar, se instala con scripts/instalar_herramientas_de_prueba.sh; no se versiona)
├── ssl/               — Certificado SSL (certificate.crt + private.key), ver ssl/README.md
├── Dockerfile
├── docker-compose.yml
├── docker-compose.e2e.yml   — entorno AISLADO para las pruebas de punta a punta (otra base, otros puertos)
├── phpunit.xml
└── .env.example
```

---

## Formatos de torneo

| Formato | Descripción |
|---------|-------------|
| **Liga** | Round-robin todos contra todos. Tabla de posiciones con criterios de desempate. |
| **Eliminación Directa** | Bracket con avance de ganadores. Soporta byes para N no potencia de 2. |
| **Sistema Suizo** | Rondas por rendimiento acumulado. Emparejamiento sin repetición de rivales. |

---

## Roles del sistema

- **Administrador**: acceso total, incluido el reporte general del sistema (Sistema → Reportes)
- **Organizador**: gestiona torneos asignados y consulta el reporte de cada uno (con descarga en CSV)
- **Participante**: consulta sus torneos y resultados
- **Público**: vista pública sin autenticación (la lista de torneos se filtra mientras se escribe, sin recargar la página)

---

## Documentación

Ver carpeta `/docs/`:
- `documentacion_funcional.md`
- `documentacion_tecnica.md`
- `api.md` (la API JSON pública y los formularios por fetch)
- `documentacion_seguridad.md`
- `manual_usuario.md`
- `manual_administrador.md`
- `plan_testing.md`
- `owasp.md`
