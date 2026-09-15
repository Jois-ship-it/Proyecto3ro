<?php
declare(strict_types=1);

class TipoTorneoModel extends BaseModel
{
    protected string $table = 'tipos_torneo';

    public function findBySlug(string $slug): ?array
    {
        $r = $this->fetchOne(
            "SELECT * FROM tipos_torneo WHERE slug = :s LIMIT 1",
            [':s' => $slug]
        );
        return $r ?: null;
    }

    /**
     * Tipos ofrecibles en el formulario de torneo: solo los que tienen su módulo
     * habilitado en `modulos` (el slug es el mismo en ambas tablas).
     *
     * $incluirId conserva el formato actual de un torneo que se está editando
     * aunque su módulo se haya deshabilitado después de crearlo; si no, el select
     * no lo ofrecería y al guardar el formato cambiaría en silencio.
     *
     * Cada fila trae `modulo_activo` (1/0) para que la vista pueda avisar cuándo
     * un formato aparece solo por ser el actual del torneo.
     */
    public function findDisponibles(?int $incluirId = null): array
    {
        $where  = "m.estado = 'activo'";
        $params = [];
        if ($incluirId !== null) {
            $where .= " OR tt.id = :id";
            $params[':id'] = $incluirId;
        }

        return $this->fetchAll(
            "SELECT tt.*, IF(m.estado = 'activo', 1, 0) AS modulo_activo
             FROM tipos_torneo tt
             LEFT JOIN modulos m ON m.slug = tt.slug
             WHERE {$where}
             ORDER BY tt.id ASC",
            $params
        );
    }
}
