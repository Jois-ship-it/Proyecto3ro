<?php
declare(strict_types=1);

/**
 * Los reportes (letra §5.1 «acceder a reportes» y §5.2 «consultar reportes del torneo»).
 *
 * Arma lo que muestran las pantallas y lo que baja en CSV a partir de las MISMAS consultas, de
 * modo que lo que se ve en pantalla y lo que se exporta no puedan diferir. Solo lee: no hay nada
 * que un reporte modifique.
 */
class ReporteService
{
    private ReporteModel      $model;
    private TorneoModel       $torneoModel;
    private EstadisticasModel $estadisticas;

    public function __construct()
    {
        $this->model        = new ReporteModel();
        $this->torneoModel  = new TorneoModel();
        $this->estadisticas = new EstadisticasModel();
    }

    // ─── Reporte global ───────────────────────────────────────

    /**
     * Las cifras generales del sistema (no dependen de los filtros de la tabla de torneos).
     *
     * @return array{resumen:array<string,int>,porFormato:array<string,int>,correcciones:array<string,int>,cuentas:array<string,int>}
     */
    public function general(): array
    {
        $cuentas = $this->model->cuentas();
        return [
            'resumen'      => $this->estadisticas->get(),
            'porFormato'   => $this->model->torneosPorFormato(),
            'correcciones' => $this->model->correccionesPorEstado(),
            'cuentas'      => [
                'Administradores'          => $cuentas['administradores'],
                'Organizadores'            => $cuentas['organizadores'],
                'Participantes'            => $cuentas['participantes'],
                'Pendientes de aprobación' => $cuentas['pendientes'],
                'Bloqueadas'               => $cuentas['bloqueadas'],
            ],
        ];
    }

    /** Una página de la tabla de torneos del reporte global, con su avance. */
    public function torneos(array $filtros, int $limite, int $desde): array
    {
        return $this->torneoModel->buscarConAvance($filtros, $limite, $desde);
    }

    public function contarTorneos(array $filtros): int
    {
        return $this->torneoModel->contarParaPanel($filtros);
    }

    /**
     * El CSV de la tabla de torneos del reporte global: TODOS los que cumplen los filtros, no
     * solo los de la página que se estaba viendo.
     *
     * @return array{encabezados:string[],filas:array<int,array<int,mixed>>}
     */
    public function csvTorneos(array $filtros): array
    {
        $filas = [];
        foreach ($this->torneoModel->buscarConAvance($filtros) as $t) {
            $filas[] = [
                (int) $t['id'], $t['nombre'], $t['tipo_nombre'], ucfirst((string) $t['modalidad']),
                ucfirst(str_replace('_', ' ', (string) $t['estado'])),
                $t['fecha_inicio'], $t['fecha_fin'],
                (int) $t['inscritos'], (int) $t['partidos_jugados'], (int) $t['partidos_total'], $t['campeon'],
            ];
        }
        return [
            'encabezados' => ['ID', 'Torneo', 'Formato', 'Modalidad', 'Estado', 'Inicio', 'Fin',
                              'Inscritos', 'Partidos jugados', 'Partidos', 'Campeón'],
            'filas'       => $filas,
        ];
    }

    // ─── Reporte de un torneo ─────────────────────────────────

    /**
     * Todo lo que muestra el reporte de un torneo, o null si no existe.
     *
     * Un empate se reconoce por los PUNTOS y no por la columna «ganador». Hoy esa columna queda en
     * NULL en un empate, pero una base anterior a la migración 2026_10_empates_sin_ganador guardaba
     * ahí al lado A: fiarse de ella mostraría un ganador donde no lo hubo.
     *
     * @return array{torneo:array,resumen:array,tabla:array,partidos:array,campeon:?string,configuracion:array}|null
     */
    public function torneo(int $torneoId): ?array
    {
        $torneo = $this->torneoModel->findByIdCompleto($torneoId);
        if (!$torneo) {
            return null;
        }

        $resumen = $this->model->resumenTorneo($torneoId);
        $resumen['partidos_pendientes'] = $resumen['partidos_total'] - $resumen['partidos_jugados'];
        $resumen['promedio'] = $resumen['partidos_jugados'] > 0
            ? $resumen['puntos_total'] / $resumen['partidos_jugados']
            : 0.0;

        $partidos = [];
        foreach ($this->model->partidos($torneoId) as $p) {
            $p['ganador_texto'] = $this->ganadorDe($p);
            $p['es_empate']     = $this->esEmpate($p);
            $partidos[] = $p;
        }

        return [
            'torneo'        => $torneo,
            'resumen'       => $resumen,
            'tabla'         => in_array($torneo['tipo_slug'], ['liga', 'suizo'], true) ? $this->model->posiciones($torneoId) : [],
            'partidos'      => $partidos,
            'campeon'       => $torneo['campeon_participante_nombre'] ?? $torneo['campeon_equipo_nombre'] ?? null,
            'configuracion' => (new ConfiguracionTorneoService())->getPorTorneo($torneoId),
        ];
    }

    /** @return array{encabezados:string[],filas:array<int,array<int,mixed>>} */
    public function csvPosiciones(int $torneoId): array
    {
        $filas = [];
        foreach ($this->model->posiciones($torneoId) as $f) {
            $filas[] = [
                (int) $f['posicion'], $f['competidor'], (int) $f['pj'], (int) $f['pg'], (int) $f['pe'], (int) $f['pp'],
                (int) $f['pf'], (int) $f['pc'], (int) $f['diferencia'], (int) $f['puntos'],
            ];
        }
        return [
            'encabezados' => ['Posición', 'Competidor', 'PJ', 'PG', 'PE', 'PP', 'PF', 'PC', 'Dif', 'Puntos'],
            'filas'       => $filas,
        ];
    }

    /** @return array{encabezados:string[],filas:array<int,array<int,mixed>>} */
    public function csvPartidos(int $torneoId): array
    {
        $filas = [];
        foreach ($this->model->partidos($torneoId) as $p) {
            // Un bye guarda los puntos que otorga, pero no es un resultado: no se jugó.
            $conResultado = $p['puntos_a'] !== null && (int) $p['es_bye'] === 0;
            $filas[] = [
                $p['ronda_nombre'],
                $p['lado_a'],
                (int) $p['es_bye'] === 1 ? 'Bye' : $p['lado_b'],
                $conResultado ? (float) $p['puntos_a'] : null,
                $conResultado ? (float) $p['puntos_b'] : null,
                $this->ganadorDe($p),
                ucfirst((string) $p['estado']),
                $p['fecha_programada'],
            ];
        }
        return [
            'encabezados' => ['Ronda', 'Local', 'Visitante', 'Puntos local', 'Puntos visitante', 'Ganador', 'Estado', 'Fecha programada'],
            'filas'       => $filas,
        ];
    }

    /** @return array{encabezados:string[],filas:array<int,array<int,mixed>>} */
    public function csvInscritos(int $torneoId): array
    {
        $filas = [];
        foreach ($this->model->inscritos($torneoId) as $i) {
            $filas[] = [$i['competidor'], $i['tipo'], ucfirst((string) $i['estado']), $i['fecha_inscripcion']];
        }
        return [
            'encabezados' => ['Competidor', 'Tipo', 'Inscripción', 'Fecha de inscripción'],
            'filas'       => $filas,
        ];
    }

    // ─── Quién ganó ───────────────────────────────────────────

    private function esEmpate(array $p): bool
    {
        return (int) $p['es_bye'] === 0 && $p['puntos_a'] !== null && (float) $p['puntos_a'] === (float) $p['puntos_b'];
    }

    /** «Empate», el nombre del ganador (en un bye, quien pasa de ronda) o '—' si todavía no hay. */
    private function ganadorDe(array $p): string
    {
        if ((int) $p['es_bye'] === 1) {
            return (string) ($p['ganador'] ?? $p['lado_a'] ?? '—');
        }
        if ($p['puntos_a'] === null) {
            return '—';
        }
        return $this->esEmpate($p) ? 'Empate' : (string) ($p['ganador'] ?? '—');
    }
}
