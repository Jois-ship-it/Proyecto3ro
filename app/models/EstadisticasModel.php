<?php
declare(strict_types=1);

/**
 * Los contadores generales del sistema (v_estadisticas_sistema): una sola fila con
 * la cantidad de torneos por estado, participantes, equipos, partidos jugados y
 * pendientes de resolver. Es de solo lectura: la vista no se escribe.
 */
class EstadisticasModel extends BaseModel
{
    protected string $table = 'v_estadisticas_sistema';

    /** @return array<string, int> */
    public function get(): array
    {
        $fila = $this->fetchOne("SELECT * FROM v_estadisticas_sistema");
        return array_map('intval', $fila ?: []);
    }
}
