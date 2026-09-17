# FlexArena

**Sistema de Gestión Deportiva Modular**

Plataforma web para organizar, administrar y consultar torneos deportivos, mentales y electrónicos.

---

## Inicio rápido

### Requisitos

- Docker Desktop (Windows/Mac/Linux) — no hace falta instalar PHP, Apache ni MySQL aparte.

### Levantar el proyecto (desarrollo local)

```bash
# 1. Copiar variables de entorno
cp .env.example .env
# Editá .env y poné tu propio DB_PASS y DB_ROOT_PASS. Vienen como placeholder
# ("change_this_db_password" / "change_this_root_password") a propósito: es el
# mismo valor genérico que docker-compose.yml usa de default si te olvidás de
# definirlos, así que conviene no dejarlo así ni en desarrollo local.

# 2. Construir las imágenes y levantar los contenedores
docker compose build
docker compose up -d

# En la PRIMERA inicialización, el contenedor 'app' carga además los datos de
# demostración (database/seed_demo.php): 52 torneos con historia real, 60
# participantes, 52 equipos, correcciones de resultados, etc. Tarda un par de
# minutos y se hace una sola vez: si la base ya tiene torneos, no se repite.
# Para arrancar sin ellos (solo schema.sql + seed.sql):  SEED_DEMO=0 docker compose up -d

# 3. Acceder a la aplicación
#    App (HTTP):  http://localhost:8080   (redirige automáticamente a HTTPS, 301)
#    App (HTTPS): https://localhost:443   (certificado autofirmado hasta que coloques uno real)
#    phpMyAdmin:  http://localhost:8081
```

La base de datos se inicializa automáticamente con `database/schema.sql` + `database/seed.sql`
la primera vez que arranca el contenedor `db` (volumen vacío). Si el volumen
`flexarena_mysql_data` ya existe, no se vuelve a cargar.

> **Primer arranque:** MySQL puede tardar más de lo normal la primerísima vez (crea el volumen
> de datos desde cero). Si `docker compose up -d` termina con
> `dependency failed to start: container flexarena_db is unhealthy`, esperá unos segundos,
> confirmá con `docker compose ps` que `db` ya está `healthy`, y volvé a correr
> `docker compose up -d` — no hace falta reconstruir nada, el resto arranca al instante.

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

### Credenciales de prueba

Ver `docs/CREDENCIALES.md` (no versionado — contiene contraseñas reales, se comparte
por fuera del repositorio).

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

El script valida `.env` al final: si `APP_SECRET`, `DB_PASS` o `DB_ROOT_PASS` quedaron con
el valor de ejemplo — **o directamente faltan** (sin esas líneas, MySQL y la app caen en
silencio en el default genérico de `docker-compose.yml`: `change_this_db_password` /
`change_this_root_password`) — imprime una `ADVERTENCIA` explícita. No bloquea la
instalación (puede ser un ambiente de prueba a propósito), pero avisa antes de dejar esto
expuesto en producción. Revisá `.env` a mano: cambiá esos tres valores por propios, y
ajustá `APP_URL` al dominio o IP real del servidor. Podés levantar primero con el
certificado autofirmado y reemplazarlo en `ssl/` cuando llegue el de la institución.

---

## Administración

```bash
bash scripts/server_management.sh {start|stop|restart|status|logs|backup|shell-app|shell-db}
bash scripts/backup.sh                    # respaldo de la BD (comprimido, en backups/)
bash scripts/restore.sh <archivo.sql.gz>  # restaurar un respaldo
bash scripts/monitor_db.sh                # estado básico de la BD
```

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
| `torneo_ownership_test.php` | `TorneoController`: quién puede crear, editar y reasignar torneos. |
| `modulos_toggle_test.php` | `ModuloService` + guardas de módulo en los tres formatos. |
| `rondas_estado_test.php` | `RondaService`: estado de las rondas, cierre automático y cerrar/reabrir manual. |
| `redireccion_segura_test.php` | `Url::interna()`: solo se redirige adentro del sitio. Cubre las formas de disfrazar un destino externo y comprueba que los controladores filtren. |
| `puntos_favor_test.php` | `usa_puntos_favor`: ordena o no por diferencia, el mismo criterio decide el campeón, y la casilla no se apaga sola al guardar. |
| `configuracion_torneo_test.php` | `ConfiguracionTorneoService`: catálogo de claves, validaciones, y el cierre de inscripción rechazando anotarse fuera de plazo. |
| `permisos_test.php` | `PermisoService` + tabla `permisos`: bypass del administrador, negar por omisión, la matriz contra la sección 5 de la letra, y que las guardas estén puestas en los controladores. |
| `ruta_parametros_test.php` | `Router`: los `{id}` de las rutas tienen que ser enteros positivos; cualquier otra cosa es 404. Recorre las 40 rutas con parámetros. |
| `inscripcion_organizador_test.php` | Inscribir desde el panel del organizador: renderiza las dos páginas de gestión y exige que la que dibuja un combobox cargue su script, y que el formulario no anuncie éxito sin inscribir a nadie. |
| `backup_restore_test.sh` | `scripts/backup.sh` y `scripts/restore.sh`: verificación de los respaldos. No toca Docker ni la base (usa un `docker` simulado). |
| `datos_minimos_test.php` | El mínimo de 50 registros por componente que pide la letra, y la coherencia de los datos sembrados. |

Archivos de apoyo: `tests/bootstrap.php` (conexión y autoload), `tests/lib/TestCase.php`
(clase base con las aserciones), `tests/lib/Fixtures.php` (datos de prueba) y
`scripts/lib_dump.sh` (criterio de verificación de respaldos, compartido por
`backup.sh` y `restore.sh`).

Los tests de shell (`*_test.sh`) necesitan `bash`; en Windows viene con Git Bash.
Si no está instalado, `tests/run.php` los marca como omitidos en lugar de fallar.

**Duración**: la batería completa tarda del orden de diez minutos. El grueso son
los tres archivos que regeneran todo el juego de datos con `seed_demo.php`
(`suizo_sin_revanchas`, `rondas_estado` y `datos_minimos`): cada uno arma los 52
torneos desde cero pasando por los servicios reales. Para iterar rápido conviene
filtrar: `php tests/run.php tabla`, `php tests/run.php ownership`, etc.

### Sobre PHPUnit

La batería **no** usa PHPUnit todavía: el proyecto no usa Composer y PHPUnit lo
requiere, así que agregarlo obliga a instalar Composer en cada máquina donde se
quiera correr los tests. Mientras tanto, `tests/lib/TestCase.php` provee lo
mínimo (`assertSame`, `assertTrue`, `assertCount`, `assertThrows`, `setUp`…) con
**los mismos nombres que PHPUnit**, justamente para que migrar sea mecánico:
cambiar `extends TestCase` por `extends PHPUnit\Framework\TestCase`, reemplazar
`assertThrows()` por `expectException()` y borrar el `exit(...)` final de cada
archivo. Los casos y los fixtures quedan igual.

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
├── core/              — Router, Database, Session, Auth, View, CSRF
├── database/          — schema.sql, seed.sql, scripts SQL
├── docker/            — Entrypoint del contenedor app (prepara el certificado SSL)
├── docs/              — Documentación completa
├── public/            — Front controller y assets (CSS, JS, img)
├── scripts/           — Scripts de administración, backup e instalación
├── ssl/               — Certificado SSL (certificate.crt + private.key), ver ssl/README.md
├── Dockerfile
├── docker-compose.yml
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

- **Administrador**: acceso total
- **Organizador**: gestiona torneos asignados
- **Participante**: consulta sus torneos y resultados
- **Público**: vista pública sin autenticación

---

## Documentación

Ver carpeta `/docs/`:
- `documentacion_funcional.md`
- `documentacion_tecnica.md`
- `documentacion_seguridad.md`
- `manual_usuario.md`
- `manual_administrador.md`
- `plan_testing.md`
- `owasp.md`
