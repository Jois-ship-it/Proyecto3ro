<?php
declare(strict_types=1);

class TorneoModel extends BaseModel
{
    protected string $table = 'torneos';

    public function findAllConTipo(): array
    {
        return $this->fetchAll(
            "SELECT t.*, tt.nombre AS tipo_nombre, tt.slug AS tipo_slug,
                    u.nombre AS creado_por_nombre
             FROM torneos t
             JOIN tipos_torneo tt ON tt.id = t.tipo_torneo_id
             JOIN usuarios u ON u.id = t.creado_por
             ORDER BY t.id DESC"
        );
    }

    public function findByIdCompleto(int $id): ?array
    {
        $r = $this->fetchOne(
            "SELECT t.*, tt.nombre AS tipo_nombre, tt.slug AS tipo_slug,
                    u.nombre AS creado_por_nombre,
                    cp.nombre AS campeon_participante_nombre,
                    ce.nombre AS campeon_equipo_nombre,
                    org.usuario_id AS organizador_id,
                    ou.nombre AS organizador_nombre
             FROM torneos t
             JOIN tipos_torneo tt ON tt.id = t.tipo_torneo_id
             JOIN usuarios u ON u.id = t.creado_por
             LEFT JOIN participantes cp ON cp.id = t.campeon_participante_id
             LEFT JOIN equipos ce ON ce.id = t.campeon_equipo_id
             LEFT JOIN torneo_organizadores org ON org.torneo_id = t.id
             LEFT JOIN usuarios ou ON ou.id = org.usuario_id
             WHERE t.id = :id
             ORDER BY org.usuario_id ASC
             LIMIT 1",
            [':id' => $id]
        );
        return $r ?: null;
    }

    /** ID del organizador asignado (el primero, modelo "un organizador por torneo"). */
    public function getOrganizadorId(int $torneoId): ?int
    {
        $r = $this->fetchColumn(
            "SELECT usuario_id FROM torneo_organizadores WHERE torneo_id = :t ORDER BY usuario_id ASC LIMIT 1",
            [':t' => $torneoId]
        );
        return $r ? (int)$r : null;
    }

    /** Sincroniza el torneo a UN organizador (borra previos y asigna el nuevo). */
    public function setOrganizador(int $torneoId, ?int $usuarioId): void
    {
        $this->query("DELETE FROM torneo_organizadores WHERE torneo_id = :t", [':t' => $torneoId]);
        if ($usuarioId) {
            $this->query(
                "INSERT INTO torneo_organizadores (torneo_id, usuario_id) VALUES (:t, :u)",
                [':t' => $torneoId, ':u' => $usuarioId]
            );
        }
    }

    public function findPublicos(): array
    {
        return $this->fetchAll(
            "SELECT t.*, tt.nombre AS tipo_nombre, tt.slug AS tipo_slug
             FROM torneos t
             JOIN tipos_torneo tt ON tt.id = t.tipo_torneo_id
             WHERE t.publico = 1 AND t.estado != 'cancelado'
             ORDER BY t.id DESC"
        );
    }

    /** Torneos asignados a un organizador */
    public function findByOrganizador(int $userId): array
    {
        return $this->fetchAll(
            "SELECT t.*, tt.nombre AS tipo_nombre, tt.slug AS tipo_slug
             FROM torneos t
             JOIN tipos_torneo tt ON tt.id = t.tipo_torneo_id
             JOIN torneo_organizadores tor ON tor.torneo_id = t.id
             WHERE tor.usuario_id = :uid
             ORDER BY t.id DESC",
            [':uid' => $userId]
        );
    }

    /** Torneos en que participa un participante (via inscripciones) */
    public function findByParticipante(int $participanteId): array
    {
        return $this->fetchAll(
            "SELECT t.*, tt.nombre AS tipo_nombre, tt.slug AS tipo_slug
             FROM torneos t
             JOIN tipos_torneo tt ON tt.id = t.tipo_torneo_id
             JOIN inscripciones i ON i.torneo_id = t.id
             WHERE i.participante_id = :pid AND t.publico = 1
             ORDER BY t.id DESC",
            [':pid' => $participanteId]
        );
    }

    public function updateEstado(int $id, string $estado): void
    {
        $this->query(
            "UPDATE torneos SET estado = :e, updated_at = NOW() WHERE id = :id",
            [':e' => $estado, ':id' => $id]
        );
    }

    public function setCampeonParticipante(int $torneoId, int $participanteId): void
    {
        $this->query(
            "UPDATE torneos SET campeon_participante_id = :c, estado = 'finalizado', updated_at = NOW()
             WHERE id = :id",
            [':c' => $participanteId, ':id' => $torneoId]
        );
    }

    public function setCampeonEquipo(int $torneoId, int $equipoId): void
    {
        $this->query(
            "UPDATE torneos SET campeon_equipo_id = :c, estado = 'finalizado', updated_at = NOW()
             WHERE id = :id",
            [':c' => $equipoId, ':id' => $torneoId]
        );
    }

    public function contarInscritos(int $torneoId): int
    {
        return (int) $this->fetchColumn(
            "SELECT COUNT(*) FROM inscripciones WHERE torneo_id = :tid",
            [':tid' => $torneoId]
        );
    }
}
