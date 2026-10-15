<?php
declare(strict_types=1);

abstract class BaseModel
{
    protected PDO    $db;
    protected string $table;
    protected string $pk = 'id';

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    // ─── CRUD básico ──────────────────────────────────────────

    public function findById(int $id): ?array
    {
        $r = $this->fetchOne(
            "SELECT * FROM {$this->table} WHERE {$this->pk} = :id LIMIT 1",
            [':id' => $id]
        );
        return $r ?: null;
    }

    public function findAll(string $orderBy = 'id', string $dir = 'ASC'): array
    {
        $dir = strtoupper($dir) === 'DESC' ? 'DESC' : 'ASC';
        return $this->fetchAll("SELECT * FROM {$this->table} ORDER BY {$orderBy} {$dir}");
    }

    public function insert(array $data): int
    {
        $cols  = implode(', ', array_keys($data));
        $binds = implode(', ', array_map(fn($c) => ":{$c}", array_keys($data)));
        $this->query("INSERT INTO {$this->table} ({$cols}) VALUES ({$binds})", $data);
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $data): bool
    {
        if (empty($data)) return false;
        $sets = implode(', ', array_map(fn($c) => "{$c} = :{$c}", array_keys($data)));
        $data[$this->pk] = $id;
        $stmt = $this->query(
            "UPDATE {$this->table} SET {$sets} WHERE {$this->pk} = :{$this->pk}",
            $data
        );
        return $stmt->rowCount() > 0;
    }

    public function delete(int $id): bool
    {
        $stmt = $this->query(
            "DELETE FROM {$this->table} WHERE {$this->pk} = :id",
            [':id' => $id]
        );
        return $stmt->rowCount() > 0;
    }

    public function count(): int
    {
        return (int) $this->fetchColumn("SELECT COUNT(*) FROM {$this->table}");
    }

    public function exists(int $id): bool
    {
        return $this->findById($id) !== null;
    }

    // ─── Helpers internos ─────────────────────────────────────

    /**
     * «WHERE a AND b» con las condiciones que haya, o '' si no hay ninguna. Las condiciones son
     * texto de la consulta (nunca datos del pedido: esos viajan en los parámetros enlazados).
     *
     * @param string[] $condiciones
     */
    protected function dondeDe(array $condiciones): string
    {
        return $condiciones === [] ? '' : 'WHERE ' . implode(' AND ', $condiciones);
    }

    /**
     * Agrega la condición «columna LIKE :nombre» por cada columna: el texto buscado aparece en
     * alguna de ellas. Cada columna lleva su propio parámetro (:nombre1, :nombre2…) porque con
     * consultas preparadas nativas un mismo nombre no puede repetirse. Si no hay texto no hace nada.
     *
     * @param string[] $condiciones se le agrega la condición
     * @param array    $params      se le agregan los parámetros
     * @param string[] $columnas    columnas donde buscar, con su alias («p.nombre»)
     */
    protected function agregarBusqueda(array &$condiciones, array &$params, string $texto, array $columnas, string $nombre = 'q'): void
    {
        $patron = Filtros::patronLike($texto);
        if ($patron === '') {
            return;
        }
        $partes = [];
        foreach (array_values($columnas) as $i => $columna) {
            $param = ":{$nombre}" . ($i + 1);
            $partes[] = "{$columna} LIKE {$param} ESCAPE '" . Filtros::ESCAPE_LIKE . "'";
            $params[$param] = $patron;
        }
        $condiciones[] = '(' . implode(' OR ', $partes) . ')';
    }

    /** « LIMIT n OFFSET m» (con los números ya como enteros), o '' si no se pidió un tope. */
    protected function limiteDe(?int $limite, int $desde): string
    {
        return $limite === null ? '' : ' LIMIT ' . max(0, $limite) . ' OFFSET ' . max(0, $desde);
    }

    protected function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    protected function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    protected function fetchOne(string $sql, array $params = []): array|false
    {
        return $this->query($sql, $params)->fetch();
    }

    protected function fetchColumn(string $sql, array $params = [], int $col = 0): mixed
    {
        return $this->query($sql, $params)->fetchColumn($col);
    }

    protected function execute(string $sql, array $params = []): int
    {
        return $this->query($sql, $params)->rowCount();
    }
}
