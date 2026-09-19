# Instalación de FlexArena

Dos escenarios. El primero es para probar en tu máquina; el segundo es el que
corresponde al servidor del instituto.

En los dos casos el despliegue es por **contenedores**: no hace falta instalar
PHP, Apache ni MySQL en el sistema. Lo único que se instala es Docker.

---

## A. En tu máquina (desarrollo local)

### Requisitos

- Docker Desktop (Windows / Mac / Linux).

### Pasos

**1. Crear el archivo de entorno.**

```bash
cp .env.example .env
```

Abrir `.env` y cambiar **dos** valores, que vienen como marcador de posición:

| Variable | Qué pasa si la dejás como viene |
|---|---|
| `DB_PASS` | La contraseña de la base queda en `change_this_db_password`, un valor público. |
| `DB_ROOT_PASS` | El usuario `root` de MySQL queda en `change_this_root_password`. |

Para desarrollo conviene además poner `APP_ENV=development`: el ejemplo viene en
`production`, que **oculta los errores de PHP**. Si algo falla vas a ver una
página en blanco en vez del error. `.env` está en `.gitignore`, así que ese
cambio nunca se commitea.

> **Ojo con `DB_ROOT_PASS`:** se aplica en la **primera** inicialización del
> volumen de MySQL y queda fijada ahí. Cambiarla en `.env` después no tiene
> ningún efecto: hay que recrear el volumen (paso «empezar de cero», más abajo) o
> hacer un `ALTER USER` a mano dentro del contenedor.

**2. Construir y levantar.**

```bash
docker compose up -d --build
```

El `--build` no es opcional la primera vez, ni cada vez que cambien el
`Dockerfile` o `docker/docker-entrypoint.sh`: **el entrypoint se copia dentro de
la imagen, no se monta**. Sin `--build` seguís corriendo el de la imagen vieja
aunque el archivo del repositorio diga otra cosa.

Qué pasa mientras tanto, y por qué tarda:

1. MySQL crea el volumen de datos desde cero y carga `database/schema.sql` y
   `database/seed.sql`. En una máquina con disco lento **pasa de cuatro
   minutos**. El `start_period` del healthcheck lo contempla, así que el comando
   espera en vez de fallar.
2. Recién cuando la base está sana arranca `app`, que carga los datos de
   demostración (`database/seed_demo.php`): unos dos minutos más.

En total, la primera vez, entre cinco y siete minutos. Los arranques siguientes
son inmediatos.

**3. Comprobar que quedó bien.**

```bash
docker compose ps
# los tres contenedores en 'Up', y 'db' además en '(healthy)'

docker compose logs app | grep "==>"
# tiene que terminar en "==> Datos de demostración cargados."
```

**4. Entrar.**

| | |
|---|---|
| Aplicación | **https://localhost/** — `http://localhost:8080` redirige ahí |
| phpMyAdmin | http://localhost:8081 — usuario `flexarena_user`, contraseña la de `DB_PASS` |

El certificado es autofirmado hasta que se coloque uno real, así que el
navegador advierte la primera vez. Es esperable: ver la sección de HTTPS del
README.

Cuentas de prueba: ver `docs/CREDENCIALES.md`, que no está versionado.

### Variantes útiles

```bash
SEED_DEMO=0     docker compose up -d --build   # sin datos de demo: solo schema + seed
SEED_DEMO=force docker compose up -d           # volver a sembrar, PISANDO lo que haya
```

Los datos de demostración se cargan **una sola vez por instalación**: queda una
marca en el volumen `flexarena_estado`. Un `restart` o un `up` no los vuelven a
cargar; `down -v` sí, porque borra la marca junto con la base.

---

## B. En un servidor que ya tiene el proyecto

El caso típico: el código ya está en el servidor (copiado por `git clone`,
`scp` o `rsync`) pero no hay nada instalado todavía, o hay una instalación
anterior que conviene rehacer.

### B.1 — Si ya hubo una instalación previa: borrarla

Parado en la carpeta del proyecto:

```bash
docker compose down -v
```

**Esto borra la base de datos.** El `-v` elimina los volúmenes
(`flexarena_mysql_data`, `flexarena_apache_logs`, `flexarena_estado`), que es
justamente lo que hace que la instalación vuelva a empezar de cero: sin borrarlos,
MySQL conserva el esquema viejo y **no vuelve a cargar** `schema.sql` ni
`seed.sql`.

Antes de correrlo, si hay algo que rescatar:

```bash
./scripts/backup.sh          # deja un .gz verificado en backups/
```

Para confirmar que no quedó nada:

```bash
docker volume ls | grep flexarena     # no debería listar ninguno
```

### B.2 — AlmaLinux 8/9: el instalador hace todo

```bash
sudo bash scripts/install_almalinux.sh
```

Qué hace, en orden:

1. **Instala Docker Engine + el plugin Compose** desde el repositorio oficial de
   Docker (no vienen en los repos base de AlmaLinux), con reintentos por si la
   red falla.
2. **Crea `.env` desde `.env.example`** si no existe, y le pone permisos `600`
   porque contiene contraseñas en texto plano.
3. **Aplica el contexto SELinux** `container_file_t` al proyecto, para que los
   contenedores puedan leer el código montado.
4. **Ajusta permisos** para que Apache (`www-data`, uid 33) pueda leer todo el
   proyecto y **escribir** en `public/assets/uploads`. Sin esto, el código
   copiado como root hace que Apache falle al leer `public/.htaccess`, que es
   donde están las reglas que enrutan todo a `index.php`.
5. **Abre en firewalld solo los puertos que el compose publica** — el de la
   aplicación y el de phpMyAdmin. La base de datos **no se publica al host**.
6. **Construye las imágenes y levanta los contenedores.**
7. **Verifica el aislamiento de red**: confirma que `db` no tenga ningún puerto
   publicado.
8. **Prueba de humo**: comprueba con `curl` que el sitio responda de verdad.

Opciones: `--no-deps` (Docker ya instalado), `--no-firewall`, `--no-selinux`,
`--no-up` (dejar todo listo sin levantar todavía).

Se puede volver a correr sin romper nada: es idempotente.

**Después de que termine, editá `.env` a mano:**

```bash
sudo nano .env
```

- `DB_PASS` y `DB_ROOT_PASS`: valores propios. El instalador avisa con una
  `ADVERTENCIA` si quedaron los de ejemplo, pero **no bloquea** la instalación.
- `APP_URL`: el dominio o la IP real del servidor. De esto sale el `ServerName`
  de Apache.
- `APP_ENV`: dejarlo en `production`.

Si cambiaste `DB_PASS` o `DB_ROOT_PASS` **después** de que la base ya se
inicializó, no alcanza con editar `.env`: hay que rehacer el volumen
(`docker compose down -v` y volver a levantar) o cambiar la contraseña dentro de
MySQL con `ALTER USER`.

Para aplicar cualquier otro cambio de `.env`:

```bash
docker compose up -d      # recrea los contenedores; 'restart' NO relee el archivo
```

### B.3 — Otra distribución de Linux, o sin usar el instalador

El instalador es específico de AlmaLinux (usa `dnf`, `semanage`, `firewall-cmd`).
En cualquier otro sistema con Docker ya instalado, los pasos son los de la
sección A, más tres cosas propias de un servidor:

```bash
# 1. Permisos para que Apache lea el proyecto y escriba las subidas
sudo find . -type d -exec chmod o+rx {} \;
sudo find . -type f -exec chmod o+r {} \;
sudo chown -R 33:33 public/assets/uploads
sudo chmod -R 775 public/assets/uploads

# 2. Abrir en el firewall SOLO los puertos publicados (nunca el 3306)
#    Ver cuáles son:
docker compose config | grep -E 'published'

# 3. Levantar
docker compose up -d --build
```

Para instalación **nativa** (Apache + PHP + MariaDB directo sobre el sistema, sin
Docker), `scripts/install.sh` sirve de referencia, pero hay que adaptarlo: el
modelo soportado y probado es el de contenedores.

### B.4 — Certificado SSL real

Mientras no haya certificado, el contenedor genera uno autofirmado en cada
arranque y el navegador advierte. Para poner el de la institución:

```bash
cp certificado_del_instituto.crt  ssl/certificate.crt
cp clave_privada.key              ssl/private.key
docker compose restart app
```

No hace falta reconstruir la imagen ni bajar los contenedores. Confirmar que
tomó el correcto:

```bash
docker compose logs app | grep "SSL:"
# debe decir "usando certificado provisto en ssl/certificate.crt + ssl/private.key"
```

Las tres formas de conseguir ese certificado (el real del instituto, `mkcert`
para pruebas, o Let's Encrypt) están explicadas en el README.

---

## Verificación final

Sirve para los dos escenarios.

```bash
# 1. Los tres contenedores arriba, 'db' healthy
docker compose ps

# 2. La app responde y redirige HTTP a HTTPS
curl -s  -o /dev/null -w "%{http_code}\n" http://localhost:8080/   # 301
curl -sk -o /dev/null -w "%{http_code}\n" https://localhost/       # 200

# 3. La base tiene datos
docker compose exec -T db sh -c \
  'mysql -u "$MYSQL_USER" -p"$MYSQL_PASSWORD" -t "$MYSQL_DATABASE" \
   -e "SELECT (SELECT COUNT(*) FROM usuarios) usuarios,
              (SELECT COUNT(*) FROM torneos) torneos;"'
```

Con los datos de demostración cargados tienen que dar **68 usuarios** y
**52 torneos**. Sin ellos (`SEED_DEMO=0`), **68 usuarios** y **6 torneos**.

---

## Problemas frecuentes

**`dependency failed to start: container flexarena_db is unhealthy`**
La base tardó más de lo que da el healthcheck. Ya no debería pasar —
`start_period` está en 360s— pero si pasa en una máquina muy lenta: esperá a que
`docker compose ps` muestre `db` en `healthy` y volvé a correr
`docker compose up -d`. No hace falta reconstruir nada.

**El sitio carga pero sin estilos, o da 403.**
Apache no puede leer el proyecto. Es el paso de permisos de B.2/B.3.

**Cambié algo del entrypoint y no surte efecto.**
Falta `--build`: el entrypoint vive dentro de la imagen.

**Cambié `.env` y no surte efecto.**
`docker compose restart` no relee el archivo de entorno. Usá
`docker compose up -d`, que recrea los contenedores.

**Los datos de demostración no aparecen.**
Se cargan una sola vez por instalación. Si la marca ya está,
`SEED_DEMO=force docker compose up -d` vuelve a sembrar — pisando lo que haya.

**Página en blanco al fallar algo.**
`APP_ENV=production` oculta los errores. En desarrollo, poné `development` en tu
`.env` y `docker compose up -d`.
