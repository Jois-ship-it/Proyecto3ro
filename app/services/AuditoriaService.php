<?php
declare(strict_types=1);

class AuditoriaService
{
    private AuditoriaModel $model;

    public function __construct()
    {
        $this->model = new AuditoriaModel();
    }

    public function log(
        string  $accion,
        string  $tablaAfectada = '',
        int     $registroId    = 0,
        string  $descripcion   = '',
        mixed   $valorAnterior = null,
        mixed   $valorNuevo    = null,
        ?int    $usuarioId     = null
    ): void {
        $this->model->registrar([
            'usuario_id'     => $usuarioId ?? Auth::id(),
            'accion'         => $accion,
            'tabla_afectada' => $tablaAfectada ?: null,
            'registro_id'    => $registroId    ?: null,
            'descripcion'    => $descripcion   ?: null,
            'valor_anterior' => $valorAnterior,
            'valor_nuevo'    => $valorNuevo,
        ]);
    }

    public function getRecientes(int $limit = 100, int $offset = 0): array
    {
        return $this->model->getRecientes($limit, $offset);
    }

    public function countTotal(): int
    {
        return $this->model->countTotal();
    }
}
