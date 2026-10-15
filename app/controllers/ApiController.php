<?php
declare(strict_types=1);

/**
 * API JSON pública y de SOLO LECTURA: los torneos, sus tablas de posiciones y sus calendarios.
 *
 *   GET /api/torneos?q=&tipo=&estado=&page=&por_pagina=
 *   GET /api/torneos/{id}/posiciones
 *   GET /api/torneos/{id}/calendario
 *
 * No requiere sesión (muestra lo mismo que las páginas públicas) y no hay ninguna ruta de
 * escritura bajo `/api`. Todas las respuestas tienen el formato de `Respuesta`:
 * {"ok":true,"datos":…,"meta":…} o {"ok":false,"error":{"codigo":…,"mensaje":…}}.
 *
 * Los filtros inválidos se ignoran (como en la lista del sitio) y `meta.filtros` dice cuáles
 * se aplicaron; `page` fuera de rango se corrige. Un torneo que no existe o no es público es 404.
 */
class ApiController extends BaseController
{
    private ApiService $api;

    public function __construct()
    {
        $this->api = new ApiService();
    }

    public function torneos(): void
    {
        $tipos   = array_column((new TipoTorneoModel())->findAll(), 'nombre', 'slug');
        $filtros = Filtros::desdeQuery($_GET, [
            'q'      => 'texto',
            'tipo'   => array_keys($tipos),
            'estado' => ApiService::ESTADOS,
        ]);

        // por_pagina: un entero positivo; lo demás vale lo de siempre. El servicio lo acota al tope.
        $pedido    = filter_var($this->get('por_pagina'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $porPagina = $pedido === false ? ApiService::POR_PAGINA : $pedido;

        $r = $this->api->listar($filtros, $this->get('page', 1), $porPagina);
        $this->responderJson(Respuesta::cuerpoOk($r['datos'], $r['meta']));
    }

    public function posiciones(string $id): void
    {
        $this->enviarOError($this->api->posiciones((int) $id));
    }

    public function calendario(string $id): void
    {
        $this->enviarOError($this->api->calendario((int) $id));
    }

    /** @param array{datos:mixed,meta:array}|null $resultado null = el torneo no existe o no es público */
    private function enviarOError(?array $resultado): never
    {
        if ($resultado === null) {
            $this->responderJson(Respuesta::cuerpoError(
                Respuesta::codigoPara(404),
                'El torneo no existe o no es público.'
            ), 404);
        }
        $this->responderJson(Respuesta::cuerpoOk($resultado['datos'], $resultado['meta']));
    }
}
