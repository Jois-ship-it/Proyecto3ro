<?php
declare(strict_types=1);

class CorreccionController extends BaseController
{
    private CorreccionService $service;

    public function __construct()
    {
        $this->service = new CorreccionService();
    }

    /** Organizador (o admin) solicita una corrección. No la aplica. */
    public function solicitar(): void
    {
        $this->requireOrganizador();
        $this->checkCsrf();

        $enfId   = $this->postInt('enfrentamiento_id');
        $puntosA = $this->postFloat('puntos_a');
        $puntosB = $this->postFloat('puntos_b');
        $motivo  = $this->postStr('motivo_correccion');

        try {
            $this->service->solicitar($enfId, $puntosA, $puntosB, $motivo, (int)Auth::id());
            $this->flash('success', 'Solicitud de corrección enviada. Quedó pendiente de aprobación administrativa.');
        } catch (RuntimeException $e) {
            $this->flash('error', $e->getMessage());
        }
        $this->redirect($_SERVER['HTTP_REFERER'] ?? '/organizador/torneos');
    }

    /** Admin: bandeja de solicitudes. */
    public function index(): void
    {
        $this->requireAdmin();
        $this->render('admin/correcciones', [
            'pageTitle'   => 'Correcciones',
            'solicitudes' => $this->service->getTodas(),
            'csrf'        => Csrf::generate(),
        ], 'admin');
    }

    public function aprobar(string $id): void
    {
        $this->requireAdmin();
        $this->checkCsrf();
        try {
            $this->service->aprobar((int)$id, (int)Auth::id());
            $this->flash('success', 'Solicitud aprobada. El resultado fue corregido y la tabla recalculada.');
        } catch (RuntimeException $e) {
            $this->flash('error', $e->getMessage());
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
            $this->flash('error', $e->getMessage());
        }
        $this->redirect('/admin/correcciones');
    }
}
