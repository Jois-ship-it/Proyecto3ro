# API JSON — FlexArena

FlexArena tiene una API **pública y de solo lectura** con los torneos, sus tablas de posiciones y sus
calendarios, y sus formularios de resultados se mandan por **fetch** con el token CSRF en una cabecera.
Este documento describe las dos cosas.

- [La API pública](#la-api-pública-de-solo-lectura)
- [Formato de las respuestas](#formato-de-las-respuestas)
- [Los formularios de resultados por fetch](#los-formularios-de-resultados-por-fetch)
- [Qué no hace](#qué-no-hace)

---

## La API pública (de solo lectura)

No pide sesión ni clave: muestra lo mismo que las páginas públicas del sitio. Solo responde a `GET`;
con cualquier otro método devuelve `405` y la cabecera `Allow: GET`. No hay ninguna ruta de escritura
bajo `/api`.

| Ruta | Qué devuelve |
|---|---|
| `GET /api/torneos` | Una página de la lista de torneos públicos |
| `GET /api/torneos/{id}/posiciones` | La tabla de posiciones de un torneo |
| `GET /api/torneos/{id}/calendario` | Todos los partidos de un torneo, ronda por ronda |

### `GET /api/torneos`

| Parámetro | Valor | Por defecto |
|---|---|---|
| `q` | Texto a buscar en el nombre (sin importar mayúsculas ni tildes) | — |
| `tipo` | `liga`, `eliminacion_directa` o `suizo` | todos |
| `estado` | `inscripcion`, `en_curso` o `finalizado` | todos |
| `page` | Número de página | `1` |
| `por_pagina` | Torneos por página, de 1 a 50 | `12` |

Un valor inválido **no es un error**: se ignora, igual que en la lista del sitio, y `meta.filtros` dice
cuáles se aplicaron de verdad. Una `page` fuera de rango devuelve la última. Los borradores, los
torneos privados y los cancelados no figuran en la lista.

```
GET /api/torneos?tipo=liga&estado=finalizado&por_pagina=1
```
```json
{
  "ok": true,
  "datos": [
    {
      "id": 21,
      "nombre": "Liga Docente 2026",
      "formato": { "slug": "liga", "nombre": "Liga" },
      "modalidad": "individual",
      "estado": "finalizado",
      "inscritos": 5,
      "fecha_inicio": "2026-05-15",
      "fecha_fin": "2026-06-29",
      "url": "/torneo/21"
    }
  ],
  "meta": {
    "pagina": 1, "por_pagina": 1, "total": 7, "paginas": 7,
    "filtros": { "q": "", "tipo": "liga", "estado": "finalizado" }
  }
}
```

### `GET /api/torneos/{id}/posiciones`

La tabla de un torneo de Liga o de Sistema Suizo. En Eliminación Directa no hay tabla: `tabla` viene
vacía y `meta.tiene_tabla` es `false`. `torneo.campeon` es `null` mientras el torneo no termina.

```json
{
  "ok": true,
  "datos": {
    "torneo": {
      "id": 1, "nombre": "Liga Apertura 2026",
      "formato": { "slug": "liga", "nombre": "Liga" },
      "modalidad": "individual", "estado": "finalizado",
      "campeon": { "id": 9, "nombre": "Lucas Romero", "tipo": "participante" }
    },
    "tabla": [
      {
        "posicion": 1,
        "competidor": { "id": 9, "nombre": "Lucas Romero", "tipo": "participante" },
        "pj": 7, "pg": 5, "pe": 1, "pp": 1, "pf": 27, "pc": 13, "diferencia": 14, "puntos": 16
      }
    ]
  },
  "meta": { "tiene_tabla": true, "total": 8 }
}
```

`competidor.tipo` es `participante` o `equipo` según la modalidad del torneo.

### `GET /api/torneos/{id}/calendario`

```json
{
  "ok": true,
  "datos": {
    "torneo": { "id": 1, "nombre": "Liga Apertura 2026", "…": "…" },
    "partidos": [
      {
        "id": 5,
        "ronda": { "numero": 2, "nombre": "Fecha 2" },
        "orden": 1,
        "estado": "finalizado",
        "es_bye": false,
        "local":     { "id": 1, "nombre": "Matías Jugador", "tipo": "participante" },
        "visitante": { "id": 8, "nombre": "Camila Torres",  "tipo": "participante" },
        "resultado": { "local": 0.0, "visitante": 0.0 },
        "empate": true,
        "ganador": null,
        "fecha_programada": null
      }
    ]
  },
  "meta": { "total": 28 }
}
```

- **Un empate** se reconoce por los puntos y **no tiene ganador** (`empate: true`, `ganador: null`).
- **Un bye** (un lado sin rival, que pasa de ronda sin jugar) trae `es_bye: true`, `visitante: null`,
  `resultado: null` y, como `ganador`, a quien pasa.
- **Un partido sin jugar** trae `resultado: null` y `ganador: null`.
- `fecha_programada` es una fecha ISO 8601 con zona horaria (`2026-10-12T18:30:00-03:00`) o `null`.
- Los puntajes son números y pueden tener decimales.

Un torneo que no existe, o que un visitante no puede ver (borrador o privado), responde `404`. Un
torneo **cancelado** sí se abre por su identificador, igual que su página del sitio.

---

## Formato de las respuestas

Todas las respuestas son JSON (`Content-Type: application/json; charset=utf-8`, `Cache-Control: no-store`)
con el mismo formato (`core/Respuesta.php`):

```json
{ "ok": true,  "datos": …, "meta": … }
{ "ok": false, "error": { "codigo": "…", "mensaje": "…", "id": "…" } }
```

El cliente decide con `ok` y con el código HTTP. `codigo` es estable y legible por un programa;
`mensaje` es para una persona; `id` solo aparece en los errores técnicos (`500`) y es el código de
referencia con el que se busca el detalle en el registro del servidor.

| HTTP | `codigo` | Cuándo |
|---|---|---|
| 200 | — | Salió bien |
| 401 | `no_autenticado` | Hace falta iniciar sesión (solo en los formularios del panel, no en la API pública) |
| 403 | `sin_permiso` | Sin permiso, torneo ajeno o token CSRF vencido |
| 404 | `no_encontrado` | El recurso no existe o no es público |
| 405 | `metodo_no_permitido` | La dirección existe, pero no con ese método |
| 422 | `datos_invalidos` | Una regla de negocio rechazó lo pedido (marcador inválido, ronda cerrada…) |
| 500 | `error_interno` | Falla técnica (con `id`) |

---

## Los formularios de resultados por fetch

Cargar un resultado, corregirlo (administrador), pedir su corrección (organizador) y programar un
partido se mandan **por fetch**: la página no se recarga cuando el servidor rechaza lo pedido, y el
motivo aparece **dentro del diálogo**, que sigue abierto con lo que se escribió. Antes se recargaba la
página y el motivo aparecía arriba, detrás del diálogo.

```
POST /admin/resultados/cargar            (también /organizador/resultados/cargar)
POST /admin/resultados/corregir          (solo administrador)
POST /organizador/correcciones/solicitar
POST /admin/resultados/programar         (también /organizador/resultados/programar)
```

**Cómo se reconoce un fetch.** El cliente manda `X-Requested-With: XMLHttpRequest` (o `Accept:
application/json`). Es la **misma ruta** que el formulario común: el servidor mira la cabecera y responde
JSON en lugar de redirigir.

**El token CSRF va en una cabecera**, `X-CSRF-Token`. Sale de la etiqueta
`<meta name="csrf-token">` que el panel publica en su `<head>`. Una cabecera personalizada no la puede
mandar otro sitio sin permiso del navegador (CORS), y el sitio no concede ninguno. El campo oculto
`csrf_token` del formulario sigue valiendo (es lo que usa el envío sin JavaScript); si la cabecera
viene, es la que vale: una cabecera equivocada no se «salva» con un campo correcto.

**Respuestas:**

```json
{ "ok": true, "datos": { "mensaje": "Resultado cargado correctamente.", "aviso": null } }
```
```json
{ "ok": false, "error": { "codigo": "datos_invalidos", "mensaje": "Los puntos no pueden ser negativos." } }
```

`aviso` trae el segundo mensaje del servicio cuando lo hay, por ejemplo «El campeón cambió: antes X,
ahora Y» tras una corrección. Si salió bien, el cliente **recarga la página**: el servidor dejó el aviso
como mensaje de la sesión y la tabla o el bracket se ven al día.

**Las compuertas responden JSON, no redirigen.** Con un fetch, una sesión vencida es un `401`; un rol sin
permiso, un torneo ajeno o un token vencido, un `403`. Un fetch que sigue una redirección recibe una página
HTML y no puede interpretarla, por eso estas respuestas no redirigen. Sin fetch (el formulario común) todo
funciona como siempre: aviso y redirección.

**El cliente** son dos scripts (`public/assets/js/`):

- `api.js` — `FlexApi.get(url)` y `FlexApi.enviarFormulario(url, form)`. Manda las cabeceras, lee la respuesta
  y convierte cualquier falla en un `ApiError` con `estado`, `codigo` y `message`.
- `resultados.js` — engancha los `<form data-api>`: desactiva el botón mientras guarda, muestra el motivo en
  el diálogo si el servidor lo rechaza, lleva a `/login` ante un `401` y recarga si salió bien. Sin
  JavaScript, el formulario se manda como siempre.

`tests/js/resultados_cliente_test.js` ejecuta esos dos scripts con un DOM y un fetch simulados
(`node tests/js/resultados_cliente_test.js`).

---

## Qué no hace

- **No escribe.** Ninguna ruta bajo `/api` modifica datos; `tests/api_test.php` lo comprueba sobre las
  rutas, sobre el código y sobre el estado de la base.
- **No expone datos personales.** Solo nombres, estados y resultados, como el sitio: ni documento, ni
  correo, ni teléfono, ni cuentas de usuario.
- **No usa sesión.** No manda cookies ni crea una sesión por cada cliente que la consulta.
- **No tiene CORS.** Otro sitio no puede leerla desde el navegador de un visitante; un script del lado
  del servidor o una herramienta como `curl` sí. Habilitarlo sería agregar la cabecera
  `Access-Control-Allow-Origin` en `Respuesta::enviar()`.
- **No limita la frecuencia de los pedidos.** Para eso se recomienda el servidor web o un proxy por delante.
- **No tiene versión.** Si el formato cambia de forma incompatible, convendría pasar a `/api/v2/`.
