<?php
declare(strict_types=1);

class TorneoModel extends BaseModel
{
    protected string $table = 'torneos';

    public function findAllConTipo(): array
    {
        return $this->fetchAll(
            "SELECT t.*, tt.nombre AS tipo_nombre, tt.slug AS tipo_slug,
                    u.nombre AS creado_por_nombre,
                    (SELECT COUNT(*) FROM inscripciones i
                      WHERE i.torneo_id = t.id AND i.estado = 'activa') AS inscritos
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

    /**
     * Sincroniza el torneo a UN organizador (borra previos y asigna el nuevo).
     *
     * Las dos sentencias van juntas: si el INSERT falla con el DELETE ya hecho, el torneo
     * quedaría sin organizador, y la aplicación no admite un torneo así.
     */
    public function setOrganizador(int $torneoId, ?int $usuarioId): void
    {
        Database::transaccion(function () use ($torneoId, $usuarioId) {
            $this->query("DELETE FROM torneo_organizadores WHERE torneo_id = :t", [':t' => $torneoId]);
            if ($usuarioId) {
                $this->query(
                    "INSERT INTO torneo_organizadores (torneo_id, usuario_id) VALUES (:t, :u)",
                    [':t' => $torneoId, ':u' => $usuarioId]
                );
            }
        });
    }

    /**
     * Torneos que se listan en el sitio público. La regla de qué es «público» vive
     * en la vista v_torneos_publicos (marcado como público y fuera de borrador);
     * el listado, además, omite los cancelados. Cada fila trae `inscritos`, así que
     * las plantillas no tienen que consultarlo torneo por torneo.
     */
    public function findPublicos(): array
    {
        return $this->fetchAll(
            "SELECT * FROM v_torneos_publicos
             WHERE estado <> 'cancelado'
             ORDER BY id DESC"
        );
    }

    /**
     * Una página del listado público, con búsqueda por nombre y filtros de formato y estado.
     * Todo se resuelve en SQL (antes se traía la lista entera y se filtraba en PHP).
     *
     * @param array{q?:string,tipo?:string,estado?:string} $f filtros ya saneados (ver Filtros)
     */
    public function buscarPublicos(array $f, ?int $limite = null, int $desde = 0): array
    {
        [$donde, $params] = $this->condicionesPublicas($f);
        return $this->fetchAll(
            "SELECT * FROM v_torneos_publicos {$donde} ORDER BY id DESC" . $this->limiteDe($limite, $desde),
            $params
        );
    }

    /** Cuántos torneos públicos cumplen los filtros (el COUNT de la lista paginada). */
    public function contarPublicos(array $f): int
    {
        [$donde, $params] = $this->condicionesPublicas($f);
        return (int) $this->fetchColumn("SELECT COUNT(*) FROM v_torneos_publicos {$donde}", $params);
    }

    /** @return array{0:string,1:array} el WHERE y sus parámetros */
    private function condicionesPublicas(array $f): array
    {
        $condiciones = ["estado <> 'cancelado'"];
        $params      = [];
        $this->agregarBusqueda($condiciones, $params, (string) ($f['q'] ?? ''), ['nombre']);
        if (($f['tipo'] ?? '') !== '') {
            $condiciones[]    = 'tipo_slug = :tipo';
            $params[':tipo']  = $f['tipo'];
        }
        if (($f['estado'] ?? '') !== '') {
            $condiciones[]      = 'estado = :estado';
            $params[':estado']  = $f['estado'];
        }
        return [$this->dondeDe($condiciones), $params];
    }

    /**
     * El torneo que se destaca en la portada: el más reciente en curso o, si no hay, el más
     * reciente. Una sola fila: antes la portada traía todos los torneos para elegir uno.
     */
    public function destacadoPublico(): ?array
    {
        $r = $this->fetchOne(
            "SELECT * FROM v_torneos_publicos
             WHERE estado <> 'cancelado'
             ORDER BY (estado = 'en_curso') DESC, id DESC
             LIMIT 1"
        );
        return $r ?: null;
    }

    /**
     * Una página de los torneos del panel. Con $organizadorId solo los de ese organizador; sin
     * él, todos (el administrador). Misma forma de filas que findAllConTipo().
     */
    public function buscarParaPanel(array $f, ?int $limite = null, int $desde = 0, ?int $organizadorId = null): array
    {
        [$donde, $params] = $this->condicionesPanel($f, $organizadorId);
        return $this->fetchAll(
            "SELECT t.*, tt.nombre AS tipo_nombre, tt.slug AS tipo_slug,
                    (SELECT COUNT(*) FROM inscripciones i
                      WHERE i.torneo_id = t.id AND i.estado = 'activa') AS inscritos
             FROM torneos t
             JOIN tipos_torneo tt ON tt.id = t.tipo_torneo_id
             {$donde}
             ORDER BY t.id DESC" . $this->limiteDe($limite, $desde),
            $params
        );
    }

    public function contarParaPanel(array $f, ?int $organizadorId = null): int
    {
        [$donde, $params] = $this->condicionesPanel($f, $organizadorId);
        return (int) $this->fetchColumn(
            "SELECT COUNT(*) FROM torneos t JOIN tipos_torneo tt ON tt.id = t.tipo_torneo_id {$donde}",
            $params
        );
    }

    /**
     * Una página de torneos con su avance, para el reporte global: los partidos jugados y los
     * que tiene en total (sin contar los bye ni los cancelados) y el nombre del campeón.
     * Mismos filtros que la lista del panel; se cuenta con contarParaPanel().
     */
    public function buscarConAvance(array $f, ?int $limite = null, int $desde = 0): array
    {
        [$donde, $params] = $this->condicionesPanel($f, null);
        return $this->fetchAll(
            "SELECT t.*, tt.nombre AS tipo_nombre, tt.slug AS tipo_slug,
                    (SELECT COUNT(*) FROM inscripciones i
                      WHERE i.torneo_id = t.id AND i.estado = 'activa') AS inscritos,
                    (SELECT COUNT(*) FROM enfrentamientos e
                      WHERE e.torneo_id = t.id AND e.es_bye = 0 AND e.estado <> 'cancelado') AS partidos_total,
                    (SELECT COUNT(*) FROM enfrentamientos e
                      WHERE e.torneo_id = t.id AND e.es_bye = 0 AND e.estado = 'finalizado') AS partidos_jugados,
                    COALESCE(cp.nombre, ce.nombre) AS campeon
             FROM torneos t
             JOIN tipos_torneo tt ON tt.id = t.tipo_torneo_id
             LEFT JOIN participantes cp ON cp.id = t.campeon_participante_id
             LEFT JOIN equipos       ce ON ce.id = t.campeon_equipo_id
             {$donde}
             ORDER BY t.id DESC" . $this->limiteDe($limite, $desde),
            $params
        );
    }

    /** @return array{0:string,1:array} el WHERE y sus parámetros */
    private function condicionesPanel(array $f, ?int $organizadorId): array
    {
        $condiciones = [];
        $params      = [];
        $this->agregarBusqueda($condiciones, $params, (string) ($f['q'] ?? ''), ['t.nombre']);
        if (($f['tipo'] ?? '') !== '') {
            $condiciones[]    = 'tt.slug = :tipo';
            $params[':tipo']  = $f['tipo'];
        }
        if (($f['estado'] ?? '') !== '') {
            $condiciones[]      = 't.estado = :estado';
            $params[':estado']  = $f['estado'];
        }
        if ($organizadorId !== null) {
            $condiciones[]   = 'EXISTS (SELECT 1 FROM torneo_organizadores tor WHERE tor.torneo_id = t.id AND tor.usuario_id = :org)';
            $params[':org']  = $organizadorId;
        }
        return [$this->dondeDe($condiciones), $params];
    }

    /**
     * ¿Un visitante puede ver este torneo? Es la misma regla del listado
     * (v_torneos_publicos) y por eso no se repite acá: antes la página de detalle
     * solo miraba la casilla «público» y mostraba por enlace directo los torneos
     * en borrador, que el listado ocultaba.
     */
    public function esPublico(int $id): bool
    {
        return (bool) $this->fetchColumn(
            "SELECT COUNT(*) FROM v_torneos_publicos WHERE id = :id",
            [':id' => $id]
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

    public function updateEstado(int $id, string $estado): void
    {
        $this->query(
            "UPDATE torneos SET estado = :e, updated_at = NOW() WHERE id = :id",
            [':e' => $estado, ':id' => $id]
        );
    }

    /** Un torneo finalizado vuelve a «en curso» y se queda sin campeón (lo decide lo que falta jugar). */
    public function reabrir(int $id): void
    {
        $this->query(
            "UPDATE torneos
             SET estado = 'en_curso', campeon_participante_id = NULL, campeon_equipo_id = NULL, updated_at = NOW()
             WHERE id = :id",
            [':id' => $id]
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

    /** Setea el campeón (participante o equipo, según modalidad) en un solo punto de entrada. */
    public function setCampeon(bool $esEquipos, int $torneoId, int $ganadorId): void
    {
        if ($esEquipos) {
            $this->setCampeonEquipo($torneoId, $ganadorId);
        } else {
            $this->setCampeonParticipante($torneoId, $ganadorId);
        }
    }

    /** Inscritos que van a competir: las inscripciones retiradas no cuentan. */
    public function contarInscritos(int $torneoId): int
    {
        return (int) $this->fetchColumn(
            "SELECT COUNT(*) FROM inscripciones WHERE torneo_id = :tid AND estado = 'activa'",
            [':tid' => $torneoId]
        );
    }
}
