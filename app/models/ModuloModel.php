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

    public function isActivo(string $slug): bool
    {
        $r = $this->fetchColumn(
            "SELECT estado FROM modulos WHERE slug = :s LIMIT 1",
            [':s' => $slug]
        );
        return $r === 'activo';
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
