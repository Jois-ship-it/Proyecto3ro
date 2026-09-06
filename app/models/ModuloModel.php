<?php
declare(strict_types=1);

class ModuloModel extends BaseModel
{
    protected string $table = 'modulos';

    public function toggleEstado(int $id): string
    {
        $modulo = $this->findById($id);
        if (!$modulo) return '';
        $nuevoEstado = $modulo['estado'] === 'activo' ? 'inactivo' : 'activo';
        $this->update($id, ['estado' => $nuevoEstado]);
        return $nuevoEstado;
    }
}
