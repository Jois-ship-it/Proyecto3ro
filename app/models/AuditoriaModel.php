<?php
declare(strict_types=1);

class AuditoriaModel extends BaseModel
{
    protected string $table = 'auditoria';

    public function registrar(array $data): int
    {
        // Serializar valores anteriores y nuevos si son arrays
        if (isset($data['valor_anterior']) && is_array($data['valor_anterior'])) {
            $data['valor_anterior'] = json_encode($data['valor_anterior'], JSON_UNESCAPED_UNICODE);
        }
        if (isset($data['valor_nuevo']) && is_array($data['valor_nuevo'])) {
            $data['valor_nuevo'] = json_encode($data['valor_nuevo'], JSON_UNESCAPED_UNICODE);
        }

        // IP y user agent
        $data['ip']         = $data['ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? null);
        $data['user_agent'] = $data['user_agent'] ?? (substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255));

        return $this->insert($data);
    }

    public function getRecientes(int $limit = 100, int $offset = 0): array
    {
        return $this->fetchAll(
            "SELECT a.*, u.nombre AS usuario_nombre, u.email AS usuario_email
             FROM auditoria a
             LEFT JOIN usuarios u ON u.id = a.usuario_id
             ORDER BY a.created_at DESC
             LIMIT :lim OFFSET :off",
            [':lim' => $limit, ':off' => $offset]
        );
    }

    /**
     * Una página de la auditoría, de la más nueva a la más vieja. Busca el texto en el nombre del
     * usuario, la descripción y la tabla; filtra por acción y por rango de fechas (el día
     * «hasta» entra completo).
     *
     * @param array{q?:string,accion?:string,desde?:string,hasta?:string} $f filtros ya saneados
     */
    public function buscar(array $f, ?int $limite = null, int $desde = 0): array
    {
        [$donde, $params] = $this->condiciones($f);
        return $this->fetchAll(
            "SELECT a.*, u.nombre AS usuario_nombre, u.email AS usuario_email
             FROM auditoria a
             LEFT JOIN usuarios u ON u.id = a.usuario_id
             {$donde}
             ORDER BY a.created_at DESC, a.id DESC" . $this->limiteDe($limite, $desde),
            $params
        );
    }

    public function contarBuscados(array $f): int
    {
        [$donde, $params] = $this->condiciones($f);
        return (int) $this->fetchColumn(
            "SELECT COUNT(*) FROM auditoria a LEFT JOIN usuarios u ON u.id = a.usuario_id {$donde}",
            $params
        );
    }

    /** Las acciones que aparecen en la auditoría, para el filtro. @return string[] */
    public function acciones(): array
    {
        return array_column($this->fetchAll("SELECT DISTINCT accion FROM auditoria ORDER BY accion ASC"), 'accion');
    }

    /** @return array{0:string,1:array} el WHERE y sus parámetros */
    private function condiciones(array $f): array
    {
        $condiciones = [];
        $params      = [];
        $this->agregarBusqueda($condiciones, $params, (string) ($f['q'] ?? ''),
            ['u.nombre', 'a.descripcion', 'a.tabla_afectada']);
        if (($f['accion'] ?? '') !== '') {
            $condiciones[]      = 'a.accion = :accion';
            $params[':accion']  = $f['accion'];
        }
        if (($f['desde'] ?? '') !== '') {
            $condiciones[]      = 'a.created_at >= :desde';
            $params[':desde']   = $f['desde'];
        }
        if (($f['hasta'] ?? '') !== '') {
            $condiciones[]      = 'a.created_at < DATE_ADD(:hasta, INTERVAL 1 DAY)';
            $params[':hasta']   = $f['hasta'];
        }
        return [$this->dondeDe($condiciones), $params];
    }

    public function countTotal(): int
    {
        return (int) $this->fetchColumn("SELECT COUNT(*) FROM auditoria");
    }
}
