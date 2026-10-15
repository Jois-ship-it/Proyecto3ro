<?php
declare(strict_types=1);

class ResultadoController extends BaseController
{
    private ResultadoService $resultadoService;

    public function __construct()
    {
        $this->resultadoService = new ResultadoService();
    }

    /** El organizador solo opera sobre partidos de SUS torneos; el administrador, sobre todos. */
    private function assertPuedeGestionar(int $enfId): void
    {
        $this->requirePartidoPropio($enfId, Url::interna($_SERVER['HTTP_REFERER'] ?? null, '/organizador/torneos'));
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

        $falla = null;
        try {
            $this->resultadoService->cargar($enfId, $puntosA, $puntosB, (int)Auth::id());
        } catch (RuntimeException $e) {
            $falla = $e;
        }

        // El Referer lo manda el navegador y puede apuntar a cualquier lado: se
        // acepta solo si es del propio sitio (ver core/Url.php).
        $referer = Url::interna($_SERVER['HTTP_REFERER'] ?? null, "/admin/torneos/{$torneoId}");
        // Con un fetch responde JSON; con el formulario común, flash y redirección (terminarAccion()).
        $this->terminarAccion($falla, 'Resultado cargado correctamente.', $referer);
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

        $falla = null;
        try {
            $this->resultadoService->programar($enfId, $fecha !== '' ? $fecha : null, (int)Auth::id());
        } catch (RuntimeException $e) {
            $falla = $e;
        }

        // El Referer lo manda el navegador y puede apuntar a cualquier lado: se
        // acepta solo si es del propio sitio (ver core/Url.php).
        $referer = Url::interna($_SERVER['HTTP_REFERER'] ?? null, "/admin/torneos/{$torneoId}");
        $this->terminarAccion($falla, 'Partido programado correctamente.', $referer);
    }

    /**
     * Aplica una corrección DIRECTAMENTE. Solo el administrador.
     *
     * Letra §5.2: el organizador corrige resultados «si cuenta con autorización». Esa autorización
     * es la aprobación del administrador: el organizador pide la corrección (CorreccionController::
     * solicitar) y el administrador la aprueba o la rechaza. Esta acción es el camino directo del
     * administrador, y la pantalla del organizador no la ofrece.
     *
     * Antes la compuerta era «rol organizador + permiso resultados/editar», así que un organizador
     * con ese permiso (que trae por defecto) podía mandar un POST a esta ruta y aplicar la corrección
     * sin que nadie la aprobara, salteándose todo el flujo de revisión.
     */
    public function corregir(): void
    {
        $this->requireAdmin();
        $this->checkCsrf();

        $enfId    = $this->postInt('enfrentamiento_id');
        $puntosA  = $this->postFloat('puntos_a');
        $puntosB  = $this->postFloat('puntos_b');
        $motivo   = $this->postStr('motivo_correccion');
        $torneoId = $this->postInt('torneo_id');

        $falla = $aviso = null;
        try {
            $aviso = $this->resultadoService->corregir($enfId, $puntosA, $puntosB, $motivo, (int)Auth::id());
        } catch (RuntimeException $e) {
            $falla = $e;
        }

        // El Referer lo manda el navegador y puede apuntar a cualquier lado: se
        // acepta solo si es del propio sitio (ver core/Url.php).
        $referer = Url::interna($_SERVER['HTTP_REFERER'] ?? null, "/admin/torneos/{$torneoId}");
        $this->terminarAccion($falla, 'Resultado corregido correctamente. La tabla fue recalculada.', $referer, $aviso);
    }
}
