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
        $enf = (new EnfrentamientoModel())->findById($enfId);
        if (!$enf) return; // el servicio informará "no encontrado"
        $this->requireTorneoOwnership(
            (int)$enf['torneo_id'],
            Url::interna($_SERVER['HTTP_REFERER'] ?? null, '/organizador/torneos')
        );
    }

    public function cargar(): void
    {
        $this->requireOrganizador();
        // Letra §5.2: «cargar resultados».
        $this->requirePermiso('resultados', 'crear', '/organizador/torneos');
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

        // El Referer lo manda el navegador y puede apuntar a cualquier lado: se
        // acepta solo si es del propio sitio (ver core/Url.php).
        $referer = Url::interna($_SERVER['HTTP_REFERER'] ?? null, "/admin/torneos/{$torneoId}");
        $this->redirect($referer);
    }

    public function programar(): void
    {
        $this->requireOrganizador();
        $this->requirePermiso('resultados', 'editar', '/organizador/torneos');
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

        // El Referer lo manda el navegador y puede apuntar a cualquier lado: se
        // acepta solo si es del propio sitio (ver core/Url.php).
        $referer = Url::interna($_SERVER['HTTP_REFERER'] ?? null, "/admin/torneos/{$torneoId}");
        $this->redirect($referer);
    }

    public function corregir(): void
    {
        $this->requireOrganizador();
        // Letra §5.2: «corregir resultados SI CUENTA CON AUTORIZACION». Esa
        // autorizacion es la fila de permisos del rol sobre el modulo
        // resultados; sin ella el organizador puede cargar pero no rehacer.
        $this->requirePermiso('resultados', 'editar', '/organizador/torneos');
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

        // El Referer lo manda el navegador y puede apuntar a cualquier lado: se
        // acepta solo si es del propio sitio (ver core/Url.php).
        $referer = Url::interna($_SERVER['HTTP_REFERER'] ?? null, "/admin/torneos/{$torneoId}");
        $this->redirect($referer);
    }
}
