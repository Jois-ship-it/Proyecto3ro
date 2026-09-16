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

## Estructura de carpetas

```
sgdm/
├── app/controllers/     — 8 controladores
├── app/models/          — 15 modelos
├── app/services/        — 18 servicios + 2 traits
├── app/views/           — 47 vistas PHP
├── config/              — app.php, database.php, routes.php
├── core/                — 10 clases base (Router, DB, Auth, CSRF, etc.)
├── database/            — schema.sql, seed.sql
├── docs/                — Documentación
├── public/              — Front controller + assets
└── scripts/             — Bash de administración
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

## Base de datos

Ver `database/schema.sql`. Las tablas principales son:

- `torneos` — configuración completa de cada torneo
- `enfrentamientos` — partidos (soporta individual y equipos)
- `resultados` — resultado con soporte de corrección y auditoría
- `tabla_posiciones` — materializada, recalculada en cada resultado

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

## Perfiles, estadísticas y subida de imágenes

- `core/Upload.php` — subida segura de imágenes: valida el contenido real con `getimagesize` (no por extensión), whitelist JPG/PNG/WEBP/GIF, máx 2 MB, nombre aleatorio. Carpeta `public/assets/uploads/{avatars,logos}` con `.htaccess` que **desactiva la ejecución de scripts**.
- `app/services/StatsService.php` — agregación de estadísticas e historial para participantes (`participante()`) y equipos (`equipo()`): PJ/PG/PE/PP/PF/PC, % victorias, torneos (activos/finalizados), campeonatos, posiciones, historial cronológico y **evolución** (win-rate acumulado). Cruza los tres formatos. Sin tablas nuevas.
- `app/views/partials/perfil_stats.php` — panel reutilizable (KPIs + barra de rendimiento + sparkline SVG de evolución + form guide + torneos + historial), usado por el perfil de participante y de equipo.
- Perfiles: privado autogestionado (`/participante/perfil`, con subida de foto) y públicos read-only (`/jugador/{id}`, `/equipo/{id}`). `View::avatar()` muestra imagen o iniciales.

## Las tres compuertas de autorización

Una acción del panel pasa por tres comprobaciones distintas, y las tres tienen
que dar verde. Cada una responde una pregunta que las otras no pueden responder:

| Compuerta | Pregunta | Granularidad | Dónde |
|---|---|---|---|
| **Rol** | ¿Qué tipo de usuario es? | por rol | `Auth::requireRole()` |
| **Permiso de módulo** | ¿Su rol puede hacer esa acción en ese módulo? | rol × módulo × acción | `PermisoService` sobre la tabla `permisos` |
| **Propiedad** | ¿Es **su** torneo? | por fila | `BaseController::requireTorneoOwnership()` |

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

## Estados reversibles

Participantes, equipos y organizadores usan un **toggle activo↔inactivo** (`toggleActivo()` en cada servicio). Es un soft-state: no borra historial (inscripciones/resultados se conservan); reactivar restaura la disponibilidad. El estado fino (`suspendido`) sigue disponible en el formulario de edición.

## Maquetado (Mobile First)

`public/assets/css/styles.css` está escrito con la filosofía **Mobile First**
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

## Seguridad

Ver `docs/documentacion_seguridad.md` y `docs/owasp.md`.

Nota PDO: con `ATTR_EMULATE_PREPARES = false` (prepares nativos) **no se puede reusar un placeholder con nombre** en una misma query (causa `SQLSTATE[HY093]`). Usar placeholders distintos (`:ga`/`:gb`) cuando el mismo valor aparece dos veces.

## Docker

```yaml
# Servicios en docker-compose.yml
app:  PHP 8.2 + Apache (puertos 8080 HTTP / 443 HTTPS)
db:   MySQL 8.0        (sin publicar al host — solo accesible dentro de flexarena_net)
pma:  phpMyAdmin       (puerto 8081)
```

El schema y seed se cargan automáticamente vía `docker-entrypoint-initdb.d/`.

HTTPS lo maneja `mod_ssl` directo en el contenedor `app` (sin proxy externo): usa el
certificado real de `ssl/certificate.crt` + `ssl/private.key` si está presente, o genera uno
autofirmado en su defecto (`docker/docker-entrypoint.sh`). Despliegue en AlmaLinux vía
`scripts/install_almalinux.sh` (instala Docker, SELinux, firewalld — ver README).
