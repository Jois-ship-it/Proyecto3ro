<?php
declare(strict_types=1);

class RolModel extends BaseModel
{
    protected string $table = 'roles';

    public function findByNombre(string $nombre): ?array
    {
        $r = $this->fetchOne("SELECT * FROM roles WHERE nombre = :n", [':n' => $nombre]);
        return $r ?: null;
    }
}
