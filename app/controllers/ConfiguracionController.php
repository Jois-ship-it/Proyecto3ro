<?php
declare(strict_types=1);

/**
 * Pantalla de configuración general del sistema (solo el administrador).
 *
 * Los ajustes y lo que acepta cada uno están en ConfiguracionSistemaService; acá solo se muestran
 * y se reciben. Si algún valor no sirve no se guarda ninguno: se vuelve a mostrar el formulario con
 * lo que la persona escribió y el motivo, en lugar de perderle los cambios.
 */
class ConfiguracionController extends BaseController
{
    private ConfiguracionSistemaService $service;

    public function __construct()
    {
        $this->service = new ConfiguracionSistemaService();
    }

    public function index(): void
    {
        $this->requireAdmin();
        $this->mostrar();
    }

    public function guardar(): void
    {
        $this->requireAdmin();
        $this->checkCsrf();

        $enviados = array_intersect_key($_POST, ConfiguracionSistemaService::CAMPOS);
        try {
            $cambios = $this->service->guardar($enviados, (int) Auth::id());
        } catch (RuntimeException $e) {
            // Se vuelve a mostrar el formulario con lo escrito, y no se pierde el resto de los cambios.
            http_response_code(422);
            $this->flash('error', $this->mensajeDe($e));
            $this->mostrar($enviados);
            return;
        }

        if ($cambios === []) {
            $this->flash('info', 'No había cambios que guardar.');
        } else {
            $etiquetas = array_map(fn(string $clave) => ConfiguracionSistemaService::CAMPOS[$clave]['etiqueta'], array_keys($cambios));
            $this->flash('success', 'Configuración guardada. Cambió: ' . implode(', ', $etiquetas) . '.');
        }
        $this->redirect('/admin/configuracion');
    }

    /** @param array<string,mixed> $enviados lo que había escrito la persona, si se vuelve de un error */
    private function mostrar(array $enviados = []): void
    {
        // Lo escrito vuelve al formulario como texto. Un envío manipulado puede traer un arreglo donde va un valor (campo[]=x):
        // eso no se dibuja (saldría «Array», con un aviso de PHP en la propia página en desarrollo).
        $enviados = array_map(static fn($v) => is_scalar($v) ? (string) $v : '', $enviados);

        $this->render('admin/configuracion', [
            'pageTitle' => 'Configuración',
            'campos'    => ConfiguracionSistemaService::CAMPOS,
            'valores'   => $this->service->valores(),
            'enviados'  => $enviados,
        ], 'admin');
    }
}
