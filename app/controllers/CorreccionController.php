<?php
declare(strict_types=1);

class CorreccionController extends BaseController
{
    /** Solicitudes por página en la bandeja del administrador. */
    public const POR_PAGINA = 15;

    /** @var array<string,string> los estados por los que se filtra la bandeja, con su texto */
    private const ESTADOS = ['pendiente' => 'Pendiente', 'aprobada' => 'Aprobada', 'rechazada' => 'Rechazada'];

    private CorreccionService $service;

    public function __construct()
    {
        $this->service = new CorreccionService();
    }

    /** Organizador (o admin) solicita una corrección. No la aplica. */
    public function solicitar(): void
    {
        $this->requireOrganizador();
        // Misma autorizacion que corregir (letra §5.2): pedir la correccion es
        // el camino del organizador que NO puede aplicarla por su cuenta.
        $this->requirePermiso('resultados', 'editar', '/organizador/torneos');
        $this->checkCsrf();

        $enfId   = $this->postInt('enfrentamiento_id');
        $puntosA = $this->postFloat('puntos_a');
        $puntosB = $this->postFloat('puntos_b');
        $motivo  = $this->postStr('motivo_correccion');

        // Tercera compuerta (rol → permiso → propiedad): solo se piden correcciones en SUS torneos.
        // Antes se comprobaban el rol y el permiso pero no de quién era el torneo, así que un
        // organizador podía pedirlas sobre los partidos de cualquier otro.
        $this->requirePartidoPropio($enfId, Url::interna($_SERVER['HTTP_REFERER'] ?? null, '/organizador/torneos'));

        $falla = null;
        try {
            $this->service->solicitar($enfId, $puntosA, $puntosB, $motivo, (int)Auth::id());
        } catch (RuntimeException $e) {
            $falla = $e;
        }
        $this->terminarAccion(
            $falla,
            'Solicitud de corrección enviada. Quedó pendiente de aprobación administrativa.',
            Url::interna($_SERVER['HTTP_REFERER'] ?? null, '/organizador/torneos')
        );
    }

    /** Admin: bandeja de solicitudes. */
    public function index(): void
    {
        $this->requireAdmin();
        $filtros = Filtros::desdeQuery($_GET, [
            'q'      => 'texto',
            'estado' => array_keys(self::ESTADOS),
        ]);
        $pag = Paginador::de($this->service->contar($filtros), $this->get('page', 1), self::POR_PAGINA);

        $this->render('admin/correcciones', [
            'pageTitle'   => 'Correcciones',
            'solicitudes' => $this->service->buscar($filtros, $pag->porPagina, $pag->desplazamiento),
            'filtros'     => $filtros,
            'pag'         => $pag,
            'estados'     => self::ESTADOS,
            'csrf'        => Csrf::generate(),
            'extraJs'     => ['modales.js', 'correcciones.js'],
        ], 'admin');
    }

    public function aprobar(string $id): void
    {
        $this->requireAdmin();
        $this->checkCsrf();
        try {
            $aviso = $this->service->aprobar((int)$id, (int)Auth::id());
            $this->flash('success', 'Solicitud aprobada. El resultado fue corregido y la tabla recalculada.');
            if ($aviso !== null) $this->flash('warning', $aviso);
        } catch (RuntimeException $e) {
            $this->flash('error', $this->mensajeDe($e));
        }
        $this->redirect('/admin/correcciones');
    }

    public function rechazar(string $id): void
    {
        $this->requireAdmin();
        $this->checkCsrf();
        try {
            $this->service->rechazar((int)$id, (int)Auth::id(), $this->postStr('motivo_rechazo'));
            $this->flash('success', 'Solicitud rechazada.');
        } catch (RuntimeException $e) {
            $this->flash('error', $this->mensajeDe($e));
        }
        $this->redirect('/admin/correcciones');
    }
}
