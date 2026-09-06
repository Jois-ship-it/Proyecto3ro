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

# 2. Construir las imágenes y levantar los contenedores
docker compose build
docker compose up -d

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

Después de correrlo, revisá `.env` a mano: cambiá `APP_SECRET`, `DB_PASS` y `DB_ROOT_PASS`
por valores propios, y ajustá `APP_URL` al dominio o IP real del servidor. Podés levantar
primero con el certificado autofirmado y reemplazarlo en `ssl/` cuando llegue el de la
institución.

---

## Administración

```bash
bash scripts/server_management.sh {start|stop|restart|status|logs|backup|shell-app|shell-db}
bash scripts/backup.sh                    # respaldo de la BD (comprimido, en backups/)
bash scripts/restore.sh <archivo.sql.gz>  # restaurar un respaldo
bash scripts/monitor_db.sh                # estado básico de la BD
```

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
