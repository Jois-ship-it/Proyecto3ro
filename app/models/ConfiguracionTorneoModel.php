<?php
declare(strict_types=1);

/**
 * Configuraciones adicionales clave-valor asociadas a un torneo.
 * Permite extender el modelo de torneos sin alterar el esquema principal.
 */
class ConfiguracionTorneoModel extends BaseModel
{
    protected string $table = 'configuraciones_torneo';

    public function get(int $torneoId, string $clave, mixed $default = null): mixed
    {
        $r = $this->fetchOne(
            "SELECT valor FROM configuraciones_torneo WHERE torneo_id = :tid AND clave = :c LIMIT 1",
            [':tid' => $torneoId, ':c' => $clave]
        );
        return $r ? $r['valor'] : $default;
    }

    public function set(int $torneoId, string $clave, string $valor): void
    {
        $this->query(
            "INSERT INTO configuraciones_torneo (torneo_id, clave, valor)
             VALUES (:tid, :c, :v)
             ON DUPLICATE KEY UPDATE valor = VALUES(valor)",
            [':tid' => $torneoId, ':c' => $clave, ':v' => $valor]
        );
    }

    public function getAllByTorneo(int $torneoId): array
    {
        $rows = $this->fetchAll(
            "SELECT clave, valor FROM configuraciones_torneo WHERE torneo_id = :tid",
            [':tid' => $torneoId]
        );
        return array_column($rows, 'valor', 'clave');
    }
}
