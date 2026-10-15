<?php
declare(strict_types=1);

/**
 * Acceso a `configuracion_sistema`: los ajustes generales de la plataforma, clave por clave.
 * No confundir con `configuraciones_torneo`, que es por torneo.
 */
class ConfiguracionSistemaModel extends BaseModel
{
    protected string $table = 'configuracion_sistema';
    protected string $pk    = 'clave';

    /** @return array<string, string> clave => valor, de todos los ajustes que hay en la base */
    public function todos(): array
    {
        $valores = [];
        foreach ($this->fetchAll('SELECT clave, valor FROM configuracion_sistema') as $fila) {
            $valores[(string) $fila['clave']] = (string) ($fila['valor'] ?? '');
        }
        return $valores;
    }

    /**
     * Guarda el valor de un ajuste y quién lo cambió. Si la fila no existe (se borró, o es una
     * instalación vieja) la crea con su descripción: el seed la trae siempre, pero guardar no tiene
     * que depender de eso.
     */
    public function guardar(string $clave, string $valor, string $descripcion, int $actorId): void
    {
        $this->query(
            "INSERT INTO configuracion_sistema (clave, valor, descripcion, updated_by)
             VALUES (:clave, :valor, :descripcion, :actor)
             ON DUPLICATE KEY UPDATE valor = VALUES(valor), updated_by = VALUES(updated_by)",
            [':clave' => $clave, ':valor' => $valor, ':descripcion' => $descripcion, ':actor' => $actorId]
        );
    }
}
