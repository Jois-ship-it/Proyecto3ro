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
├── app/controllers/     — 7 controladores
├── app/models/          — 14 modelos
├── app/services/        — 13 servicios
├── app/views/           — ~40 vistas PHP
├── config/              — app.php, database.php, routes.php
├── core/                — 8 clases base (Router, DB, Auth, CSRF, etc.)
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

## Estados reversibles

Participantes, equipos y organizadores usan un **toggle activo↔inactivo** (`toggleActivo()` en cada servicio). Es un soft-state: no borra historial (inscripciones/resultados se conservan); reactivar restaura la disponibilidad. El estado fino (`suspendido`) sigue disponible en el formulario de edición.

## Seguridad

Ver `docs/documentacion_seguridad.md` y `docs/owasp.md`.

Nota PDO: con `ATTR_EMULATE_PREPARES = false` (prepares nativos) **no se puede reusar un placeholder con nombre** en una misma query (causa `SQLSTATE[HY093]`). Usar placeholders distintos (`:ga`/`:gb`) cuando el mismo valor aparece dos veces.

## Docker

```yaml
# Servicios en docker-compose.yml
app:  PHP 8.2 + Apache (puerto 8080)
db:   MySQL 8.0       (puerto 3307)
pma:  phpMyAdmin      (puerto 8081)
```

El schema y seed se cargan automáticamente vía `docker-entrypoint-initdb.d/`.
