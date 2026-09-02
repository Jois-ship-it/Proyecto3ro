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
<<<<<<< HEAD
        self::assertValidIdentifier($orderBy);
=======
>>>>>>> 4ef20e9daebe2b5733c8675afc915fe244e92e77
        return $this->fetchAll("SELECT * FROM {$this->table} ORDER BY {$orderBy} {$dir}");
    }

    public function insert(array $data): int
    {
<<<<<<< HEAD
        foreach (array_keys($data) as $col) {
            self::assertValidIdentifier($col);
        }
=======
>>>>>>> 4ef20e9daebe2b5733c8675afc915fe244e92e77
        $cols  = implode(', ', array_keys($data));
        $binds = implode(', ', array_map(fn($c) => ":{$c}", array_keys($data)));
        $this->query("INSERT INTO {$this->table} ({$cols}) VALUES ({$binds})", $data);
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $data): bool
    {
        if (empty($data)) return false;
<<<<<<< HEAD
        foreach (array_keys($data) as $col) {
            self::assertValidIdentifier($col);
        }
=======
>>>>>>> 4ef20e9daebe2b5733c8675afc915fe244e92e77
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

<<<<<<< HEAD
    private static function assertValidIdentifier(string $name): string
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
            throw new InvalidArgumentException("Identificador inválido: $name");
        }
        return $name;
    }

=======
>>>>>>> 4ef20e9daebe2b5733c8675afc915fe244e92e77
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
