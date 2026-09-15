<?php
declare(strict_types=1);

/**
 * Gestión de módulos del sistema.
 *
 * Existe para que el cambio de estado quede auditado como el resto de las
 * acciones sensibles: el controlador solo enruta, el registro en `auditoria`
 * se hace acá (mismo criterio que TorneoService, UsuarioService, etc.).
 */
class ModuloService
{
    private ModuloModel      $model;
    private AuditoriaService $auditoria;

    public function __construct()
    {
        $this->model     = new ModuloModel();
        $this->auditoria = new AuditoriaService();
    }

    public function getTodos(): array
    {
        return $this->model->findAll();
    }

    /**
     * Activa/desactiva un módulo y lo registra en auditoría.
     *
     * @return array{modulo: array, anterior: string, nuevo: string}
     * @throws RuntimeException si el módulo no existe.
     */
    public function toggle(int $id): array
    {
        $modulo = $this->model->findById($id);
        if (!$modulo) {
            throw new RuntimeException('El módulo indicado no existe.');
        }

        $anterior = (string) $modulo['estado'];
        $nuevo    = $this->model->toggleEstado($id);

        $this->auditoria->log(
            'toggle_modulo',
            'modulos',
            $id,
            "Módulo «{$modulo['nombre']}» ({$modulo['slug']}): {$anterior} → {$nuevo}",
            ['estado' => $anterior],   // columnas JSON: AuditoriaModel serializa arrays
            ['estado' => $nuevo]
        );

        return ['modulo' => $modulo, 'anterior' => $anterior, 'nuevo' => $nuevo];
    }
}
