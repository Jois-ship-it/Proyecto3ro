<?php
declare(strict_types=1);

class ResultadoController extends BaseController
{
    private ResultadoService $resultadoService;

    public function __construct()
    {
        $this->resultadoService = new ResultadoService();
    }

    /** El organizador solo opera sobre SUS torneos; el administrador, sobre todos. */
    private function assertPuedeGestionar(int $enfId): void
    {
        if (Auth::isAdmin()) return;
        $enf = (new EnfrentamientoModel())->findById($enfId);
        if (!$enf) return; // el servicio informará "no encontrado"
        $ownerId = (new TorneoModel())->getOrganizadorId((int)$enf['torneo_id']);
        if ($ownerId !== (int)Auth::id()) {
            $this->flash('error', 'No tenés acceso a este torneo.');
            $this->redirect($_SERVER['HTTP_REFERER'] ?? '/organizador/torneos');
        }
    }

    public function cargar(): void
    {
        $this->requireOrganizador();
        $this->checkCsrf();

        $enfId   = $this->postInt('enfrentamiento_id');
        $puntosA = $this->postFloat('puntos_a');
        $puntosB = $this->postFloat('puntos_b');
        $torneoId = $this->postInt('torneo_id');
        $this->assertPuedeGestionar($enfId);

        try {
            $this->resultadoService->cargar($enfId, $puntosA, $puntosB, (int)Auth::id());
            $this->flash('success', 'Resultado cargado correctamente.');
        } catch (RuntimeException $e) {
            $this->flash('error', $e->getMessage());
        }

        $referer = $_SERVER['HTTP_REFERER'] ?? "/admin/torneos/{$torneoId}";
        $this->redirect($referer);
    }

    public function programar(): void
    {
        $this->requireOrganizador();
        $this->checkCsrf();

        $enfId    = $this->postInt('enfrentamiento_id');
        $fecha    = $this->postStr('fecha_programada');
        $torneoId = $this->postInt('torneo_id');
        $this->assertPuedeGestionar($enfId);

        try {
            $this->resultadoService->programar($enfId, $fecha !== '' ? $fecha : null, (int)Auth::id());
            $this->flash('success', 'Partido programado correctamente.');
        } catch (RuntimeException $e) {
            $this->flash('error', $e->getMessage());
        }

        $referer = $_SERVER['HTTP_REFERER'] ?? "/admin/torneos/{$torneoId}";
        $this->redirect($referer);
    }

    public function corregir(): void
    {
        $this->requireOrganizador();
        $this->checkCsrf();

        $enfId    = $this->postInt('enfrentamiento_id');
        $puntosA  = $this->postFloat('puntos_a');
        $puntosB  = $this->postFloat('puntos_b');
        $motivo   = $this->postStr('motivo_correccion');
        $torneoId = $this->postInt('torneo_id');
        $this->assertPuedeGestionar($enfId);

        try {
            $this->resultadoService->corregir($enfId, $puntosA, $puntosB, $motivo, (int)Auth::id());
            $this->flash('success', 'Resultado corregido correctamente. La tabla fue recalculada.');
        } catch (RuntimeException $e) {
            $this->flash('error', $e->getMessage());
        }

        $referer = $_SERVER['HTTP_REFERER'] ?? "/admin/torneos/{$torneoId}";
        $this->redirect($referer);
    }
}
