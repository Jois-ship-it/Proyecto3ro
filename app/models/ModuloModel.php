<?php
declare(strict_types=1);

class ModuloModel extends BaseModel
{
    protected string $table = 'modulos';

    public function findBySlug(string $slug): ?array
    {
        $r = $this->fetchOne(
            "SELECT * FROM modulos WHERE slug = :s LIMIT 1",
            [':s' => $slug]
        );
        return $r ?: null;
    }

    /** ¿El módulo existe y está habilitado? Un slug desconocido cuenta como deshabilitado. */
    public function estaActivo(string $slug): bool
    {
        $m = $this->findBySlug($slug);
        return $m !== null && $m['estado'] === 'activo';
    }

    public function toggleEstado(int $id): string
    {
        $modulo = $this->findById($id);
        if (!$modulo) return '';
        $nuevoEstado = $modulo['estado'] === 'activo' ? 'inactivo' : 'activo';
        $this->update($id, ['estado' => $nuevoEstado]);
        return $nuevoEstado;
    }
}
