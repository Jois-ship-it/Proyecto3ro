<?php
declare(strict_types=1);

/**
 * Reportes: el general del sistema (solo administrador, §5.1) y el de cada torneo (el
 * administrador en cualquiera y el organizador en los suyos, §5.2), con salida en CSV.
 *
 * Todas las acciones son de lectura (GET) y pasan por las compuertas de siempre: rol, permiso del
 * módulo «torneos» y propiedad del torneo. Cada CSV que se baja queda en la auditoría.
 */
class ReporteController extends BaseController
{
    /** Torneos por página en la tabla del reporte general. */
    public const POR_PAGINA = 15;

    private const ESTADOS = [
        'borrador'    => 'Borrador',
        'inscripcion' => 'Inscripción',
        'en_curso'    => 'En curso',
        'finalizado'  => 'Finalizado',
        'cancelado'   => 'Cancelado',
    ];

    private ReporteService   $reportes;
    private AuditoriaService $auditoria;
    private TipoTorneoModel  $tipoModel;

    public function __construct()
    {
        $this->reportes  = new ReporteService();
        $this->auditoria = new AuditoriaService();
        $this->tipoModel = new TipoTorneoModel();
    }

    // ─── Reporte general (solo administrador) ─────────────────

    public function general(): void
    {
        $this->requireAdmin();
        [$tipos, $filtros] = $this->filtrosDeTorneos();
        $pag = Paginador::de($this->reportes->contarTorneos($filtros), $this->get('page', 1), self::POR_PAGINA);

        $this->render('admin/reportes', $this->reportes->general() + [
            'pageTitle'   => 'Reportes',
            'generado'    => date('Y-m-d H:i:s'),
            'generadoPor' => (string) (Auth::user()['nombre'] ?? ''),
            'torneos'     => $this->reportes->torneos($filtros, $pag->porPagina, $pag->desplazamiento),
            'filtros'     => $filtros,
            'pag'         => $pag,
            'tipos'       => $tipos,
            'estados'     => self::ESTADOS,
        ], 'admin');
    }

    public function generalCsv(): void
    {
        $this->requireAdmin();
        [, $filtros] = $this->filtrosDeTorneos();
        $csv = $this->reportes->csvTorneos($filtros);

        $this->auditoria->log('exportar_reporte', 'torneos', 0,
            'Exportó el reporte general de torneos en CSV (' . count($csv['filas']) . ' filas).');
        $this->descargarCsv(Csv::nombreArchivo('torneos'), $csv['encabezados'], $csv['filas']);
    }

    /** @return array{0:array<string,string>,1:array<string,string>} los formatos [slug => nombre] y los filtros */
    private function filtrosDeTorneos(): array
    {
        $tipos = array_column($this->tipoModel->findAll(), 'nombre', 'slug');
        return [$tipos, Filtros::desdeQuery($_GET, [
            'q'      => 'texto',
            'tipo'   => array_keys($tipos),
            'estado' => array_keys(self::ESTADOS),
        ])];
    }

    // ─── Reporte de un torneo (administrador y organizador dueño) ─────────

    public function torneo(string $id): void
    {
        $datos = $this->datosDelTorneo((int) $id);
        $this->render('admin/torneo_reporte', $datos + [
            'pageTitle'   => 'Reporte · ' . $datos['torneo']['nombre'],
            'generado'    => date('Y-m-d H:i:s'),
            'generadoPor' => (string) (Auth::user()['nombre'] ?? ''),
        ], 'admin');
    }

    public function posicionesCsv(string $id): void
    {
        $this->exportar((int) $id, 'posiciones', fn(int $t) => $this->reportes->csvPosiciones($t));
    }

    public function partidosCsv(string $id): void
    {
        $this->exportar((int) $id, 'partidos', fn(int $t) => $this->reportes->csvPartidos($t));
    }

    public function inscritosCsv(string $id): void
    {
        $this->exportar((int) $id, 'inscritos', fn(int $t) => $this->reportes->csvInscritos($t));
    }

    /** Las tres compuertas del torneo y, si pasan, el reporte. Si no existe, vuelve a la lista. */
    private function datosDelTorneo(int $torneoId): array
    {
        $this->requireOrganizador();
        $this->requirePermiso('torneos', 'ver');
        $this->requireTorneoOwnership($torneoId, '/admin/torneos');

        $datos = $this->reportes->torneo($torneoId);
        if ($datos === null) {
            $this->flash('error', 'Torneo no encontrado.');
            $this->redirect('/admin/torneos');
        }
        return $datos;
    }

    /** @param callable(int):array{encabezados:string[],filas:array} $armar */
    private function exportar(int $torneoId, string $que, callable $armar): void
    {
        $this->datosDelTorneo($torneoId);       // las compuertas
        $csv = $armar($torneoId);

        $this->auditoria->log('exportar_reporte', 'torneos', $torneoId,
            "Exportó en CSV las {$que} del torneo {$torneoId} (" . count($csv['filas']) . ' filas).');
        $this->descargarCsv(Csv::nombreArchivo("torneo-{$torneoId}-{$que}"), $csv['encabezados'], $csv['filas']);
    }

    /**
     * Entrega el archivo. Es un método aparte para que los tests lo sustituyan y miren el
     * contenido sin tener que mandar cabeceras.
     *
     * No se cachea: el reporte es información del sistema y no tiene que quedar en el
     * navegador de una computadora compartida.
     */
    protected function descargarCsv(string $archivo, array $encabezados, array $filas): void
    {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $archivo . '"');
        header('Cache-Control: no-store, private');
        header('X-Content-Type-Options: nosniff');
        echo Csv::generar($encabezados, $filas);
    }
}
