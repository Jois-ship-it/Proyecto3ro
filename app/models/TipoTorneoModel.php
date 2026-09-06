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
}
