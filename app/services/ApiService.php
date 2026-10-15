<?php
declare(strict_types=1);

/**
 * Lo que la API pública de solo lectura devuelve: torneos, tablas de posiciones y calendarios.
 *
 * Arma datos con forma de JSON: números como números (no como el texto «2.00» que da la base),
 * fechas en ISO 8601, y cada competidor como un objeto {id, nombre, tipo}. Muestra lo mismo que las
 * páginas públicas del sitio y nada más: nombres, estados y resultados. Ni documento, ni correo, ni
 * teléfono, ni identificadores de cuenta; y solo de torneos que un visitante puede ver (públicos y
 * no borradores, la regla de `v_torneos_publicos`).
 */
class ApiService
{
    /** Torneos por página por defecto y tope: un cliente no puede pedir la lista entera de una vez. */
    public const POR_PAGINA        = 12;
    public const POR_PAGINA_MAXIMA = 50;

    /** Estados por los que se filtra: no hay borradores en lo público, ni se listan los cancelados. */
    public const ESTADOS = ['inscripcion', 'en_curso', 'finalizado'];

    private TorneoModel  $torneos;
    private ReporteModel $lecturas;

    public function __construct()
    {
        $this->torneos  = new TorneoModel();
        $this->lecturas = new ReporteModel();
    }

    /**
     * Una página de la lista pública de torneos.
     *
     * @param array{q?:string,tipo?:string,estado?:string} $filtros ya saneados (ver Filtros)
     * @return array{datos:array<int,array>,meta:array}
     */
    public function listar(array $filtros, mixed $pagina, int $porPagina): array
    {
        $porPagina = max(1, min($porPagina, self::POR_PAGINA_MAXIMA));
        $pag       = Paginador::de($this->torneos->contarPublicos($filtros), $pagina, $porPagina);

        $datos = [];
        foreach ($this->torneos->buscarPublicos($filtros, $pag->porPagina, $pag->desplazamiento) as $t) {
            $datos[] = [
                'id'           => (int) $t['id'],
                'nombre'       => (string) $t['nombre'],
                'formato'      => ['slug' => (string) $t['tipo_slug'], 'nombre' => (string) $t['tipo_nombre']],
                'modalidad'    => (string) $t['modalidad'],
                'estado'       => (string) $t['estado'],
                'inscritos'    => (int) $t['inscritos'],
                'fecha_inicio' => $t['fecha_inicio'] !== null ? (string) $t['fecha_inicio'] : null,
                'fecha_fin'    => $t['fecha_fin'] !== null ? (string) $t['fecha_fin'] : null,
                'url'          => '/torneo/' . (int) $t['id'],
            ];
        }

        return [
            'datos' => $datos,
            'meta'  => [
                'pagina'     => $pag->pagina,
                'por_pagina' => $pag->porPagina,
                'total'      => $pag->total,
                'paginas'    => $pag->paginas,
                'filtros'    => $filtros,
            ],
        ];
    }

    /**
     * La tabla de posiciones de un torneo público, o null si no existe o no es público. Un
     * Eliminación Directa no tiene tabla: devuelve la lista vacía y `tiene_tabla` en false.
     *
     * @return array{datos:array,meta:array}|null
     */
    public function posiciones(int $torneoId): ?array
    {
        $torneo = $this->torneoPublico($torneoId);
        if ($torneo === null) {
            return null;
        }

        $conTabla = in_array($torneo['tipo_slug'], ['liga', 'suizo'], true);
        $tabla    = [];
        if ($conTabla) {
            foreach ($this->lecturas->posiciones($torneoId) as $f) {
                $esEquipo = $f['equipo_id'] !== null;
                $tabla[] = [
                    'posicion'   => (int) $f['posicion'],
                    'competidor' => $this->competidor($esEquipo ? (int) $f['equipo_id'] : (int) $f['participante_id'], (string) $f['competidor'], $esEquipo),
                    'pj'         => (int) $f['pj'],
                    'pg'         => (int) $f['pg'],
                    'pe'         => (int) $f['pe'],
                    'pp'         => (int) $f['pp'],
                    'pf'         => (int) $f['pf'],
                    'pc'         => (int) $f['pc'],
                    'diferencia' => (int) $f['diferencia'],
                    'puntos'     => (int) $f['puntos'],
                ];
            }
        }

        return [
            'datos' => ['torneo' => $this->resumenDelTorneo($torneo), 'tabla' => $tabla],
            'meta'  => ['tiene_tabla' => $conTabla, 'total' => count($tabla)],
        ];
    }

    /**
     * Todos los partidos de un torneo público, ronda por ronda, o null si no existe o no es público.
     * Un empate se reconoce por los PUNTOS y no tiene ganador; un bye no tiene resultado.
     *
     * @return array{datos:array,meta:array}|null
     */
    public function calendario(int $torneoId): ?array
    {
        $torneo = $this->torneoPublico($torneoId);
        if ($torneo === null) {
            return null;
        }

        $partidos = [];
        foreach ($this->lecturas->partidos($torneoId) as $p) {
            $partidos[] = $this->partido($p);
        }

        return [
            'datos' => ['torneo' => $this->resumenDelTorneo($torneo), 'partidos' => $partidos],
            'meta'  => ['total' => count($partidos)],
        ];
    }

    // ─── Armado ──────────────────────────────────────────────────────────

    /** El torneo con su formato y campeón, si un visitante puede verlo. */
    private function torneoPublico(int $torneoId): ?array
    {
        if (!$this->torneos->esPublico($torneoId)) {
            return null;
        }
        return $this->torneos->findByIdCompleto($torneoId);
    }

    /** @return array<string,mixed> */
    private function resumenDelTorneo(array $t): array
    {
        $campeon = null;
        if ($t['campeon_participante_id'] !== null) {
            $campeon = $this->competidor((int) $t['campeon_participante_id'], (string) $t['campeon_participante_nombre'], false);
        } elseif ($t['campeon_equipo_id'] !== null) {
            $campeon = $this->competidor((int) $t['campeon_equipo_id'], (string) $t['campeon_equipo_nombre'], true);
        }

        return [
            'id'        => (int) $t['id'],
            'nombre'    => (string) $t['nombre'],
            'formato'   => ['slug' => (string) $t['tipo_slug'], 'nombre' => (string) $t['tipo_nombre']],
            'modalidad' => (string) $t['modalidad'],
            'estado'    => (string) $t['estado'],
            'campeon'   => $campeon,
        ];
    }

    /** @return array{id:int,nombre:string,tipo:string} */
    private function competidor(int $id, string $nombre, bool $esEquipo): array
    {
        return ['id' => $id, 'nombre' => $nombre, 'tipo' => $esEquipo ? 'equipo' : 'participante'];
    }

    /** @return array<string,mixed> un partido de v_calendario con forma de JSON */
    private function partido(array $p): array
    {
        $esBye = (int) $p['es_bye'] === 1;
        $lado = function (string $nombre, ?string $idParticipante, ?string $idEquipo) use ($p): ?array {
            if ($p[$nombre] === null) return null;
            return $idEquipo !== null && $p[$idEquipo] !== null
                ? $this->competidor((int) $p[$idEquipo], (string) $p[$nombre], true)
                : $this->competidor((int) $p[(string) $idParticipante], (string) $p[$nombre], false);
        };
        $local     = $lado('lado_a', 'participante_a_id', 'equipo_a_id');
        $visitante = $esBye ? null : $lado('lado_b', 'participante_b_id', 'equipo_b_id');

        $conResultado = !$esBye && $p['puntos_a'] !== null;
        $empate       = $conResultado && (float) $p['puntos_a'] === (float) $p['puntos_b'];

        $ganador = null;
        if ($esBye) {
            $ganador = $local;                                   // quien pasa de ronda sin jugar
        } elseif ($conResultado && !$empate) {
            $gana = (float) $p['puntos_a'] > (float) $p['puntos_b'];
            $ganador = $gana ? $local : $visitante;
        }

        return [
            'id'               => (int) $p['id'],
            'ronda'            => ['numero' => (int) $p['ronda_numero'], 'nombre' => (string) $p['ronda_nombre']],
            'orden'            => (int) $p['orden'],
            'estado'           => (string) $p['estado'],
            'es_bye'           => $esBye,
            'local'            => $local,
            'visitante'        => $visitante,
            'resultado'        => $conResultado ? ['local' => (float) $p['puntos_a'], 'visitante' => (float) $p['puntos_b']] : null,
            'empate'           => $empate,
            'ganador'          => $ganador,
            'fecha_programada' => $p['fecha_programada'] !== null ? date('c', strtotime((string) $p['fecha_programada'])) : null,
        ];
    }
}
